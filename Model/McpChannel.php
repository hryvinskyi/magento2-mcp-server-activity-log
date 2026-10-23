<?php
/**
 * Copyright (c) 2026. Volodymyr Hryvinskyi. All rights reserved.
 * Author: Volodymyr Hryvinskyi <volodymyr@hryvinskyi.com>
 * GitHub: https://github.com/hryvinskyi
 */

declare(strict_types=1);

namespace Hryvinskyi\McpServerActivityLog\Model;

/**
 * The codes the MCP channel of the activity log records under
 */
class McpChannel
{
    /**
     * The channel of every MCP ability call
     */
    public const CODE = 'mcp';

    /**
     * The kind of subject an MCP call acts on: the ability it called
     */
    public const SUBJECT_TYPE = 'mcp_ability';

    /**
     * The setting of how many days the journal keeps MCP calls
     */
    public const XML_PATH_RETENTION_DAYS = 'hryvinskyi_mcp_server/activity_log/retention_days';
}
