<?php
/**
 * Copyright (c) 2026. Volodymyr Hryvinskyi. All rights reserved.
 * Author: Volodymyr Hryvinskyi <volodymyr@hryvinskyi.com>
 * GitHub: https://github.com/hryvinskyi
 */

declare(strict_types=1);

namespace Hryvinskyi\McpServerActivityLog\ViewModel;

use Hryvinskyi\McpServerActivityLog\Model\ResourceModel\CallStore;
use Magento\Backend\Model\UrlInterface;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\View\Element\Block\ArgumentInterface;

/**
 * The MCP panel of an activity's page: which client called which ability, how it ended and why, with the masked
 * arguments and result
 *
 * @phpstan-import-type CallDetail from CallStore
 */
class McpCallPanel implements ArgumentInterface
{
    private const JSON_FLAGS = JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
        | JSON_PRESERVE_ZERO_FRACTION;

    /**
     * @param RequestInterface $request
     * @param CallStore $store
     * @param UrlInterface $url
     */
    public function __construct(
        private readonly RequestInterface $request,
        private readonly CallStore $store,
        private readonly UrlInterface $url
    ) {
    }

    /**
     * The call of the activity the page shows, or null when it has none
     *
     * @return CallDetail|null
     */
    public function call(): ?array
    {
        $id = $this->request->getParam('id');

        return is_numeric($id) ? ($this->store->findMany([(int) $id])[(int) $id] ?? null) : null;
    }

    /**
     * Why a call was refused, in words
     *
     * @param string $reason
     * @return string
     */
    public function reasonText(string $reason): string
    {
        return (string) match ($reason) {
            'acl' => __('The caller\'s role lacks a resource the ability needs.'),
            'deny_list' => __('The ability is on the deny-list.'),
            'read_only_mode' => __('Read-only mode is on, and the ability does not only read.'),
            'anonymous_ability' => __('The ability is open to anyone, and Expose Anonymous Abilities is off.'),
            'argument_guard' => __('An argument guard refused the arguments.'),
            'section_off' => __('The ability belongs to a section that is switched off.'),
            'needs_confirmation' => __('The ability needs a confirmed call; the client was asked to confirm.'),
            default => __('Refused by the rule "%1".', $reason),
        };
    }

    /**
     * Stored JSON laid out over lines to read
     *
     * @param string|null $json
     * @return string
     */
    public function readable(?string $json): string
    {
        if ($json === null || $json === '') {
            return '';
        }

        $decoded = json_decode($json, false, 512, JSON_BIGINT_AS_STRING);
        $pretty = json_last_error() === JSON_ERROR_NONE ? json_encode($decoded, self::JSON_FLAGS) : false;

        return $pretty === false ? $json : $pretty;
    }

    /**
     * The MCP Server page, where the ability's exposure is managed
     *
     * @return string
     */
    public function serverPageUrl(): string
    {
        return $this->url->getUrl('hryvinskyi_mcp/server/index');
    }
}
