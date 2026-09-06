# PHDB API

Source: `library/PHDB.php`  
SHA-256: `7196e6feb5fc0128a1b74879c37cf58df261a1d54952b1e9a797ec01690f64c5`

Classes: `PHDB`, `PHDB_Result`, `PHDB_Driver`, `PHDB_SqliteDriver`, `PHDB_PgsqlDriver`

## Public methods

### `static error()`

Retrieve the last error message encountered.

### `static id()`

Get the ID generated from the last INSERT query.

### `static affected()`

Get the number of affected rows in the last query.

### `static checker(): array`

Performs a read-only database availability check without creating or

### `static connect()`

Connect to the database.

### `static disconnect()`

Disconnect from the database.

### `static query(string $query, array $params = [], bool $single = false)`

The Ultimate Auto-Healing, Bulletproof Query Executor.

### `static fast(string $query, array $params = [], string|array $columns = '*'): \Generator`

Stream a database result one row at a time without buffering the full

### `static first(string $query, array $params = []): ?array`

Execute a SELECT and return its first row, or null when no row matches.

### `static scalar(string $query, array $params = []): mixed`

Execute a SELECT and return the first value from its first row.

### `static save(string $table, array $data, mixed $uniqueKeys = null)`

Smart Save: Insert, Update, or Skip.

### `static insert(string $table, array $data, bool $overwrite = false)`

Insert a record into the database.

### `static batchInsert(string $table, array $data, bool $overwrite = false)`

Insert multiple records in a single query with optional overwrite

### `static update(string $table, array $data, array $where = [])`

Update records in the database based on specified conditions.

### `static delete(string $table, array $where = [], bool $allow_all = false)`

Delete records from the database based on specified conditions.

### `static select(string $table, string $columns = '*', array $where = [], ?int $limit = null, ?int $offset = null, ?string $orderBy = null, ?string $groupBy = null, ?array $joins = null, bool $distinct = false)`

Select records from the database based on specified conditions.

### `static find(string $table, $id, string $columns = '*')`

Find a record by its primary key (ID).

### `static specificSelect(string $query, array $params = [])`

Perform a specific selection from the database.

### `static getValue(string $table, string $column, array $where = [])`

Get a single value from the database.

### `static getSpecificValue(string $query, array $params = [])`

Get a specific value from the database.

### `static addDB(string $dbname, string $collation = 'utf8mb4_unicode_ci')`

Create a database manually (Utility function).

### `static createTable(string $table_name, array $columns, mixed $sync = true)`

Create or fully synchronize a table from one schema definition.

### `static dropTable(string $table_name)`

Drop a table from the database.

### `static alterTable(string $table_name, array $changes)`

Alter a table in the database.

### `static truncateTable(string $table_name)`

Truncate a table in the database.

### `static findBy(string $table, string $columns = '*', array $conditions = [], ?int $limit = null, ?int $offset = null)`

Find records in the database based on specific conditions.

### `static search(string $table, string $columns = '*', array|string $conditions = [], ?int $limit = null, ?int $offset = null, ?string $orderBy = null, ?string $groupBy = null, ?array $joins = null)`

Search records in the database based on specified conditions.

### `static columns(string $table, string|array|null $filter = null, string|array|null $skip = null)`

Get the available columns from a specified table in the database.

### `static deleteBy(string $table, array $conditions)`

Delete records from the database based on specific conditions.

### `static paginate(string $table, string $columns = '*', array $where = [], int $page = 1, int $per_page = 10)`

Paginate results with total count information

### `static sum(string $table, string $column, array $where = [])`

Get sum of a column safely.

### `static avg(string $table, string $column, array $where = [])`

Get average of a column safely.

### `static max(string $table, string $column, array $where = [])`

Get maximum value of a column safely.

### `static min(string $table, string $column, array $where = [])`

Get minimum value of a column safely.

### `static count(string $table, string|array|null $column = null, array|null $conditions = null): int`

Count records safely.

### `static exists(string $table, array $where = [])`

Check if a record exists in the database.

### `static api(string $table, string|array $columns = '*', array $where = [], array $options = [], bool $return = false)`

Serving JSON API responses instantly with Smart Pagination & Formatting.

### `static transaction(callable $callback)`

Execute operations within a database transaction.

### `static clean(string $table, array $options = [])`

Clean database records with various options

### `static array_get(string $table, string $column, array $where, $key = null, $default = null)`

Gets a value from a column containing array-like data.

### `static array_set(string $table, string $column, array $where, $key = null, $value = null, bool $force = false)`

Sets a value in a column containing array-like data.

### `static close()`

Close the database connection.

