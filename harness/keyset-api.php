<?php

declare(strict_types=1);

/** Validates the installed Keyset public signatures using production Composer autoload. */

use Maatify\PhpPersistence\Exception\PhpPersistenceExceptionInterface;
use Maatify\PhpPersistence\Pagination\Criteria\KeysetQueryDescriptor;
use Maatify\PhpPersistence\Pagination\DTO\KeysetPageResultDTO;
use Maatify\PhpPersistence\Pagination\Exception\InvalidKeysetCursorException;
use Maatify\PhpPersistence\Pagination\Repository\PdoKeysetPaginator;

require __DIR__ . '/vendor/autoload.php';

$paginatorParameters = [
    ['activeKeyId', 'string'], ['keys', 'array'], ['defaultLimit', 'int', 20], ['maxLimit', 'int', 200],
];
$paginateParameters = [
    ['pdo', PDO::class], ['query', KeysetQueryDescriptor::class], ['scope', 'string'],
    ['bindings', 'array'], ['mapper', 'callable'], ['cursor', '?string', null], ['limit', '?int', null],
];
$descriptorParameters = [
    ['queryId', 'string'], ['revision', 'string'], ['table', 'string'], ['columns', 'array'],
    ['scopePredicate', 'string'], ['parameterNames', 'array'], ['filterPredicate', 'string', '1 = 1'],
];
$resultParameters = [['data', 'array'], ['hasNext', 'bool'], ['nextCursor', '?string']];
$signatures = [
    [PdoKeysetPaginator::class, '__construct', '', $paginatorParameters],
    [PdoKeysetPaginator::class, 'paginate', KeysetPageResultDTO::class, $paginateParameters],
    [KeysetQueryDescriptor::class, '__construct', '', $descriptorParameters],
    [KeysetPageResultDTO::class, '__construct', '', $resultParameters],
    [KeysetPageResultDTO::class, 'jsonSerialize', 'array', []],
];

foreach ($signatures as [$class, $name, $return, $parameters]) {
    $method = new ReflectionMethod($class, $name);
    $actual = array_map(static function (ReflectionParameter $parameter): array {
        $parameterShape = [$parameter->getName(), (string) $parameter->getType()];
        if ($parameter->isDefaultValueAvailable()) {
            $parameterShape[] = $parameter->getDefaultValue();
        }

        return $parameterShape;
    }, $method->getParameters());

    if (!$method->isPublic() || $method->isStatic() || (string) $method->getReturnType() !== $return || $actual !== $parameters) {
        throw new RuntimeException('Unexpected installed Keyset signature: ' . $class . '::' . $name);
    }
}

$keys = new ReflectionParameter([PdoKeysetPaginator::class, '__construct'], 'keys');
if ($keys->getAttributes(SensitiveParameter::class) === []
    || !is_subclass_of(InvalidKeysetCursorException::class, PhpPersistenceExceptionInterface::class)) {
    throw new RuntimeException('Unexpected installed Keyset diagnostic or exception contract.');
}

echo "KEYSET PUBLIC API OK\n";
