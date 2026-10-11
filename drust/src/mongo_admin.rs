//! Browse and edit MongoDB for the panel's Mongo Admin page (the panel's
//! answer to phpMyAdmin). Documents travel as relaxed Extended JSON, so
//! ObjectIds, dates and the like survive a round trip through the browser.

use std::{
    collections::HashMap,
    sync::Mutex,
    time::Duration,
};

use futures_util::TryStreamExt;
use mongodb::{
    Client, Collection, Database,
    bson::{Bson, Document, doc},
    options::{ClientOptions, Credential, ServerAddress},
};
use serde::Deserialize;
use serde_json::{Value, json};

/// Most documents a single page (find or aggregate) returns.
const PAGE_LIMIT: i64 = 500;
/// Most documents an export returns.
const EXPORT_LIMIT: i64 = 100_000;
/// Databases MongoDB needs for itself; they can be read but not dropped.
const SYSTEM_DATABASES: [&str; 3] = ["admin", "local", "config"];

#[derive(Deserialize, Clone, PartialEq, Eq, Hash)]
pub(crate) struct Connection {
    pub host: String,
    pub port: u16,
    pub username: String,
    pub password: String,
    /// A database's own login: the user is authenticated against this
    /// database and the page can only open it. None means the admin login.
    #[serde(default)]
    pub database: Option<String>,
}

#[derive(Deserialize)]
#[serde(tag = "action", rename_all = "snake_case")]
pub(crate) enum Op {
    Overview,
    CreateCollection { db: String, collection: String },
    DropCollection { db: String, collection: String },
    RenameCollection { db: String, collection: String, to: String },
    DropDatabase { db: String },
    Find {
        db: String,
        collection: String,
        filter: Option<Value>,
        sort: Option<Value>,
        projection: Option<Value>,
        skip: Option<u64>,
        limit: Option<i64>,
    },
    Insert { db: String, collection: String, documents: Value },
    Replace { db: String, collection: String, id: Value, document: Value },
    Delete { db: String, collection: String, id: Value },
    DeleteMany { db: String, collection: String, filter: Value },
    Indexes { db: String, collection: String },
    CreateIndex { db: String, collection: String, keys: Value, options: Option<Value> },
    DropIndex { db: String, collection: String, name: String },
    Aggregate { db: String, collection: String, pipeline: Value },
    Command { db: String, command: Value },
    Export { db: String, collection: String, filter: Option<Value> },
    /// Create a database's own user (dbOwner of it), or reset its password.
    ProvisionUser { db: String, user: String, password: String },
}

impl Op {
    /// The database this operation touches; None for the server overview.
    fn database(&self) -> Option<&str> {
        match self {
            Op::Overview => None,
            Op::CreateCollection { db, .. }
            | Op::DropCollection { db, .. }
            | Op::RenameCollection { db, .. }
            | Op::DropDatabase { db }
            | Op::Find { db, .. }
            | Op::Insert { db, .. }
            | Op::Replace { db, .. }
            | Op::Delete { db, .. }
            | Op::DeleteMany { db, .. }
            | Op::Indexes { db, .. }
            | Op::CreateIndex { db, .. }
            | Op::DropIndex { db, .. }
            | Op::Aggregate { db, .. }
            | Op::Command { db, .. }
            | Op::Export { db, .. }
            | Op::ProvisionUser { db, .. } => Some(db),
        }
    }
}

/// Most logins kept connected at once (the admin plus recent database logins).
const CACHED_CLIENTS: usize = 16;

/// One client per connection settings; the driver pools connections inside it.
static CLIENTS: Mutex<Option<HashMap<Connection, Client>>> = Mutex::new(None);

fn client(connection: &Connection) -> Result<Client, String> {
    let mut cached = CLIENTS.lock().map_err(|_| "client cache poisoned".to_string())?;
    let cached = cached.get_or_insert_with(HashMap::new);
    if let Some(client) = cached.get(connection) {
        return Ok(client.clone());
    }

    let mut options = ClientOptions::default();
    options.hosts = vec![ServerAddress::Tcp {
        host: connection.host.clone(),
        port: Some(connection.port),
    }];
    if !connection.username.is_empty() {
        let mut credential = Credential::default();
        credential.username = Some(connection.username.clone());
        credential.password = Some(connection.password.clone());
        credential.source = Some(connection.database.clone().unwrap_or_else(|| "admin".to_string()));
        options.credential = Some(credential);
    }
    options.app_name = Some("dpanel".to_string());
    options.direct_connection = Some(true);
    // Fail fast when mongod is turned off instead of hanging the page.
    options.server_selection_timeout = Some(Duration::from_secs(3));
    options.connect_timeout = Some(Duration::from_secs(3));

    let client = Client::with_options(options).map_err(|e| e.to_string())?;
    if cached.len() >= CACHED_CLIENTS {
        cached.clear();
    }
    cached.insert(connection.clone(), client.clone());
    Ok(client)
}

