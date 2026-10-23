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
use Magento\Backend\Model\UrlInterface;

/**
 * The abilities MCP clients called most in the period, with how many calls were refused or failed
 */
class TopAbilitiesWidget implements DashboardWidgetInterface
{
    private const ROWS = 10;

    /**
     * @param CallStore $store
     * @param UrlInterface $url
     */
    public function __construct(
        private readonly CallStore $store,
        private readonly UrlInterface $url
    ) {
    }

    /**
     * @inheritDoc
     */
    public function getCode(): string
    {
        return 'mcp_top_abilities';
    }

    /**
     * @inheritDoc
     */
    public function getTitle(): string
    {
        return (string) __('Top abilities');
    }

    /**
     * @inheritDoc
     */
    public function getSubtitle(): ?string
    {
        return (string) __('MCP calls by ability');
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
            ? $this->store->abilitiesBetween($query->getFromMs(), $query->getToMs(), self::ROWS)
            : [];

        $rows = [];
        foreach ($abilities as $ability) {
            $rows[] = [
                'cells' => [
                    'ability' => $ability['ability'],
                    'calls' => number_format($ability['calls']),
                    'refused' => number_format($ability['refused']),
                    'failed' => number_format($ability['failed']),
                ],
                'href' => $this->url->getUrl(
                    'hryvinskyi_activitylog/activity/index',
                    ['_query' => ['channel' => McpChannel::CODE, 'text' => $ability['ability']]]
                ),
            ];
        }

        return [
            'columns' => [
                ['key' => 'ability', 'label' => (string) __('Ability')],
                ['key' => 'calls', 'label' => (string) __('Calls'), 'align' => 'right'],
                ['key' => 'refused', 'label' => (string) __('Refused'), 'align' => 'right'],
                ['key' => 'failed', 'label' => (string) __('Failed'), 'align' => 'right'],
            ],
            'rows' => $rows,
        ];
    }
}
