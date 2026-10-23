<?php
/**
 * Copyright (c) 2026. Volodymyr Hryvinskyi. All rights reserved.
 * Author: Volodymyr Hryvinskyi <volodymyr@hryvinskyi.com>
 * GitHub: https://github.com/hryvinskyi
 */

declare(strict_types=1);

namespace Hryvinskyi\McpServerActivityLog\Test\Integration\Controller;

use Hryvinskyi\ActivityLog\Api\SanitizerInterface;
use Hryvinskyi\ActivityLog\Model\Config as ActivityLogConfig;
use Hryvinskyi\McpServerActivityLog\Test\Integration\Fixture\McpCalls;
use Magento\Framework\App\Config\MutableScopeConfigInterface;
use Magento\Framework\Escaper;
use Magento\TestFramework\Fixture\DbIsolation;
use Magento\TestFramework\Helper\Bootstrap;
use Magento\TestFramework\Response;
use Magento\TestFramework\TestCase\AbstractBackendController;

/**
 * An MCP call as the admin pages show it: its panel on its activity's page, and its client on the MCP Server page
 *
 * Each test dispatches once: the test application serves a second dispatch of one test from the first one's state.
 */
#[DbIsolation(true)]
class McpPagesTest extends AbstractBackendController
{
    /**
     * @var string
     */
    protected $resource = 'Hryvinskyi_McpServer::view';

    /**
     * @var string
     */
    protected $uri = 'backend/mcpserver/server/index';

    protected function setUp(): void
    {
        parent::setUp();
        $config = Bootstrap::getObjectManager()->get(MutableScopeConfigInterface::class);
        self::assertInstanceOf(MutableScopeConfigInterface::class, $config);
        $config->setValue(ActivityLogConfig::XML_PATH_ENABLED, '1');
        $config->setValue('hryvinskyi_mcp_server/graphql/enabled', '0');
    }

    public function testAnActivityOfACallShowsTheCallWithItsSecretsMasked(): void
    {
        $id = (new McpCalls())->call(['status' => 'pending', 'api_key' => 'plain-key']);

        $this->dispatch('backend/activitylog/activity/view/id/' . $id);
        $body = $this->body();

        self::assertStringContainsString('MCP call', $body);
        self::assertStringContainsString(McpCalls::ABILITY, $body);
        self::assertStringContainsString('Cursor 1.7', $body);
        self::assertStringContainsString('Magento_Sales::sales', $body);
        self::assertStringContainsString($this->escaped(SanitizerInterface::MASK), $body);
        self::assertStringNotContainsString('plain-key', $body);
        self::assertStringNotContainsString('plain-token', $body);
    }

    public function testTheMcpServerPageNamesTheClientsOfTheLastDay(): void
    {
        (new McpCalls())->call([]);

        $this->dispatch('backend/mcpserver/server/index');
        $body = $this->body();

        self::assertStringContainsString('Clients in the last 24 h:', $body);
        self::assertMatchesRegularExpression('#Cursor 1\.7\s*\(\d+ calls\)#', $body);
    }

    /**
     * The page, which must have been answered
     *
     * @return string
     */
    private function body(): string
    {
        $response = $this->getResponse();
        self::assertInstanceOf(Response::class, $response);
        self::assertSame(200, $response->getHttpResponseCode());

        return (string) $response->getBody();
    }

    /**
     * Text as the page prints it
     *
     * @param string $text
     * @return string
     */
    private function escaped(string $text): string
    {
        $escaper = Bootstrap::getObjectManager()->get(Escaper::class);
        self::assertInstanceOf(Escaper::class, $escaper);

        return $escaper->escapeHtml($text);
    }
}
