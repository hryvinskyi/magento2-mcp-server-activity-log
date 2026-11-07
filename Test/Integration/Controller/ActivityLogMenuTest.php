<?php
/**
 * Copyright (c) 2026. Volodymyr Hryvinskyi. All rights reserved.
 * Author: Volodymyr Hryvinskyi <volodymyr@hryvinskyi.com>
 * GitHub: https://github.com/hryvinskyi
 */

declare(strict_types=1);

namespace Hryvinskyi\McpServerActivityLog\Test\Integration\Controller;

use Hryvinskyi\ActivityLog\Test\Integration\Controller\Fixture\ActivityLogMenu;
use Magento\TestFramework\Response;
use Magento\TestFramework\TestCase\AbstractBackendController;

/**
 * The entries this module puts into the Activity Log menu, as the Activity Log dashboard prints the menu
 *
 * This module has an entry in each of the menu's groups, so its menu shows them all, in the order Logs, Pages,
 * Settings. Each test dispatches once: the test application serves a second dispatch of one test from the first
 * one's state.
 */
class ActivityLogMenuTest extends AbstractBackendController
{
    /**
     * @var string
     */
    protected $resource = 'Hryvinskyi_ActivityLog::dashboard';

    /**
     * @var string
     */
    protected $uri = 'backend/activitylog/dashboard/index';

    public function testTheGroupsAreLogsPagesAndSettingsInThatOrder(): void
    {
        $headings = array_values(array_intersect($this->menu()->headings(), ['Logs', 'Pages', 'Settings']));

        self::assertSame(['Logs', 'Pages', 'Settings'], $headings);
    }

    public function testEachEntryIsInItsGroupWithAnIcon(): void
    {
        $menu = $this->menu();

        $calls = $menu->entry('MCP calls');
        self::assertSame('Logs', $calls['heading']);
        self::assertStringContainsString('/activitylog/activity/index/', $calls['href']);
        self::assertStringContainsString('channel=mcp', $calls['href']);
        self::assertTrue($calls['icon'], 'MCP calls has an icon');

        $server = $menu->entry('MCP Server');
        self::assertSame('Pages', $server['heading']);
        self::assertStringContainsString('/mcpserver/server/index/', $server['href']);
        self::assertTrue($server['icon'], 'MCP Server has an icon');

        $settings = $menu->entry('MCP call logging');
        self::assertSame('Settings', $settings['heading']);
        self::assertStringContainsString('/system_config/edit/section/hryvinskyi_mcp_server/', $settings['href']);
        self::assertTrue($settings['icon'], 'MCP call logging has an icon');
    }

    /**
     * The Activity Log menu of the dashboard, which must have been answered
     *
     * @return ActivityLogMenu
     */
    private function menu(): ActivityLogMenu
    {
        $this->dispatch($this->uri);
        $response = $this->getResponse();
        self::assertInstanceOf(Response::class, $response);
        self::assertSame(200, $response->getHttpResponseCode());

        return new ActivityLogMenu((string) $response->getBody());
    }
}
