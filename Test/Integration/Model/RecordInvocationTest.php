<?php
/**
 * Copyright (c) 2026. Volodymyr Hryvinskyi. All rights reserved.
 * Author: Volodymyr Hryvinskyi <volodymyr@hryvinskyi.com>
 * GitHub: https://github.com/hryvinskyi
 */

declare(strict_types=1);

namespace Hryvinskyi\McpServerActivityLog\Test\Integration\Model;

use Hryvinskyi\ActivityLog\Api\ActivityBuilderInterface;
use Hryvinskyi\ActivityLog\Api\ActivityRecorderInterface;
use Hryvinskyi\ActivityLog\Api\ActorContextInterface;
use Hryvinskyi\ActivityLog\Api\Data\Outcome;
use Hryvinskyi\ActivityLog\Api\Data\Search\Verb;
use Hryvinskyi\ActivityLog\Api\SanitizerInterface;
use Hryvinskyi\ActivityLog\Model\Config as ActivityLogConfig;
use Hryvinskyi\ActivityLog\Model\Search\Detail\EventDetails;
use Hryvinskyi\McpServer\Api\AbilityOverrideRepositoryInterface;
use Hryvinskyi\McpServer\Api\Data\AbilityAccess;
use Hryvinskyi\McpServer\Api\Data\AbilityInterface;
use Hryvinskyi\McpServer\Api\Data\AbilityInterfaceFactory;
use Hryvinskyi\McpServer\Api\Data\AbilityOverrideInterface;
use Hryvinskyi\McpServer\Api\Data\CallerInterfaceFactory;
use Hryvinskyi\McpServer\Api\Data\ClientInterfaceFactory;
use Hryvinskyi\McpServer\Api\Data\InvocationInterface;
use Hryvinskyi\McpServer\Api\Data\InvocationInterfaceFactory;
use Hryvinskyi\McpServer\Model\AbilityCatalog;
use Hryvinskyi\McpServer\Model\AbilityInvoker;
use Hryvinskyi\McpServer\Model\AbilityOverrideRepository;
use Hryvinskyi\McpServer\Test\Unit\Fake\FakeAbilitySource;
use Hryvinskyi\McpServerActivityLog\Model\ResourceModel\CallStore;
use Magento\Authorization\Model\UserContextInterface;
use Magento\Framework\App\Config\MutableScopeConfigInterface;
use Magento\Framework\App\ResourceConnection;
use Magento\TestFramework\Fixture\Config;
use Magento\TestFramework\Fixture\DbIsolation;
use Magento\TestFramework\Helper\Bootstrap;
use Magento\User\Model\User;
use Magento\User\Model\UserFactory;
use PHPUnit\Framework\TestCase;

/**
 * MCP calls through the container's own invoker, with this module's listener wired, over a catalog of one test source
 *
 * The test source's write records an activity of its own, as an ability's changes would: it must share the call's
 * operation and be attributed to the caller via the client.
 *
 * @phpstan-import-type CallDetail from CallStore
 */
#[DbIsolation(true)]
class RecordInvocationTest extends TestCase
{
    private const WRITE = 'test.order.cancel';
    private const SIDE_CHANNEL = 'test_side';

    /**
     * @var AbilityInvoker
     */
    private AbilityInvoker $invoker;

    /**
     * @var User
     */
    private User $admin;

    protected function setUp(): void
    {
        $repository = self::service(AbilityOverrideRepositoryInterface::class);
        self::assertInstanceOf(AbilityOverrideRepository::class, $repository);
        $repository->_resetState();
        self::service(MutableScopeConfigInterface::class)->setValue(ActivityLogConfig::XML_PATH_ENABLED, '1');

        $admin = self::service(UserFactory::class)->create();
        $admin->loadByUsername(\Magento\TestFramework\Bootstrap::ADMIN_NAME);
        $this->admin = $admin;

        $source = new FakeAbilitySource(
            'test',
            [$this->ability(self::WRITE, AbilityAccess::Write)],
            true,
            static function (): array {
                $builder = self::service(ActivityBuilderInterface::class);
                self::service(ActivityRecorderInterface::class)->record(
                    $builder->build(self::SIDE_CHANNEL, 'Order cancelled', Outcome::Success)
                );

                return ['cancelled' => true, 'token' => 'plain-token'];
            }
        );
        $catalog = Bootstrap::getObjectManager()->create(AbilityCatalog::class, ['sources' => ['test' => $source]]);
        $invoker = Bootstrap::getObjectManager()->create(AbilityInvoker::class, [
            'catalog' => $catalog,
            'sourceResolver' => $catalog,
        ]);
        self::assertInstanceOf(AbilityInvoker::class, $invoker);
        $this->invoker = $invoker;
    }

