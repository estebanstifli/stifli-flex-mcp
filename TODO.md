# Easy Visual MCP — Tasks

This file tracks the main work items to make the plugin robust and ready for OpenAI/ChatGPT-style integrations.

## Top priority

- [ ] Implement OpenAI/ChatGPT `functions` adapter and basic parameter validation (in-progress)
- [ ] Map tools -> WP capability and require token for mutating tools
- [ ] Add token validation helper in `mod.php` and document how to configure it

## Medium priority

- [ ] Add `parameters` JSON Schema compatible with OpenAI for every tool in `getTools()` (adapter may populate this automatically)
- [ ] Add stricter validation of incoming arguments against the schema
- [ ] Improve logging and error mapping (WP_Error -> JSON-RPC)

## Low priority / Nice to have

- [ ] Add smoke-test script (PowerShell) to validate common flows: tools/list, mcp_ping, create page, upload image (with token)
- [ ] Update `readme.txt` with examples for registering the endpoint as external functions in ChatGPT
- [ ] Add more tools (media advanced, taxonomies, plugins/themes management) and tests

## Notes

- Some hosts/WAFs may block large HTML bodies or certain patterns – keep a fallback update-by-chunks strategy and require authentication for large writes.
