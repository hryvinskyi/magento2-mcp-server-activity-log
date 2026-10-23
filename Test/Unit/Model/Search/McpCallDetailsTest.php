<?php
/**
 * Copyright (c) 2026. Volodymyr Hryvinskyi. All rights reserved.
 * Author: Volodymyr Hryvinskyi <volodymyr@hryvinskyi.com>
 * GitHub: https://github.com/hryvinskyi
 */

declare(strict_types=1);

namespace Hryvinskyi\McpServerActivityLog\Test\Unit\Model\Search;

use Hryvinskyi\ActivityLog\Api\Data\Search\EventDetailInterfaceFactory;
use Hryvinskyi\ActivityLog\Api\Data\Search\Verb;
use Hryvinskyi\ActivityLog\Model\Data\Search\EventDetail;
use Hryvinskyi\ActivityLog\Model\Data\Search\LinkPolicy;
use Hryvinskyi\McpServerActivityLog\Model\ResourceModel\CallStore;
use Hryvinskyi\McpServerActivityLog\Model\Search\McpCallDetails;
use Magento\Backend\Model\UrlInterface;
use Magento\Framework\AuthorizationInterface;
use PHPUnit\Framework\TestCase;

/**
 * @phpstan-import-type CallDetail from CallStore
 */
class McpCallDetailsTest extends TestCase
{
    public function testARefusedWriteOrDeleteIsATryAndAnyOtherCallPerformedItsAbility(): void
    {
        $details = $this->details(true)->detailsFor([1, 2, 3, 4, 5]);

        self::assertSame(
            [Verb::TriedToChange, Verb::TriedToDelete, Verb::Performed, Verb::Performed, Verb::TriedToChange],
            [
                $details[1]->getVerb(),
                $details[2]->getVerb(),
                $details[3]->getVerb(),
                $details[4]->getVerb(),
                $details[5]->getVerb(),
            ]
        );
        self::assertSame(['Ability', 'catalog.product_repository.save', '/admin/hryvinskyi_mcp/server/index'], [
            $details[1]->getSubjectTypeLabel(),
            $details[1]->getSubjectLabel(),
            $details[1]->getSubjectUrl(),
        ]);
    }

    public function testTheAbilityIsLinkedOnlyForAnAdminWhoMayOpenTheMcpServerPage(): void
    {
        self::assertNull(($this->details(false)->detailsFor([1])[1] ?? null)?->getSubjectUrl());
    }

    public function testDescribesTheMcpChannelOnly(): void
    {
        self::assertTrue($this->details(true)->supports('mcp'));
        self::assertFalse($this->details(true)->supports('rest'));
    }

    /**
     * Details over five calls: a refused write, a refused delete, a refused read, a write that ran, and a write that
     * waits for its confirmation
     *
     * @param bool $mayOpenServerPage
     * @return McpCallDetails
     */
    private function details(bool $mayOpenServerPage): McpCallDetails
    {
        $calls = [
            1 => $this->call('catalog.product_repository.save', 'write', 'denied'),
            2 => $this->call('catalog.product_repository.delete', 'delete', 'denied'),
            3 => $this->call('catalog.product_repository.get', 'read', 'denied'),
            4 => $this->call('catalog.product_repository.save', 'write', 'succeeded'),
            5 => $this->call('catalog.product_repository.save', 'write', 'needs_confirmation'),
        ];
        $store = $this->createMock(CallStore::class);
        $store->method('findMany')->willReturnCallback(
            static fn (array $ids): array => array_filter(
                $calls,
                static fn (int $id): bool => in_array($id, $ids, true),
                ARRAY_FILTER_USE_KEY
            )
        );
        $factory = $this->createMock(EventDetailInterfaceFactory::class);
        $factory->method('create')->willReturnCallback(static fn (): EventDetail => new EventDetail(new LinkPolicy()));
        $url = $this->createMock(UrlInterface::class);
        $url->method('getUrl')->willReturnCallback(
            static fn (mixed $route): string => '/admin/' . (is_string($route) ? $route : '')
        );
        $authorization = $this->createMock(AuthorizationInterface::class);
        $authorization->method('isAllowed')->willReturn($mayOpenServerPage);

        return new McpCallDetails($store, $factory, $url, $authorization);
    }

    /**
     * @param string $ability
     * @param string $access
     * @param string $outcome
     * @return CallDetail
     */
    private function call(string $ability, string $access, string $outcome): array
    {
        return [
            'occurredAtMs' => 1792500000000,
            'ability' => $ability,
            'clientName' => 'Claude Desktop',
            'clientVersion' => '0.9',
            'transport' => 'http',
            'source' => 'webapi',
            'sourceReference' => 'POST /V1/products',
            'access' => $access,
            'aclResources' => ['Magento_Catalog::products'],
            'outcome' => $outcome,
            'denialReason' => $outcome === 'denied' ? 'acl' : '',
            'message' => null,
            'arguments' => '{}',
            'result' => null,
            'truncated' => false,
        ];
    }
}
