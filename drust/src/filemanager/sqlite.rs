//! SQLite viewer and editor for files inside an account home: the schema,
//! a page of a table's rows, one statement typed by the user, and cell /
//! row edits.
//!
//! drust runs as root, so the connection is locked down: it is read-only
//! unless the request sets `write`, ATTACH is refused in both modes (it
//! could open any file on the host, and VACUUM INTO needs it), extensions
//! stay off, and each request has a time and row budget. After a write the
//! journal / WAL files SQLite may have created are handed back to the
//! database file's owner, so the site's own app can still write to it.

use std::fs::File;
use std::io::Read;
use std::path::Path;
use std::sync::Arc;
use std::time::{Duration, Instant};

use crate::api::{ApiResponse, ApiState, check_token};
use axum::{
    extract::{Json, State},
    http::HeaderMap,
    response::{IntoResponse, Response},
};
use rusqlite::hooks::{AuthAction, AuthContext, Authorization};
use rusqlite::limits::Limit;
use rusqlite::types::ValueRef;
use rusqlite::{Connection, OpenFlags, Statement};
use serde::Deserialize;
use serde_json::{Value, json};

use super::common::{ensure_canonical_inside_home, validate_account, validate_user_path};

const HEADER: &[u8; 16] = b"SQLite format 3\0";
const TIME_LIMIT: Duration = Duration::from_secs(10);
const MAX_QUERY_ROWS: usize = 1000;
const MAX_PAGE_SIZE: u32 = 500;
const MAX_SQL_BYTES: usize = 64 * 1024;
/// Longer text cells are cut so one huge value cannot bloat the response.
const MAX_TEXT_CHARS: usize = 20_000;
const BLOB_PREVIEW_BYTES: usize = 32;
/// Integers beyond this lose precision as JSON numbers in a browser.
const JS_SAFE_INTEGER: i64 = 9_007_199_254_740_991;

#[derive(Deserialize)]
pub(crate) struct Request {
    username: String,
    path: String,
    /// Open read-write. Without it every statement that changes data is refused.
    #[serde(default)]
    write: bool,
    #[serde(flatten)]
    op: Op,
}

#[derive(Deserialize)]
#[serde(tag = "action", rename_all = "snake_case")]
pub(crate) enum Op {
    Schema,
    Rows {
        table: String,
        #[serde(default = "first_page")]
        page: u32,
        #[serde(default = "default_page_size")]
        per_page: u32,
        #[serde(default)]
        order_by: Option<String>,
        #[serde(default)]
        order_desc: bool,
        #[serde(default)]
        search: Option<String>,
    },
    Query {
        sql: String,
    },
    UpdateCell {
        table: String,
        rowid: Value,
        column: String,
        value: Value,
    },
    /// Several columns of one row in a single UPDATE, so a row edit is atomic.
    UpdateRow {
        table: String,
        rowid: Value,
        values: serde_json::Map<String, Value>,
    },
    DeleteRows {
        table: String,
        rowids: Vec<Value>,
    },
    InsertRow {
        table: String,
        #[serde(default)]
        values: serde_json::Map<String, Value>,
    },
}

impl Op {
    fn changes_data(&self) -> bool {
        matches!(self, Op::UpdateCell { .. } | Op::UpdateRow { .. } | Op::DeleteRows { .. } | Op::InsertRow { .. })
    }
}

fn first_page() -> u32 {
    1
}

fn default_page_size() -> u32 {
    50
}

pub(crate) fn run(path: &Path, op: &Op, write: bool) -> Result<Value, String> {
    if !looks_like_sqlite(path)? {
        return Err("This file is not a SQLite 3 database.".into());
    }
    if op.changes_data() && !write {
        return Err(READ_ONLY_MESSAGE.into());
    }

    let owner = SidecarOwner::of(path);
    let conn = open(path, write)?;
    let result = execute(&conn, path, op, write);
    drop(conn);
    if write {
        owner.restore(path);
    }
    result
}

const READ_ONLY_MESSAGE: &str = "Read-only mode: turn on editing to change data.";

