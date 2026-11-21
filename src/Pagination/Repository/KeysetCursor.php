<?php

declare(strict_types=1);

namespace Maatify\PhpPersistence\Pagination\Repository;

use Maatify\PhpPersistence\Pagination\Exception\InvalidKeysetCursorException;
use Maatify\PhpPersistence\Pagination\Exception\InvalidPaginationConfigurationException;
use SensitiveParameter;

/** @internal Keyset v1 cursor protocol, including the integer domain and context encoding. */
final readonly class KeysetCursor
{
    private const KEY_ID = '/\A[A-Za-z0-9_-]{1,32}\z/';
    private const DOMAIN = 'maatify/php-persistence/keyset/v1/';

    /** @var array<string, array{sign: string, context: string}> Derived keys indexed by a non-numeric key ID. */
    private array $keyring;

    /** @param list<array{id: string, secret: string}> $keys */
    public function __construct(private string $activeKeyId, #[SensitiveParameter] array $keys)
    {
        $this->keyring = self::deriveKeys($keys);
        if (! isset($this->keyring[':' . $activeKeyId])) {
            throw new InvalidPaginationConfigurationException('Invalid active keyset signing key.');
        }
    }

    /**
     * @param array<array-key, mixed> $keys
     * @return array<string, array{sign: string, context: string}>
     */
    private static function deriveKeys(#[SensitiveParameter] array $keys): array
    {
        if (! array_is_list($keys)) {
            throw new InvalidPaginationConfigurationException('Invalid keyset signing key list.');
        }

        $keyring = [];
        foreach ($keys as $entry) {
            if (! is_array($entry) || count($entry) !== 2) {
                throw new InvalidPaginationConfigurationException('Invalid keyset signing key.');
            }
            $id = $entry['id'] ?? null;
            $secret = $entry['secret'] ?? null;
            if (! is_string($id) || preg_match(self::KEY_ID, $id) !== 1
                || ! is_string($secret) || strlen($secret) < 32 || isset($keyring[':' . $id])) {
                throw new InvalidPaginationConfigurationException('Invalid or duplicate keyset signing key.');
            }

            $keyring[':' . $id] = [
                'sign' => hash_hmac('sha256', self::DOMAIN . 'sign', $secret, true),
                'context' => hash_hmac('sha256', self::DOMAIN . 'context', $secret, true),
            ];
        }

        return $keyring;
    }

    public function issue(int $lastId, string $contextFrame): string
    {
        $keys = $this->keyring[':' . $this->activeKeyId];
        $contextHex = hash_hmac('sha256', $contextFrame, $keys['context']);
        $envelope = 'v1.' . $this->activeKeyId . '.' . self::encode($lastId . ':' . $contextHex);

        return $envelope . '.' . self::encode(hash_hmac('sha256', $envelope, $keys['sign'], true));
    }

    public function verify(string $cursor, string $contextFrame): int
    {
        if (strlen($cursor) > 256
            || preg_match('/\Av1\.([A-Za-z0-9_-]{1,32})\.([A-Za-z0-9_-]+)\.([A-Za-z0-9_-]{43})\z/', $cursor, $parts) !== 1) {
            throw new InvalidKeysetCursorException('Invalid keyset cursor.');
        }

        $keys = $this->keyring[':' . $parts[1]] ?? null;
        $mac = self::decode($parts[3]);
        if ($keys === null || $mac === null || strlen($mac) !== 32
            || ! hash_equals(hash_hmac('sha256', 'v1.' . $parts[1] . '.' . $parts[2], $keys['sign'], true), $mac)) {
            throw new InvalidKeysetCursorException('Invalid keyset cursor.');
        }

        $payload = self::decode($parts[2]);
        if ($payload === null || preg_match('/\A([1-9][0-9]*):([0-9a-f]{64})\z/', $payload, $fields) !== 1
            || ! hash_equals(hash_hmac('sha256', $contextFrame, $keys['context']), $fields[2])) {
            throw new InvalidKeysetCursorException('Invalid keyset cursor.');
        }

        return self::parseId($fields[1]) ?? throw new InvalidKeysetCursorException('Invalid keyset cursor.');
    }

    public static function parseId(mixed $value): ?int
    {
        if (is_int($value)) {
            return $value > 0 ? $value : null;
        }
        if (! is_string($value) || preg_match('/\A[1-9][0-9]*\z/', $value) !== 1) {
            return null;
        }

        $max = (string) PHP_INT_MAX;
        $length = strlen($value);
        if ($length > strlen($max) || ($length === strlen($max) && strcmp($value, $max) > 0)) {
            return null;
        }

        return (int) $value;
    }

    private static function encode(string $bytes): string
    {
        return rtrim(strtr(base64_encode($bytes), '+/', '-_'), '=');
    }

    private static function decode(string $encoded): ?string
    {
        $bytes = base64_decode(strtr($encoded, '-_', '+/'), true);

        return $bytes !== false && self::encode($bytes) === $encoded ? $bytes : null;
    }
}
