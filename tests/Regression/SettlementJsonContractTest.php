<?php

declare(strict_types=1);

namespace Maatify\PhpPersistence\Tests\Regression;

use Maatify\PhpPersistence\Pagination\DTO\HostSnapshotPageSettlementDTO;
use Maatify\PhpPersistence\Pagination\DTO\SnapshotConnectionOutcomeDTO;
use Maatify\PhpPersistence\Pagination\Enum\HostSnapshotPageDispositionEnum as PageDisposition;
use Maatify\PhpPersistence\Pagination\Enum\HostSnapshotPageSettlementReasonEnum as PageReason;
use Maatify\PhpPersistence\Pagination\Enum\SnapshotConnectionDispositionEnum as ConnectionDisposition;
use Maatify\PhpPersistence\Transaction\DTO\ProtectedTransactionSettlementDTO;
use Maatify\PhpPersistence\Transaction\DTO\VerifiedTransactionSettlementDTO;
use Maatify\PhpPersistence\Transaction\Enum\ProtectedTransactionDispositionEnum as ProtectedDisposition;
use Maatify\PhpPersistence\Transaction\Enum\ProtectedTransactionReasonEnum as ProtectedReason;
use Maatify\PhpPersistence\Transaction\Enum\VerifiedTransactionOutcomeEnum as VerifiedOutcome;
use Maatify\PhpPersistence\Transaction\Enum\VerifiedTransactionReasonEnum as VerifiedReason;
use PHPUnit\Framework\TestCase;

/** Covers the complete set of stable settlement codes and the JSON envelopes. */
final class SettlementJsonContractTest extends TestCase
{
    public function testSnapshotOutcomePreservesDispositionCodesAndDiagnostic(): void
    {
        $outcomes = array_map(
            static fn(ConnectionDisposition $case): SnapshotConnectionOutcomeDTO => new SnapshotConnectionOutcomeDTO($case, 'qualified stage'),
            ConnectionDisposition::cases(),
        );
        self::assertSame(
            '[{"disposition":"UNCHANGED","diagnostic":"qualified stage"},{"disposition":"REUSABLE","diagnostic":"qualified stage"},{"disposition":"DISCARD","diagnostic":"qualified stage"}]',
            json_encode($outcomes, JSON_THROW_ON_ERROR),
        );
        self::assertSame(
            '{"disposition":"REUSABLE","diagnostic":null}',
            json_encode(new SnapshotConnectionOutcomeDTO(ConnectionDisposition::Reusable, null), JSON_THROW_ON_ERROR),
        );
    }

    public function testHostSnapshotSettlementPreservesDispositionAndReasonCodes(): void
    {
        self::assertSame(
            ['NOT_ACCEPTED', 'RETURNED_TO_HOST', 'HOST_ACTION_REQUIRED'],
            array_map(
                static fn(PageDisposition $case): string => (new HostSnapshotPageSettlementDTO($case, PageReason::PageCompleted))->jsonSerialize()['disposition'],
                PageDisposition::cases(),
            ),
        );
        $expectedReasons = [
            'SOURCE_UNAVAILABLE',
            'STALE_CONTEXT',
            'GRANT_CONSUMED',
            'BUSY',
            'PAGE_COMPLETED',
            'READ_FAILED',
            'RESOURCE_RELEASE_UNCONFIRMED',
            'SCOPE_UNCONFIRMED',
            'SETTLEMENT_UNCONFIRMED',
        ];
        self::assertSame(
            $expectedReasons,
            array_map(
                static fn(PageReason $case): string => (new HostSnapshotPageSettlementDTO(PageDisposition::ReturnedToHost, $case))->jsonSerialize()['reason'],
                PageReason::cases(),
            ),
        );
        self::assertSame(
            '{"disposition":"RETURNED_TO_HOST","reason":"PAGE_COMPLETED"}',
            json_encode(new HostSnapshotPageSettlementDTO(PageDisposition::ReturnedToHost, PageReason::PageCompleted), JSON_THROW_ON_ERROR),
        );
    }

