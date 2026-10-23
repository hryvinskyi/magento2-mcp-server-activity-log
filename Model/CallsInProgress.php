<?php
/**
 * Copyright (c) 2026. Volodymyr Hryvinskyi. All rights reserved.
 * Author: Volodymyr Hryvinskyi <volodymyr@hryvinskyi.com>
 * GitHub: https://github.com/hryvinskyi
 */

declare(strict_types=1);

namespace Hryvinskyi\McpServerActivityLog\Model;

use Hryvinskyi\ActivityLog\Api\ActorResolverInterface;
use Hryvinskyi\ActivityLog\Api\CorrelationScopeInterface;
use Hryvinskyi\ActivityLog\Api\Data\ActorInterface;
use Hryvinskyi\ActivityLog\Api\Data\ActorInterfaceFactory;
use Hryvinskyi\ActivityLog\Api\UserActorsInterface;
use Hryvinskyi\McpServer\Api\Data\InvocationInterface;
use Magento\Framework\ObjectManager\ResetAfterRequestInterface;

/**
 * The MCP calls running now, innermost last: each one an operation of its own, done by its caller through its client
 *
 * While a call runs, everything it causes (the call itself, the changes its ability makes) shares the call's
 * correlation id, and is attributed to the account whose ACL the call ran with, "via" the client that sent it
 * ("jane via Claude Desktop 1.2"). As an actor resolver it answers first, before the session the call may run in.
 * A caller that is no account (no user id) is not named here: the other resolvers decide.
 *
 * @phpstan-type RunningCall array{correlationId: string, actor: ActorInterface|null}
 */
class CallsInProgress implements ActorResolverInterface, ResetAfterRequestInterface
{
    private const CLIENT_MAX_LENGTH = 128;
    private const VERSION_MAX_LENGTH = 32;
    private const UNNAMED_CLIENT = 'MCP client';

    /**
     * @var list<RunningCall>
     */
    private array $calls = [];

    /**
     * @param CorrelationScopeInterface $correlationScope
     * @param UserActorsInterface $userActors
     * @param ActorInterfaceFactory $actorFactory
     */
    public function __construct(
        private readonly CorrelationScopeInterface $correlationScope,
        private readonly UserActorsInterface $userActors,
        private readonly ActorInterfaceFactory $actorFactory
    ) {
    }

    /**
     * A call starts: open its operation and name who makes it
     *
     * @param InvocationInterface $invocation
     * @return void
     */
    public function begin(InvocationInterface $invocation): void
    {
        $this->calls[] = [
            'correlationId' => $this->correlationScope->begin(),
            'actor' => $this->actorOf($invocation),
        ];
    }

    /**
     * The innermost call ends: close its operation
     *
     * @return void
     */
    public function end(): void
    {
        $call = array_pop($this->calls);
        if ($call !== null) {
            $this->correlationScope->end($call['correlationId']);
        }
    }

    /**
     * @inheritDoc
     */
    public function resolve(): ?ActorInterface
    {
        return $this->calls === [] ? null : $this->calls[count($this->calls) - 1]['actor'];
    }

    /**
     * @inheritDoc
     */
    public function _resetState(): void
    {
        $this->calls = [];
    }

    /**
     * The caller's account, acting through the call's client; null for a caller that is no account
     *
     * @param InvocationInterface $invocation
     * @return ActorInterface|null
     */
    private function actorOf(InvocationInterface $invocation): ?ActorInterface
    {
        $caller = $invocation->getCaller();
        $account = $this->userActors->actorOf($caller->getUserType(), (int) $caller->getUserId());
        if ($account === null) {
            return null;
        }

        $client = $invocation->getClient();
        $name = mb_substr(trim($client->getName()), 0, self::CLIENT_MAX_LENGTH);
        $version = mb_substr(trim($client->getVersion()), 0, self::VERSION_MAX_LENGTH);

        return $this->actorFactory->create([
            'type' => $account->getType(),
            'name' => $account->getName(),
            'id' => $account->getId(),
            'viaClient' => $name === '' ? self::UNNAMED_CLIENT : $name,
            'viaVersion' => $version === '' ? null : $version,
            'viaChannel' => McpChannel::CODE,
        ]);
    }
}
