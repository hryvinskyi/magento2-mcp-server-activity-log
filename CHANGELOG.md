# Changelog

All notable changes to this project are documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [1.1.0] - 2026-11-07

### Added
- A call refused because its section is switched off says so on its activity's page.
- **MCP call logging** in the Activity Log menu, under Settings: it opens the MCP Server settings, where the activity
  log's settings of this module are. It is shown to an admin who may change the MCP Server settings.

### Changed
- Requires `hryvinskyi/magento2-mcp-server` ^1.3. A call's result is stored as the client received it: with the
  store's personal data hidden.
- The Activity Log menu entries of this module moved into the menu's groups, each with an icon: **MCP calls** (was
  "MCP Calls") is under Logs, **MCP Server** under Pages. Their item names are unchanged.
- Requires `hryvinskyi/magento2-activity-log` ^1.3, which declares the menu's groups.

## [1.0.0] - 2026-10-23

### Added
- Every MCP ability call is an activity of the MCP channel, attributed to the caller via the client, with the
  ability's changes in the same operation.
- The call's detail: client, transport, source, access, required ACL, denial reason, and the masked arguments and
  result (cut to 256 KB).
- "Tried to change" and "tried to delete" for refused calls of abilities that write or delete.
- The MCP panel on an activity's page, the Top abilities and Denied MCP calls dashboard widgets, the clients of the
  last 24 hours on the MCP Server page, and menu entries in both menus.
- The setting **Keep MCP Calls (days)** (90 by default).
