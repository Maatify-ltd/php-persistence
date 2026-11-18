<?php

declare(strict_types=1);

namespace Maatify\PhpPersistence\Tests\Support\Ordering;

use PDO;
use PDOException;
use PHPUnit\Framework\AssertionFailedError;

/**
 * Forces a specific row's UPDATE of `display_order` to fail with a real, server-raised
 * CHECK constraint violation on either an intermediate shift or the final target write.
 *
 * Legacy (1f6a7aa) proved this with a BEFORE UPDATE trigger issuing SIGNAL. That requires
 * either the SUPER privilege or `log_bin_trust_function_creators=ON`, neither of which the
 * database-scoped application user granted by this project's Integration Compose service
 * has (confirmed against mysql:8.4.13: `GRANT ALL PRIVILEGES ON <db>.*`, binlog ON by
 * default, error 1419/1458). A CHECK constraint needs only ALTER on the table, so it
 * reproduces the same "real shift already happened, then the target write genuinely fails"
 * scenario without requiring a privilege level this project's test environment does not grant.
 *
 * The constraint cannot reference `id` directly (MySQL/MariaDB reject a CHECK referring to
 * an AUTO_INCREMENT column, error 3818), so the target row is first marked with a unique
 * `label` the condition can reference instead; no assertion in the Ordering suite depends
 * on the marked row's label.
 */
final readonly class OrderingFailureInjector
{
    public function __construct(private PDO $pdo) {}

    /**
     * Adds a CHECK constraint that a later `UPDATE ... SET display_order = $forbiddenOrder WHERE id = $targetId`
     * violates, without affecting any other row or value.
     */
    public function createTargetUpdateFailure(string $table, int $targetId, int $forbiddenOrder): string
    {
        if ($targetId <= 0 || $forbiddenOrder <= 0) {
            throw new AssertionFailedError('Failure injector target id and order must be positive integers.');
        }

        $marker = 'chk_poison_' . $targetId;
        $update = $this->pdo->prepare('UPDATE `' . $table . '` SET `label` = :marker WHERE `id` = :id');

        if ($update === false) {
            throw new AssertionFailedError('Unable to prepare the ordering failure marker update.');
        }

        $update->execute(['marker' => $marker, 'id' => $targetId]);

        if ($update->rowCount() !== 1) {
            throw new AssertionFailedError('Unable to mark the ordering failure target row.');
        }

        $constraint = 'chk_fail_' . $table;
        $result = $this->pdo->exec(
            'ALTER TABLE `' . $table . '` ADD CONSTRAINT `' . $constraint . '`'
            . ' CHECK (`label` <> \'' . $marker . '\' OR `display_order` <> ' . $forbiddenOrder . ')',
        );

        if ($result === false) {
            throw new AssertionFailedError('Unable to add the ordering failure CHECK constraint.');
        }

        return $constraint;
    }

    /**
     * `DROP CHECK` is MySQL's own syntax for a CHECK constraint since 8.0.16 (the project
     * minimum) and is not understood by MariaDB; `DROP CONSTRAINT` is the generic form MariaDB
     * (and MySQL 8.0.19+) understands but MySQL 8.0.16 itself does not (confirmed directly
     * against the pinned image: 1064 "near 'CONSTRAINT ...'"). Trying the MySQL-native form
     * first and falling back covers both without needing to detect the engine here.
     */
    public function dropConstraint(string $table, string $constraint): void
    {
        try {
            $this->pdo->exec('ALTER TABLE `' . $table . '` DROP CHECK `' . $constraint . '`');
        } catch (PDOException) {
            $this->pdo->exec('ALTER TABLE `' . $table . '` DROP CONSTRAINT `' . $constraint . '`');
        }
    }
}