/// A database login may only reach its own database, and never manage users.
fn check_scope(connection: &Connection, op: &Op) -> Result<(), String> {
    let Some(scope) = connection.database.as_deref() else {
        return Ok(());
    };
    if matches!(op, Op::ProvisionUser { .. }) {
        return Err("Only the admin login can manage database users.".to_string());
    }
    match op.database() {
        Some(db) if db != scope => Err(format!("This login can only open the {scope} database.")),
        _ => Ok(()),
    }
}

pub(crate) async fn run(connection: &Connection, op: Op) -> Result<Value, String> {
    check_scope(connection, &op)?;
    let client = client(connection)?;
    execute(&client, op, connection.database.as_deref()).await.map_err(|error| {
        if error.contains("Server selection timeout") {
            "MongoDB is not reachable. Turn it on under Database Management > MongoDB.".to_string()
        } else {
            error
        }
    })
}

async fn execute(client: &Client, op: Op, scope: Option<&str>) -> Result<Value, String> {
    match op {
        Op::Overview => overview(client, scope).await,
        Op::CreateCollection { db, collection } => {
            database(client, &db)?
                .create_collection(checked_collection(&collection)?)
                .await
                .map_err(err)?;
            Ok(json!({ "message": format!("Collection {db}.{collection} created.") }))
        }
        Op::DropCollection { db, collection } => {
            collection_handle(client, &db, &collection)?.drop().await.map_err(err)?;
            Ok(json!({ "message": format!("Collection {db}.{collection} dropped.") }))
        }
        Op::RenameCollection { db, collection, to } => {
            checked_database(&db)?;
            checked_collection(&collection)?;
            checked_collection(&to)?;
            client
                .database("admin")
                .run_command(doc! {
                    "renameCollection": format!("{db}.{collection}"),
                    "to": format!("{db}.{to}"),
                })
                .await
                .map_err(err)?;
            Ok(json!({ "message": format!("Collection renamed to {db}.{to}.") }))
        }
        Op::DropDatabase { db } => {
            if SYSTEM_DATABASES.contains(&db.as_str()) {
                return Err(format!("The {db} database belongs to MongoDB and can't be dropped."));
            }
            database(client, &db)?.drop().await.map_err(err)?;
            Ok(json!({ "message": format!("Database {db} dropped.") }))
        }
        Op::Find { db, collection, filter, sort, projection, skip, limit } => {
            let collection = collection_handle(client, &db, &collection)?;
            let filter = optional_document(filter, "filter")?;
            let limit = limit.unwrap_or(20).clamp(1, PAGE_LIMIT);
            let skip = skip.unwrap_or(0);

            let mut find = collection.find(filter.clone()).skip(skip).limit(limit);
            if let Some(sort) = optional_document(sort, "sort").map(non_empty)? {
                find = find.sort(sort);
            }
            if let Some(projection) = optional_document(projection, "projection").map(non_empty)? {
                find = find.projection(projection);
            }
            let documents: Vec<Document> = find.await.map_err(err)?.try_collect().await.map_err(err)?;
            let total = if filter.is_empty() {
                collection.estimated_document_count().await.map_err(err)?
            } else {
                collection.count_documents(filter).await.map_err(err)?
            };
            Ok(json!({ "documents": to_json(documents), "total": total, "skip": skip, "limit": limit }))
        }
        Op::Insert { db, collection, documents } => {
            let collection = collection_handle(client, &db, &collection)?;
            let documents = match documents {
                Value::Array(items) => items
                    .into_iter()
                    .map(|item| document(item, "document"))
                    .collect::<Result<Vec<_>, _>>()?,
                other => vec![document(other, "document")?],
            };
            if documents.is_empty() {
                return Err("Nothing to insert.".to_string());
            }
            let result = collection.insert_many(documents).await.map_err(err)?;
            let count = result.inserted_ids.len();
            Ok(json!({ "message": format!("Inserted {count} document{}.", plural(count)), "inserted": count }))
        }
        Op::Replace { db, collection, id, document: replacement } => {
            let collection = collection_handle(client, &db, &collection)?;
            let id = bson(id, "_id")?;
            let mut replacement = document(replacement, "document")?;
            // The _id can't change; keep the original even if it was edited out.
            replacement.insert("_id", id.clone());
            let result = collection.replace_one(doc! { "_id": id }, replacement).await.map_err(err)?;
            if result.matched_count == 0 {
                return Err("That document no longer exists.".to_string());
            }
            Ok(json!({ "message": "Document saved." }))
        }
        Op::Delete { db, collection, id } => {
            let collection = collection_handle(client, &db, &collection)?;
            let result = collection.delete_one(doc! { "_id": bson(id, "_id")? }).await.map_err(err)?;
            Ok(json!({ "message": format!("Deleted {} document.", result.deleted_count), "deleted": result.deleted_count }))
        }
        Op::DeleteMany { db, collection, filter } => {
            let collection = collection_handle(client, &db, &collection)?;
            let result = collection.delete_many(document(filter, "filter")?).await.map_err(err)?;
            let count = result.deleted_count;
            Ok(json!({ "message": format!("Deleted {count} document{}.", plural(count as usize)), "deleted": count }))
        }
        Op::Indexes { db, collection } => {
            checked_collection(&collection)?;
            let result = database(client, &db)?
                .run_command(doc! { "listIndexes": &collection })
                .await
                .map_err(err)?;
            let indexes = result
                .get_document("cursor")
                .and_then(|cursor| cursor.get_array("firstBatch"))
                .map(|batch| batch.clone())
                .unwrap_or_default();
            Ok(json!({ "indexes": Bson::Array(indexes).into_relaxed_extjson() }))
        }
        Op::CreateIndex { db, collection, keys, options } => {
            checked_collection(&collection)?;
            let keys = non_empty(document(keys, "keys")?).ok_or("Give at least one key, e.g. { \"email\": 1 }.")?;
            let mut index = optional_document(options, "options")?;
            index.insert("key", keys);
            if !index.contains_key("name") {
                index.insert("name", default_index_name(index.get_document("key").map_err(err)?));
            }
            let name = index.get_str("name").unwrap_or_default().to_string();
            database(client, &db)?
                .run_command(doc! { "createIndexes": &collection, "indexes": [index] })
                .await
                .map_err(err)?;
            Ok(json!({ "message": format!("Index {name} created.") }))
        }
        Op::DropIndex { db, collection, name } => {
            if name == "_id_" {
                return Err("The _id index can't be dropped.".to_string());
            }
            checked_collection(&collection)?;
            database(client, &db)?
                .run_command(doc! { "dropIndexes": &collection, "index": &name })
                .await
                .map_err(err)?;
            Ok(json!({ "message": format!("Index {name} dropped.") }))
        }
        Op::Aggregate { db, collection, pipeline } => {
            let collection = collection_handle(client, &db, &collection)?;
            let mut stages = match bson(pipeline, "pipeline")? {
                Bson::Array(items) => items
                    .into_iter()
                    .map(|stage| match stage {
                        Bson::Document(stage) => Ok(stage),
                        _ => Err("Every pipeline stage must be an object.".to_string()),
                    })
                    .collect::<Result<Vec<_>, _>>()?,
                _ => return Err("The pipeline must be an array of stages.".to_string()),
            };
            stages.push(doc! { "$limit": PAGE_LIMIT });
            let documents: Vec<Document> =
                collection.aggregate(stages).await.map_err(err)?.try_collect().await.map_err(err)?;
            Ok(json!({ "documents": to_json(documents), "limit": PAGE_LIMIT }))
        }
        Op::Command { db, command } => {
            let result = database(client, &db)?
                .run_command(document(command, "command")?)
                .await
                .map_err(err)?;
            Ok(json!({ "result": Bson::Document(result).into_relaxed_extjson() }))
        }
        Op::Export { db, collection, filter } => {
            let collection = collection_handle(client, &db, &collection)?;
            let documents: Vec<Document> = collection
                .find(optional_document(filter, "filter")?)
                .limit(EXPORT_LIMIT)
                .await
                .map_err(err)?
                .try_collect()
                .await
                .map_err(err)?;
            Ok(json!({ "documents": to_json(documents), "limit": EXPORT_LIMIT }))
        }
        Op::ProvisionUser { db, user, password } => {
            if user.is_empty() || password.is_empty() {
                return Err("A database user and password are required.".to_string());
            }
            let database = database(client, &db)?;
            let existing = database.run_command(doc! { "usersInfo": &user }).await.map_err(err)?;
            let exists = existing.get_array("users").is_ok_and(|users| !users.is_empty());
            let roles = vec![Bson::Document(doc! { "role": "dbOwner", "db": &db })];
            let command = if exists {
                doc! { "updateUser": &user, "pwd": &password, "roles": roles }
            } else {
                doc! { "createUser": &user, "pwd": &password, "roles": roles }
            };
            database.run_command(command).await.map_err(err)?;
            Ok(json!({ "message": format!("MongoDB database/user synced successfully: {db} / {user}") }))
        }
    }
}