    public function testProtectedSettlementPreservesDispositionAndReasonCodes(): void
    {
        self::assertSame(
            ['UNCHANGED', 'REUSABLE', 'DISCARD_REQUIRED'],
            array_map(
                static fn(ProtectedDisposition $case): string => (new ProtectedTransactionSettlementDTO($case, ProtectedReason::Committed))->jsonSerialize()['disposition'],
                ProtectedDisposition::cases(),
            ),
        );
        $expectedReasons = [
            'CANCELLED_BEFORE_QUALIFICATION',
            'SERVER_QUALIFICATION_FAILED',
            'START_UNCONFIRMED',
            'TRANSACTION_LOST',
            'OPERATION_UNCONFIRMED',
            'COMMIT_OUTCOME_UNKNOWN',
            'COMMIT_CONFIRMED_CONNECTION_UNSAFE',
            'ROLLBACK_UNCONFIRMED',
            'COMMITTED',
            'ROLLED_BACK',
            'HOST_DISCARDED',
        ];
        self::assertSame(
            $expectedReasons,
            array_map(
                static fn(ProtectedReason $case): string => (new ProtectedTransactionSettlementDTO(ProtectedDisposition::Reusable, $case))->jsonSerialize()['reason'],
                ProtectedReason::cases(),
            ),
        );
        self::assertSame(
            '{"disposition":"DISCARD_REQUIRED","reason":"COMMIT_CONFIRMED_CONNECTION_UNSAFE"}',
            json_encode(new ProtectedTransactionSettlementDTO(ProtectedDisposition::DiscardRequired, ProtectedReason::CommitConfirmedConnectionUnsafe), JSON_THROW_ON_ERROR),
        );
    }

    public function testVerifiedSettlementPreservesOutcomeReasonAndNullableFields(): void
    {
        self::assertSame(
            ['NOT_STARTED', 'PENDING_HOST', 'LOCAL_UNDONE', 'COMMITTED', 'ROLLED_BACK', 'UNKNOWN'],
            array_map(
                static fn(VerifiedOutcome $case): ?string => (new VerifiedTransactionSettlementDTO($case, null, null, null, null))->jsonSerialize()['operationOutcome'],
                VerifiedOutcome::cases(),
            ),
        );
        $expectedReasons = [
            'QUALIFICATION_FAILED',
            'START_UNCONFIRMED',
            'CONTINUITY_UNCONFIRMED',
            'SAVEPOINT_UNCONFIRMED',
            'CALLBACK_FAILED',
            'COMMAND_FAILED',
            'CURSOR_UNCONFIRMED',
            'ROLLBACK_TO_UNCONFIRMED',
            'RELEASE_UNCONFIRMED',
            'COMMIT_OUTCOME_UNKNOWN',
            'ROLLBACK_UNCONFIRMED',
            'HANDOFF_UNCONFIRMED',
            'TRANSACTION_LOST',
            'HOST_DISCARDED',
        ];
        self::assertSame(
            $expectedReasons,
            array_map(
                static fn(VerifiedReason $case): ?string => (new VerifiedTransactionSettlementDTO(null, $case, null, null, null))->jsonSerialize()['operationReason'],
                VerifiedReason::cases(),
            ),
        );
        $settlement = new VerifiedTransactionSettlementDTO(
            VerifiedOutcome::LocalUndone,
            VerifiedReason::CallbackFailed,
            VerifiedOutcome::Unknown,
            VerifiedReason::HandoffUnconfirmed,
            ProtectedDisposition::DiscardRequired,
        );
        self::assertSame(
            '{"operationOutcome":"LOCAL_UNDONE","operationReason":"CALLBACK_FAILED","transactionOutcome":"UNKNOWN","checkoutReason":"HANDOFF_UNCONFIRMED","disposition":"DISCARD_REQUIRED"}',
            json_encode($settlement, JSON_THROW_ON_ERROR),
        );
        self::assertSame(
            '{"operationOutcome":null,"operationReason":null,"transactionOutcome":null,"checkoutReason":null,"disposition":null}',
            json_encode(new VerifiedTransactionSettlementDTO(null, null, null, null, null), JSON_THROW_ON_ERROR),
        );
    }
}
