<?php

declare(strict_types=1);

namespace Phlix\Hub\Tests\Support\Updates;

/**
 * Mutable box for the VERSION marker body a fetcher double must return at
 * CALL time. S306: replaces a `&$this->marker` property reference in
 * AdminUpdatesRouteRegistrationTest — Psalm cannot analyse references to
 * object properties (UnsupportedPropertyReferenceUsage), and a holder object
 * expresses the same late-read semantics without the reference.
 */
final class LiveMarkerHolder
{
    public string $body = '0.5.0';
}