fn execute(conn: &Connection, path: &Path, op: &Op, write: bool) -> Result<Value, String> {
    match op {
        Op::Schema => schema(conn, path),
        Op::Rows {
            table,
            page,
            per_page,
            order_by,
            order_desc,
            search,
        } => rows(
            conn,
            table,
            (*page).max(1),
            (*per_page).clamp(1, MAX_PAGE_SIZE),
            order_by.as_deref(),
            *order_desc,
            search.as_deref().map(str::trim).filter(|s| !s.is_empty()),
        ),
        Op::Query { sql } => query(conn, sql, write),
        Op::UpdateCell { table, rowid, column, value } => update_cell(conn, table, rowid, column, value),
        Op::UpdateRow { table, rowid, values } => update_row(conn, table, rowid, values),
        Op::DeleteRows { table, rowids } => delete_rows(conn, table, rowids),
        Op::InsertRow { table, values } => insert_row(conn, table, values),
    }
}

/// Owner of the database file, so files SQLite creates beside it while
/// drust (root) writes can be given back to the site user.
struct SidecarOwner {
    owner: Option<crate::platform::fs::OwnerId>,
}

impl SidecarOwner {
    fn of(path: &Path) -> Self {
        let files = crate::platform::files();
        Self { owner: std::fs::metadata(path).ok().and_then(|meta| files.owner(&meta).ok()) }
    }

    fn restore(&self, path: &Path) {
        let Some(owner) = self.owner else { return };
        let files = crate::platform::files();
        for suffix in ["-wal", "-shm", "-journal"] {
            let mut name = path.as_os_str().to_owned();
            name.push(suffix);
            let sidecar = Path::new(&name);
            if let Ok(meta) = std::fs::symlink_metadata(sidecar)
                && meta.is_file()
                && files.owner(&meta).is_ok_and(|current| current != owner)
            {
                let _ = files.set_owner(sidecar, &owner);
            }
        }
    }
}

fn looks_like_sqlite(path: &Path) -> Result<bool, String> {
    let mut file = File::open(path).map_err(|e| format!("Cannot open file: {e}"))?;
    let mut header = [0u8; 16];
    match file.read_exact(&mut header) {
        // A zero-byte file is a valid, empty database to SQLite.
        Err(_) => Ok(file.metadata().map(|m| m.len() == 0).unwrap_or(false)),
        Ok(()) => Ok(&header == HEADER),
    }
}

fn open(path: &Path, write: bool) -> Result<Connection, String> {
    let mode = if write { OpenFlags::SQLITE_OPEN_READ_WRITE } else { OpenFlags::SQLITE_OPEN_READ_ONLY };
    let conn = Connection::open_with_flags(path, mode | OpenFlags::SQLITE_OPEN_NO_MUTEX)
    .map_err(|e| format!("Cannot open the database: {e}"))?;

    conn.set_limit(Limit::SQLITE_LIMIT_ATTACHED, 0)
        .map_err(|e| e.to_string())?;
    conn.authorizer(Some(|ctx: AuthContext<'_>| match ctx.action {
        AuthAction::Attach { .. } | AuthAction::Detach { .. } => Authorization::Deny,
        // Deprecated pragmas that point SQLite at another directory on the host.
        AuthAction::Pragma { pragma_name, .. }
            if pragma_name.eq_ignore_ascii_case("temp_store_directory")
                || pragma_name.eq_ignore_ascii_case("data_store_directory") =>
        {
            Authorization::Deny
        }
        _ => Authorization::Allow,
    }))
    .map_err(|e| e.to_string())?;
    conn.busy_timeout(Duration::from_secs(2))
        .map_err(|e| e.to_string())?;

    let started = Instant::now();
    conn.progress_handler(10_000, Some(move || started.elapsed() > TIME_LIMIT))
        .map_err(|e| e.to_string())?;

    Ok(conn)
}

fn friendly(error: rusqlite::Error) -> String {
    let text = error.to_string();
    if text.contains("interrupted") {
        format!("Stopped after {} seconds. Narrow the query (add WHERE or LIMIT).", TIME_LIMIT.as_secs())
    } else if text.contains("not authorized") || text.contains("too many attached") {
        "ATTACH, VACUUM INTO and directory pragmas are not allowed in the viewer.".into()
    } else {
        text
    }
}

fn quote_ident(name: &str) -> String {
    format!("\"{}\"", name.replace('"', "\"\""))
}

