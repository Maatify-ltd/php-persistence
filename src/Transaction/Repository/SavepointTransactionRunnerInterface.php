<?php

declare(strict_types=1);

namespace Maatify\PhpPersistence\Transaction\Repository;

/**
 * Runs a callback within an operation-local savepoint when joining an active
 * caller-owned transaction. The caller retains control of the outer transaction.
 */
interface SavepointTransactionRunnerInterface extends TransactionRunnerInterface {}