    #[Config('hryvinskyi_mcp_server/general/read_only_mode', '0')]
    public function testACallIsTheCallersViaTheClientAndWhatItCausesSharesItsOperation(): void
    {
        $this->invoker->invoke($this->invocation()->withArguments(['order_id' => 7, 'password' => 'hunter2']));

        $call = $this->latest('mcp');
        $side = $this->latest(self::SIDE_CHANNEL);
        self::assertSame(
            [self::WRITE, 'success', 'admin', $this->admin->getUserName(), 'Claude Desktop', '0.9', 'mcp'],
            [
                $call['action'],
                $call['outcome'],
                $call['actor_type'],
                $call['actor_name'],
                $call['via_client'],
                $call['via_version'],
                $call['via_channel'],
            ]
        );
        self::assertSame($call['correlation_id'], $side['correlation_id'], 'The ability\'s changes join the call');
        self::assertSame(
            [$this->admin->getUserName(), 'Claude Desktop'],
            [$side['actor_name'], $side['via_client']]
        );

        $detail = $this->detail($call);
        self::assertSame(
            ['http', 'test', 'write', ['Magento_Sales::cancel'], 'succeeded', ''],
            [
                $detail['transport'],
                $detail['source'],
                $detail['access'],
                $detail['aclResources'],
                $detail['outcome'],
                $detail['denialReason'],
            ]
        );
        self::assertSame(
            ['order_id' => 7, 'password' => SanitizerInterface::MASK],
            json_decode((string) $detail['arguments'], true)
        );
        self::assertSame(
            ['cancelled' => true, 'token' => SanitizerInterface::MASK],
            json_decode((string) $detail['result'], true)
        );
        self::assertNotSame(
            'Claude Desktop',
            self::service(ActorContextInterface::class)->current()->getViaClient(),
            'Once the call ends, nothing is attributed to it'
        );
    }

    #[Config('hryvinskyi_mcp_server/general/read_only_mode', '1')]
    public function testAWriteReadOnlyModeRefusesIsDeniedAndReadsAsTriedToChange(): void
    {
        $this->invoker->invoke($this->invocation());

        $call = $this->latest('mcp');
        self::assertSame('denied', $call['outcome']);
        self::assertSame('read_only_mode', $this->detail($call)['denialReason']);
        self::assertNull($this->detail($call)['result'], 'Nothing ran, so nothing came back');

        self::assertIsNumeric($call['entity_id']);
        $id = (int) $call['entity_id'];
        $searched = self::service(EventDetails::class)->forActivities([$id => 'mcp'])[$id] ?? null;
        self::assertSame(
            [Verb::TriedToChange, self::WRITE],
            [$searched?->getVerb(), $searched?->getSubjectLabel()]
        );
    }

    #[Config('hryvinskyi_mcp_server/general/read_only_mode', '0')]
    public function testACallWaitingForItsConfirmationKeepsNoMessage(): void
    {
        $override = Bootstrap::getObjectManager()->create(AbilityOverrideInterface::class);
        self::assertInstanceOf(AbilityOverrideInterface::class, $override);
        self::service(AbilityOverrideRepositoryInterface::class)->save(
            $override->setAbility(self::WRITE)->setNeedsConfirmation(true)
        );

        $this->invoker->invoke($this->invocation());

        $call = $this->latest('mcp');
        self::assertSame(['denied', null], [$call['outcome'], $call['message']]);
        self::assertSame('needs_confirmation', $this->detail($call)['denialReason']);
    }

    /**
     * A call of the write by the test admin through a named client
     *
     * @return InvocationInterface
     */
    private function invocation(): InvocationInterface
    {
        return self::service(InvocationInterfaceFactory::class)->create([
            'abilityName' => self::WRITE,
            'caller' => self::service(CallerInterfaceFactory::class)->create([
                'userType' => UserContextInterface::USER_TYPE_ADMIN,
                'userId' => is_numeric($this->admin->getId()) ? (int) $this->admin->getId() : 0,
            ]),
            'client' => self::service(ClientInterfaceFactory::class)->create([
                'name' => 'Claude Desktop',
                'version' => '0.9',
            ]),
            'transport' => 'http',
            'confirmed' => false,
        ]);
    }

    /**
     * @param string $name
     * @param AbilityAccess $access
     * @return AbilityInterface
     */
    private function ability(string $name, AbilityAccess $access): AbilityInterface
    {
        return self::service(AbilityInterfaceFactory::class)->create([
            'name' => $name,
            'title' => 'Cancel an order',
            'category' => 'Magento_Sales',
            'source' => 'test',
            'sourceReference' => 'POST /V1/orders/:id/cancel',
            'access' => $access,
            'aclResources' => ['Magento_Sales::cancel'],
        ]);
    }

    /**
     * The newest activity of a channel
     *
     * @param string $channel
     * @return array<string, mixed>
     */
    private function latest(string $channel): array
    {
        $resource = self::service(ResourceConnection::class);
        $connection = $resource->getConnection();
        $row = $connection->fetchRow(
            $connection->select()
                ->from($resource->getTableName('hryvinskyi_activity_log'))
                ->where('channel = ?', $channel)
                ->order('entity_id DESC')
                ->limit(1)
        );
        self::assertIsArray($row, sprintf('No activity in channel %s.', $channel));
        $named = [];
        foreach ($row as $column => $value) {
            $named[(string) $column] = $value;
        }

        return $named;
    }

    /**
     * The call detail of an activity
     *
     * @param array<string, mixed> $activity
     * @return CallDetail
     */
    private function detail(array $activity): array
    {
        $id = $activity['entity_id'] ?? null;
        self::assertIsNumeric($id);
        $detail = self::service(CallStore::class)->findMany([(int) $id])[(int) $id] ?? null;
        self::assertNotNull($detail, 'The call has no detail.');

        return $detail;
    }

    /**
     * @template T of object
     * @param class-string<T> $class
     * @return T
     */
    private static function service(string $class): object
    {
        $service = Bootstrap::getObjectManager()->get($class);
        self::assertInstanceOf($class, $service);

        return $service;
    }
}
