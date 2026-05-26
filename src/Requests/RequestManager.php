<?php

declare(strict_types=1);

namespace Phlix\Hub\Requests;

use Phlix\Hub\Common\Logger\LogChannels;
use Phlix\Hub\Common\Logger\LoggerFactory;
use Phlix\Hub\Common\Logger\StructuredLogger;
use Phlix\Shared\Arr\ArrClientFactory;
use Phlix\Shared\Arr\RadarrClient;
use Phlix\Shared\Arr\SonarrClient;
use Workerman\MySQL\Connection;

/**
 * Manages the user media-request lifecycle on the hub.
 *
 * Backs the `/api/v1/me/requests` and `/api/v1/admin/requests` endpoints
 * by persisting requests to the hub `requests` table. When a request is
 * approved, the manager talks to Sonarr/Radarr via {@see ArrClientFactory}
 * to actually pull the title.
 *
 * @package Phlix\Hub\Requests
 *
 * @phpstan-type RequestRow array{
 *     id: string,
 *     user_id: string,
 *     type: string,
 *     tmdb_id: int,
 *     title: string,
 *     poster_url: ?string,
 *     season: ?int,
 *     episode: ?int,
 *     status: string,
 *     rejection_reason: ?string,
 *     created_at: string,
 *     updated_at: string
 * }
 */
class RequestManager
{
    private StructuredLogger $logger;

    /**
     * @param Connection            $db               Hub MySQL connection.
     * @param ArrClientFactory      $arrClientFactory Factory for Sonarr/Radarr clients.
     * @param StructuredLogger|null $logger           Optional logger; defaults to HUB channel.
     */
    public function __construct(
        private readonly Connection $db,
        private readonly ArrClientFactory $arrClientFactory,
        ?StructuredLogger $logger = null,
    ) {
        $this->logger = $logger ?? LoggerFactory::get(LogChannels::HUB);
    }

