# mcp/

`server.php` — a JSON-RPC 2.0 server over stdio, exposing the same scores the web app shows as
agent tools.

```json
{ "mcpServers": { "divergence": { "command": "php", "args": ["/home/USER/divergence/mcp/server.php"] } } }
```

No HTTP listener, no port, no framework, and no dependency the shared host has to satisfy: the
protocol surface an MCP client needs is small enough to implement directly, and adding a package
manager to this project to avoid a hundred lines would be the wrong trade.

Every tool is a thin wrapper over `../lib/queries.php`, which is the same file `../public/` reads
through. That is what makes the two surfaces answer identically rather than approximately — see D8.

**No tool takes a write action.** The only writer in the system is cron.

Tool surface: [`../docs/mcp-tools.md`](../docs/mcp-tools.md)
