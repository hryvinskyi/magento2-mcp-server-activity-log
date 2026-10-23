<?php
/**
 * Copyright (c) 2026. Volodymyr Hryvinskyi. All rights reserved.
 * Author: Volodymyr Hryvinskyi <volodymyr@hryvinskyi.com>
 * GitHub: https://github.com/hryvinskyi
 */

declare(strict_types=1);

namespace Hryvinskyi\McpServerActivityLog\ViewModel;

use Hryvinskyi\ActivityLog\Api\MillisecondClockInterface;
use Hryvinskyi\McpServerActivityLog\Model\McpChannel;
use Hryvinskyi\McpServerActivityLog\Model\ResourceModel\CallStore;
use Magento\Backend\Model\UrlInterface;
use Magento\Framework\View\Element\Block\ArgumentInterface;

/**
 * The MCP clients that called in the last 24 hours, for the MCP Server page
 */
class RecentClients implements ArgumentInterface
{
    private const DAY_MS = 86_400_000;

    /**
     * @param CallStore $store
     * @param MillisecondClockInterface $clock
     * @param UrlInterface $url
     */
    public function __construct(
        private readonly CallStore $store,
        private readonly MillisecondClockInterface $clock,
        private readonly UrlInterface $url
    ) {
    }

    /**
     * Each client with its version and calls, the busiest first
     *
     * @return list<array{name: string, version: string, calls: int}>
     */
    public function clients(): array
    {
        return $this->store->clientsSince($this->clock->nowMs() - self::DAY_MS);
    }

    /**
     * The activity log's search for MCP calls
     *
     * @return string
     */
    public function activityUrl(): string
    {
        return $this->url->getUrl(
            'hryvinskyi_activitylog/activity/index',
            ['_query' => ['channel' => McpChannel::CODE]]
        );
    }
}
