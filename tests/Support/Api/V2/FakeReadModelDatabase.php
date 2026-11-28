<?php

declare(strict_types=1);

namespace Tests\Support\Api\V2;

use Illuminate\Database\DatabaseManager;
use Illuminate\Support\Facades\DB;
use LogicException;
use RuntimeException;

/**
 * Stands in for the DB facade where the v2 read-model repositories ask PostgreSQL questions SQLite cannot answer.
 * Answers are chosen by a fragment of the SQL; the first fragment that matches wins. A query nobody answered fails loudly.
 */
final class FakeReadModelDatabase extends DatabaseManager
{
    /** @var list<array{0: string, 1: list<object>}> */
    private array $answers = [];

    /** @var list<string> */
    private array $queries = [];

    private bool $lenient = false;

    private ?string $driver = null;

    private ?string $failure = null;

    /** @var list<string> */
    private array $statements = [];

    public static function install(): self
    {
        $fake = new self(app(), app('db.factory'));
        DB::swap($fake);
        app()->instance('db', $fake);

        return $fake;
    }

    /** @param list<array<string, mixed>> $rows */
    public function answer(string $fragment, array $rows): self
    {
        $this->answers[] = [$fragment, array_map(static fn(array $row): object => (object) $row, $rows)];

        return $this;
    }

    /** Unanswered queries return no rows instead of failing; single-row reads return an all-null, zero-count row. */
    public function lenient(): self
    {
        $this->lenient = true;

        return $this;
    }

    /** Reports the given driver name from connection(), so code that refuses non-PostgreSQL connections runs on SQLite. */
    public function driver(string $name): self
    {
        $this->driver = $name;

        return $this;
    }

    /** Every DDL or write statement fails with a RuntimeException of this message. */
    public function failStatementsWith(string $message): self
    {
        $this->failure = $message;

        return $this;
    }

    /** @return list<string> the DDL and writes that were sent (unprepared, statement) */
    public function statements(): array
    {
        return $this->statements;
    }

    public function connection($name = null)
    {
        $driver = $this->driver;

        return $driver === null ? parent::connection($name) : new class ($driver) {
            public function __construct(private readonly string $driver) {}

            public function getDriverName(): string
            {
                return $this->driver;
            }
        };
    }

    public function unprepared(string $sql): bool
    {
        $this->failure === null || throw new RuntimeException($this->failure);
        $this->statements[] = $sql;

        return true;
    }

    /** @param list<mixed> $bindings */
    public function statement(string $sql, array $bindings = []): bool
    {
        $this->statements[] = $sql;

        return true;
    }

    public function transaction(callable $callback): mixed
    {
        return $callback();
    }

    /** @return list<string> */
    public function queries(): array
    {
        return $this->queries;
    }

    /**
     * @param list<mixed> $bindings
     * @return list<object>
     */
    public function select(string $sql, array $bindings = []): array
    {
        $this->queries[] = $sql;

        return $this->find($sql) ?? ($this->lenient ? [] : $this->unanswered($sql));
    }

    /** @param list<mixed> $bindings */
    public function selectOne(string $sql, array $bindings = []): ?object
    {
        $this->queries[] = $sql;
        $rows = $this->find($sql);

        if ($rows === null) {
            return $this->lenient ? (object) ['n' => 0, 'lo' => null, 'hi' => null] : $this->unanswered($sql);
        }

        return $rows[0] ?? null;
    }

    /** @return list<object>|null */
    private function find(string $sql): ?array
    {
        foreach ($this->answers as [$fragment, $rows]) {
            if (str_contains($sql, $fragment)) {
                return $rows;
            }
        }

        return null;
    }

    private function unanswered(string $sql): never
    {
        throw new LogicException('No fake answer for: ' . $sql);
    }
}
