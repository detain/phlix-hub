<?php

/**
 * S329 — seed the hub's database for the live MCP-client E2E session.
 *
 * ## What this does, and why it must exist
 *
 * The live-session suite (`tests/E2E/Mcp/McpClientSseE2ETest.php`) needs two
 * things the CI job's MySQL service does not have by default: a USER row (the
 * `mcp_tokens.user_id` column is a foreign key onto `users`) and MCP personal
 * access tokens. This script creates both through the REAL code paths —
 * `McpTokenService::mint()` for the tokens, exactly the path a hub user's own
 * PAT goes through — rather than by hand-writing `token_hash` rows that might
 * not match what the service would have stored.
 *
 * Two tokens are minted:
 *
 *  - `full`     — `McpScopes::all()`, used by the positive session cases;
 *  - `readonly` — `McpScopes::readOnly()` (NO `mcp:playback:control`), used by
 *    the denied-scope case, which asserts that calling `playback_control` with
 *    it comes back `mcp.scope_denied` (fail closed).
 *
 * Every run DELETES the previous run's rows and mints fresh ones: PATs are
 * random 256-bit values, so re-running the job must not accumulate dead rows
 * or collide with a previous run's tokens.
 *
 * Output: `var/mcp-e2e-tokens.json` — `{"full_token","readonly_token","user_id"}`.
 * The plaintext tokens are PRINTED as well: they are throwaway credentials in
 * a throwaway CI database, and printing them makes a failing run diagnosable
 * from the log.
 *
 * Fails hard (exit 1) when the database is unreachable, a mint does not round-
 * trip through `McpTokenService::validate()`, or the tokens file cannot be
 * written. A gate that cannot seed cannot measure.
 *
 * @package Phlix\Hub
 */

declare(strict_types=1);

use Phlix\Hub\Mcp\McpScopes;
use Phlix\Hub\Mcp\McpTokenService;
use Workerman\MySQL\Connection;

$autoload = __DIR__ . '/../vendor/autoload.php';
if (!is_file($autoload)) {
    fwrite(STDERR, "::error::S329 MCP E2E seed: vendor/autoload.php is missing; run composer install.\n");
    exit(1);
}
require_once $autoload;

$fail = static function (string $message): never {
    fwrite(STDERR, '::error::S329 MCP E2E seed: ' . $message . "\n");
    exit(1);
};

$say = static function (string $message): void {
    fwrite(STDOUT, $message . "\n");
};

$env = static function (string $key, string $default): string {
    $value = getenv($key);

    return $value === false || $value === '' ? $default : $value;
};

$host = $env('HUB_DB_HOST', '127.0.0.1');
$port = (int) $env('HUB_DB_PORT', '3306');
$user = $env('HUB_DB_USER', 'phlix_hub');
$password = $env('HUB_DB_PASSWORD', 'phlix_hub');
$database = $env('HUB_DB_NAME', 'phlix_hub');

$say(sprintf('connecting to MySQL at %s:%d as %s (db %s)', $host, $port, $user, $database));

try {
    $db = new Connection($host, $port, $user, $password, $database);
} catch (\Throwable $e) {
    $fail('could not connect to the database: ' . $e->getMessage());
}

// A fixed, namespaced identity so the E2E run is deterministic and can never
// touch a real account. `is_admin = 1` is not required by the MCP surface —
// the PAT auth path never consults it — but it costs nothing and keeps the
// seed row unmistakable in any later audit.
$userId = '00000000-0000-4000-8000-00000000e2e1';
$username = 's329_e2e_user';
$email = 's329-e2e@phlix.local';

try {
    $db->query('DELETE FROM mcp_tokens WHERE user_id = :user_id', ['user_id' => $userId]);
    $db->query('DELETE FROM users WHERE id = :user_id', ['user_id' => $userId]);
    $db->query(
        'INSERT INTO users (id, username, email, password_hash, display_name)'
            . ' VALUES (:id, :username, :email, :password_hash, :display_name)',
        [
            'id' => $userId,
            'username' => $username,
            'email' => $email,
            'password_hash' => password_hash('s329-e2e-password', PASSWORD_ARGON2ID),
            'display_name' => 'S329 E2E user',
        ],
    );
} catch (\Throwable $e) {
    $fail('could not seed the user row: ' . $e->getMessage());
}

$service = new McpTokenService($db);

try {
    $full = $service->mint($userId, 'S329 E2E full', McpScopes::all());
    $readonly = $service->mint($userId, 'S329 E2E read-only', McpScopes::readOnly());
} catch (\Throwable $e) {
    $fail('could not mint the MCP tokens: ' . $e->getMessage());
}

$fullToken = (string) ($full['token'] ?? '');
$readonlyToken = (string) ($readonly['token'] ?? '');
if ($fullToken === '' || $readonlyToken === '') {
    $fail('mint() returned an empty plaintext token');
}

// Round-trip through the REAL validation path: a token that cannot validate
// against the same database would make every later probe 401 with a puzzle
// that looks like a hub problem but is a seed problem. Fail here instead.
if ($service->validate($fullToken) === null) {
    $fail('the minted full token did not validate back — seed/database mismatch');
}
if ($service->validate($readonlyToken) === null) {
    $fail('the minted read-only token did not validate back — seed/database mismatch');
}

$outPath = __DIR__ . '/../var/mcp-e2e-tokens.json';
$payload = [
    'full_token' => $fullToken,
    'readonly_token' => $readonlyToken,
    'user_id' => $userId,
];
$written = file_put_contents(
    $outPath,
    json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
);
if ($written === false) {
    $fail(sprintf('could not write %s', $outPath));
}
// The file holds plaintext PATs. 0600 is one line and removes the
// "world-readable credentials" smell if the job workspace is ever inspected.
if (!chmod($outPath, 0600)) {
    $fail(sprintf('could not chmod 0600 %s', $outPath));
}

$say('seeded user ' . $userId . ' (' . $username . ')');
$say('full token     : ' . $fullToken . '  scopes=' . implode(' ', $full['scopes'] ?? []));
$say('readonly token : ' . $readonlyToken . '  scopes=' . implode(' ', $readonly['scopes'] ?? []));
$say('wrote ' . $outPath . ' (' . $written . ' bytes)');
$say('S329 MCP E2E seed OK — both tokens validate through McpTokenService::validate().');

exit(0);
