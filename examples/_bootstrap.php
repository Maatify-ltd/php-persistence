<?php

declare(strict_types=1);

/**
 * Shared connection plumbing for the runnable examples in this directory.
 *
 * This file is support code for the examples, not itself a demonstration of the
 * package's Public API and not meant to be executed directly. Each example
 * connects using the same environment-variable contract the repository's own
 * Integration infrastructure already provisions (see
 * docs/architecture/CI_AND_INTEGRATION.md), so the examples can be smoke-executed
 * against a real MySQL/MariaDB service without inventing a second database stack.
 */

/**
 * Reads a required environment variable, or fails with guidance instead of a
 * confusing PDO connection error.
 */
function php_persistence_example_env(string $name): string
{
    $value = getenv($name);
    if ($value === false || $value === '') {
        fwrite(
            STDERR,
            "Missing required environment variable {$name}.\n"
            . "This example needs a real MySQL/MariaDB connection. Run it through\n"
            . "'composer examples:smoke' (starts a disposable service via the\n"
            . "repository's Integration infrastructure), or set PHP_PERSISTENCE_IT_*\n"
            . "yourself to point at a disposable database; see\n"
            . "docs/guides/USAGE_GUIDE.md and CONTRIBUTING.md.\n",
        );
        exit(1);
    }

    return $value;
}

/**
 * Connects using the same PHP_PERSISTENCE_IT_* variables the Integration suite
 * uses (scripts/integration-support.php), so this example runs unchanged whether
 * it is smoke-executed by this repository's own infrastructure or pointed at a
 * developer's own MySQL/MariaDB instance.
 */
function php_persistence_example_connect(): PDO
{
    $host = php_persistence_example_env('PHP_PERSISTENCE_IT_HOST');
    $port = php_persistence_example_env('PHP_PERSISTENCE_IT_PORT');
    $database = php_persistence_example_env('PHP_PERSISTENCE_IT_DATABASE');
    $user = php_persistence_example_env('PHP_PERSISTENCE_IT_USER');
    $password = php_persistence_example_env('PHP_PERSISTENCE_IT_PASSWORD');

    return new PDO(
        "mysql:host={$host};port={$port};dbname={$database}",
        $user,
        $password,
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_TIMEOUT => 5],
    );
}

/**
 * A table name unique to this process run, so concurrent example runs (and
 * repeated runs against a shared database) never collide and the residue check
 * in 'composer examples:smoke' always finds a clean schema after DROP TABLE.
 *
 * @return non-empty-string
 */
function php_persistence_example_table_name(string $label): string
{
    return 'php_persistence_example_' . $label . '_' . bin2hex(random_bytes(4));
}

/**
 * Casts a PDO-returned scalar to int after confirming its actual type, since
 * pdo_mysql may represent a numeric column as a string depending on its
 * configuration and a blind cast of a `mixed` database value is not safe.
 */
function php_persistence_example_to_int(mixed $value): int
{
    if (is_int($value)) {
        return $value;
    }
    if (is_string($value) || is_float($value)) {
        return (int) $value;
    }
    throw new RuntimeException('Expected a scalar database value, got ' . get_debug_type($value));
}

/**
 * Casts a PDO-returned scalar to string after confirming its actual type; see
 * php_persistence_example_to_int().
 */
function php_persistence_example_to_string(mixed $value): string
{
    if (is_string($value)) {
        return $value;
    }
    if (is_int($value) || is_float($value)) {
        return (string) $value;
    }
    throw new RuntimeException('Expected a scalar database value, got ' . get_debug_type($value));
}

/**
 * Fails the example with a clear message instead of a bare assertion failure.
 */
function php_persistence_example_assert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

/**
 * Narrows PDO::prepare()/PDO::query()'s `PDOStatement|false` return to
 * `PDOStatement`, failing the example with a clear message instead of a bare
 * type error when preparation fails.
 */
function php_persistence_example_require_statement(PDOStatement|false $statement, string $message): PDOStatement
{
    if ($statement === false) {
        throw new RuntimeException($message);
    }

    return $statement;
}
