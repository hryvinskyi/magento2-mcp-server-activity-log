# MCP Server Activity Log

Records every call an MCP client makes to the [MCP server](https://github.com/hryvinskyi/magento2-mcp-server) in the
[activity log](https://github.com/hryvinskyi/magento2-activity-log): who called which ability, through which client,
how the call ended and why, and what the ability changed.

## What it does

- Records each ability call as an activity of the **MCP** channel. The action and the subject are the ability; the
  outcome is how the call ended. A call the server refused (the caller's ACL, the deny-list, read-only mode, an
  ability open to anyone, an argument guard) is **denied**, and so is a call waiting for the client's confirmation.
- Attributes the call to the account whose ACL it ran with, *via* the client that sent it: "jane via Claude Desktop
  0.9". Everything the ability does while the call runs (the entity changes another channel records, for example)
  shares the call's correlation id and the same actor, so **Show related** lists the call with its changes.
- Keeps the call's detail beside the activity: client, version, transport, the ability's source, access and required
  ACL, why it was refused, and the arguments and the result. The result is the one the client received, so the
  personal data the MCP server hides stays hidden here too. Secrets are masked by the activity log's sanitizer
  before anything is stored, and each value is cut to 256 KB. A call waiting for confirmation keeps no message: it
  would carry the one-time confirmation token.
- On the search page, a refused call of an ability that writes reads "tried to change", of one that deletes "tried to
  delete"; any other call "performed" its ability. The ability links to the MCP Server page.
- Shows the call on its activity's page (for an admin with **View the MCP Server page**), two dashboard widgets
  (**Top abilities** and **Denied MCP calls**), and the clients of the last 24 hours on the MCP Server page.
- Adds **MCP calls** (under Logs), **MCP Server** (under Pages) and **MCP call logging** (under Settings, for an admin
  who may change the MCP Server settings) to the Activity Log menu, and **MCP activity** to the MCP Server menu.

## Requirements

- PHP 8.1 or later, Magento 2.4
- [`hryvinskyi/magento2-activity-log`](https://github.com/hryvinskyi/magento2-activity-log) ^1.3
- [`hryvinskyi/magento2-mcp-server`](https://github.com/hryvinskyi/magento2-mcp-server) ^1.3

## Installation

```bash
composer require hryvinskyi/magento2-mcp-server-activity-log
bin/magento module:enable Hryvinskyi_McpServerActivityLog
bin/magento setup:upgrade
```

## Configuration

**Stores › Configuration › Hryvinskyi › MCP Server › Activity Log**:

- **Keep MCP Calls (days)**: 90 by default; 0 keeps them for good. The journal removes older MCP activities, and their
  detail with them.

The activity log's own settings apply: when **Record Activity** is off, no call is recorded, and the secret field names
it lists are masked in arguments and results.

## How it plugs in

- An invocation listener of the MCP server (`listeners` on `Hryvinskyi\McpServer\Model\AbilityInvoker`).
- An actor resolver of the activity log, asked first (`resolvers` on `ActorContext`, sortOrder 5).
- The channel `mcp`, a detail provider for the search page, two dashboard widgets and the `mcp` activity panel.
- Table `hryvinskyi_mcp_activity_call`, one row per activity, removed with its activity.

## License

MIT. See [LICENSE](LICENSE).
