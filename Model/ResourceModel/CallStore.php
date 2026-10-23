<?php
/**
 * Copyright (c) 2026. Volodymyr Hryvinskyi. All rights reserved.
 * Author: Volodymyr Hryvinskyi <volodymyr@hryvinskyi.com>
 * GitHub: https://github.com/hryvinskyi
 */

declare(strict_types=1);

namespace Hryvinskyi\McpServerActivityLog\Model\ResourceModel;

use Magento\Framework\App\ResourceConnection;
use Magento\Framework\Serialize\Serializer\Json;

/**
 * The MCP call of an activity, one row per activity, removed with its activity
 *
 * @phpstan-type CallDetail array{
 *     occurredAtMs: int,
 *     ability: string,
 *     clientName: string,
 *     clientVersion: string,
 *     transport: string,
 *     source: string|null,
 *     sourceReference: string|null,
 *     access: string|null,
 *     aclResources: list<string>,
 *     outcome: string,
 *     denialReason: string,
 *     message: string|null,
 *     arguments: string|null,
 *     result: string|null,
 *     truncated: bool
 * }
 */
class CallStore
{
    public const TABLE = 'hryvinskyi_mcp_activity_call';

    /**
     * @param ResourceConnection $resourceConnection
     * @param Json $json
     */
    public function __construct(
        private readonly ResourceConnection $resourceConnection,
        private readonly Json $json
    ) {
    }

    /**
     * Store the call of an activity
     *
     * @param int $activityId
     * @param CallDetail $call
     * @return void
     */
    public function save(int $activityId, array $call): void
    {
        $this->resourceConnection->getConnection()->insert($this->table(), [
            'activity_id' => $activityId,
            'occurred_at_ms' => $call['occurredAtMs'],
            'ability' => mb_substr($call['ability'], 0, 255),
            'client_name' => mb_substr($call['clientName'], 0, 255),
            'client_version' => mb_substr($call['clientVersion'], 0, 64),
            'transport' => mb_substr($call['transport'], 0, 16),
            'source' => $call['source'],
            'source_reference' => $call['sourceReference'] === null
                ? null
                : mb_substr($call['sourceReference'], 0, 255),
            'access' => $call['access'],
            'acl_resources' => $this->json->serialize($call['aclResources']),
            'outcome' => $call['outcome'],
            'denial_reason' => mb_substr($call['denialReason'], 0, 64),
            'message' => $call['message'],
            'arguments' => $call['arguments'],
            'result' => $call['result'],
            'is_truncated' => (int) $call['truncated'],
        ]);
    }

    /**
     * The calls of the activities that have one, keyed by activity id, read in one query
     *
     * @param list<int> $activityIds
     * @return array<int, CallDetail>
     */
    public function findMany(array $activityIds): array
    {
        if ($activityIds === []) {
            return [];
        }

        $connection = $this->resourceConnection->getConnection();
        $calls = [];
        foreach ($connection->fetchAll(
            $connection->select()->from($this->table())->where('activity_id IN (?)', $activityIds)
        ) as $row) {
            if (is_array($row) && is_numeric($row['activity_id'] ?? null)) {
                $calls[(int) $row['activity_id']] = $this->callOf($row);
            }
        }

        return $calls;
    }

    /**
     * The clients that called since a moment, with how many calls each made, the busiest first
     *
     * @param int $sinceMs
     * @return list<array{name: string, version: string, calls: int}>
     */
    public function clientsSince(int $sinceMs): array
    {
        $connection = $this->resourceConnection->getConnection();
        $clients = [];
        foreach ($connection->fetchAll(
            $connection->select()
                ->from($this->table(), ['client_name', 'client_version', 'calls' => 'COUNT(*)'])
                ->where('occurred_at_ms >= ?', $sinceMs)
                ->group(['client_name', 'client_version'])
                ->order('calls DESC')
        ) as $row) {
            if (is_array($row)) {
                $clients[] = [
                    'name' => $this->text($row['client_name'] ?? null),
                    'version' => $this->text($row['client_version'] ?? null),
                    'calls' => is_numeric($row['calls'] ?? null) ? (int) $row['calls'] : 0,
                ];
            }
        }

        return $clients;
    }

