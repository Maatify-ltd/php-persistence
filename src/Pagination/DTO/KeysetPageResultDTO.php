<?php

declare(strict_types=1);

namespace Maatify\PhpPersistence\Pagination\DTO;

use JsonSerializable;
use Maatify\PhpPersistence\Pagination\Exception\PaginationExecutionException;
use SensitiveParameter;

/**
 * Ordered mapped rows and the opaque continuation for this read.
 *
 * @template T of array<array-key, mixed>|object
 */
final readonly class KeysetPageResultDTO implements JsonSerializable
{
    /**
     * @param list<T> $data
     * @throws PaginationExecutionException For an invalid result shape or continuation state.
     */
    public function __construct(
        public array $data,
        public bool $hasNext,
        #[SensitiveParameter]
        public ?string $nextCursor,
    ) {
        self::validateData($data);

        if ($hasNext !== ($nextCursor !== null) || ($hasNext && $data === []) || $nextCursor === '') {
            throw new PaginationExecutionException('Invalid keyset pagination continuation state.');
        }
    }

    /** @return array{data: list<T>, hasNext: bool, nextCursor: ?string} */
    public function jsonSerialize(): array
    {
        return [
            'data' => $this->data,
            'hasNext' => $this->hasNext,
            'nextCursor' => $this->nextCursor,
        ];
    }

    /** @param array<array-key, mixed> $data */
    private static function validateData(array $data): void
    {
        if (! array_is_list($data)) {
            throw new PaginationExecutionException('Keyset pagination data must be a list.');
        }
        foreach ($data as $item) {
            if (! is_array($item) && ! is_object($item)) {
                throw new PaginationExecutionException('Keyset pagination data item must be an array or object.');
            }
        }
    }
}