fn schema(conn: &Connection, path: &Path) -> Result<Value, String> {
    let mut objects = conn
        .prepare(
            "SELECT type, name, tbl_name, sql FROM sqlite_schema \
             WHERE type IN ('table','view') ORDER BY type, name",
        )
        .map_err(friendly)?;
    let list: Vec<(String, String, Option<String>)> = objects
        .query_map([], |row| Ok((row.get(0)?, row.get(1)?, row.get(3)?)))
        .map_err(friendly)?
        .collect::<Result<_, _>>()
        .map_err(friendly)?;

    let mut tables = Vec::new();
    for (kind, name, sql) in list {
        let columns = columns_of(conn, &name)?;
        let indexes = if kind == "table" { indexes_of(conn, &name)? } else { Vec::new() };
        let row_count: Option<i64> = if kind == "table" {
            conn.query_row(&format!("SELECT count(*) FROM {}", quote_ident(&name)), [], |r| r.get(0))
                .ok()
        } else {
            None
        };
        tables.push(json!({
            "name": name,
            "type": kind,
            "internal": name.starts_with("sqlite_"),
            "row_count": row_count,
            "columns": columns,
            "indexes": indexes,
            "sql": sql,
        }));
    }

    let pragma = |name: &str| -> Value {
        conn.query_row(&format!("PRAGMA {name}"), [], |r| Ok(cell(r.get_ref(0)?)))
            .unwrap_or(Value::Null)
    };

    Ok(json!({
        "file_size": std::fs::metadata(path).map(|m| m.len()).unwrap_or(0),
        "sqlite_version": rusqlite::version(),
        "journal_mode": pragma("journal_mode"),
        "page_size": pragma("page_size"),
        "page_count": pragma("page_count"),
        "encoding": pragma("encoding"),
        "user_version": pragma("user_version"),
        "tables": tables,
    }))
}

fn columns_of(conn: &Connection, table: &str) -> Result<Vec<Value>, String> {
    let mut stmt = conn
        .prepare(&format!("PRAGMA table_xinfo({})", quote_ident(table)))
        .map_err(friendly)?;
    stmt.query_map([], |row| {
        Ok(json!({
            "name": row.get::<_, String>(1)?,
            "type": row.get::<_, String>(2)?,
            "not_null": row.get::<_, i64>(3)? != 0,
            "default": row.get::<_, Option<String>>(4)?,
            "pk": row.get::<_, i64>(5)?,
            "hidden": row.get::<_, i64>(6)? != 0,
        }))
    })
    .map_err(friendly)?
    .collect::<Result<_, _>>()
    .map_err(friendly)
}

fn indexes_of(conn: &Connection, table: &str) -> Result<Vec<Value>, String> {
    let mut stmt = conn
        .prepare(&format!("PRAGMA index_list({})", quote_ident(table)))
        .map_err(friendly)?;
    let list: Vec<(String, bool, String)> = stmt
        .query_map([], |row| {
            Ok((row.get(1)?, row.get::<_, i64>(2)? != 0, row.get(3)?))
        })
        .map_err(friendly)?
        .collect::<Result<_, _>>()
        .map_err(friendly)?;

    let mut out = Vec::new();
    for (name, unique, origin) in list {
        let mut cols = conn
            .prepare(&format!("PRAGMA index_info({})", quote_ident(&name)))
            .map_err(friendly)?;
        let columns: Vec<Option<String>> = cols
            .query_map([], |row| row.get(2))
            .map_err(friendly)?
            .collect::<Result<_, _>>()
            .map_err(friendly)?;
        out.push(json!({
            "name": name,
            "unique": unique,
            "origin": origin,
            "columns": columns.into_iter().map(|c| c.unwrap_or_else(|| "(expression)".into())).collect::<Vec<_>>(),
        }));
    }
    Ok(out)
}

