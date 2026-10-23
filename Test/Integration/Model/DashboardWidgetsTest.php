<?php
/**
 * Copyright (c) 2026. Volodymyr Hryvinskyi. All rights reserved.
 * Author: Volodymyr Hryvinskyi <volodymyr@hryvinskyi.com>
 * GitHub: https://github.com/hryvinskyi
 */

declare(strict_types=1);

namespace Hryvinskyi\McpServerActivityLog\Test\Integration\Model;

use Hryvinskyi\ActivityLog\Api\MillisecondClockInterface;
use Hryvinskyi\ActivityLog\Model\Config as ActivityLogConfig;
use Hryvinskyi\ActivityLog\Model\Data\DashboardQuery;
use Hryvinskyi\ActivityLog\Model\Dashboard\WidgetPool;
use Hryvinskyi\McpServerActivityLog\Test\Integration\Fixture\McpCalls;
use Magento\Framework\App\Config\MutableScopeConfigInterface;
use Magento\TestFramework\Fixture\DbIsolation;
use Magento\TestFramework\Helper\Bootstrap;
use PHPUnit\Framework\TestCase;

/**
 * The MCP widgets of the activity dashboard, through the real widget pool and the calls this module records
 */
#[DbIsolation(true)]
class DashboardWidgetsTest extends TestCase
{
    public function testTheTopAbilitiesCountTheCallsOfThePeriodOnlyWhenTheChannelIsChosen(): void
    {
        $config = Bootstrap::getObjectManager()->get(MutableScopeConfigInterface::class);
        self::assertInstanceOf(MutableScopeConfigInterface::class, $config);
        $config->setValue(ActivityLogConfig::XML_PATH_ENABLED, '1');
        (new McpCalls())->call([]);
        (new McpCalls())->call([]);
        $clock = Bootstrap::getObjectManager()->get(MillisecondClockInterface::class);
        self::assertInstanceOf(MillisecondClockInterface::class, $clock);
        $nowMs = $clock->nowMs() + 1000;

        $pool = Bootstrap::getObjectManager()->get(WidgetPool::class);
        self::assertInstanceOf(WidgetPool::class, $pool);
        $widgets = [];
        foreach ($pool->visible() as $widget) {
            $widgets[$widget->getCode()] = $widget;
        }
        $top = $widgets['mcp_top_abilities'] ?? null;
        self::assertNotNull($top);
        $data = $top->getData(new DashboardQuery(7, ['mcp'], $nowMs));
        $rows = $data['rows'] ?? null;
        self::assertIsArray($rows);
        $mine = array_values(array_filter(
            $rows,
            static fn (mixed $row): bool => is_array($row)
                && is_array($row['cells'] ?? null)
                && ($row['cells']['ability'] ?? null) === McpCalls::ABILITY
        ));
        $first = $mine[0] ?? null;
        $cells = is_array($first) ? $first['cells'] : null;
        self::assertIsArray($cells);
        self::assertSame('2', $cells['calls'] ?? null);
        self::assertSame([], $top->getData(new DashboardQuery(7, ['rest'], $nowMs))['rows'] ?? null);
        self::assertArrayHasKey('mcp_denied_calls', $widgets);
    }
}