async fn overview(client: &Client, scope: Option<&str>) -> Result<Value, String> {
    // A database login can't list databases, and MongoDB only shows a
    // database once it holds data, so a scoped login always gets its own.
    let specs: Vec<(String, u64)> = match scope {
        Some(name) => {
            let stats = client.database(name).run_command(doc! { "dbStats": 1 }).await.map_err(err)?;
            let size = match stats.get("storageSize") {
                Some(Bson::Int32(n)) => *n as u64,
                Some(Bson::Int64(n)) => *n as u64,
                Some(Bson::Double(n)) => *n as u64,
                _ => 0,
            };
            vec![(name.to_string(), size)]
        }
        None => client
            .list_databases()
            .await
            .map_err(err)?
            .into_iter()
            .map(|spec| (spec.name, spec.size_on_disk))
            .collect(),
    };
    let mut databases = Vec::new();
    for (name, size) in specs {
        let db = client.database(&name);
        let mut collections = Vec::new();
        let mut cursor = db.list_collections().await.map_err(err)?;
        while let Some(collection) = cursor.try_next().await.map_err(err)? {
            let kind = format!("{:?}", collection.collection_type).to_lowercase();
            let count = if kind == "collection" {
                db.collection::<Document>(&collection.name)
                    .estimated_document_count()
                    .await
                    .unwrap_or(0)
            } else {
                0
            };
            collections.push(json!({ "name": collection.name, "type": kind, "count": count }));
        }
        collections.sort_by(|a, b| a["name"].as_str().cmp(&b["name"].as_str()));
        databases.push(json!({
            "system": SYSTEM_DATABASES.contains(&name.as_str()),
            "name": name,
            "size": size,
            "collections": collections,
        }));
    }
    let version = client
        .database("admin")
        .run_command(doc! { "buildInfo": 1 })
        .await
        .ok()
        .and_then(|info| info.get_str("version").ok().map(str::to_string));
    Ok(json!({ "databases": databases, "version": version }))
}

