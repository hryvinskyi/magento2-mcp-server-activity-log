<?php
/**
 * Copyright (c) 2026. Volodymyr Hryvinskyi. All rights reserved.
 * Author: Volodymyr Hryvinskyi <volodymyr@hryvinskyi.com>
 * GitHub: https://github.com/hryvinskyi
 */

declare(strict_types=1);

namespace Hryvinskyi\McpServerActivityLog\Model\Dashboard;

use Hryvinskyi\ActivityLog\Api\DashboardWidgetInterface;
use Hryvinskyi\ActivityLog\Api\Data\DashboardQueryInterface;
use Hryvinskyi\ActivityLog\Api\Data\WidgetViewType;
use Hryvinskyi\McpServerActivityLog\Model\McpChannel;
use Hryvinskyi\McpServerActivityLog\Model\ResourceModel\CallStore;

/**
 * The abilities MCP calls were refused for most often in the period, and why
 *
 * A refusal names its rule: the caller's ACL, the deny-list, read-only mode, an ability open to anyone, an argument
 * guard or another module's rule.
 */
class DeniedCallsWidget implements DashboardWidgetInterface
{
    private const ROWS = 10;

    /**
     * @param CallStore $store
     */
    public function __construct(
        private readonly CallStore $store
    ) {
    }

    /**
     * @inheritDoc
     */
    public function getCode(): string
    {
        return 'mcp_denied_calls';
    }

    /**
     * @inheritDoc
     */
    public function getTitle(): string
    {
        return (string) __('Denied MCP calls');
    }

    /**
     * @inheritDoc
     */
    public function getSubtitle(): ?string
    {
        return (string) __('Abilities refused most often, and why');
    }

    /**
     * @inheritDoc
     */
    public function getModuleTag(): ?string
    {
        return 'MCP';
    }

    /**
     * @inheritDoc
     */
    public function getViewType(): WidgetViewType
    {
        return WidgetViewType::Table;
    }

    /**
     * @inheritDoc
     */
    public function getSpan(): int
    {
        return 1;
    }

    /**
     * @inheritDoc
     */
    public function getAclResource(): ?string
    {
        return null;
    }

    /**
     * @inheritDoc
     */
    public function getData(DashboardQueryInterface $query): array
    {
        $abilities = in_array(McpChannel::CODE, $query->getChannels(), true)
            ? $this->store->abilitiesBetween($query->getFromMs(), $query->getToMs(), self::ROWS, 'denied')
            : [];

        $rows = [];
        foreach ($abilities as $ability) {
            $rows[] = [
                'cells' => [
                    'ability' => $ability['ability'],
                    'reason' => $ability['reason'],
                    'calls' => number_format($ability['calls']),
                ],
            ];
        }

        return [
            'columns' => [
                ['key' => 'ability', 'label' => (string) __('Ability')],
                ['key' => 'reason', 'label' => (string) __('Refused by')],
                ['key' => 'calls', 'label' => (string) __('Calls'), 'align' => 'right'],
            ],
            'rows' => $rows,
        ];
    }
}
