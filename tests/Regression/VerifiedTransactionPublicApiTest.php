<?php

declare(strict_types=1);

namespace Maatify\PhpPersistence\Tests\Regression;

use Closure;
use Maatify\PhpPersistence\Transaction\Criteria\ProtectedTransactionCommandCatalog;
use Maatify\PhpPersistence\Transaction\Command\VerifiedTransactionDecisionCommand as Decision;
use Maatify\PhpPersistence\Transaction\DTO\VerifiedTransactionSettlementDTO as Settlement;
use Maatify\PhpPersistence\Transaction\Enum\ProtectedTransactionDispositionEnum as Disposition;
use Maatify\PhpPersistence\Transaction\Enum\VerifiedTransactionOutcomeEnum as Outcome;
use Maatify\PhpPersistence\Transaction\Enum\VerifiedTransactionReasonEnum as Reason;
use Maatify\PhpPersistence\Transaction\Repository\PdoVerifiedTransactionCheckout as Checkout;
use Maatify\PhpPersistence\Transaction\Repository\VerifiedTransactionCommandsInterface as Commands;
use PDO;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionMethod;

final class VerifiedTransactionPublicApiTest extends TestCase
{
    public function testCheckoutSignaturesAndVisibilityAreExact(): void
    {
        $checkout = new ReflectionClass(Checkout::class);
        self::assertTrue($checkout->isFinal());
        self::assertTrue($checkout->getConstructor()?->isPrivate());
        self::assertTrue($checkout->getMethod('__clone')->isPrivate());
        $actual = array_map(static fn(ReflectionMethod $method): string => $method->name, $checkout->getMethods(ReflectionMethod::IS_PUBLIC));
        $methods = [
            'attachExclusive' => [Checkout::class, ['pdo' => PDO::class, 'catalog' => ProtectedTransactionCommandCatalog::class]],
            'runOwned' => ['mixed', ['operation' => Closure::class]],
            'beginHost' => ['void', []],
            'runRegisteredCommand' => ['array|int', ['commandId' => 'string', 'bindings' => 'array']],
            'runInHost' => ['mixed', ['operation' => Closure::class]],
            'commit' => ['void', []],
            'rollBack' => ['void', []],
            'settlement' => [Settlement::class, []],
            'returnForHost' => [PDO::class, []],
            'discardForHost' => ['void', []],
            '__serialize' => ['array', []],
            '__unserialize' => ['void', ['data' => 'array']],
        ];
        self::assertEqualsCanonicalizing(array_keys($methods), $actual);
        self::assertTrue($checkout->getMethod('attachExclusive')->isStatic());
        self::assertNotEmpty($checkout->getMethod('attachExclusive')->getParameters()[0]->getAttributes(\SensitiveParameter::class));
        foreach ($methods as $name => [$return, $parameters]) {
            $method = $checkout->getMethod($name);
            self::assertSame($return, str_replace('self', Checkout::class, (string) $method->getReturnType()));
            $actualParameters = [];
            foreach ($method->getParameters() as $parameter) {
                $actualParameters[$parameter->name] = (string) $parameter->getType();
                if ($parameter->name === 'bindings') {
                    self::assertSame([], $parameter->getDefaultValue());
                } else {
                    self::assertFalse($parameter->isDefaultValueAvailable());
                }
            }
            self::assertSame($parameters, $actualParameters);
        }
        $scope = new ReflectionClass(Commands::class);
        self::assertTrue($scope->isInterface());
        self::assertCount(1, $scope->getMethods());
        $command = $scope->getMethod('runRegisteredCommand');
        self::assertSame('array|int', (string) $command->getReturnType());
        self::assertSame('commandId', $command->getParameters()[0]->name);
        self::assertSame('bindings', $command->getParameters()[1]->name);
        self::assertSame([], $command->getParameters()[1]->getDefaultValue());
    }

    public function testDecisionAndSettlementAreImmutableValues(): void
    {
        $decision = new ReflectionClass(Decision::class);
        self::assertTrue($decision->isFinal() && $decision->isReadOnly());
        self::assertTrue($decision->getConstructor()?->isPrivate());
        foreach (['apply', 'discard'] as $name) {
            $method = $decision->getMethod($name);
            self::assertTrue($method->isStatic());
            self::assertSame(Decision::class, str_replace('self', Decision::class, (string) $method->getReturnType()));
            self::assertSame('payload', $method->getParameters()[0]->name);
            self::assertSame('mixed', (string) $method->getParameters()[0]->getType());
            self::assertNull($method->getParameters()[0]->getDefaultValue());
        }
        $payload = new \stdClass();
        self::assertTrue(Decision::apply($payload)->isApply);
        self::assertSame($payload, Decision::apply($payload)->payload);
        self::assertFalse(Decision::discard(false)->isApply);
        self::assertFalse(Decision::discard(false)->payload);
        self::assertNull(Decision::discard()->payload);
        self::assertNotContains(\JsonSerializable::class, $decision->getInterfaceNames());

        $settlement = new ReflectionClass(Settlement::class);
        self::assertTrue($settlement->isFinal() && $settlement->isReadOnly());
        $parameters = $settlement->getConstructor()?->getParameters() ?? [];
        self::assertSame([
            'operationOutcome' => '?' . Outcome::class,
            'operationReason' => '?' . Reason::class,
            'transactionOutcome' => '?' . Outcome::class,
            'checkoutReason' => '?' . Reason::class,
            'disposition' => '?' . Disposition::class,
        ], array_combine(
            array_map(static fn(\ReflectionParameter $p): string => $p->name, $parameters),
            array_map(static fn(\ReflectionParameter $p): string => (string) $p->getType(), $parameters),
        ));
        self::assertSame(
            ['NOT_STARTED', 'PENDING_HOST', 'LOCAL_UNDONE', 'COMMITTED', 'ROLLED_BACK', 'UNKNOWN'],
            array_column(Outcome::cases(), 'name'),
        );
        self::assertSame([
            'QUALIFICATION_FAILED', 'START_UNCONFIRMED', 'CONTINUITY_UNCONFIRMED', 'SAVEPOINT_UNCONFIRMED',
            'CALLBACK_FAILED', 'COMMAND_FAILED', 'CURSOR_UNCONFIRMED', 'ROLLBACK_TO_UNCONFIRMED',
            'RELEASE_UNCONFIRMED', 'COMMIT_OUTCOME_UNKNOWN', 'ROLLBACK_UNCONFIRMED', 'HANDOFF_UNCONFIRMED',
            'TRANSACTION_LOST', 'HOST_DISCARDED',
        ], array_column(Reason::cases(), 'name'));
    }

    public function testSettlementJsonPreservesPropertiesAsCaseNamesOrNull(): void
    {
        $settlement = new Settlement(Outcome::LOCAL_UNDONE, Reason::CALLBACK_FAILED, Outcome::UNKNOWN, null, Disposition::DISCARD_REQUIRED);

        self::assertSame(Outcome::LOCAL_UNDONE, $settlement->operationOutcome);
        self::assertSame([
            'operationOutcome' => 'LOCAL_UNDONE',
            'operationReason' => 'CALLBACK_FAILED',
            'transactionOutcome' => 'UNKNOWN',
            'checkoutReason' => null,
            'disposition' => 'DISCARD_REQUIRED',
        ], json_decode(json_encode($settlement, JSON_THROW_ON_ERROR), true, 512, JSON_THROW_ON_ERROR));
    }
}
