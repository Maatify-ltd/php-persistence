<?php

declare(strict_types=1);

namespace Maatify\PhpPersistence\Pagination\Repository;

use Maatify\PhpPersistence\Pagination\Criteria\KeysetQueryDescriptor;
use Maatify\PhpPersistence\Pagination\DTO\KeysetPageResultDTO;
use Maatify\PhpPersistence\Pagination\Exception\InvalidKeysetCursorException;
use Maatify\PhpPersistence\Pagination\Exception\InvalidPaginationConfigurationException;
use Maatify\PhpPersistence\Pagination\Exception\InvalidPaginationQueryException;
use Maatify\PhpPersistence\Pagination\Exception\PaginationExecutionException;
use PDO;
use PDOStatement;
use SensitiveParameter;
use Throwable;

/**
 * Optional forward-only pagination on a caller-owned pdo_mysql connection.
 * The operation runs one bounded read; the Host owns the session and its transaction.
 */
final readonly class PdoKeysetPaginator
{
    private const RAW_ID = '__ks_id';
    private const SERIALIZATION_ERROR = 'Keyset paginator does not support serialization or restoration.';

    private KeysetCursor $cursorProtocol;

    /**
     * @param list<array{id: string, secret: string}> $keys
     * @throws InvalidPaginationConfigurationException For an invalid keyring or limit policy.
     */
    public function __construct(
        string $activeKeyId,
        #[SensitiveParameter]
        array $keys,
        private int $defaultLimit = 20,
        private int $maxLimit = 200,
    ) {
        if ($defaultLimit < 1 || $defaultLimit > $maxLimit || $maxLimit === PHP_INT_MAX) {
            throw new InvalidPaginationConfigurationException('Invalid keyset page limit policy.');
        }
        $this->cursorProtocol = new KeysetCursor($activeKeyId, $keys);
    }

    /**
     * @template T of array<array-key, mixed>|object
     * @param array<string, string|int|bool|null> $bindings The exact registered parameter names and their values.
     * @param callable(array<string, mixed>): T $mapper Maps an exposed row after PDO releases the result set.
     * @return KeysetPageResultDTO<T>
     * @throws InvalidPaginationQueryException For an ineligible request, binding map or connection.
     * @throws InvalidKeysetCursorException For a continuation that cannot be used on this read.
     * @throws PaginationExecutionException For an invalid row, mapper result or failure reported by PDO.
     */
    public function paginate(
        PDO $pdo,
        KeysetQueryDescriptor $query,
        #[SensitiveParameter]
        string $scope,
        #[SensitiveParameter]
        array $bindings,
        callable $mapper,
        #[SensitiveParameter]
        ?string $cursor = null,
        ?int $limit = null,
    ): KeysetPageResultDTO {
        $limit ??= $this->defaultLimit;
        if ($scope === '' || $limit < 1 || $limit > $this->maxLimit) {
            throw new InvalidPaginationQueryException('Invalid keyset scope or page limit.');
        }
        $registration = $query->registration();
        $bindings = self::freezeBindings($registration['parameterNames'], $bindings);
        if ($pdo->getAttribute(PDO::ATTR_DRIVER_NAME) !== 'mysql'
            || $pdo->getAttribute(PDO::ATTR_ERRMODE) !== PDO::ERRMODE_EXCEPTION
            || $pdo->getAttribute(PDO::ATTR_CASE) !== PDO::CASE_NATURAL) {
            throw new InvalidPaginationQueryException('PDO connection is not eligible for keyset pagination.');
        }

        $columns = $registration['columns'];
        $projection = array_map(static fn(string $name): string => 't.' . self::quote($name), $columns);
        $projection[] = 't.`id` AS ' . self::quote(self::RAW_ID);
        $table = implode('.', array_map(self::quote(...), explode('.', $registration['table'])));
        $prefix = 'SELECT ' . implode(', ', $projection) . ' FROM ' . $table . ' AS t'
            . ' WHERE (' . $registration['scopePredicate'] . ') AND (' . $registration['filterPredicate'] . ')';
        $suffix = ' ORDER BY t.`id` ASC LIMIT :__ks_limit';
        $firstSql = $prefix . $suffix;
        $nextSql = $prefix . ' AND t.`id` > :__ks_after' . $suffix;
        $contextFrame = self::contextFrame($registration['queryId'], $registration['revision'], $scope, $firstSql, $nextSql, $limit, $bindings);
        $after = $cursor === null ? null : $this->cursorProtocol->verify($cursor, $contextFrame);

        $rowLimit = $limit + 1;
        $bindings['__ks_limit'] = $rowLimit;
        if ($after !== null) {
            $bindings['__ks_after'] = $after;
        }
        $rows = self::readRows($pdo, $after === null ? $firstSql : $nextSql, $bindings, $columns, $rowLimit, $after ?? 0);

        $hasNext = count($rows) > $limit;
        if ($hasNext) {
            array_pop($rows);
        }
        $data = [];
        $lastId = null;
        foreach ($rows as [$lastId, $row]) {
            $item = $mapper($row);
            self::assertMappedItem($item);
            $data[] = $item;
        }
        $nextCursor = $hasNext && $lastId !== null ? $this->cursorProtocol->issue($lastId, $contextFrame) : null;

        return new KeysetPageResultDTO($data, $hasNext, $nextCursor);
    }

    /**
     * Reports only the default and maximum page limits in PHP debug output.
     * Omits cursor signing data.
     *
     * @return array{defaultLimit: int, maxLimit: int}
     */
    public function __debugInfo(): array
    {
        return ['defaultLimit' => $this->defaultLimit, 'maxLimit' => $this->maxLimit];
    }

    /**
     * @return array<array-key, mixed>
     * @throws PaginationExecutionException Always, because the paginator cannot be serialized.
     */
    public function __serialize(): array
    {
        throw new PaginationExecutionException(self::SERIALIZATION_ERROR);
    }

    /**
     * @param array<array-key, mixed> $data
     * @throws PaginationExecutionException Always, because the paginator cannot be restored.
     */
    public function __unserialize(#[SensitiveParameter] array $data): void
    {
        throw new PaginationExecutionException(self::SERIALIZATION_ERROR);
    }

    /** @param list<string> $required
     *  @param array<array-key, mixed> $values
     *  @return array<string, string|int|bool|null>
     */
    private static function freezeBindings(array $required, #[SensitiveParameter] array $values): array
    {
        if (count($required) !== count($values)) {
            throw new InvalidPaginationQueryException('Keyset bindings do not match the registration.');
        }

        $bindings = [];
        foreach ($required as $name) {
            if (! array_key_exists($name, $values)) {
                throw new InvalidPaginationQueryException('Keyset bindings do not match the registration.');
            }
            $value = $values[$name];
            if ($value !== null && ! is_int($value) && ! is_bool($value) && ! is_string($value)) {
                throw new InvalidPaginationQueryException('Invalid keyset binding type.');
            }
            $bindings[$name] = $value;
        }
        ksort($bindings, SORT_STRING);

        return $bindings;
    }

    /** @param array<string, string|int|bool|null> $bindings */
    private static function contextFrame(
        string $id,
        string $revision,
        #[SensitiveParameter]
        string $scope,
        string $firstSql,
        string $nextSql,
        int $limit,
        #[SensitiveParameter]
        array $bindings,
    ): string {
        $fields = [$id, $revision, $scope, $firstSql, $nextSql, (string) $limit, (string) count($bindings)];
        foreach ($bindings as $name => $value) {
            [$type, $bytes] = match (true) {
                $value === null => ['n', ''],
                is_bool($value) => ['b', $value ? '1' : '0'],
                is_int($value) => ['i', (string) $value],
                default => ['s', $value],
            };
            array_push($fields, $name, $type, $bytes);
        }

        return implode('', array_map(static fn(string $field): string => strlen($field) . ':' . $field, $fields));
    }

    private static function quote(string $identifier): string
    {
        return '`' . $identifier . '`';
    }

    /**
     * @param array<string, string|int|bool|null> $bindings
     * @param list<string> $columns
     * @return list<array{int, array<string, mixed>}>
     */
    private static function readRows(
        PDO $pdo,
        string $sql,
        #[SensitiveParameter]
        array $bindings,
        array $columns,
        int $rowLimit,
        int $after,
    ): array {
        $statement = $pdo->prepare($sql);
        if ($statement === false) {
            throw new PaginationExecutionException('Failed to prepare keyset query.');
        }

        try {
            self::execute($statement, $bindings);
            $expectedKeys = [...$columns, self::RAW_ID];
            $rows = [];
            while (count($rows) < $rowLimit) {
                /** @var mixed $row PDO or a custom statement may return an unexpected value. */
                $row = $statement->fetch(PDO::FETCH_ASSOC);
                if ($row === false) {
                    if ($statement->errorCode() !== '00000') {
                        throw new PaginationExecutionException('Failed to fetch keyset row.');
                    }
                    break;
                }
                if (! is_array($row) || array_keys($row) !== $expectedKeys) {
                    throw new PaginationExecutionException('Invalid keyset row projection.');
                }

                $id = KeysetCursor::parseId($row[self::RAW_ID]);
                if ($id === null || $id <= $after) {
                    throw new PaginationExecutionException('Invalid keyset row id.');
                }
                $exposed = [];
                foreach ($columns as $column) {
                    $exposed[$column] = $row[$column];
                }
                $rows[] = [$id, $exposed];
                $after = $id;
            }
        } catch (Throwable $primary) {
            try {
                $statement->closeCursor();
            } catch (Throwable) {
                // Preserve the primary error; the Host decides how to dispose of the connection.
            }
            throw $primary;
        }
        if (! $statement->closeCursor()) {
            throw new PaginationExecutionException('Failed to close keyset result.');
        }

        return $rows;
    }

    /** @param array<string, string|int|bool|null> $bindings */
    private static function execute(PDOStatement $statement, #[SensitiveParameter] array $bindings): void
    {
        foreach ($bindings as $name => $value) {
            $type = match (true) {
                $value === null => PDO::PARAM_NULL,
                is_bool($value) => PDO::PARAM_BOOL,
                is_int($value) => PDO::PARAM_INT,
                default => PDO::PARAM_STR,
            };
            if (! $statement->bindValue(':' . $name, $value, $type)) {
                throw new PaginationExecutionException('Failed to bind keyset query parameter.');
            }
        }
        if (! $statement->execute()) {
            throw new PaginationExecutionException('Failed to execute keyset query.');
        }
    }

    private static function assertMappedItem(#[SensitiveParameter] mixed $item): void
    {
        if (! is_array($item) && ! is_object($item)) {
            throw new PaginationExecutionException('Keyset mapper must return an array or object.');
        }
    }
}