    /**
     * Create a new media request.
     *
     * @param string      $userId    User UUID submitting the request.
     * @param string      $type      Either 'movie' or 'series'.
     * @param int         $tmdbId    TMDB id of the requested media.
     * @param string      $title     Display title.
     * @param string|null $posterUrl Optional poster URL.
     * @param int|null    $season    Optional season number (series only).
     * @param int|null    $episode   Optional episode number (series only).
     *
     * @return RequestRow The newly-created request row.
     *
     * @throws \RuntimeException When the row cannot be re-read after INSERT.
     */
    public function createRequest(
        string $userId,
        string $type,
        int $tmdbId,
        string $title,
        ?string $posterUrl = null,
        ?int $season = null,
        ?int $episode = null
    ): array {
        $id = $this->generateUuid();

        $this->db->query(
            'INSERT INTO requests
                (id, user_id, type, tmdb_id, title, poster_url, season, episode, status)
             VALUES
                (:id, :user_id, :type, :tmdb_id, :title, :poster_url, :season, :episode, \'pending\')',
            [
                'id'         => $id,
                'user_id'    => $userId,
                'type'       => $type,
                'tmdb_id'    => $tmdbId,
                'title'      => $title,
                'poster_url' => $posterUrl,
                'season'     => $season,
                'episode'    => $episode,
            ]
        );

        $rows = $this->db->query('SELECT * FROM requests WHERE id = :id', ['id' => $id]);
        if (!is_array($rows) || count($rows) === 0) {
            throw new \RuntimeException('Failed to create request: row not retrievable after insert');
        }

        $firstRow = $rows[0];
        if (!is_array($firstRow)) {
            throw new \RuntimeException('Failed to create request: invalid row returned');
        }

        return $this->hydrateRequest($firstRow);
    }

    /**
     * Approve a pending request. Triggers Sonarr/Radarr to start fetching.
     *
     * @param string $requestId Request UUID to approve.
     *
     * @return bool True when the arr client accepted the add and the row was updated.
     */
    public function approveRequest(string $requestId): bool
    {
        $request = $this->getRequestById($requestId);
        if ($request === null || $request['status'] !== 'pending') {
            return false;
        }

        $success = match ($request['type']) {
            'movie'  => $this->approveMovieRequest($request),
            'series' => $this->approveSeriesRequest($request),
            default  => false,
        };

        if ($success) {
            $this->db->query(
                'UPDATE requests SET status = \'approved\' WHERE id = :id',
                ['id' => $requestId]
            );
        }

        return $success;
    }

    /**
     * Reject a pending request with an optional human-readable reason.
     *
     * @param string $requestId Request UUID to reject.
     * @param string $reason    Optional rejection reason.
     *
     * @return bool True when the row was updated.
     */
    public function rejectRequest(string $requestId, string $reason = ''): bool
    {
        $request = $this->getRequestById($requestId);
        if ($request === null || $request['status'] !== 'pending') {
            return false;
        }

        $this->db->query(
            'UPDATE requests SET status = \'rejected\', rejection_reason = :reason WHERE id = :id',
            ['reason' => $reason, 'id' => $requestId]
        );

        return true;
    }

    /**
     * Look up the status of a request by id.
     *
     * @param string $requestId Request UUID.
     *
     * @return string One of 'pending', 'approved', 'available', 'rejected',
     *                or 'unknown' when not found.
     */
    public function getRequestStatus(string $requestId): string
    {
        $request = $this->getRequestById($requestId);
        if ($request === null) {
            return 'unknown';
        }
        return $request['status'];
    }

    /**
     * List pending requests, optionally restricted to one user.
     *
     * @param string|null $userId Optional user UUID filter.
     *
     * @return list<RequestRow>
     */
    public function listPendingRequests(?string $userId = null): array
    {
        if ($userId !== null) {
            /** @var mixed $rows */
            $rows = $this->db->query(
                'SELECT * FROM requests WHERE user_id = :user_id AND status = \'pending\'
                 ORDER BY created_at DESC',
                ['user_id' => $userId]
            );
        } else {
            /** @var mixed $rows */
            $rows = $this->db->query(
                'SELECT * FROM requests WHERE status = \'pending\' ORDER BY created_at DESC'
            );
        }
        return $this->hydrateRequests($rows);
    }

    /**
     * List requests that have been fulfilled and are now available in a library.
     *
     * @return list<RequestRow>
     */
    public function listAvailableRequests(): array
    {
        /** @var mixed $rows */
        $rows = $this->db->query(
            'SELECT * FROM requests WHERE status = \'available\' ORDER BY updated_at DESC'
        );
        return $this->hydrateRequests($rows);
    }

    /**
     * List every request belonging to a single user, regardless of status.
     *
     * @param string $userId User UUID.
     *
     * @return list<RequestRow>
     */
    public function listUserRequests(string $userId): array
    {
        /** @var mixed $rows */
        $rows = $this->db->query(
            'SELECT * FROM requests WHERE user_id = :user_id ORDER BY created_at DESC',
            ['user_id' => $userId]
        );
        return $this->hydrateRequests($rows);
    }

    /**
     * Look up a single request by id.
     *
     * @param string $requestId Request UUID.
     *
     * @return RequestRow|null
     */
    public function getRequestById(string $requestId): ?array
    {
        $rows = $this->db->query('SELECT * FROM requests WHERE id = :id', ['id' => $requestId]);
        if (!is_array($rows) || count($rows) === 0) {
            return null;
        }
        $firstRow = $rows[0];
        if (!is_array($firstRow)) {
            return null;
        }
        return $this->hydrateRequest($firstRow);
    }

    /**
     * Delete a request unconditionally.
     *
     * @param string $requestId Request UUID.
     *
     * @return bool True if the row existed and was removed.
     */
    public function deleteRequest(string $requestId): bool
    {
        if ($this->getRequestById($requestId) === null) {
            return false;
        }
        $this->db->query('DELETE FROM requests WHERE id = :id', ['id' => $requestId]);
        return true;
    }

    /**
     * Promote an approved request to 'available' (called when the arr client
     * reports the title is fully imported).
     *
     * @param string $requestId Request UUID.
     *
     * @return bool True when the row transitioned.
     */
    public function markAvailable(string $requestId): bool
    {
        $request = $this->getRequestById($requestId);
        if ($request === null || $request['status'] !== 'approved') {
            return false;
        }
        $this->db->query('UPDATE requests SET status = \'available\' WHERE id = :id', ['id' => $requestId]);
        return true;
    }

    /**
     * Approve a movie request by adding it to Radarr.
     *
     * @param RequestRow $request Hydrated request row.
     *
     * @return bool True when Radarr accepted the add.
     */
    private function approveMovieRequest(array $request): bool
    {
        // ArrClientFactory expects a PSR-3 LoggerInterface; StructuredLogger
        // is a Monolog wrapper without that interface, so we pass null and
        // log any failures through $this->logger ourselves.
        $radarrClient = $this->arrClientFactory->createRadarrClient(null);
        if ($radarrClient === null) {
            $this->logger->warning('Cannot approve movie request: Radarr not configured', [
                'request_id' => $request['id'],
            ]);
            return false;
        }

        try {
            $qualityProfiles = $radarrClient->getQualityProfiles();
            if (empty($qualityProfiles)) {
                return false;
            }
            $firstProfile = $qualityProfiles[0];
            $qualityProfileId = isset($firstProfile['id']) && is_numeric($firstProfile['id'])
                ? (int) $firstProfile['id']
                : 1;
            $rootFolder = $this->getRadarrRootFolder($radarrClient);

            $radarrClient->addMovie($request['tmdb_id'], $qualityProfileId, $rootFolder);
            return true;
        } catch (\Throwable $e) {
            $this->logger->warning('Radarr addMovie failed', [
                'request_id' => $request['id'],
                'error'      => $e->getMessage(),
            ]);
            return false;
        }
    }

    /**
     * Approve a series request by adding it to Sonarr.
     *
     * @param RequestRow $request Hydrated request row.
     *
     * @return bool True when Sonarr accepted the add.
     */
    private function approveSeriesRequest(array $request): bool
    {
        // See approveMovieRequest() for the null-logger rationale.
        $sonarrClient = $this->arrClientFactory->createSonarrClient(null);
        if ($sonarrClient === null) {
            $this->logger->warning('Cannot approve series request: Sonarr not configured', [
                'request_id' => $request['id'],
            ]);
            return false;
        }

        try {
            $qualityProfiles = $sonarrClient->getQualityProfiles();
            if (empty($qualityProfiles)) {
                return false;
            }
            $firstProfile = $qualityProfiles[0] ?? null;
            $qualityProfileId = is_array($firstProfile) && isset($firstProfile['id']) && is_numeric($firstProfile['id'])
                ? (int) $firstProfile['id']
                : 1;
            $rootFolder = $this->getSonarrRootFolder($sonarrClient);

            $sonarrClient->addSeries($request['tmdb_id'], $qualityProfileId, $rootFolder);
            return true;
        } catch (\Throwable $e) {
            $this->logger->warning('Sonarr addSeries failed', [
                'request_id' => $request['id'],
                'error'      => $e->getMessage(),
            ]);
            return false;
        }
    }

    /**
     * Inspect Radarr to pick a sensible root folder, falling back to `/movies`.
     */
    private function getRadarrRootFolder(RadarrClient $radarrClient): string
    {
        try {
            $movies = $radarrClient->getMovies();
            if (!empty($movies) && isset($movies[0]['movieFile'])) {
                /** @var mixed $movieFile */
                $movieFile = $movies[0]['movieFile'];
                if (is_array($movieFile) && isset($movieFile['path']) && is_string($movieFile['path'])) {
                    $path = $movieFile['path'];
                    if ($path !== '') {
                        return dirname($path);
                    }
                }
            }
        } catch (\Throwable) {
            // Fall through to default
        }
        return '/movies';
    }

    /**
     * Inspect Sonarr to pick a sensible root-folder index, falling back to 0.
     */
    private function getSonarrRootFolder(SonarrClient $sonarrClient): int
    {
        try {
            $series = $sonarrClient->getSeries();
            if (!empty($series) && isset($series[0]['path'])) {
                return 0;
            }
        } catch (\Throwable) {
            // Fall through to default
        }
        return 0;
    }

    /**
     * Hydrate a single raw DB row into the typed shape used by callers.
     *
     * @param array<mixed, mixed> $row Raw row from {@see Connection::query()}.
     *
     * @return RequestRow
     */
    private function hydrateRequest(array $row): array
    {
        return [
            'id'               => $this->extractString($row, 'id'),
            'user_id'          => $this->extractString($row, 'user_id'),
            'type'             => $this->extractString($row, 'type'),
            'tmdb_id'          => $this->extractInt($row, 'tmdb_id'),
            'title'            => $this->extractString($row, 'title'),
            'poster_url'       => $this->extractNullableString($row, 'poster_url'),
            'season'           => $this->extractNullableInt($row, 'season'),
            'episode'          => $this->extractNullableInt($row, 'episode'),
            'status'           => $this->extractString($row, 'status', 'pending'),
            'rejection_reason' => $this->extractNullableString($row, 'rejection_reason'),
            'created_at'       => $this->extractString($row, 'created_at'),
            'updated_at'       => $this->extractString($row, 'updated_at'),
        ];
    }

    /**
     * Hydrate a list of raw DB rows.
     *
     * @param mixed $rows Raw rows from {@see Connection::query()}.
     *
     * @return list<RequestRow>
     */
    private function hydrateRequests(mixed $rows): array
    {
        if (!is_array($rows)) {
            return [];
        }
        $result = [];
        /** @var mixed $row */
        foreach ($rows as $row) {
            if (is_array($row)) {
                $result[] = $this->hydrateRequest($row);
            }
        }
        return $result;
    }

    /**
     * Coerce a raw row value to string; default when missing or non-string.
     *
     * @param array<mixed, mixed> $row
     */
    private function extractString(array $row, string $key, string $default = ''): string
    {
        /** @var mixed $val */
        $val = $row[$key] ?? null;
        return is_string($val) ? $val : $default;
    }

    /**
     * Coerce a raw row value to int; default when missing or non-numeric.
     *
     * @param array<mixed, mixed> $row
     */
    private function extractInt(array $row, string $key, int $default = 0): int
    {
        /** @var mixed $val */
        $val = $row[$key] ?? null;
        if (is_int($val)) {
            return $val;
        }
        if (is_string($val) && is_numeric($val)) {
            return (int) $val;
        }
        return $default;
    }

    /**
     * Coerce a raw row value to nullable string.
     *
     * @param array<mixed, mixed> $row
     */
    private function extractNullableString(array $row, string $key): ?string
    {
        /** @var mixed $val */
        $val = $row[$key] ?? null;
        return is_string($val) ? $val : null;
    }

    /**
     * Coerce a raw row value to nullable int.
     *
     * @param array<mixed, mixed> $row
     */
    private function extractNullableInt(array $row, string $key): ?int
    {
        /** @var mixed $val */
        $val = $row[$key] ?? null;
        if (is_int($val)) {
            return $val;
        }
        if (is_string($val) && is_numeric($val)) {
            return (int) $val;
        }
        return null;
    }

    /**
     * Generate a UUID v4 string.
     */
    private function generateUuid(): string
    {
        return sprintf(
            '%04x%04x-%04x-%04x-%04x-%04x%04x%04x',
            mt_rand(0, 0xffff),
            mt_rand(0, 0xffff),
            mt_rand(0, 0xffff),
            mt_rand(0, 0x0fff) | 0x4000,
            mt_rand(0, 0x3fff) | 0x8000,
            mt_rand(0, 0xffff),
            mt_rand(0, 0xffff),
            mt_rand(0, 0xffff)
        );
    }
}
