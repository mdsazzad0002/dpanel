import { ref } from 'vue';
import { buildDeleteSql, buildInsertSql, buildUpdateSql } from '../helpers/phpMyAdminSql.js';

export function usePhpMyAdminWriteState(readState, transport) {
    const dropConfirmOpen = ref(false);
    const confirmAction = ref('drop');
    const dropTarget = ref({ database: '', table: '' });
    const dropInProgress = ref(false);
    const renameInProgress = ref(false);
    const createInProgress = ref(false);
    const bulkTableMutationInProgress = ref(false);

    const patchTableRow = (original, draft, columns) => {
        const details = readState.tableDetails.value;
        const rows = Array.isArray(details?.rows) ? details.rows : [];
        if (rows.length === 0) return false;

        const primaryColumns = (columns || []).filter((column) => column.is_primary);
        const rowIndex = rows.findIndex((row) => {
            if (primaryColumns.length === 0) {
                return false;
            }

            return primaryColumns.every((column) => String(row?.[column.name] ?? '') === String(original?.[column.name] ?? ''));
        });

        if (rowIndex < 0) return false;

        const nextRows = rows.map((row, index) => {
            if (index !== rowIndex) return row;
            return {
                ...row,
                ...draft,
            };
        });

        readState.tableDetails.value = {
            ...details,
            rows: nextRows,
        };

        return true;
    };

    const openConfirm = (action, table) => {
        if (!readState.selectedDatabase.value || !table) return;

        dropTarget.value = {
            database: readState.selectedDatabase.value,
            table,
        };
        confirmAction.value = action;
        dropConfirmOpen.value = true;
    };

    const closeDropConfirm = () => {
        if (dropInProgress.value) return;

        dropConfirmOpen.value = false;
        confirmAction.value = 'drop';
        dropTarget.value = { database: '', table: '' };
    };

    const confirmTableMutation = async () => {
        if (!dropTarget.value.database || !dropTarget.value.table || dropInProgress.value) return;

        dropInProgress.value = true;

        try {
            const routeName = confirmAction.value === 'empty'
                ? 'phpmyadmin.table.empty'
                : 'phpmyadmin.table.destroy';

            const { response, data } = await transport.requestJson(routeName, {
                database: dropTarget.value.database,
                table: dropTarget.value.table,
            }, {
                method: confirmAction.value === 'empty' ? 'POST' : 'DELETE',
                headers: {
                    'X-CSRF-TOKEN': transport.csrfToken.value,
                    'X-Requested-With': 'XMLHttpRequest',
                },
            });

            if (!response.ok || !data?.ok) {
                throw new Error(data?.message || `Failed to ${confirmAction.value === 'empty' ? 'empty' : 'drop'} table.`);
            }

            transport.pushToast(data.message || 'Table dropped successfully.');
            dropConfirmOpen.value = false;
            readState.selectedTable.value = '';
            readState.tableDetails.value = null;
            readState.activeTableAction.value = 'structure';
            await readState.loadDatabase(dropTarget.value.database, {
                page: 1,
                perPage: readState.perPage.value,
                loadRows: false,
                selectDatabase: true,
            });
            readState.setDatabaseExpanded(dropTarget.value.database, true);
            confirmAction.value = 'drop';
            dropTarget.value = { database: '', table: '' };
        } catch (error) {
            transport.pushToast(error?.message || `Failed to ${confirmAction.value === 'empty' ? 'empty' : 'drop'} table.`);
        } finally {
            dropInProgress.value = false;
        }
    };

    const handleRowSave = async ({ original, draft, onSuccess, onError }) => {
        if (!readState.selectedDatabase.value || !readState.selectedTable.value) return;

        try {
            const sql = buildUpdateSql(
                readState.selectedDatabase.value,
                readState.selectedTable.value,
                original,
                draft,
                readState.tableDetails.value?.columns || [],
            );
            const data = await transport.executeSql(sql, readState.selectedDatabase.value);
            transport.pushToast(data.message || 'Row updated successfully.');
            patchTableRow(original, draft, readState.tableDetails.value?.columns || []);
            if (typeof onSuccess === 'function') {
                await onSuccess({ original, draft, data });
            }
        } catch (error) {
            transport.pushToast(error?.message || 'Row update failed.');
            if (typeof onError === 'function') {
                onError(error);
            }
        }
    };

    const handleRowDelete = async ({ row }) => {
        if (!readState.selectedDatabase.value || !readState.selectedTable.value) return;

        if (typeof window !== 'undefined' && !window.confirm(`Delete this row from ${readState.selectedDatabase.value}.${readState.selectedTable.value}?`)) {
            return;
        }

        try {
            const sql = buildDeleteSql(
                readState.selectedDatabase.value,
                readState.selectedTable.value,
                row,
                readState.tableDetails.value?.columns || [],
            );
            const data = await transport.executeSql(sql, readState.selectedDatabase.value);
            transport.pushToast(data.message || 'Row deleted successfully.');
            await readState.loadTable(readState.selectedDatabase.value, readState.selectedTable.value, {
                page: readState.selectedTablePage.value || 1,
                perPage: readState.perPage.value,
                action: 'browse',
            });
            readState.activeTableAction.value = 'browse';
        } catch (error) {
            transport.pushToast(error?.message || 'Row delete failed.');
        }
    };

    const handleBulkDelete = async ({ rows }) => {
        if (!readState.selectedDatabase.value || !readState.selectedTable.value || !Array.isArray(rows) || rows.length === 0) return;

        if (typeof window !== 'undefined' && !window.confirm(`Delete ${rows.length} selected row(s) from ${readState.selectedDatabase.value}.${readState.selectedTable.value}?`)) {
            return;
        }

        try {
            for (const row of rows) {
                const sql = buildDeleteSql(
                    readState.selectedDatabase.value,
                    readState.selectedTable.value,
                    row,
                    readState.tableDetails.value?.columns || [],
                );
                await transport.executeSql(sql, readState.selectedDatabase.value);
            }

            transport.pushToast(`${rows.length} row(s) deleted successfully.`);
            await readState.loadTable(readState.selectedDatabase.value, readState.selectedTable.value, {
                page: readState.selectedTablePage.value || 1,
                perPage: readState.perPage.value,
                action: 'browse',
            });
            readState.activeTableAction.value = 'browse';
        } catch (error) {
            transport.pushToast(error?.message || 'Bulk delete failed.');
        }
    };

    const handleBulkTableAction = async ({ action, tables } = {}) => {
        const database = readState.selectedDatabase.value;
        const selectedTables = Array.isArray(tables)
            ? tables
                .map((table) => String(table?.name || table || '').trim())
                .filter(Boolean)
            : [];

        if (!database || selectedTables.length === 0) return;

        if (action === 'browse' || action === 'structure') {
            const targetTable = selectedTables[0];
            readState.activeTableAction.value = action;
            await readState.loadTable(database, targetTable, {
                page: 1,
                perPage: readState.perPage.value,
                action,
            });
            readState.setDatabaseExpanded(database, true);
            if (selectedTables.length > 1) {
                transport.pushToast(`Opened ${targetTable}.`);
            }
            return;
        }

        if (action !== 'empty' && action !== 'drop') {
            return;
        }

        if (bulkTableMutationInProgress.value) {
            return;
        }

        const confirmLabel = action === 'empty' ? 'empty' : 'drop';
        if (typeof window !== 'undefined' && !window.confirm(`${confirmLabel === 'empty' ? 'Empty' : 'Drop'} ${selectedTables.length} selected table(s) from ${database}?`)) {
            return;
        }

        bulkTableMutationInProgress.value = true;

        try {
            const routeName = action === 'empty' ? 'phpmyadmin.table.empty' : 'phpmyadmin.table.destroy';
            const method = action === 'empty' ? 'POST' : 'DELETE';

            for (const table of selectedTables) {
                const { response, data } = await transport.requestJson(routeName, {
                    database,
                    table,
                }, {
                    method,
                    headers: {
                        'X-CSRF-TOKEN': transport.csrfToken.value,
                        'X-Requested-With': 'XMLHttpRequest',
                    },
                });

                if (!response.ok || !data?.ok) {
                    throw new Error(data?.message || `Failed to ${confirmLabel} table ${table}.`);
                }
            }

            transport.pushToast(`${selectedTables.length} table(s) ${action === 'empty' ? 'emptied' : 'dropped'} successfully.`, 'success');
            readState.selectedTable.value = '';
            readState.tableDetails.value = null;
            readState.activeTableAction.value = 'structure';
            await readState.loadDatabase(database, {
                page: 1,
                perPage: readState.perPage.value,
                loadRows: false,
                selectDatabase: true,
            });
            readState.setDatabaseExpanded(database, true);
        } catch (error) {
            transport.pushToast(error?.message || `Failed to ${confirmLabel} selected tables.`, 'error');
        } finally {
            bulkTableMutationInProgress.value = false;
        }
    };

    const handleInsertSubmit = async ({ rows }) => {
        if (!readState.selectedDatabase.value || !readState.selectedTable.value) return;

        try {
            const sql = buildInsertSql(readState.selectedDatabase.value, readState.selectedTable.value, rows);
            const data = await transport.executeSql(sql, readState.selectedDatabase.value);
            transport.pushToast(data.message || 'Row inserted successfully.');
            readState.activeTableAction.value = 'browse';
            await readState.loadTable(readState.selectedDatabase.value, readState.selectedTable.value, {
                page: readState.selectedTablePage.value || 1,
                perPage: readState.perPage.value,
                action: 'browse',
            });
        } catch (error) {
            transport.pushToast(error?.message || 'Insert failed.');
        }
    };

    const handleTableRename = async ({ newTable } = {}) => {
        const database = readState.selectedDatabase.value;
        const table = readState.selectedTable.value;
        const nextTable = String(newTable || '').trim();

        if (!database || !table) return;
        if (!nextTable) {
            transport.pushToast('Provide a new table name.', 'error');
            return;
        }

        renameInProgress.value = true;

        try {
            const { response, data } = await transport.requestJson('phpmyadmin.table.rename', {
                database,
                table,
            }, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-CSRF-TOKEN': transport.csrfToken.value,
                    'X-Requested-With': 'XMLHttpRequest',
                },
                body: JSON.stringify({ new_table: nextTable }),
            });

            if (!response.ok || !data?.ok) {
                throw new Error(data?.message || 'Table rename failed.');
            }

            transport.pushToast(data.message || `Table renamed to ${nextTable}.`, 'success');
            readState.selectedTable.value = nextTable;
            readState.activeTableAction.value = 'browse';
            await readState.loadDatabase(database, {
                page: 1,
                perPage: readState.perPage.value,
                loadRows: true,
                table: nextTable,
                selectDatabase: true,
                action: 'browse',
            });
            readState.setDatabaseExpanded(database, true);
        } catch (error) {
            transport.pushToast(error?.message || 'Table rename failed.', 'error');
        } finally {
            renameInProgress.value = false;
        }
    };

    const saveSchema = async ({ mode, tableName, columns } = {}) => {
        const database = readState.selectedDatabase.value;
        const nextTable = String(tableName || '').trim();
        const schemaColumns = Array.isArray(columns) ? columns : [];

        if (!database) {
            transport.pushToast('Select a database before creating a table.', 'error');
            return;
        }

        if (!nextTable) {
            transport.pushToast('Provide a table name.', 'error');
            return;
        }

        createInProgress.value = true;

        try {
            const routeName = mode === 'edit'
                ? 'phpmyadmin.table.structure.update'
                : 'phpmyadmin.table.create';

            const routeParams = mode === 'edit'
                ? { database, table: readState.selectedTable.value || nextTable }
                : { database };

            const payload = mode === 'edit'
                ? { columns: schemaColumns }
                : { table_name: nextTable, columns: schemaColumns };

            const { response, data } = await transport.requestJson(routeName, routeParams, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-CSRF-TOKEN': transport.csrfToken.value,
                    'X-Requested-With': 'XMLHttpRequest',
                },
                body: JSON.stringify(payload),
            });

            if (!response.ok || !data?.ok) {
                throw new Error(data?.message || (mode === 'edit' ? 'Structure update failed.' : 'Table creation failed.'));
            }

            transport.pushToast(
                data.message || (mode === 'edit' ? 'Structure updated successfully.' : `Table ${nextTable} created successfully.`),
                'success',
            );

            readState.selectedTable.value = mode === 'edit' ? (data.table || readState.selectedTable.value || nextTable) : nextTable;
            readState.activeTableAction.value = 'structure';
            await readState.loadDatabase(database, {
                page: 1,
                perPage: readState.perPage.value,
                loadRows: true,
                table: readState.selectedTable.value || nextTable,
                selectDatabase: true,
                action: 'structure',
            });
            readState.setDatabaseExpanded(database, true);
            if (readState.selectedTable.value) {
                await readState.loadTable(database, readState.selectedTable.value, {
                    page: 1,
                    perPage: readState.perPage.value,
                    action: 'structure',
                });
            }
        } catch (error) {
            transport.pushToast(error?.message || (mode === 'edit' ? 'Structure update failed.' : 'Table creation failed.'), 'error');
        } finally {
            createInProgress.value = false;
        }
    };

    const handleTableCreate = async ({ tableName, columns } = {}) => {
        await saveSchema({ mode: 'create', tableName, columns });
    };

    const handleTableStructureSave = async ({ tableName, columns } = {}) => {
        await saveSchema({
            mode: 'edit',
            tableName: tableName || readState.selectedTable.value,
            columns,
        });
    };

    return {
        dropConfirmOpen,
        confirmAction,
        dropTarget,
        dropInProgress,
        renameInProgress,
        createInProgress,
        openConfirm,
        closeDropConfirm,
        confirmTableMutation,
        handleRowSave,
        handleRowDelete,
        handleBulkDelete,
        handleBulkTableAction,
        handleInsertSubmit,
        handleTableRename,
        handleTableCreate,
        handleTableStructureSave,
    };
}
