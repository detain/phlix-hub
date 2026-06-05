<?php

declare(strict_types=1);

namespace Phlix\Hub\Tests\Support;

use PDO;
use PDOException;
use Workerman\MySQL\Connection;

/**
 * Test double for {@see Connection} that enforces workerman/mysql's real
 * named-parameter binding contract.
 *
 * workerman's {@see Connection::bind()} prepends a `':'` to every param-array
 * key before handing it to `PDO::bindParam()`. A query placeholder `:status`
 * therefore matches a bound key of `status` (no colon) and NOT `:status` —
 * the latter becomes the unbound `::status`, which PDO rejects with
 * `SQLSTATE[HY093] Invalid parameter number: parameter was not defined`. That
 * is exactly the 500 the hub admin dashboard summary endpoint returned in
 * production until the `[':status' => …]` keys were corrected to `['status' =>
 * …]`.
 *
 * PHPUnit's `createMock(Connection::class)` cannot catch that mistake: its
 * `query()` stub ignores the colon and happily returns canned rows, so a test
 * built on it passes against broken code. This double replays the real rule
 * instead — every `:name` placeholder in the SQL must have a colon-free `name`
 * key in `$params`, and no key may carry a leading colon — throwing the same
 * HY093 {@see PDOException} on any violation. Matching queries return canned
 * rows supplied as `['fragment' => rows]` pairs.
 *
 * @psalm-suppress PropertyNotSetInConstructor
 *   The parent {@see Connection} lazily initialises its query-builder
 *   properties on first use; this double overrides {@see self::query()} and
 *   never touches them, so it deliberately skips `parent::__construct()` (no
 *   socket is opened).
 */
final class BindingContractConnection extends Connection
{
    /**
     * Every query the subject issued, in order, for post-hoc assertions.
     *
     * @var list<array{sql: string, params: array<string, mixed>}>
     */
    public array $calls = [];

    /**
     * @param array<string, list<array<string, mixed>>> $responses
     *   Map of SQL fragment => rows returned when the executed SQL contains it.
     *   The first matching fragment (insertion order) wins; no match yields `[]`.
     */
    public function __construct(private readonly array $responses = [])
    {
        // Intentionally no parent::__construct(): this double never opens a
        // real MySQL socket — it only validates binding and returns canned rows.
    }

    /**
     * Validate the binding contract, record the call, and return canned rows.
     *
     * @param array<int|string, mixed>|string|null $params
     * @param int                                  $fetchmode
     *
     * @return list<array<string, mixed>>
     */
    public function query($query = '', $params = null, $fetchmode = PDO::FETCH_ASSOC): array
    {
        $sql = (string) $query;
        /** @var array<int|string, mixed> $bind */
        $bind = is_array($params) ? $params : [];
        self::assertBindable($sql, $bind);

        /** @var array<string, mixed> $recorded */
        $recorded = $bind;
        $this->calls[] = ['sql' => $sql, 'params' => $recorded];

        foreach ($this->responses as $fragment => $rows) {
            if ($fragment !== '' && str_contains($sql, $fragment)) {
                return $rows;
            }
        }
        return [];
    }

    /**
     * Replay workerman's `bind()` + PDO's placeholder matching: throw HY093 on
     * any colon-prefixed key or any placeholder/param-key mismatch.
     *
     * @param array<int|string, mixed> $params
     */
    private static function assertBindable(string $sql, array $params): void
    {
        preg_match_all('/:([A-Za-z_][A-Za-z0-9_]*)/', $sql, $matches);
        $placeholders = array_values(array_unique($matches[1]));
        sort($placeholders);

        $keys = [];
        foreach (array_keys($params) as $key) {
            $name = (string) $key;
            if (str_starts_with($name, ':')) {
                // workerman would bind this as '::name' — never matched by PDO.
                throw new PDOException(
                    'SQLSTATE[HY093]: Invalid parameter number: parameter was not defined'
                    . " (param key '{$name}' must not include the leading colon)",
                );
            }
            $keys[] = $name;
        }
        sort($keys);

        if ($placeholders !== $keys) {
            throw new PDOException(
                'SQLSTATE[HY093]: Invalid parameter number: parameter was not defined'
                . ' (SQL placeholders ' . self::fmt($placeholders)
                . ' != bound keys ' . self::fmt($keys) . ')',
            );
        }
    }

    /**
     * Render a key list for an assertion message.
     *
     * @param list<string> $names
     */
    private static function fmt(array $names): string
    {
        return '[' . implode(', ', $names) . ']';
    }
}
