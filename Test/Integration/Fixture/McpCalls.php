<?php
/**
 * Copyright (c) 2026. Volodymyr Hryvinskyi. All rights reserved.
 * Author: Volodymyr Hryvinskyi <volodymyr@hryvinskyi.com>
 * GitHub: https://github.com/hryvinskyi
 */

declare(strict_types=1);

namespace Hryvinskyi\McpServerActivityLog\Test\Integration\Fixture;

use Hryvinskyi\McpServer\Api\Data\AbilityAccess;
use Hryvinskyi\McpServer\Api\Data\AbilityInterfaceFactory;
use Hryvinskyi\McpServer\Api\Data\CallerInterfaceFactory;
use Hryvinskyi\McpServer\Api\Data\ClientInterfaceFactory;
use Hryvinskyi\McpServer\Api\Data\InvocationInterfaceFactory;
use Hryvinskyi\McpServer\Model\AbilityCatalog;
use Hryvinskyi\McpServer\Model\AbilityInvoker;
use Hryvinskyi\McpServer\Test\Unit\Fake\FakeAbilitySource;
use Magento\Authorization\Model\UserContextInterface;
use Magento\Framework\App\ResourceConnection;
use Magento\TestFramework\Helper\Bootstrap;
use PHPUnit\Framework\Assert;

/**
 * Makes MCP calls of one test ability through the container's invoker, with this module's listener wired
 *
 * The ability reads and answers a token, so its result has something to mask.
 */
class McpCalls
{
    public const ABILITY = 'test.order.list';

    /**
     * Call the ability as admin 1 through a named client; the id of the call's activity
     *
     * @param array<string, mixed> $arguments
     * @param array<string, mixed> $returns What the ability returns
     * @return int
     */
    public function call(array $arguments, array $returns = ['token' => 'plain-token']): int
    {
        $objectManager = Bootstrap::getObjectManager();
        $ability = self::service(AbilityInterfaceFactory::class)->create([
            'name' => self::ABILITY,
            'title' => 'List orders',
            'category' => 'Magento_Sales',
            'source' => 'test',
            'sourceReference' => 'GET /V1/orders',
            'access' => AbilityAccess::Read,
            'aclResources' => ['Magento_Sales::sales'],
        ]);
        $source = new FakeAbilitySource('test', [$ability], true, static fn (): array => $returns);
        $catalog = $objectManager->create(AbilityCatalog::class, ['sources' => ['test' => $source]]);
        $invoker = $objectManager->create(AbilityInvoker::class, ['catalog' => $catalog, 'sourceResolver' => $catalog]);
        Assert::assertInstanceOf(AbilityInvoker::class, $invoker);

        $invoker->invoke(self::service(InvocationInterfaceFactory::class)->create([
            'abilityName' => self::ABILITY,
            'caller' => self::service(CallerInterfaceFactory::class)->create([
                'userType' => UserContextInterface::USER_TYPE_ADMIN,
                'userId' => 1,
            ]),
            'client' => self::service(ClientInterfaceFactory::class)->create([
                'name' => 'Cursor',
                'version' => '1.7',
            ]),
            'transport' => 'stdio',
            'confirmed' => false,
        ])->withArguments($arguments));

        $resource = self::service(ResourceConnection::class);
        $connection = $resource->getConnection();
        $id = $connection->fetchOne(
            $connection->select()
                ->from($resource->getTableName('hryvinskyi_activity_log'), ['MAX(entity_id)'])
                ->where('channel = ?', 'mcp')
        );
        Assert::assertIsNumeric($id, 'The call was not recorded.');

        return (int) $id;
    }

    /**
     * @template T of object
     * @param class-string<T> $class
     * @return T
     */
    private static function service(string $class): object
    {
        $service = Bootstrap::getObjectManager()->get($class);
        Assert::assertInstanceOf($class, $service);

        return $service;
    }
}