fn database(client: &Client, name: &str) -> Result<Database, String> {
    Ok(client.database(checked_database(name)?))
}

fn collection_handle(client: &Client, db: &str, name: &str) -> Result<Collection<Document>, String> {
    Ok(database(client, db)?.collection(checked_collection(name)?))
}

/// MongoDB's own naming rules for databases, checked up front for a clear error.
fn checked_database(name: &str) -> Result<&str, String> {
    if name.is_empty() || name.len() > 63 || name.chars().any(|c| "/\\. \"$\0".contains(c)) {
        return Err(format!("Invalid database name: {name:?}"));
    }
    Ok(name)
}

fn checked_collection(name: &str) -> Result<&str, String> {
    if name.is_empty() || name.len() > 255 || name.contains('$') || name.contains('\0') {
        return Err(format!("Invalid collection name: {name:?}"));
    }
    Ok(name)
}

fn bson(value: Value, what: &str) -> Result<Bson, String> {
    Bson::try_from(value).map_err(|e| format!("Invalid {what}: {e}"))
}

fn document(value: Value, what: &str) -> Result<Document, String> {
    match bson(value, what)? {
        Bson::Document(document) => Ok(document),
        _ => Err(format!("The {what} must be a JSON object.")),
    }
}

fn optional_document(value: Option<Value>, what: &str) -> Result<Document, String> {
    match value {
        None | Some(Value::Null) => Ok(Document::new()),
        Some(value) => document(value, what),
    }
}

fn non_empty(document: Document) -> Option<Document> {
    (!document.is_empty()).then_some(document)
}

