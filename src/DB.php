<?php
/**
 * src/DB.php
 * Thin PDO wrapper for MySQL/MariaDB with safe prepared statements.
 *
 * Features:
 *  - UTF-8 (utf8mb4) DSN with ERRMODE_EXCEPTION and FETCH_ASSOC
 *  - one():    fetch single row (or null)
 *  - all():    fetch all rows (array<row>)
 *  - value():  fetch first column of first row (mixed|null)
 *  - execStmt(): execute DML; returns lastInsertId (if available) or affected rows
 *  - transaction(callable): run a closure inside BEGIN/COMMIT with auto-rollback
 *  - convenience begin()/commit()/rollBack()
 *
 * Usage:
 *   $db = new \DreamAI\DB($config['db']);         // see config.php
 *   $row = $db->one('SELECT * FROM users WHERE id=?', [$id]);
 *   $id  = $db->execStmt('INSERT INTO users(anon_id) VALUES (?)', [$uuid]);
 */

declare(strict_types=1);

namespace DreamAI;

use PDO;
use PDOException;

final class DB
{
    /** Public for direct access when needed (e.g., ->pdo->exec(...)) */
    public PDO $pdo;

    /**
     * @param array{host:string,port:string|int,name:string,user:string,pass:string,charset:string} $cfg
     * @throws \RuntimeException on connection failure
     */
    public function __construct(array $cfg)
    {
        $host    = $cfg['host']    ?? '';
        $port    = (string)($cfg['port'] ?? '3306');
        $dbname  = $cfg['name']    ?? '';
        $charset = $cfg['charset'] ?? 'utf8mb4';
        $user    = $cfg['user']    ?? '';
        $pass    = $cfg['pass']    ?? '';

        $dsn = "mysql:host={$host};port={$port};dbname={$dbname};charset={$charset}";

        try {
            $this->pdo = new PDO($dsn, $user, $pass, [
                PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES   => false, // use native prepares
            ]);

            // Optional: keep SQL mode sane and ensure timezone if needed
            // $this->pdo->exec("SET sql_mode = 'STRICT_ALL_TABLES,ERROR_FOR_DIVISION_BY_ZERO,NO_ENGINE_SUBSTITUTION'");
            // $this->pdo->exec("SET time_zone = '+00:00'");

        } catch (PDOException $e) {
            throw new \RuntimeException('Database connection failed: ' . $e->getMessage(), (int)$e->getCode(), $e);
        }
    }

    /**
     * Fetch single row or null.
     * @param string $sql
     * @param array<int|string, mixed> $params
     * @return array<string,mixed>|null
     */
    public function one(string $sql, array $params = []): ?array
    {
        $st = $this->prepareAndExecute($sql, $params);
        $row = $st->fetch();
        return $row === false ? null : $row;
    }

    /**
     * Fetch all rows (possibly empty).
     * @param string $sql
     * @param array<int|string, mixed> $params
     * @return array<int, array<string,mixed>>
     */
    public function all(string $sql, array $params = []): array
    {
        $st = $this->prepareAndExecute($sql, $params);
        return $st->fetchAll();
    }

    /**
     * Fetch first column of first row (or null).
     * Useful for COUNT(*), EXISTS, MIN/MAX, etc.
     * @param string $sql
     * @param array<int|string, mixed> $params
     * @return mixed|null
     */
    public function value(string $sql, array $params = []): mixed
    {
        $st = $this->prepareAndExecute($sql, $params);
        $val = $st->fetchColumn(0);
        return $val === false ? null : $val;
    }

    /**
     * Execute INSERT/UPDATE/DELETE; returns:
     *  - If lastInsertId is non-empty → that id (string)
     *  - Else → affected row count (int)
     *  - False will never be returned (exceptions are thrown on errors)
     *
     * NOTE: In this project we typically rely on lastInsertId for INSERTs.
     *
     * @param string $sql
     * @param array<int|string, mixed> $params
     * @return int|string
     */
    public function execStmt(string $sql, array $params = []): int|string
    {
        $st = $this->prepareAndExecute($sql, $params);
        $id = $this->pdo->lastInsertId();
        if ($id !== '0' && $id !== '') {
            return $id;
        }
        return $st->rowCount();
    }

    /**
     * Run a callable inside a transaction. Automatically commits on success
     * or rolls back on exception, then rethrows the exception.
     *
     * @template T
     * @param callable(self):T $fn
     * @return T
     * @throws \Throwable
     */
    public function transaction(callable $fn)
    {
        $this->begin();
        try {
            /** @var T $result */
            $result = $fn($this);
            $this->commit();
            return $result;
        } catch (\Throwable $e) {
            $this->rollBack();
            throw $e;
        }
    }

    /** Begin transaction (idempotent-safe). */
    public function begin(): void
    {
        if (!$this->pdo->inTransaction()) {
            $this->pdo->beginTransaction();
        }
    }

    /** Commit transaction if in progress. */
    public function commit(): void
    {
        if ($this->pdo->inTransaction()) {
            $this->pdo->commit();
        }
    }

    /** Roll back transaction if in progress. */
    public function rollBack(): void
    {
        if ($this->pdo->inTransaction()) {
            $this->pdo->rollBack();
        }
    }

    // ---------------------------------------------------------------------
    // Internals
    // ---------------------------------------------------------------------

    /**
     * Prepare and execute a statement with positional or named parameters.
     * Performs light type-binding for ints/bools to help MySQL optimize.
     */
    private function prepareAndExecute(string $sql, array $params)
    {
        $st = $this->pdo->prepare($sql);

        // Bind parameters with basic type hints
        foreach ($params as $key => $val) {
            $paramType = PDO::PARAM_STR;

            // Detect integer/boolean/null types
            if (is_int($val)) {
                $paramType = PDO::PARAM_INT;
            } elseif (is_bool($val)) {
                $paramType = PDO::PARAM_BOOL;
            } elseif ($val === null) {
                $paramType = PDO::PARAM_NULL;
            }

            // Named (:name) vs positional (?) support
            $paramKey = is_int($key) ? $key + 1 : (str_starts_with((string)$key, ':') ? (string)$key : ':' . $key);

            $st->bindValue($paramKey, $val, $paramType);
        }

        $st->execute();
        return $st;
    }
}
