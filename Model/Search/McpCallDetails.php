<?php
/**
 * Copyright (c) 2026. Volodymyr Hryvinskyi. All rights reserved.
 * Author: Volodymyr Hryvinskyi <volodymyr@hryvinskyi.com>
 * GitHub: https://github.com/hryvinskyi
 */

declare(strict_types=1);

namespace Hryvinskyi\McpServerActivityLog\Model\Search;

use Hryvinskyi\ActivityLog\Api\Data\Search\EventDetailInterface;
use Hryvinskyi\ActivityLog\Api\Data\Search\EventDetailInterfaceFactory;
use Hryvinskyi\ActivityLog\Api\Data\Search\Verb;
use Hryvinskyi\ActivityLog\Api\Search\EventDetailProviderInterface;
use Hryvinskyi\McpServerActivityLog\Model\McpChannel;
use Hryvinskyi\McpServerActivityLog\Model\ResourceModel\CallStore;
use Magento\Backend\Model\UrlInterface;
use Magento\Framework\AuthorizationInterface;

/**
 * MCP calls as search results describe them: who called which ability, and who tried to change or delete through one
 *
 * A call the server refused (or that waits for its confirmation) of an ability that writes tried to change; of one
 * that deletes, tried to delete. Any other call performed its ability. The subject is the ability, linked to the MCP
 * Server page for an admin who may open it. The calls of a page of activities are read in one query.
 *
 * @phpstan-import-type CallDetail from CallStore
 */
class McpCallDetails implements EventDetailProviderInterface
{
    private const SERVER_PAGE = 'hryvinskyi_mcp/server/index';
    private const SERVER_PAGE_RESOURCE = 'Hryvinskyi_McpServer::view';
    private const REFUSED = ['denied', 'needs_confirmation'];
    private const TRIED = ['write' => Verb::TriedToChange, 'delete' => Verb::TriedToDelete];

    /**
     * @param CallStore $store
     * @param EventDetailInterfaceFactory $detailFactory
     * @param UrlInterface $url
     * @param AuthorizationInterface $authorization
     */
    public function __construct(
        private readonly CallStore $store,
        private readonly EventDetailInterfaceFactory $detailFactory,
        private readonly UrlInterface $url,
        private readonly AuthorizationInterface $authorization
    ) {
    }

    /**
     * @inheritDoc
     */
    public function supports(string $channel): bool
    {
        return $channel === McpChannel::CODE;
    }

    /**
     * @inheritDoc
     */
    public function detailsFor(array $activityIds, array $subjectLabels = []): array
    {
        $calls = $this->store->findMany($activityIds);
        if ($calls === []) {
            return [];
        }

        $page = $this->authorization->isAllowed(self::SERVER_PAGE_RESOURCE)
            ? $this->url->getUrl(self::SERVER_PAGE)
            : null;
        $details = [];
        foreach ($calls as $activityId => $call) {
            $details[$activityId] = $this->detailOf($call, $page);
        }

        return $details;
    }

    /**
     * The detail of one call
     *
     * @param CallDetail $call
     * @param string|null $page
     * @return EventDetailInterface
     */
    private function detailOf(array $call, ?string $page): EventDetailInterface
    {
        $verb = in_array($call['outcome'], self::REFUSED, true)
            ? (self::TRIED[$call['access'] ?? ''] ?? Verb::Performed)
            : Verb::Performed;

        return $this->detailFactory->create()
            ->withVerb($verb)
            ->withSubject((string) __('Ability'), $page)
            ->withSubjectLabel($call['ability']);
    }
}
