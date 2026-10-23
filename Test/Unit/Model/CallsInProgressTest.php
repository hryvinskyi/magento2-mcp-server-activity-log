<?php
/**
 * Copyright (c) 2026. Volodymyr Hryvinskyi. All rights reserved.
 * Author: Volodymyr Hryvinskyi <volodymyr@hryvinskyi.com>
 * GitHub: https://github.com/hryvinskyi
 */

declare(strict_types=1);

namespace Hryvinskyi\McpServerActivityLog\Test\Unit\Model;

use Hryvinskyi\ActivityLog\Api\CorrelationScopeInterface;
use Hryvinskyi\ActivityLog\Api\Data\ActorInterface;
use Hryvinskyi\ActivityLog\Api\Data\ActorInterfaceFactory;
use Hryvinskyi\ActivityLog\Api\Data\ActorType;
use Hryvinskyi\ActivityLog\Api\UserActorsInterface;
use Hryvinskyi\ActivityLog\Model\Data\Actor;
use Hryvinskyi\McpServer\Api\Data\CallerInterface;
use Hryvinskyi\McpServer\Api\Data\ClientInterface;
use Hryvinskyi\McpServer\Api\Data\InvocationInterface;
use Hryvinskyi\McpServerActivityLog\Model\CallsInProgress;
use PHPUnit\Framework\TestCase;

class CallsInProgressTest extends TestCase
{
    /**
     * @var list<string>
     */
    private array $ended = [];

    public function testARunningCallIsItsCallersViaItsClientInAnOperationOfItsOwn(): void
    {
        $calls = $this->calls();
        self::assertNull($calls->resolve(), 'Nothing runs yet');

        $calls->begin($this->invocation(7, 'Claude Desktop', '0.9'));
        $actor = $calls->resolve();

        self::assertSame(
            [ActorType::Admin, 'jane', '7', 'Claude Desktop', '0.9', 'mcp'],
            [
                $actor?->getType(),
                $actor?->getName(),
                $actor?->getId(),
                $actor?->getViaClient(),
                $actor?->getViaVersion(),
                $actor?->getViaChannel(),
            ]
        );
        $calls->end();
        self::assertNull($calls->resolve());
        self::assertSame(['op-1'], $this->ended, 'The call\'s operation is closed when it ends');
    }

    public function testAnUnnamedClientIsStillNamedAndANestedCallEndsFirst(): void
    {
        $calls = $this->calls();
        $calls->begin($this->invocation(7, 'Outer', '1'));
        $calls->begin($this->invocation(7, '   ', ''));

        $inner = $calls->resolve();
        self::assertSame(['MCP client', null], [$inner?->getViaClient(), $inner?->getViaVersion()]);
        $calls->end();
        self::assertSame('Outer', $calls->resolve()?->getViaClient());
        self::assertSame(['op-2'], $this->ended);
    }

    public function testACallerThatIsNoAccountLeavesTheActorToTheOtherResolvers(): void
    {
        $calls = $this->calls();
        $calls->begin($this->invocation(0, 'Claude Desktop', '0.9'));

        self::assertNull($calls->resolve());
    }

    /**
     * Running calls over a scope that numbers its operations, and accounts of which admin 7 is "jane"
     *
     * @return CallsInProgress
     */
    private function calls(): CallsInProgress
    {
        $opened = 0;
        $scope = $this->createMock(CorrelationScopeInterface::class);
        $scope->method('begin')->willReturnCallback(static function () use (&$opened): string {
            $opened++;

            return 'op-' . $opened;
        });
        $scope->method('end')->willReturnCallback(function (string $id): void {
            $this->ended[] = $id;
        });
        $accounts = $this->createMock(UserActorsInterface::class);
        $accounts->method('actorOf')->willReturnCallback(
            static fn (int $type, int $id): ?ActorInterface => $id === 7
                ? new Actor(ActorType::Admin, 'jane', '7')
                : null
        );
        $factory = $this->createMock(ActorInterfaceFactory::class);
        $factory->method('create')->willReturnCallback(
            static function (array $data): ActorInterface {
                self::assertInstanceOf(ActorType::class, $data['type']);
                self::assertIsString($data['name']);
                self::assertIsString($data['id']);
                self::assertIsString($data['viaClient']);
                self::assertTrue($data['viaVersion'] === null || is_string($data['viaVersion']));
                self::assertIsString($data['viaChannel']);

                return new Actor(
                    $data['type'],
                    $data['name'],
                    $data['id'],
                    $data['viaClient'],
                    $data['viaVersion'],
                    $data['viaChannel']
                );
            }
        );

        return new CallsInProgress($scope, $accounts, $factory);
    }

    /**
     * @param int $userId
     * @param string $client
     * @param string $version
     * @return InvocationInterface
     */
    private function invocation(int $userId, string $client, string $version): InvocationInterface
    {
        $caller = $this->createMock(CallerInterface::class);
        $caller->method('getUserType')->willReturn(2);
        $caller->method('getUserId')->willReturn($userId === 0 ? null : $userId);
        $clientInfo = $this->createMock(ClientInterface::class);
        $clientInfo->method('getName')->willReturn($client);
        $clientInfo->method('getVersion')->willReturn($version);
        $invocation = $this->createMock(InvocationInterface::class);
        $invocation->method('getCaller')->willReturn($caller);
        $invocation->method('getClient')->willReturn($clientInfo);

        return $invocation;
    }
}
