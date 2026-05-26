<?php

declare(strict_types=1);

namespace Phlix\Hub\Hub\Dns;

/**
 * Static zone file writer for DNS management.
 *
 * Writes zone files to a directory for later propagation to DNS servers.
 * This is a pluggable interface - Cloudflare/Route53 implementations can
 * be added later without changing the calling code.
 *
 * @package Phlix\Hub\Hub\Dns
 */
class StaticZoneManager
{
    /**
     * @param string $zoneDir Directory where zone files are written.
     */
    public function __construct(
        private readonly string $zoneDir,
    ) {
    }

    /**
     * Add a DNS record to a zone file.
     *
     * @param string $zone  Zone name (e.g. "phlix.media").
     * @param string $name   Record name (e.g. "abc123" for abc123.phlix.media).
     * @param string $type   Record type (A, AAAA, CNAME, TXT, etc.).
     * @param string $value Record value.
     *
     * @return void
     *
     */
    public function addRecord(string $zone, string $name, string $type, string $value): void
    {
        $zoneFile = $this->getZonePath($zone);
        $this->ensureZoneDirExists($zone);

        $ttl = 300;
        $line = sprintf('%s.%s. %d IN %s %s', $name, $zone, $ttl, $type, $value);

        $content = '';
        if (file_exists($zoneFile)) {
            $content = (string) file_get_contents($zoneFile);
        }

        if (str_contains($content, $line)) {
            return;
        }

        file_put_contents($zoneFile, $content . $line . "\n", LOCK_EX);
    }

    /**
     * Remove a DNS record from a zone file.
     *
     * @param string $zone  Zone name.
     * @param string $name  Record name.
     * @param string $type  Record type.
     *
     * @return void
     *
     */
    public function removeRecord(string $zone, string $name, string $type): void
    {
        $zoneFile = $this->getZonePath($zone);

        if (!file_exists($zoneFile)) {
            return;
        }

        $content = (string) file_get_contents($zoneFile);
        $nameQuoted = preg_quote($name, '/');
        $zoneQuoted = preg_quote($zone, '/');
        $typeQuoted = preg_quote($type, '/');
        $pattern = sprintf('/^%s\.%s\..*%s.*/m', $nameQuoted, $zoneQuoted, $typeQuoted);
        $newContent = preg_replace($pattern, '', $content);

        if ($newContent !== null) {
            file_put_contents($zoneFile, $newContent, LOCK_EX);
        }
    }

    /**
     * Update the SOA record for a zone.
     *
     * @param string $zone Zone name.
     *
     * @return void
     *
     */
    public function updateSoa(string $zone): void
    {
        $zoneFile = $this->getZonePath($zone);

        if (!file_exists($zoneFile)) {
            return;
        }

        $content = (string) file_get_contents($zoneFile);
        $serial = date('YmdHis');

        if (preg_match('/(\d+\s+IN\s+SOA\s+.+?\s+)([0-9]+)/s', $content, $matches)) {
            $content = str_replace($matches[2], $serial, $content);
            file_put_contents($zoneFile, $content, LOCK_EX);
        }
    }

    /**
     * Get the path to a zone file.
     *
     * @param string $zone Zone name.
     *
     * @return string Absolute path to zone file.
     */
    private function getZonePath(string $zone): string
    {
        return $this->zoneDir . '/' . $zone . '.zone';
    }

    /**
     * Ensure the zone directory exists.
     *
     * @param string $zone Zone name.
     *
     * @return void
     */
    private function ensureZoneDirExists(string $zone): void
    {
        $dir = dirname($this->getZonePath($zone));
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }
    }
}
