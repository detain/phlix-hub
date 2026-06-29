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
     * @throws \RuntimeException When $name, $type, or $value fails validation.
     */
    public function addRecord(string $zone, string $name, string $type, string $value): void
    {
        $this->validateRecordLabel($name);
        $this->validateRecordType($type);
        $this->validateRecordValue($value);

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
     * Validate a DNS record label (name) against RFC 1123 / LDH rules.
     *
     * A label must:
     *  - Be 1–63 characters long
     *  - Contain only LDH (letters, digits, hyphens)
     *  - Not start or end with a hyphen
     *
     * @param string $label The record label to validate.
     *
     * @throws \RuntimeException When the label is invalid.
     */
    private function validateRecordLabel(string $label): void
    {
        if ($label === '' || strlen($label) > 63) {
            throw new \RuntimeException('DNS label must be 1–63 characters');
        }

        // RFC 1123: each label is alphanumerics and hyphens, not starting/ending with hyphen.
        if (!preg_match('/^[a-z0-9]([a-z0-9-]{0,61}[a-z0-9])?$/i', $label)) {
            throw new \RuntimeException('DNS label contains invalid characters or starts/ends with a hyphen');
        }
    }

    /**
     * Validate a DNS record type string.
     *
     * Permitted types are the standard DNS record types that are safe to
     * write into a zone file (alphanumeric, 1–10 chars).
     *
     * @param string $type The record type to validate.
     *
     * @throws \RuntimeException When the type is invalid.
     */
    private function validateRecordType(string $type): void
    {
        if ($type === '' || strlen($type) > 10) {
            throw new \RuntimeException('DNS record type must be 1–10 characters');
        }

        // Restrict to well-known DNS record types to prevent injecting
        // arbitrary text that could be interpreted as directives.
        static $allowed = [
            'A' => true, 'AAAA' => true, 'CNAME' => true, 'TXT' => true,
            'MX' => true, 'NS' => true, 'SOA' => true, 'PTR' => true,
            'SRV' => true, 'SPF' => true, 'CAA' => true, 'DNAME' => true,
            'TLSA' => true, 'URI' => true,
        ];

        if (!isset($allowed[strtoupper($type)])) {
            throw new \RuntimeException('DNS record type is not a recognised standard type');
        }
    }

    /**
     * Validate a DNS record value has no control characters.
     *
     * Prevents injecting extra zone file lines via embedded CR/LF in the value.
     *
     * @param string $value The record value to validate.
     *
     * @throws \RuntimeException When the value contains control characters.
     */
    private function validateRecordValue(string $value): void
    {
        if (strpbrk($value, "\r\n") !== false) {
            throw new \RuntimeException('DNS record value must not contain newline characters');
        }
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