/// The name MongoDB itself would pick: `email_1_age_-1`.
fn default_index_name(keys: &Document) -> String {
    keys.iter()
        .map(|(key, value)| {
            let direction = match value {
                Bson::Int32(n) => n.to_string(),
                Bson::Int64(n) => n.to_string(),
                Bson::Double(n) => n.to_string(),
                Bson::String(s) => s.clone(),
                other => other.to_string(),
            };
            format!("{key}_{direction}")
        })
        .collect::<Vec<_>>()
        .join("_")
}

fn to_json(documents: Vec<Document>) -> Vec<Value> {
    documents.into_iter().map(|document| Bson::Document(document).into_relaxed_extjson()).collect()
}

fn plural(count: usize) -> &'static str {
    if count == 1 { "" } else { "s" }
}

fn err(error: impl std::fmt::Display) -> String {
    error.to_string()
}

#[cfg(test)]
mod tests {
    use super::*;

    #[test]
    fn rejects_unsafe_names() {
        assert!(checked_database("shop").is_ok());
        assert!(checked_database("a.b").is_err());
        assert!(checked_database("a/b").is_err());
        assert!(checked_database("").is_err());
        assert!(checked_collection("users").is_ok());
        assert!(checked_collection("orders$x").is_err());
    }

    #[test]
    fn extended_json_round_trips_object_ids_and_dates() {
        let input = json!({ "_id": { "$oid": "65f0c0ffee0000000000abcd" }, "at": { "$date": "2026-01-02T03:04:05Z" }, "n": 5 });
        let parsed = document(input, "document").unwrap();
        assert!(matches!(parsed.get("_id"), Some(Bson::ObjectId(_))));
        assert!(matches!(parsed.get("at"), Some(Bson::DateTime(_))));
        let back = Bson::Document(parsed).into_relaxed_extjson();
        assert_eq!(back["_id"]["$oid"], "65f0c0ffee0000000000abcd");
        assert_eq!(back["n"], 5);
    }

    #[test]
    fn names_indexes_like_mongodb() {
        assert_eq!(default_index_name(&doc! { "email": 1, "age": -1 }), "email_1_age_-1");
        assert_eq!(default_index_name(&doc! { "body": "text" }), "body_text");
    }

    #[test]
    fn database_logins_stay_in_their_database() {
        let scoped = Connection {
            host: "127.0.0.1".into(),
            port: 27017,
            username: "shop_user".into(),
            password: "pw".into(),
            database: Some("shop".into()),
        };
        let op = |value: Value| serde_json::from_value::<Op>(value).unwrap();
        assert!(check_scope(&scoped, &op(json!({"action": "overview"}))).is_ok());
        assert!(check_scope(&scoped, &op(json!({"action": "find", "db": "shop", "collection": "c"}))).is_ok());
        assert!(check_scope(&scoped, &op(json!({"action": "find", "db": "admin", "collection": "c"}))).is_err());
        assert!(check_scope(&scoped, &op(json!({"action": "provision_user", "db": "shop", "user": "u", "password": "p"}))).is_err());
        let admin = Connection { database: None, ..scoped };
        assert!(check_scope(&admin, &op(json!({"action": "find", "db": "admin", "collection": "c"}))).is_ok());
    }

    #[test]
    fn rejects_non_object_documents() {
        assert!(document(json!([1, 2]), "document").is_err());
        assert!(optional_document(None, "filter").unwrap().is_empty());
    }
}

/// Runs against a real mongod: MONGO_TEST_PASSWORD=... cargo test -- --ignored mongo_admin
#[cfg(test)]
mod live_tests {
    use super::*;

