# mcp/

MCP server exposing the same scores as agent tools. A thin wrapper over the queries `public/`
already needs.

Built last, on purpose — see decision D8 in `../docs/decisions.md`. It cannot exist before the
scores do, and it adds nothing they do not already contain.

Tool surface: [`../docs/mcp-tools.md`](../docs/mcp-tools.md)

No tool takes a write action.
