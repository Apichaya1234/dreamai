<?php
/**
 * src/Env.php
 * Minimal, dependency-free .env loader for PHP 8+
 *
 * Features:
 * - Loads KEY=VALUE pairs from a .env file (or a given path)
 * - Supports comments (# or ;) and empty lines
 * - Supports leading `export KEY=...`
 * - Trims whitespace around keys/values
 * - Supports single-quoted and double-quoted values
 *   - Double quotes allow escapes: \n \r \t \\ \" and ${VAR} expansion
 *   - Single quotes are treated as literals (no expansion, no unescaping)
 * - Supports inline comments (outside of quotes)
 * - Optional variable expansion for unquoted / double-quoted values: ${VAR}
 * - Writes to getenv(), $_ENV, and $_SERVER
 * - Optional $override to overwrite existing environment values
 *
 * Usage:
 *   \DreamAI\Env::load(__DIR__.'/../.env');          // load if exists
 *   $val = \DreamAI\Env::get('OPENAI_API_KEY');
 */

namespace DreamAI;

final class Env
{
    /**
     * Load variables from a .env file.
     *
     * @param string $path      Path to .env file or a directory containing .env
     * @param bool   $override  If true, overwrite existing values
     */
    public static function load(string $path, bool $override = false): void
    {
        $file = self::resolveFile($path);
        if ($file === null) {
            return; // silently ignore if file not found
        }

        $lines = file($file, FILE_IGNORE_NEW_LINES);
        if ($lines === false) {
            return;
        }

        // Remove UTF-8 BOM from first line if present
        if (isset($lines[0])) {
            $lines[0] = self::stripBom($lines[0]);
        }

        foreach ($lines as $raw) {
            $line = trim($raw);
            if ($line === '' || self::isComment($line)) {
                continue;
            }

            // Allow "export KEY=VAL"
            if (str_starts_with($line, 'export ')) {
                $line = trim(substr($line, 7));
            }

            // Split KEY and VALUE by the first '='
            $pos = strpos($line, '=');
            if ($pos === false) {
                // No '=', skip
                continue;
            }

            $key   = rtrim(substr($line, 0, $pos));
            $value = ltrim(substr($line, $pos + 1));

            if ($key === '') {
                continue;
            }

            // Validate key
            if (!preg_match('/^[A-Z0-9_\.]+$/i', $key)) {
                // Skip invalid keys to be safe
                continue;
            }

            // Parse value (quotes / inline comments / escapes / expansion)
            $value = self::parseValue($value);

            // Set environment if allowed
            if (!$override && self::exists($key)) {
                continue;
            }
            self::set($key, $value);
        }
    }

    /**
     * Get an environment variable with optional default.
     */
    public static function get(string $key, ?string $default = null): ?string
    {
        $val = getenv($key);
        if ($val === false) {
            // Check superglobals as a fallback
            if (array_key_exists($key, $_ENV)) {
                return (string) $_ENV[$key];
            }
            if (array_key_exists($key, $_SERVER)) {
                return (string) $_SERVER[$key];
            }
            return $default;
        }
        return (string) $val;
    }

    /**
     * Convenience casts.
     */
    public static function getBool(string $key, ?bool $default = null): ?bool
    {
        $val = self::get($key);
        if ($val === null) return $default;
        $v = strtolower(trim($val));
        return match ($v) {
            '1', 'true', 'yes', 'on'  => true,
            '0', 'false', 'no', 'off' => false,
            default => $default,
        };
    }

    public static function getInt(string $key, ?int $default = null): ?int
    {
        $val = self::get($key);
        if ($val === null || !is_numeric($val)) return $default;
        return (int) $val;
    }

    // ---------- internals ----------

    private static function resolveFile(string $path): ?string
    {
        // If a directory is passed, look for "<dir>/.env"
        if (is_dir($path)) {
            $candidate = rtrim($path, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . '.env';
            return is_file($candidate) ? $candidate : null;
        }
        // If a file is passed, use it
        return is_file($path) ? $path : null;
    }

    private static function stripBom(string $s): string
    {
        if (substr($s, 0, 3) === "\xEF\xBB\xBF") {
            return substr($s, 3);
        }
        return $s;
    }

    private static function isComment(string $line): bool
    {
        return str_starts_with($line, '#') || str_starts_with($line, ';');
    }

    /**
     * Parse a .env value:
     * - Strip inline comments outside quotes
     * - Handle quoted vs unquoted
     * - Unescape double-quoted sequences
     * - Expand ${VAR} for unquoted or double-quoted values
     */
    private static function parseValue(string $value): string
    {
        $value = trim($value);

        // If quoted (single or double)
        if (self::isQuoted($value, '"')) {
            $inner = substr($value, 1, -1);
            // Expand ${VAR} then unescape double-quote sequences
            $expanded = self::expandVars($inner);
            return self::unescapeDoubleQuoted($expanded);
        }

        if (self::isQuoted($value, "'")) {
            // Single quotes: literal, no expansion, no unescape
            return substr($value, 1, -1);
        }

        // Unquoted: strip inline comment and trim
        $value = self::stripInlineComment($value);
        $value = trim($value);
        // Expand ${VAR}
        $value = self::expandVars($value);

        // Interpret barewords: true/false/null (case-insensitive)
        $lower = strtolower($value);
        if ($lower === 'null')   return '';
        if ($lower === 'true')   return 'true';
        if ($lower === 'false')  return 'false';

        return $value;
    }

    private static function isQuoted(string $s, string $quote): bool
    {
        return strlen($s) >= 2 && str_starts_with($s, $quote) && str_ends_with($s, $quote);
    }

    private static function stripInlineComment(string $s): string
    {
        // Remove inline comments starting with # or ; when not inside quotes
        $len = strlen($s);
        $out = '';
        $inSingle = false; $inDouble = false;

        for ($i = 0; $i < $len; $i++) {
            $ch = $s[$i];
            if ($ch === "'" && !$inDouble) { $inSingle = !$inSingle; $out .= $ch; continue; }
            if ($ch === '"' && !$inSingle) { $inDouble = !$inDouble; $out .= $ch; continue; }

            if (!$inSingle && !$inDouble && ($ch === '#' || $ch === ';')) {
                // Start of comment
                break;
            }
            $out .= $ch;
        }
        return $out;
    }

    private static function unescapeDoubleQuoted(string $s): string
    {
        // Replace common escapes in double-quoted strings
        $s = str_replace(['\\"', '\\\\'], ['"', '\\'], $s);
        $s = str_replace(['\\n','\\r','\\t'], ["\n","\r","\t"], $s);
        return $s;
    }

    private static function expandVars(string $s): string
    {
        return preg_replace_callback('/\\$\\{([A-Z0-9_\\.]+)\\}/i', function ($m) {
            $key = $m[1];
            $val = getenv($key);
            if ($val === false && array_key_exists($key, $_ENV))   $val = $_ENV[$key];
            if ($val === false && array_key_exists($key, $_SERVER)) $val = $_SERVER[$key];
            return $val === false ? '' : (string) $val;
        }, $s);
    }

    private static function exists(string $key): bool
    {
        if (getenv($key) !== false) return true;
        if (array_key_exists($key, $_ENV)) return true;
        if (array_key_exists($key, $_SERVER)) return true;
        return false;
    }

    private static function set(string $key, string $value): void
    {
        // Store in all 3 places for maximum compatibility
        putenv("$key=$value");
        $_ENV[$key]    = $value;
        $_SERVER[$key] = $value;
    }
}