    #[tokio::test]
    #[ignore]
    async fn full_round_trip_against_local_mongod() {
        let connection = Connection {
            host: "127.0.0.1".into(),
            port: 27017,
            username: std::env::var("MONGO_TEST_USER").unwrap_or_else(|_| "admin".into()),
            password: std::env::var("MONGO_TEST_PASSWORD").expect("MONGO_TEST_PASSWORD"),
            database: None,
        };
        let op = |value: Value| serde_json::from_value::<Op>(value).unwrap();
        let db = "dpanel_live_test";

        run(&connection, op(json!({"action": "drop_database", "db": db}))).await.unwrap();
        run(&connection, op(json!({"action": "create_collection", "db": db, "collection": "people"}))).await.unwrap();
        let inserted = run(&connection, op(json!({"action": "insert", "db": db, "collection": "people", "documents": [
            {"name": "Ada", "age": 36, "at": {"$date": "2026-01-02T03:04:05Z"}},
            {"name": "Linus", "age": 28}
        ]}))).await.unwrap();
        assert_eq!(inserted["inserted"], 2);

        let found = run(&connection, op(json!({"action": "find", "db": db, "collection": "people",
            "filter": {"age": {"$gt": 30}}, "sort": {"name": 1}}))).await.unwrap();
        assert_eq!(found["total"], 1);
        let ada = found["documents"][0].clone();
        assert!(ada["_id"]["$oid"].is_string());
        assert!(ada["at"]["$date"].is_string());

        let mut edited = ada.clone();
        edited["age"] = json!(37);
        run(&connection, op(json!({"action": "replace", "db": db, "collection": "people", "id": ada["_id"], "document": edited}))).await.unwrap();

        run(&connection, op(json!({"action": "create_index", "db": db, "collection": "people", "keys": {"name": 1}, "options": {"unique": true}}))).await.unwrap();
        let indexes = run(&connection, op(json!({"action": "indexes", "db": db, "collection": "people"}))).await.unwrap();
        assert_eq!(indexes["indexes"].as_array().unwrap().len(), 2);

        let grouped = run(&connection, op(json!({"action": "aggregate", "db": db, "collection": "people",
            "pipeline": [{"$group": {"_id": null, "total": {"$sum": "$age"}}}]}))).await.unwrap();
        assert_eq!(grouped["documents"][0]["total"], 65);

        run(&connection, op(json!({"action": "rename_collection", "db": db, "collection": "people", "to": "humans"}))).await.unwrap();
        let overview = run(&connection, op(json!({"action": "overview"}))).await.unwrap();
        let test_db = overview["databases"].as_array().unwrap().iter().find(|d| d["name"] == db).unwrap();
        assert_eq!(test_db["collections"][0]["name"], "humans");
        assert_eq!(test_db["collections"][0]["count"], 2);

        run(&connection, op(json!({"action": "delete", "db": db, "collection": "humans", "id": ada["_id"]}))).await.unwrap();
        let stats = run(&connection, op(json!({"action": "command", "db": db, "command": {"count": "humans"}}))).await.unwrap();
        assert_eq!(stats["result"]["n"], 1);

        assert!(run(&connection, op(json!({"action": "drop_database", "db": "admin"}))).await.is_err());
        run(&connection, op(json!({"action": "drop_database", "db": db}))).await.unwrap();
    }

    #[tokio::test]
    #[ignore]
    async fn database_login_round_trip_against_local_mongod() {
        let admin = Connection {
            host: "127.0.0.1".into(),
            port: 27017,
            username: std::env::var("MONGO_TEST_USER").unwrap_or_else(|_| "admin".into()),
            password: std::env::var("MONGO_TEST_PASSWORD").expect("MONGO_TEST_PASSWORD"),
            database: None,
        };
        let op = |value: Value| serde_json::from_value::<Op>(value).unwrap();
        let db = "dpanel_live_scope";
        let provision = json!({"action": "provision_user", "db": db, "user": "dpanel_live_user", "password": "first-pw"});

        run(&admin, op(provision.clone())).await.unwrap();
        // Running it again resets the password instead of failing.
        let mut reset = provision.clone();
        reset["password"] = json!("second-pw");
        run(&admin, op(reset)).await.unwrap();

        let user = Connection {
            username: "dpanel_live_user".into(),
            password: "second-pw".into(),
            database: Some(db.into()),
            ..admin.clone()
        };
        // An empty database still shows up for its own login.
        let overview = run(&user, op(json!({"action": "overview"}))).await.unwrap();
        assert_eq!(overview["databases"].as_array().unwrap().len(), 1);
        assert_eq!(overview["databases"][0]["name"], db);

        run(&user, op(json!({"action": "insert", "db": db, "collection": "notes", "documents": {"text": "hi"}}))).await.unwrap();
        assert!(run(&user, op(json!({"action": "find", "db": "admin", "collection": "system.users"}))).await.is_err());
        let old_password = Connection { password: "first-pw".into(), ..user.clone() };
        assert!(run(&old_password, op(json!({"action": "overview"}))).await.is_err());

        run(&admin, op(json!({"action": "command", "db": db, "command": {"dropUser": "dpanel_live_user"}}))).await.unwrap();
        run(&admin, op(json!({"action": "drop_database", "db": db}))).await.unwrap();
    }
}