    /**
     * The abilities called most in a period, with their calls and how many of them were refused or failed
     *
     * @param int $fromMs
     * @param int $toMs
     * @param int $limit
     * @param string|null $outcome Only calls that ended so, or every call when null
     * @return list<array{ability: string, calls: int, refused: int, failed: int, reason: string}>
     */
    public function abilitiesBetween(int $fromMs, int $toMs, int $limit, ?string $outcome = null): array
    {
        $connection = $this->resourceConnection->getConnection();
        $select = $connection->select()
            ->from($this->table(), [
                'ability',
                'calls' => 'COUNT(*)',
                'refused' => "SUM(outcome IN ('denied', 'needs_confirmation'))",
                'failed' => "SUM(outcome = 'failed')",
                'reason' => 'MAX(denial_reason)',
            ])
            ->where('occurred_at_ms >= ?', $fromMs)
            ->where('occurred_at_ms < ?', $toMs)
            ->group('ability')
            ->order(['calls DESC', 'ability ASC'])
            ->limit($limit);
        if ($outcome !== null) {
            $select->where('outcome = ?', $outcome);
        }

        $abilities = [];
        foreach ($connection->fetchAll($select) as $row) {
            if (is_array($row)) {
                $abilities[] = [
                    'ability' => $this->text($row['ability'] ?? null),
                    'calls' => is_numeric($row['calls'] ?? null) ? (int) $row['calls'] : 0,
                    'refused' => is_numeric($row['refused'] ?? null) ? (int) $row['refused'] : 0,
                    'failed' => is_numeric($row['failed'] ?? null) ? (int) $row['failed'] : 0,
                    'reason' => $this->text($row['reason'] ?? null),
                ];
            }
        }

        return $abilities;
    }

    /**
     * A stored row as a call
     *
     * @param array<mixed> $row
     * @return CallDetail
     */
    private function callOf(array $row): array
    {
        $acl = $row['acl_resources'] ?? null;
        $acl = is_string($acl) && $acl !== '' ? $this->json->unserialize($acl) : [];

        return [
            'occurredAtMs' => is_numeric($row['occurred_at_ms'] ?? null) ? (int) $row['occurred_at_ms'] : 0,
            'ability' => $this->text($row['ability'] ?? null),
            'clientName' => $this->text($row['client_name'] ?? null),
            'clientVersion' => $this->text($row['client_version'] ?? null),
            'transport' => $this->text($row['transport'] ?? null),
            'source' => $this->nullableText($row['source'] ?? null),
            'sourceReference' => $this->nullableText($row['source_reference'] ?? null),
            'access' => $this->nullableText($row['access'] ?? null),
            'aclResources' => is_array($acl) ? array_values(array_filter($acl, 'is_string')) : [],
            'outcome' => $this->text($row['outcome'] ?? null),
            'denialReason' => $this->text($row['denial_reason'] ?? null),
            'message' => $this->nullableText($row['message'] ?? null),
            'arguments' => $this->nullableText($row['arguments'] ?? null),
            'result' => $this->nullableText($row['result'] ?? null),
            'truncated' => (bool) ($row['is_truncated'] ?? false),
        ];
    }

    /**
     * The table
     *
     * @return string
     */
    private function table(): string
    {
        return $this->resourceConnection->getTableName(self::TABLE);
    }

    /**
     * A stored value as text
     *
     * @param mixed $value
     * @return string
     */
    private function text(mixed $value): string
    {
        return is_scalar($value) ? (string) $value : '';
    }

    /**
     * A stored value as text, or null when there is none
     *
     * @param mixed $value
     * @return string|null
     */
    private function nullableText(mixed $value): ?string
    {
        return is_scalar($value) && (string) $value !== '' ? (string) $value : null;
    }
}