fn rows(
    conn: &Connection,
    table: &str,
    page: u32,
    per_page: u32,
    order_by: Option<&str>,
    order_desc: bool,
    search: Option<&str>,
) -> Result<Value, String> {
    let kind = object_kind(conn, table)?;
    let columns = column_names(conn, table)?;
    if let Some(column) = order_by
        && !columns.iter().any(|c| c == column)
    {
        return Err(format!("No column named \"{column}\"."));
    }

    let from = quote_ident(table);
    let filter = match search {
        Some(_) if !columns.is_empty() => format!(
            " WHERE {}",
            columns
                .iter()
                .map(|c| format!("CAST({} AS TEXT) LIKE ?1 ESCAPE '\\'", quote_ident(c)))
                .collect::<Vec<_>>()
                .join(" OR ")
        ),
        _ => String::new(),
    };
    let pattern = search.map(|s| {
        format!("%{}%", s.replace('\\', "\\\\").replace('%', "\\%").replace('_', "\\_"))
    });
    let params: Vec<&dyn rusqlite::ToSql> = match (&pattern, filter.is_empty()) {
        (Some(p), false) => vec![p],
        _ => vec![],
    };

    let total: i64 = conn
        .query_row(&format!("SELECT count(*) FROM {from}{filter}"), params.as_slice(), |r| r.get(0))
        .map_err(friendly)?;

    let order = match order_by {
        Some(column) => format!(" ORDER BY {} {}", quote_ident(column), if order_desc { "DESC" } else { "ASC" }),
        None => String::new(),
    };
    let offset = u64::from(page - 1) * u64::from(per_page);
    // Rows of a rowid table come with their rowid, so the grid can edit them.
    let alias = if kind == "table" { rowid_alias(conn, table, &columns) } else { None };
    let select = match alias {
        Some(alias) => format!("{alias} AS \"__dpanel_rowid\", *"),
        None => "*".into(),
    };
    let sql = format!("SELECT {select} FROM {from}{filter}{order} LIMIT {per_page} OFFSET {offset}");
    let mut stmt = conn.prepare(&sql).map_err(friendly)?;
    let (mut names, mut data, _) = collect(&mut stmt, params.as_slice(), per_page as usize)?;
    let mut rowids = Vec::new();
    if alias.is_some() {
        names.remove(0);
        for row in &mut data {
            if let Value::Array(cells) = row {
                rowids.push(cells.remove(0));
            }
        }
    }

    Ok(json!({
        "table": table,
        "editable": alias.is_some(),
        "rowids": rowids,
        "columns": names,
        "rows": data,
        "total": total,
        "page": page,
        "per_page": per_page,
    }))
}

/// "table" or "view"; only names that exist ever reach the SQL text.
fn object_kind(conn: &Connection, table: &str) -> Result<String, String> {
    conn.query_row(
        "SELECT type FROM sqlite_schema WHERE type IN ('table','view') AND name = ?1",
        [table],
        |row| row.get(0),
    )
    .map_err(|_| format!("No table or view named \"{table}\"."))
}

fn column_names(conn: &Connection, table: &str) -> Result<Vec<String>, String> {
    Ok(columns_of(conn, table)?
        .into_iter()
        .filter_map(|c| c["name"].as_str().map(str::to_owned))
        .collect())
}

/// The name that reaches the rowid, unless a real column shadows all three
/// spellings or the table is WITHOUT ROWID.
fn rowid_alias(conn: &Connection, table: &str, columns: &[String]) -> Option<&'static str> {
    ["_rowid_", "rowid", "oid"].into_iter().find(|alias| {
        !columns.iter().any(|c| c.eq_ignore_ascii_case(alias))
            && conn.prepare(&format!("SELECT {alias} FROM {} LIMIT 0", quote_ident(table))).is_ok()
    })
}

/// A table that can be edited by rowid, with its column names.
fn editable_table(conn: &Connection, table: &str) -> Result<(&'static str, Vec<String>), String> {
    if object_kind(conn, table)? != "table" {
        return Err("Views cannot be edited.".into());
    }
    let columns = column_names(conn, table)?;
    let alias = rowid_alias(conn, table, &columns)
        .ok_or("This table has no rowid (WITHOUT ROWID); edit it with SQL.")?;
    Ok((alias, columns))
}

fn parse_rowid(value: &Value) -> Result<i64, String> {
    match value {
        Value::Number(n) => n.as_i64(),
        Value::String(s) => s.parse().ok(),
        _ => None,
    }
    .ok_or_else(|| "Invalid rowid.".to_string())
}