### `__construct(public readonly bool $isSelect, public readonly array $rows = [], public readonly int $affected = 0, public readonly int|string|null $insertId = null,)`

See executable source and call sites for the behavioral contract.

### `name(): string`

See executable source and call sites for the behavioral contract.

### `isAvailable(): bool`

See executable source and call sites for the behavioral contract.

### `connect(array $config): void`

See executable source and call sites for the behavioral contract.

### `isConnected(): bool`

See executable source and call sites for the behavioral contract.

### `disconnect(): void`

See executable source and call sites for the behavioral contract.

### `run(string $sql, array $params = []): PHDB_Result`

See executable source and call sites for the behavioral contract.

### `stream(string $sql, array $params = []): \Generator`

See executable source and call sites for the behavioral contract.

### `quoteIdentifier(string $name): string`

See executable source and call sites for the behavioral contract.

### `tableExists(string $table): bool`

See executable source and call sites for the behavioral contract.

### `columns(string $table): array`

See executable source and call sites for the behavioral contract.

### `primaryKey(string $table): string`

See executable source and call sites for the behavioral contract.

### `uniqueConstraints(string $table): array`

See executable source and call sites for the behavioral contract.

### `normalizeColumn(string $mysqlDefinition): string`

See executable source and call sites for the behavioral contract.

### `createTableSql(string $table, array $definitions): string`

See executable source and call sites for the behavioral contract.

### `begin(): void`

See executable source and call sites for the behavioral contract.

### `commit(): void`

See executable source and call sites for the behavioral contract.

### `rollback(): void`

See executable source and call sites for the behavioral contract.

### `probe(array $config): array`

See executable source and call sites for the behavioral contract.

### `name(): string`

See executable source and call sites for the behavioral contract.

### `isAvailable(): bool`

See executable source and call sites for the behavioral contract.

### `connect(array $config): void`

See executable source and call sites for the behavioral contract.

### `isConnected(): bool`

See executable source and call sites for the behavioral contract.

### `disconnect(): void`

See executable source and call sites for the behavioral contract.

### `run(string $sql, array $params = []): PHDB_Result`

See executable source and call sites for the behavioral contract.

### `stream(string $sql, array $params = []): \Generator`

See executable source and call sites for the behavioral contract.

### `quoteIdentifier(string $name): string`

See executable source and call sites for the behavioral contract.

### `tableExists(string $table): bool`

See executable source and call sites for the behavioral contract.

### `columns(string $table): array`

See executable source and call sites for the behavioral contract.

### `primaryKey(string $table): string`

See executable source and call sites for the behavioral contract.

### `uniqueConstraints(string $table): array`

See executable source and call sites for the behavioral contract.

### `normalizeColumn(string $mysqlDefinition): string`

See executable source and call sites for the behavioral contract.

### `createTableSql(string $table, array $definitions): string`

See executable source and call sites for the behavioral contract.

### `begin(): void`

See executable source and call sites for the behavioral contract.

### `commit(): void`

See executable source and call sites for the behavioral contract.

### `rollback(): void`

See executable source and call sites for the behavioral contract.

### `probe(array $config): array`

See executable source and call sites for the behavioral contract.

### `name(): string`

See executable source and call sites for the behavioral contract.

### `isAvailable(): bool`

See executable source and call sites for the behavioral contract.

### `connect(array $config): void`

See executable source and call sites for the behavioral contract.

### `isConnected(): bool`

See executable source and call sites for the behavioral contract.

### `disconnect(): void`

See executable source and call sites for the behavioral contract.

### `run(string $sql, array $params = []): PHDB_Result`

See executable source and call sites for the behavioral contract.

### `stream(string $sql, array $params = []): \Generator`

See executable source and call sites for the behavioral contract.

### `quoteIdentifier(string $name): string`

See executable source and call sites for the behavioral contract.

### `tableExists(string $table): bool`

See executable source and call sites for the behavioral contract.

### `columns(string $table): array`

See executable source and call sites for the behavioral contract.

### `primaryKey(string $table): string`

See executable source and call sites for the behavioral contract.

### `uniqueConstraints(string $table): array`

See executable source and call sites for the behavioral contract.

### `normalizeColumn(string $mysqlDefinition): string`

See executable source and call sites for the behavioral contract.

### `createTableSql(string $table, array $definitions): string`

See executable source and call sites for the behavioral contract.

### `begin(): void`

See executable source and call sites for the behavioral contract.

### `commit(): void`

See executable source and call sites for the behavioral contract.

### `rollback(): void`

See executable source and call sites for the behavioral contract.

### `probe(array $config): array`

See executable source and call sites for the behavioral contract.


Generated by `mystack docs:build`; do not hand-edit.
