<?php

declare(strict_types=1);

namespace Maatify\PhpPersistence\Ordering\Repository;

use Maatify\PhpPersistence\Ordering\Config\ScopedOrderingConfig;
use Maatify\PhpPersistence\Ordering\Config\UniqueOrderingConfig;

/** @internal Checks the visible B1 schema and the Host's deployment receipt. */
final readonly class UniqueOrderingSchema
{
    public function __construct(private UniqueOrderingSql $sql, private UniqueOrderingConfig $config) {}

    /** @return array{id: int, position: int, anchor: int} Positive integer maxima for the selected profile. */
    public function verify(): array
    {
        $receipt = $this->config->schemaAttestation;
        $this->checkReceipt($receipt);

        $session = $this->sql->rows('SELECT VERSION() AS server_version, @@SESSION.unique_checks AS unique_checks, @@SESSION.foreign_key_checks AS foreign_key_checks');
        if (count($session) !== 1 || ($session[0]['server_version'] ?? null) !== $receipt['serverVersion']
            || !in_array($session[0]['unique_checks'] ?? null, [1, '1'], true)
            || !in_array($session[0]['foreign_key_checks'] ?? null, [1, '1'], true)
        ) {
            self::unverified();
        }

        $items = $this->config->ordering;
        $anchor = $this->config->anchor;
        $beforeItems = $this->definition($items);
        $beforeAnchor = $this->definition($anchor);
        // Executing both reads pins metadata in the active Host transaction.
        $this->sql->rows('SELECT 1 FROM ' . $anchor->quotedTable() . ' WHERE 1 = 0');
        $this->sql->rows('SELECT 1 FROM ' . $items->quotedTable() . ' WHERE 1 = 0');
        if ($beforeItems !== $this->definition($items) || $beforeAnchor !== $this->definition($anchor)
            || $beforeItems['fingerprint'] !== $receipt['itemsFingerprint'] || $beforeAnchor['fingerprint'] !== $receipt['anchorFingerprint']
        ) {
            self::unverified();
        }

        $orderKey = $items->scopeColumn === null ? [$items->orderColumn] : [$items->scopeColumn, $items->orderColumn];
        $types = $this->tableTypes($items, $orderKey);
        $anchorTypes = $this->tableTypes($anchor);
        $idLimit = self::integerLimit($types[$items->idColumn] ?? null);
        $positionLimit = self::integerLimit($types[$items->orderColumn] ?? null);
        $anchorLimit = self::integerLimit($anchorTypes[$anchor->idColumn] ?? null);
        if ($items->scopeColumn !== null && self::integerLimit($types[$items->scopeColumn] ?? null) !== $anchorLimit
            || $this->config->maxPosition > $positionLimit || ($this->config->globalAnchorId ?? 0) > $anchorLimit
        ) {
            self::ineligible('B1 ordering or scope bounds do not match the column types.');
        }

        $this->checkForeignKeys($items, $beforeItems['foreignKeys']);
        $this->checkForeignKeys($anchor, $beforeAnchor['foreignKeys']);

        return ['id' => $idLimit, 'position' => $positionLimit, 'anchor' => $anchorLimit];
    }

    /**
     * @param array<string, mixed> $receipt
     * @phpstan-assert array{formatVersion: 1, schemaRevision: non-empty-string, serverVersion: non-empty-string, itemsTable: string, anchorTable: string, itemsFingerprint: string, anchorFingerprint: string, dependenciesReviewed: true, writersReviewed: true, anchorProvisioned: true} $receipt
     */
    private function checkReceipt(array $receipt): void
    {
        if (($receipt['formatVersion'] ?? null) !== 1 || $this->config->activeSchemaRevision === ''
            || ($receipt['schemaRevision'] ?? null) !== $this->config->activeSchemaRevision
            || !is_string($receipt['serverVersion'] ?? null) || $receipt['serverVersion'] === ''
            || ($receipt['itemsTable'] ?? null) !== $this->config->ordering->table
            || ($receipt['anchorTable'] ?? null) !== $this->config->anchor->table
            || ($receipt['dependenciesReviewed'] ?? null) !== true || ($receipt['writersReviewed'] ?? null) !== true
            || ($receipt['anchorProvisioned'] ?? null) !== true
        ) {
            self::unverified();
        }
        foreach (['itemsFingerprint', 'anchorFingerprint'] as $field) {
            if (!is_string($receipt[$field] ?? null) || preg_match('/\A[a-f0-9]{64}\z/', $receipt[$field]) !== 1) {
                self::unverified();
            }
        }
    }

    /** @return array{fingerprint: string, foreignKeys: int} */
    private function definition(ScopedOrderingConfig $table): array
    {
        $rows = $this->sql->rows('SHOW CREATE TABLE ' . $table->quotedTable());
        $definition = $rows[0]['Create Table'] ?? null;
        if (count($rows) !== 1 || !is_string($definition)) {
            self::ineligible('B1 requires a permanent base table with a visible definition.');
        }
        $name = self::coordinates($table)['table'];
        if (!str_starts_with($definition, 'CREATE TABLE ' . chr(96) . $name . chr(96) . " (\n")
            || preg_match('/^\) ENGINE=InnoDB(?: |$)/m', $definition) !== 1
            || preg_match('/^\s*(?:\/\*!(?:[0-9]+ )?)?PARTITION BY\b/mi', $definition) === 1
        ) {
            self::ineligible('B1 requires a permanent non-partitioned InnoDB table.');
        }

        // The table-level counter changes on insert and is not part of the structure.
        $structural = preg_replace('/^(\) ENGINE=InnoDB) AUTO_INCREMENT=[0-9]+(?= |$)/m', '$1', $definition);

        // SHOW CREATE can expose constraints that the runtime account cannot
        // see in INFORMATION_SCHEMA. Ignore identifiers and quoted literals.
        $pattern = <<<'REGEX'
~(?:`(?:``|[^`])*`|'(?:\x5c.|''|[^'\x5c])*'|"(?:\x5c.|""|[^"\x5c])*")(*SKIP)(*F)|\b(CHECK|FOREIGN\s+KEY)\s*\(~i
REGEX;
        if (preg_match_all($pattern, $definition, $constraints) === false) {
            self::unverified();
        }
        $foreignKeys = 0;
        foreach ($constraints[1] as $constraint) {
            if (strcasecmp($constraint, 'CHECK') === 0) {
                self::ineligible('B1 does not support CHECK constraints.');
            }
            $foreignKeys++;
        }

        return ['fingerprint' => hash('sha256', $structural ?? $definition), 'foreignKeys' => $foreignKeys];
    }

    /**
     * @param list<string>|null $orderKey
     * @return array<string, string>
     */
    private function tableTypes(ScopedOrderingConfig $table, ?array $orderKey = null): array
    {
        $coordinates = self::coordinates($table);
        $where = ' WHERE CAST(TABLE_SCHEMA AS BINARY) = :schema AND CAST(TABLE_NAME AS BINARY) = :table';
        $metadata = $this->sql->rows('SELECT TABLE_TYPE, ENGINE FROM INFORMATION_SCHEMA.TABLES' . $where, $coordinates);
        if (count($metadata) !== 1 || ($metadata[0]['TABLE_TYPE'] ?? null) !== 'BASE TABLE'
            || ($metadata[0]['ENGINE'] ?? null) !== 'InnoDB'
        ) {
            self::unverified();
        }

        $types = [];
        $columns = $this->sql->rows('SELECT COLUMN_NAME, COLUMN_TYPE, IS_NULLABLE, EXTRA FROM INFORMATION_SCHEMA.COLUMNS' . $where, $coordinates);
        foreach ($columns as $column) {
            $name = $column['COLUMN_NAME'] ?? null;
            $type = $column['COLUMN_TYPE'] ?? null;
            $extra = $column['EXTRA'] ?? null;
            if (!is_string($name) || !is_string($type) || !is_string($extra)) {
                self::unverified();
            }
            if (!in_array($extra, ['', 'auto_increment', 'DEFAULT_GENERATED'], true)) {
                self::ineligible('B1 rejects generated columns and automatic update effects.');
            }
            if (($column['IS_NULLABLE'] ?? null) === 'NO') {
                $types[$name] = $type;
            }
        }
        $indexes = $this->sql->rows('SELECT INDEX_NAME, COLUMN_NAME, NON_UNIQUE, INDEX_TYPE, SUB_PART FROM INFORMATION_SCHEMA.STATISTICS'
            . $where . ' ORDER BY INDEX_NAME, SEQ_IN_INDEX', $coordinates);
        $unique = [];
        foreach ($indexes as $index) {
            $name = $index['INDEX_NAME'] ?? null;
            $column = $index['COLUMN_NAME'] ?? null;
            if (!is_string($name) || !is_string($column) || ($index['INDEX_TYPE'] ?? null) !== 'BTREE'
                || ($index['SUB_PART'] ?? null) !== null
            ) {
                self::ineligible('B1 requires ordinary full-column BTREE indexes.');
            }
            if (in_array($index['NON_UNIQUE'] ?? null, [0, '0'], true)) {
                $unique[$name][] = $column;
            }
        }
        if (($unique['PRIMARY'] ?? null) !== [$table->idColumn]) {
            self::ineligible('B1 requires a single-column primary key.');
        }
        unset($unique['PRIMARY']);
        if (array_values($unique) !== ($orderKey === null ? [] : [$orderKey])) {
            self::ineligible('Unexpected UNIQUE keys in B1 ordering profile.');
        }

        $constraints = $this->sql->rows('SELECT CONSTRAINT_TYPE FROM INFORMATION_SCHEMA.TABLE_CONSTRAINTS' . $where, $coordinates);
        foreach ($constraints as $constraint) {
            if (!in_array($constraint['CONSTRAINT_TYPE'] ?? null, ['PRIMARY KEY', 'UNIQUE', 'FOREIGN KEY'], true)) {
                self::ineligible('B1 does not support CHECK or other additional constraints.');
            }
        }
        $triggers = $this->sql->rows('SELECT TRIGGER_NAME FROM INFORMATION_SCHEMA.TRIGGERS'
            . ' WHERE CAST(EVENT_OBJECT_SCHEMA AS BINARY) = :schema AND CAST(EVENT_OBJECT_TABLE AS BINARY) = :table', $coordinates);
        if ($triggers !== []) {
            self::ineligible('B1 does not support triggers.');
        }

        return $types;
    }

    private function checkForeignKeys(ScopedOrderingConfig $table, int $declaredCount): void
    {
        $key = self::coordinates($table);
        $items = self::coordinates($this->config->ordering);
        $anchor = self::coordinates($this->config->anchor);
        $sql = 'SELECT k.TABLE_SCHEMA, k.TABLE_NAME, k.COLUMN_NAME, k.REFERENCED_TABLE_SCHEMA,'
            . ' k.REFERENCED_TABLE_NAME, k.REFERENCED_COLUMN_NAME, r.UPDATE_RULE, r.DELETE_RULE'
            . ' FROM INFORMATION_SCHEMA.KEY_COLUMN_USAGE k LEFT JOIN INFORMATION_SCHEMA.REFERENTIAL_CONSTRAINTS r'
            . ' ON r.CONSTRAINT_SCHEMA = k.CONSTRAINT_SCHEMA AND r.CONSTRAINT_NAME = k.CONSTRAINT_NAME AND r.TABLE_NAME = k.TABLE_NAME'
            . ' WHERE k.REFERENCED_TABLE_NAME IS NOT NULL AND ((CAST(k.TABLE_SCHEMA AS BINARY) = :schema AND CAST(k.TABLE_NAME AS BINARY) = :table)'
            . ' OR (CAST(k.REFERENCED_TABLE_SCHEMA AS BINARY) = :target_schema AND CAST(k.REFERENCED_TABLE_NAME AS BINARY) = :target_table))';
        $rows = $this->sql->rows($sql, $key + ['target_schema' => $key['schema'], 'target_table' => $key['table']]);
        $visibleOutgoing = 0;
        foreach ($rows as $row) {
            if (($row['TABLE_SCHEMA'] ?? null) === $key['schema'] && ($row['TABLE_NAME'] ?? null) === $key['table']) {
                $visibleOutgoing++;
            }
        }
        if ($visibleOutgoing < $declaredCount) {
            self::unverified();
        }
        if ($rows === []) {
            return; // The trusted receipt covers otherwise invisible dependencies.
        }
        $key = $rows[0];
        if (count($rows) !== 1 || $this->config->ordering->scopeColumn === null
            || ($key['TABLE_SCHEMA'] ?? null) !== $items['schema'] || ($key['TABLE_NAME'] ?? null) !== $items['table']
            || ($key['COLUMN_NAME'] ?? null) !== $this->config->ordering->scopeColumn
            || ($key['REFERENCED_TABLE_SCHEMA'] ?? null) !== $anchor['schema']
            || ($key['REFERENCED_TABLE_NAME'] ?? null) !== $anchor['table']
            || ($key['REFERENCED_COLUMN_NAME'] ?? null) !== $this->config->anchor->idColumn
            || $items['schema'] !== $anchor['schema']
            || !in_array($key['UPDATE_RULE'] ?? null, ['RESTRICT', 'NO ACTION'], true)
            || !in_array($key['DELETE_RULE'] ?? null, ['RESTRICT', 'NO ACTION'], true)
        ) {
            self::ineligible('B1 permits only the restricted, same-schema scope-to-anchor foreign key.');
        }
    }

    /** @return array{schema: string, table: string} */
    private static function coordinates(ScopedOrderingConfig $table): array
    {
        [$schema, $name] = explode('.', $table->table, 2) + ['', ''];

        return ['schema' => $schema, 'table' => $name];
    }

    private static function integerLimit(?string $type): int
    {
        if ($type === null || preg_match('/\Aint(?:\([0-9]+\))?( unsigned)?\z/i', $type, $parts) !== 1) {
            self::ineligible('B1 requires NOT NULL INT identifiers and positions.');
        }
        if (isset($parts[1])) {
            if (PHP_INT_SIZE < 8) {
                self::ineligible('Unsigned INT requires a 64-bit PHP integer.');
            }

            return 4294967295;
        }

        return 2147483647;
    }

    private static function ineligible(string $message): never
    {
        UniqueOrderingSql::reject('INELIGIBLE_SCHEMA', $message);
    }

    private static function unverified(): never
    {
        UniqueOrderingSql::reject('SCHEMA_UNVERIFIED', 'The B1 deployment receipt or visible schema could not be confirmed.');
    }
}