fn sql_value(value: &Value) -> Result<rusqlite::types::Value, String> {
    use rusqlite::types::Value as Sql;
    Ok(match value {
        Value::Null => Sql::Null,
        Value::Bool(b) => Sql::Integer(i64::from(*b)),
        Value::Number(n) => match n.as_i64() {
            Some(i) => Sql::Integer(i),
            None => Sql::Real(n.as_f64().unwrap_or(0.0)),
        },
        Value::String(s) => Sql::Text(s.clone()),
        _ => return Err("Only text, numbers and NULL can be written here; change blobs with SQL.".into()),
    })
}

fn update_cell(conn: &Connection, table: &str, rowid: &Value, column: &str, value: &Value) -> Result<Value, String> {
    let (alias, columns) = editable_table(conn, table)?;
    if !columns.iter().any(|c| c == column) {
        return Err(format!("No column named \"{column}\"."));
    }
    let sql = format!("UPDATE {} SET {} = ?1 WHERE {alias} = ?2", quote_ident(table), quote_ident(column));
    let changes = conn
        .execute(&sql, rusqlite::params![sql_value(value)?, parse_rowid(rowid)?])
        .map_err(friendly)?;
    if changes == 0 {
        return Err("The row no longer exists. Refresh and try again.".into());
    }
    Ok(json!({ "changes": changes }))
}

fn update_row(conn: &Connection, table: &str, rowid: &Value, values: &serde_json::Map<String, Value>) -> Result<Value, String> {
    let (alias, columns) = editable_table(conn, table)?;
    if values.is_empty() {
        return Err("Nothing to save: no column was changed.".into());
    }
    let mut sets = Vec::new();
    let mut params = Vec::new();
    for (column, value) in values {
        if !columns.iter().any(|c| c == column) {
            return Err(format!("No column named \"{column}\"."));
        }
        params.push(sql_value(value)?);
        sets.push(format!("{} = ?{}", quote_ident(column), params.len()));
    }
    params.push(rusqlite::types::Value::Integer(parse_rowid(rowid)?));
    let sql = format!("UPDATE {} SET {} WHERE {alias} = ?{}", quote_ident(table), sets.join(", "), params.len());
    let changes = conn.execute(&sql, rusqlite::params_from_iter(params)).map_err(friendly)?;
    if changes == 0 {
        return Err("The row no longer exists. Refresh and try again.".into());
    }
    Ok(json!({ "changes": changes }))
}

fn delete_rows(conn: &Connection, table: &str, rowids: &[Value]) -> Result<Value, String> {
    let (alias, _) = editable_table(conn, table)?;
    if rowids.is_empty() || rowids.len() > MAX_PAGE_SIZE as usize {
        return Err(format!("Select between 1 and {MAX_PAGE_SIZE} rows to delete."));
    }
    let ids = rowids.iter().map(parse_rowid).collect::<Result<Vec<_>, _>>()?;
    let marks = vec!["?"; ids.len()].join(",");
    let sql = format!("DELETE FROM {} WHERE {alias} IN ({marks})", quote_ident(table));
    let changes = conn.execute(&sql, rusqlite::params_from_iter(ids)).map_err(friendly)?;
    Ok(json!({ "changes": changes }))
}

fn insert_row(conn: &Connection, table: &str, values: &serde_json::Map<String, Value>) -> Result<Value, String> {
    if object_kind(conn, table)? != "table" {
        return Err("Rows can only be added to tables.".into());
    }
    let columns = column_names(conn, table)?;
    let mut names = Vec::new();
    let mut params = Vec::new();
    for (column, value) in values {
        if !columns.iter().any(|c| c == column) {
            return Err(format!("No column named \"{column}\"."));
        }
        names.push(quote_ident(column));
        params.push(sql_value(value)?);
    }
    let sql = if names.is_empty() {
        format!("INSERT INTO {} DEFAULT VALUES", quote_ident(table))
    } else {
        format!(
            "INSERT INTO {} ({}) VALUES ({})",
            quote_ident(table),
            names.join(", "),
            vec!["?"; names.len()].join(", ")
        )
    };
    let changes = conn.execute(&sql, rusqlite::params_from_iter(params)).map_err(friendly)?;
    Ok(json!({ "changes": changes, "rowid": conn.last_insert_rowid() }))
}

