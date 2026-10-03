<?php

declare(strict_types=1);

/**
 * Invite-link configuration.
 *
 * Optional env:
 *   HUB_INVITE_DEFAULT_EXPIRY — default invite lifetime in seconds when the
 *                               creator omits `expires_in` (default 604800 =
 *                               7 days, the literal InviteLinkController has
 *                               shipped with since creation). `0` means
 *                               invites never expire by default.
 *
 * Load-bearing twin: `invite.default_expiry_seconds` in
 * {@see \Phlix\Hub\Hub\HubSettingsRepository::ALLOWED_KEYS} resolves this
 * file's `default_expiry_seconds` via getDefault(); HubSettingsAllowListTest
 * rule 4 fails CI if the key is renamed or removed. LIVE consumer:
 * InviteLinkController::handleCreate() consults the effective value only when
 * the request body omits `expires_in` — an explicit body value always wins.
 *
 * @package Phlix\Hub
 */

return [
    'default_expiry_seconds' => is_numeric($v = getenv('HUB_INVITE_DEFAULT_EXPIRY')) ? (int) $v : 604800,
];
