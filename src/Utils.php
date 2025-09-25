<?php
/**
 * src/Utils.php
 * Helper utilities for DreamAI project (PHP 8+), dependency-free.
 *
 * Functions provided:
 *  - uuidv4(): string                                // Secure UUID v4
 *  - normalizeText(string $s, bool $collapse=true): string
 *  - sanitizeHtml(string $s): string
 *  - toJson(mixed $data): string                     // JSON_UNESCAPED_UNICODE|SLASHES + throw on error
 *  - fromJson(?string $json, mixed $default=[]): mixed
 *  - isJson(string $json): bool
 *  - secureRandomInt(int $min, int $max): int
 *  - pickRandom(array $items): mixed                 // One random element
 *  - pickRandomN(array $items, int $n): array        // Unique random subset (max n)
 *  - shuffleList(array $items): array                // Returns new shuffled list (values reindexed)
 *  - hashWithSalt(string $data, string $salt, string $algo='sha256'): string
 *  - ipHash(?string $ip, string $salt, string $algo='sha256'): string
 *  - isThai(string $s): bool                         // Detect Thai characters presence
 *  - nowIso(): string                                // ISO-8601 timestamp
 */

declare(strict_types=1);

namespace DreamAI;

final class Utils
{
    private function __construct() {}

    /**
     * Generate a RFC 4122 version 4 UUID using random_bytes.
     */
    public static function uuidv4(): string
    {
        $data = random_bytes(16);
        // Set version to 0100
        $data[6] = chr((ord($data[6]) & 0x0f) | 0x40);
        // Set bits 6-7 to 10 (variant RFC 4122)
        $data[8] = chr((ord($data[8]) & 0x3f) | 0x80);

        $hex = bin2hex($data);
        return sprintf(
            '%s-%s-%s-%s-%s',
            substr($hex, 0, 8),
            substr($hex, 8, 4),
            substr($hex, 12, 4),
            substr($hex, 16, 4),
            substr($hex, 20, 12)
        );
    }

    /**
     * Normalize text:
     *  - convert CRLF/CR -> LF
     *  - trim surrounding spaces
     *  - remove zero-width chars (ZWSP/ZWNJ/ZWJ/BOM)
     *  - optionally collapse multiple whitespace to single space (including newlines)
     */
    public static function normalizeText(string $s, bool $collapse = true): string
    {
        // Normalize line endings to LF
        $s = str_replace(["\r\n", "\r"], "\n", $s);

        // Remove zero-width characters
        $s = preg_replace('/[\x{200B}\x{200C}\x{200D}\x{FEFF}]/u', '', $s) ?? $s;

        $s = trim($s);

        if ($collapse) {
            // Collapse all runs of whitespace (space, tab, newline) to a single space
            $s = preg_replace('/\s+/u', ' ', $s) ?? $s;
        }

        return $s;
    }

    /**
     * Escape HTML safely with UTF-8 and substitution for invalid code units.
     */
    public static function sanitizeHtml(string $s): string
    {
        return htmlspecialchars($s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    /**
     * Encode data to JSON with sane defaults. Throws on failure.
     */
    public static function toJson(mixed $data): string
    {
        $json = json_encode(
            $data,
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
        );

        if ($json === false) {
            throw new \RuntimeException('JSON encode error: ' . json_last_error_msg());
        }
        return $json;
    }

    /**
     * Decode JSON to PHP types (assoc arrays). Returns $default on failure/empty.
     */
    public static function fromJson(?string $json, mixed $default = []): mixed
    {
        if (!is_string($json) || trim($json) === '') {
            return $default;
        }
        $data = json_decode($json, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            return $default;
        }
        return $data;
    }

    /**
     * Quick JSON validity check (strictly checks decode errors).
     */
    public static function isJson(string $json): bool
    {
        if ($json === '') return false;
        json_decode($json);
        return json_last_error() === JSON_ERROR_NONE;
    }

    /**
     * cryptographically secure random integer in [min, max]
     */
    public static function secureRandomInt(int $min, int $max): int
    {
        return random_int($min, $max);
    }

    /**
     * Pick one random element from an array (returns null if empty).
     */
    public static function pickRandom(array $items): mixed
    {
        if (!$items) return null;
        $idx = array_rand($items);
        return $items[$idx];
    }

    /**
     * Pick up to N unique random elements from an array.
     * Returns an indexed array (values only). If n >= count($items), returns shuffled copy.
     */
    public static function pickRandomN(array $items, int $n): array
    {
        $count = count($items);
        if ($count === 0 || $n <= 0) return [];
        if ($n >= $count) {
            return self::shuffleList($items);
        }
        $keys = array_rand($items, $n);
        if (!is_array($keys)) $keys = [$keys];
        $out = [];
        foreach ($keys as $k) {
            $out[] = $items[$k];
        }
        return $out;
    }

    /**
     * Return a new shuffled list (values reindexed).
     */
    public static function shuffleList(array $items): array
    {
        // Use Fisher–Yates on a copy to avoid mutating input.
        $out = array_values($items);
        for ($i = count($out) - 1; $i > 0; $i--) {
            $j = self::secureRandomInt(0, $i);
            [$out[$i], $out[$j]] = [$out[$j], $out[$i]];
        }
        return $out;
    }

    /**
     * Generic hashing with salt (default SHA-256).
     */
    public static function hashWithSalt(string $data, string $salt, string $algo = 'sha256'): string
    {
        return hash($algo, $data . '|' . $salt);
    }

    /**
     * Deterministic anonymized IP hash (handles nulls).
     */
    public static function ipHash(?string $ip, string $salt, string $algo = 'sha256'): string
    {
        $ip = $ip ?: '0.0.0.0';
        return hash($algo, $ip . '|' . $salt);
    }

    /**
     * Rough check: does the string contain Thai characters (U+0E00–U+0E7F)?
     */
    public static function isThai(string $s): bool
    {
        return (bool) preg_match('/[\x{0E00}-\x{0E7F}]/u', $s);
    }

    /**
     * Current timestamp in ISO-8601 (UTC).
     */
    public static function nowIso(): string
    {
        return gmdate('c');
    }
}