fn query(conn: &Connection, sql: &str, write: bool) -> Result<Value, String> {
    let sql = sql.trim();
    if sql.is_empty() {
        return Err("Type a query to run.".into());
    }
    if sql.len() > MAX_SQL_BYTES {
        return Err("The query is too long (max 64 KB).".into());
    }

    let mut stmt = conn.prepare(sql).map_err(|e| match e {
        rusqlite::Error::MultipleStatement => "Run one statement at a time.".to_string(),
        other => friendly(other),
    })?;
    let changes_data = !stmt.readonly();
    if changes_data && !write {
        return Err(READ_ONLY_MESSAGE.into());
    }

    let started = Instant::now();
    let (names, data, truncated) = collect(&mut stmt, &[], MAX_QUERY_ROWS)?;
    drop(stmt);

    Ok(json!({
        "changes": if changes_data { conn.changes() } else { 0 },
        "wrote": changes_data,
        "columns": names,
        "rows": data,
        "row_count": data.len(),
        "truncated": truncated,
        "max_rows": MAX_QUERY_ROWS,
        "duration_ms": started.elapsed().as_millis() as u64,
    }))
}

/// Up to `limit` rows as arrays of JSON cells, and whether more were left.
fn collect(
    stmt: &mut Statement<'_>,
    params: &[&dyn rusqlite::ToSql],
    limit: usize,
) -> Result<(Vec<String>, Vec<Value>, bool), String> {
    let names: Vec<String> = stmt.column_names().into_iter().map(str::to_owned).collect();
    let width = names.len();
    let mut rows = stmt.query(params).map_err(friendly)?;
    let mut data = Vec::new();
    let mut truncated = false;

    while let Some(row) = rows.next().map_err(friendly)? {
        if data.len() == limit {
            truncated = true;
            break;
        }
        let mut cells = Vec::with_capacity(width);
        for index in 0..width {
            cells.push(cell(row.get_ref(index).map_err(friendly)?));
        }
        data.push(Value::Array(cells));
    }

    Ok((names, data, truncated))
}

fn cell(value: ValueRef<'_>) -> Value {
    match value {
        ValueRef::Null => Value::Null,
        ValueRef::Integer(i) if i.abs() <= JS_SAFE_INTEGER => json!(i),
        ValueRef::Integer(i) => json!(i.to_string()),
        ValueRef::Real(f) => serde_json::Number::from_f64(f).map_or(Value::Null, Value::Number),
        ValueRef::Text(bytes) => {
            let text = String::from_utf8_lossy(bytes);
            if text.chars().count() > MAX_TEXT_CHARS {
                json!({
                    "$text": text.chars().take(MAX_TEXT_CHARS).collect::<String>(),
                    "length": text.chars().count(),
                })
            } else {
                Value::String(text.into_owned())
            }
        }
        ValueRef::Blob(bytes) => json!({
            "$blob": bytes.len(),
            "hex": bytes.iter().take(BLOB_PREVIEW_BYTES).map(|b| format!("{b:02x}")).collect::<String>(),
        }),
    }
}

fn handle_request(request: &Request) -> Result<Value, String> {
    let (_, canonical_home, _) = validate_account(&request.username)?;
    let path = validate_user_path(&request.username, &request.path)?;
    let path = ensure_canonical_inside_home(&canonical_home, &path, "File")?;
    if !path.is_file() {
        return Err("Selected path is not a file.".into());
    }
    run(&path, &request.op, request.write)
}

pub(crate) async fn handle(
    State(state): State<Arc<ApiState>>,
    headers: HeaderMap,
    Json(request): Json<Request>,
) -> Response {
    if let Err(error) = check_token(&state, &headers) {
        return error.into_response();
    }
    match tokio::task::spawn_blocking(move || handle_request(&request)).await {
        Ok(Ok(data)) => ApiResponse::ok_data("SQLite", data).into_response(),
        Ok(Err(error)) => ApiResponse::error(&error).into_response(),
        Err(error) => ApiResponse::error(&format!("SQLite viewer failed: {error}")).into_response(),
    }
}

#[cfg(test)]
mod tests {
    use super::{Op, run};
    use serde_json::json;
    use std::path::PathBuf;

