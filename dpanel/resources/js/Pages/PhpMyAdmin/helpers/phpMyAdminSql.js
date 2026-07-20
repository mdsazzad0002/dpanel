export const quoteIdentifier = (value) => `\`${String(value ?? '').replaceAll('`', '``')}\``;

export const escapeSqlValue = (value) => {
    if (value === null || value === undefined) {
        return 'NULL';
    }

    const text = String(value);
    if (text.trim().toUpperCase() === 'NULL') {
        return 'NULL';
    }

    return `'${text.replaceAll("'", "''")}'`;
};

export const formatBytes = (bytes) => {
    const value = Number(bytes || 0);
    if (!Number.isFinite(value) || value <= 0) return '0 B';

    const units = ['B', 'KB', 'MB', 'GB', 'TB'];
    const index = Math.min(units.length - 1, Math.floor(Math.log(value) / Math.log(1024)));
    return `${(value / (1024 ** index)).toFixed(index === 0 ? 0 : 2)} ${units[index]}`;
};

export const buildInsertSql = (database, table, rows) => {
    const normalizedRows = Array.isArray(rows) ? rows : [];
    const entries = normalizedRows
        .map((row) => {
            const name = String(row?.name || '').trim();
            if (!name) return null;

            if (row.useNull) {
                return { name, value: 'NULL', raw: true };
            }

            const rawValue = String(row?.value ?? '').trim();
            if (rawValue === '' && !row.function) {
                return null;
            }

            if (row.function === 'NOW' || row.function === 'CURRENT_TIMESTAMP') {
                return { name, value: row.function === 'NOW' ? 'NOW()' : 'CURRENT_TIMESTAMP', raw: true };
            }

            let transformed = String(row?.value ?? '');
            if (row.function === 'LOWER') transformed = transformed.toLowerCase();
            if (row.function === 'UPPER') transformed = transformed.toUpperCase();

            return { name, value: transformed, raw: false };
        })
        .filter(Boolean);

    if (entries.length === 0) {
        throw new Error('Please enter at least one value before inserting.');
    }

    const columns = entries.map((entry) => quoteIdentifier(entry.name)).join(', ');
    const payload = entries.map((entry) => (entry.raw ? entry.value : escapeSqlValue(entry.value))).join(', ');

    return `INSERT INTO ${quoteIdentifier(database)}.${quoteIdentifier(table)} (${columns}) VALUES (${payload});`;
};

export const buildTableQueryLabel = (database, table, page, limit, action = 'browse', sortColumn = '', sortDirection = 'asc') => {
    if (!database || !table) return '';

    if (action === 'structure') {
        return `DESCRIBE ${quoteIdentifier(database)}.${quoteIdentifier(table)};`;
    }

    const safeLimit = Math.max(1, Number(limit || 25));
    const safePage = Math.max(1, Number(page || 1));
    const offset = (safePage - 1) * safeLimit;
    const orderBy = sortColumn ? ` ORDER BY ${quoteIdentifier(sortColumn)} ${String(sortDirection).toUpperCase() === 'DESC' ? 'DESC' : 'ASC'}` : '';

    return `SELECT * FROM ${quoteIdentifier(database)}.${quoteIdentifier(table)}${orderBy} LIMIT ${safeLimit} OFFSET ${offset};`;
};

export const buildRowCondition = (row, columns) => {
    const primaryColumns = (columns || []).filter((column) => column.is_primary);
    if (primaryColumns.length === 0) {
        throw new Error('This table needs a primary key for row actions.');
    }

    return primaryColumns.map((column) => {
        const value = row?.[column.name];
        if (value === null || value === undefined) {
            return `${quoteIdentifier(column.name)} IS NULL`;
        }

        return `${quoteIdentifier(column.name)} = ${escapeSqlValue(value)}`;
    }).join(' AND ');
};

export const buildUpdateSql = (database, table, original, draft, columns) => {
    const editableColumns = (columns || []).filter((column) => !String(column.extra || '').toLowerCase().includes('generated'));
    const changes = editableColumns
        .map((column) => {
            const nextValue = draft?.[column.name];
            const currentValue = original?.[column.name];
            if (String(nextValue ?? '') === String(currentValue ?? '')) {
                return null;
            }
            return `${quoteIdentifier(column.name)} = ${escapeSqlValue(nextValue)}`;
        })
        .filter(Boolean);

    if (changes.length === 0) {
        throw new Error('No changes to save.');
    }

    return `UPDATE ${quoteIdentifier(database)}.${quoteIdentifier(table)} SET ${changes.join(', ')} WHERE ${buildRowCondition(original, columns)} LIMIT 1;`;
};

export const buildDeleteSql = (database, table, row, columns) => (
    `DELETE FROM ${quoteIdentifier(database)}.${quoteIdentifier(table)} WHERE ${buildRowCondition(row, columns)} LIMIT 1;`
);
