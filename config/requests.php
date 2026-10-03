<?php

declare(strict_types=1);

/**
 * Media-requests subsystem configuration.
 *
 * Optional env:
 *   HUB_REQUESTS_AUTO_APPROVE — "true"/"1"/"yes"/"on" auto-approves every
 *                               newly filed request (default false: requests
 *                               queue as 'pending' for manual approval, the
 *                               behavior shipped since Phase 4).
 *
 * Load-bearing twin: `requests.auto_approve` in
 * {@see \Phlix\Hub\Hub\HubSettingsRepository::ALLOWED_KEYS} resolves this
 * file's `auto_approve` via getDefault(); HubSettingsAllowListTest rule 4
 * fails CI if the key is renamed or removed. LIVE consumer:
 * {@see \Phlix\Hub\Requests\RequestManager::createRequest()} — the flag is
 * re-read per insert; when on, the fresh request is approved best-effort
 * immediately after the 'pending' row lands (approval failure leaves the row
 * pending, exactly as today).
 *
 * @package Phlix\Hub
 */

$raw = getenv('HUB_REQUESTS_AUTO_APPROVE');

return [
    'auto_approve' => $raw !== false
        && !in_array(strtolower(trim($raw)), ['', '0', 'false', 'no', 'off'], true),
];