    fn fixture(name: &str) -> PathBuf {
        let path = std::env::temp_dir().join(format!("drust-sqlite-{}-{name}.db", std::process::id()));
        let _ = std::fs::remove_file(&path);
        let conn = rusqlite::Connection::open(&path).unwrap();
        conn.execute_batch(
            "CREATE TABLE users (id INTEGER PRIMARY KEY, email TEXT NOT NULL UNIQUE, avatar BLOB);
             CREATE VIEW emails AS SELECT email FROM users;
             INSERT INTO users (email, avatar) VALUES ('a@x.test', x'0102'), ('b@x.test', NULL), ('c_100%@x.test', NULL);",
        )
        .unwrap();
        path
    }

    fn query(sql: &str) -> Op {
        Op::Query { sql: sql.into() }
    }

    #[test]
    fn schema_lists_tables_views_columns_and_indexes() {
        let path = fixture("schema");
        let data = run(&path, &Op::Schema, false).unwrap();
        std::fs::remove_file(&path).unwrap();

        let users = data["tables"].as_array().unwrap().iter().find(|t| t["name"] == "users").unwrap();
        assert_eq!(users["row_count"], 3);
        assert_eq!(users["columns"][1]["name"], "email");
        assert_eq!(users["indexes"][0]["unique"], true);
        assert!(data["tables"].as_array().unwrap().iter().any(|t| t["name"] == "emails" && t["type"] == "view"));
    }

    #[test]
    fn rows_page_sort_and_search_literally() {
        let path = fixture("rows");
        let page = run(&path, &Op::Rows { table: "users".into(), page: 1, per_page: 2, order_by: Some("id".into()), order_desc: true, search: None }, false).unwrap();
        let found = run(&path, &Op::Rows { table: "users".into(), page: 1, per_page: 50, order_by: None, order_desc: false, search: Some("_100%".into()) }, false).unwrap();
        let bad_column = run(&path, &Op::Rows { table: "users".into(), page: 1, per_page: 50, order_by: Some("id; DROP TABLE users".into()), order_desc: false, search: None }, false);
        std::fs::remove_file(&path).unwrap();

        assert_eq!(page["total"], 3);
        assert_eq!(page["rows"].as_array().unwrap().len(), 2);
        assert_eq!(page["rows"][0][0], 3);
        assert_eq!(page["rows"][0][2], serde_json::Value::Null);
        assert_eq!(found["total"], 1);
        assert!(bad_column.is_err());
    }

    #[test]
    fn select_runs_and_blobs_are_summarised() {
        let path = fixture("select");
        let data = run(&path, &query("SELECT avatar FROM users WHERE id = 1"), false).unwrap();
        std::fs::remove_file(&path).unwrap();

        assert_eq!(data["rows"][0][0]["$blob"], 2);
        assert_eq!(data["rows"][0][0]["hex"], "0102");
    }

    #[test]
    fn writes_attach_and_multiple_statements_are_refused() {
        let path = fixture("refuse");
        let attempts = [
            "DELETE FROM users",
            "UPDATE users SET email = 'x'",
            "DROP TABLE users",
            "ATTACH DATABASE '/etc/passwd' AS p",
            "VACUUM INTO '/tmp/drust-sqlite-copy.db'",
            "SELECT 1; DELETE FROM users",
            "PRAGMA user_version = 5",
        ];
        for sql in attempts {
            assert!(run(&path, &query(sql), false).is_err(), "should refuse: {sql}");
        }
        let left = run(&path, &query("SELECT count(*) FROM users"), false).unwrap();
        std::fs::remove_file(&path).unwrap();

        assert_eq!(left["rows"][0][0], 3);
        assert!(!std::path::Path::new("/tmp/drust-sqlite-copy.db").exists());
    }

    #[test]
    fn non_sqlite_files_are_rejected() {
        let path = std::env::temp_dir().join(format!("drust-sqlite-{}-text.db", std::process::id()));
        std::fs::write(&path, "just some text, not a database").unwrap();
        let result = run(&path, &Op::Schema, false);
        std::fs::remove_file(&path).unwrap();

        assert!(result.unwrap_err().contains("not a SQLite"));
    }

    fn rows_of(path: &std::path::Path) -> serde_json::Value {
        run(path, &Op::Rows { table: "users".into(), page: 1, per_page: 50, order_by: Some("id".into()), order_desc: false, search: None }, false).unwrap()
    }

