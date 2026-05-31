<?php

declare(strict_types=1);

namespace Phlix\Hub\Http\Controllers;

use Phlix\Hub\Hub\AuditLogRepository;
use Phlix\Hub\Http\Request;
use Phlix\Hub\Http\Response;

/**
 * Audit log viewer API — hub admin only.
 *
 * @package Phlix\Hub\Http\Controllers
 */
final class AuditLogController
{
    public function __construct(
        private readonly AuditLogRepository $repo,
    ) {
    }

    /**
     * GET /api/v1/me/audit-logs
     *
     * Query params: event, user_id, resource, action, success (0|1),
     *               from (unix ts), to (unix ts), limit (1-200), offset
     *
     * @return Response
     */
    public function index(Request $request): Response
    {
        /** @var array<string, mixed> $params */
        $params = $request->query;

        $filters = [];
        if (isset($params['event']) && is_string($params['event']) && $params['event'] !== '') {
            $filters['event'] = $params['event'];
        }
        if (isset($params['user_id']) && is_string($params['user_id']) && $params['user_id'] !== '') {
            $filters['user_id'] = $params['user_id'];
        }
        if (isset($params['resource']) && is_string($params['resource']) && $params['resource'] !== '') {
            $filters['resource'] = $params['resource'];
        }
        if (isset($params['action']) && is_string($params['action']) && $params['action'] !== '') {
            $filters['action'] = $params['action'];
        }
        $successVal = $params['success'] ?? null;
        if (is_string($successVal) && in_array($successVal, ['0', '1'], true)) {
            $filters['success'] = (bool) (int) $successVal;
        }
        if (isset($params['from']) && is_numeric($params['from'])) {
            $filters['from'] = (int) $params['from'];
        }
        if (isset($params['to']) && is_numeric($params['to'])) {
            $filters['to'] = (int) $params['to'];
        }
        if (isset($params['limit']) && is_numeric($params['limit'])) {
            $filters['limit'] = (int) $params['limit'];
        }
        if (isset($params['offset']) && is_numeric($params['offset'])) {
            $filters['offset'] = (int) $params['offset'];
        }

        $result = $this->repo->find($filters);

        return (new Response())->json([
            'logs' => $result['entries'],
            'total' => $result['total'],
            'limit' => (int) ($filters['limit'] ?? 50),
            'offset' => (int) ($filters['offset'] ?? 0),
        ]);
    }
}
