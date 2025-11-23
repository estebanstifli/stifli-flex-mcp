# Development Files

This directory contains files used during plugin development that are **not** included in the WordPress.org distribution.

## Contents

### Documentation
- `MIGRACION_TOOLS.md` - Tool migration guide from ai-copilot
- `PERFILES_DESIGN.md` - Profile system design documentation
- `TODO.md` - Development tasks and roadmap
- `WOOCOMMERCE_TOOLS.md` - WooCommerce tools documentation
- `WORDPRESS_ORG_COMPLIANCE.md` - WordPress.org submission checklist

### Testing Scripts
- `test-mcp-ping.ps1` - PowerShell script to test MCP ping
- `test-query-auth.ps1` - PowerShell script to test authentication
- `test-tools-list.ps1` - PowerShell script to list available tools
- `test-upload-base64.ps1` - PowerShell script to test base64 image upload

### Utilities
- `count_tools.php` - PHP script to count and analyze registered tools

## Usage

These files are for developer reference only. Use the PowerShell scripts to test the plugin's REST API endpoints during development.

**Note:** This entire directory is excluded from the plugin ZIP via `.gitignore` and WordPress.org SVN deployment.