    #[test]
    fn edits_need_write_mode() {
        let path = fixture("needwrite");
        let edit = Op::UpdateCell { table: "users".into(), rowid: json!(1), column: "email".into(), value: json!("z@x.test") };
        let refused = run(&path, &edit, false);
        let update = run(&path, &query("UPDATE users SET email = 'q'"), false);
        let left = rows_of(&path);
        std::fs::remove_file(&path).unwrap();

        assert!(refused.unwrap_err().contains("Read-only"));
        assert!(update.unwrap_err().contains("Read-only"));
        assert_eq!(left["rows"][0][1], "a@x.test");
    }

    #[test]
    fn rows_carry_rowids_and_cells_rows_can_be_edited() {
        let path = fixture("edit");
        let before = rows_of(&path);
        run(&path, &Op::UpdateCell { table: "users".into(), rowid: before["rowids"][0].clone(), column: "email".into(), value: json!("new@x.test") }, true).unwrap();
        run(&path, &Op::UpdateCell { table: "users".into(), rowid: json!("2"), column: "avatar".into(), value: json!(null) }, true).unwrap();
        let inserted = run(&path, &Op::InsertRow { table: "users".into(), values: serde_json::from_value(json!({"email": "d@x.test"})).unwrap() }, true).unwrap();
        let deleted = run(&path, &Op::DeleteRows { table: "users".into(), rowids: vec![json!(2), json!(3)] }, true).unwrap();
        let bad_column = run(&path, &Op::UpdateCell { table: "users".into(), rowid: json!(1), column: "nope".into(), value: json!(1) }, true);
        let view = run(&path, &Op::DeleteRows { table: "emails".into(), rowids: vec![json!(1)] }, true);
        let after = rows_of(&path);
        std::fs::remove_file(&path).unwrap();

        assert_eq!(before["editable"], true);
        assert_eq!(before["rowids"], json!([1, 2, 3]));
        assert_eq!(before["columns"][0], "id");
        assert_eq!(inserted["rowid"], 4);
        assert_eq!(deleted["changes"], 2);
        assert!(bad_column.is_err());
        assert!(view.is_err());
        assert_eq!(after["rowids"], json!([1, 4]));
        assert_eq!(after["rows"][0][1], "new@x.test");
        assert_eq!(after["rows"][1][1], "d@x.test");
    }

    #[test]
    fn update_row_changes_several_columns_at_once() {
        let path = fixture("updaterow");
        let values = serde_json::from_value(json!({"email": "row@x.test", "avatar": null})).unwrap();
        let refused = run(&path, &Op::UpdateRow { table: "users".into(), rowid: json!(1), values: serde_json::Map::new() }, true);
        let readonly = run(&path, &Op::UpdateRow { table: "users".into(), rowid: json!(1), values: serde_json::from_value(json!({"email": "no"})).unwrap() }, false);
        let done = run(&path, &Op::UpdateRow { table: "users".into(), rowid: json!(1), values }, true).unwrap();
        let after = rows_of(&path);
        std::fs::remove_file(&path).unwrap();

        assert!(refused.is_err());
        assert!(readonly.unwrap_err().contains("Read-only"));
        assert_eq!(done["changes"], 1);
        assert_eq!(after["rows"][0][1], "row@x.test");
        assert_eq!(after["rows"][0][2], serde_json::Value::Null);
    }

    #[test]
    fn write_mode_runs_sql_but_still_blocks_host_access() {
        let path = fixture("writesql");
        let updated = run(&path, &query("UPDATE users SET avatar = NULL"), true).unwrap();
        let created = run(&path, &query("CREATE TABLE notes (body TEXT)"), true).unwrap();
        for sql in [
            "ATTACH DATABASE '/tmp/drust-sqlite-attach.db' AS other",
            "VACUUM INTO '/tmp/drust-sqlite-vacuum.db'",
            "PRAGMA temp_store_directory = '/tmp'",
        ] {
            assert!(run(&path, &query(sql), true).is_err(), "should refuse: {sql}");
        }
        std::fs::remove_file(&path).unwrap();

        assert_eq!(updated["changes"], 3);
        assert_eq!(updated["wrote"], true);
        assert_eq!(created["wrote"], true);
        assert!(!std::path::Path::new("/tmp/drust-sqlite-attach.db").exists());
        assert!(!std::path::Path::new("/tmp/drust-sqlite-vacuum.db").exists());
    }
}
