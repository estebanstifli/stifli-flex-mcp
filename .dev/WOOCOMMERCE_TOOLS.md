# WooCommerce Tools Implementation

## Overview
This plugin now includes **60 WooCommerce tools** organized in 4 modular files, fully integrated with the MCP protocol.

## Architecture

### Modular Structure
```
models/woocommerce/
├── wc-products.php          (21 tools)
├── wc-orders.php            (8 tools)
├── wc-customers-coupons.php (8 tools)
└── wc-system.php            (23 tools)
```

### Integration Points
1. **Auto-loading**: `models/model.php` loads WooCommerce modules conditionally
2. **Tool Registry**: `getTools()` merges WooCommerce tool arrays
3. **Capabilities**: `getToolCapability()` merges capability mappings
4. **Dispatch**: `dispatchTool()` routes `wc_*` tools to appropriate modules
5. **Seeding**: `stifli_flex_mcp_seed_initial_tools()` includes WooCommerce tools when active
6. **Intent Classification**: `getIntentForTool()` classifies WooCommerce operations

## Tools by Category

### Products (21 tools) - `wc-products.php`
- **Products**: `wc_get_products`, `wc_create_product`, `wc_update_product`, `wc_delete_product`, `wc_batch_update_products`
- **Variations**: `wc_get_product_variations`, `wc_create_product_variation`, `wc_update_product_variation`, `wc_delete_product_variation`
- **Categories**: `wc_get_product_categories`, `wc_create_product_category`, `wc_update_product_category`, `wc_delete_product_category`
- **Tags**: `wc_get_product_tags`, `wc_create_product_tag`, `wc_update_product_tag`, `wc_delete_product_tag`
- **Reviews**: `wc_get_product_reviews`, `wc_create_product_review`, `wc_update_product_review`, `wc_delete_product_review`

### Orders (8 tools) - `wc-orders.php`
- **Orders**: `wc_get_orders`, `wc_create_order`, `wc_update_order`, `wc_delete_order`, `wc_batch_update_orders`
- **Order Notes**: `wc_get_order_notes`, `wc_create_order_note`, `wc_delete_order_note`

### Customers & Coupons (8 tools) - `wc-customers-coupons.php`
- **Customers**: `wc_get_customers`, `wc_create_customer`, `wc_update_customer`, `wc_delete_customer`
- **Coupons**: `wc_get_coupons`, `wc_create_coupon`, `wc_update_coupon`, `wc_delete_coupon`

### System (23 tools) - `wc-system.php`
- **Reports**: `wc_get_sales_report`, `wc_get_top_sellers_report`
- **Tax**: `wc_get_tax_classes`, `wc_get_tax_rates`, `wc_create_tax_rate`, `wc_update_tax_rate`, `wc_delete_tax_rate`
- **Shipping**: `wc_get_shipping_zones`, `wc_get_shipping_zone_methods`, `wc_create_shipping_zone`, `wc_update_shipping_zone`, `wc_delete_shipping_zone`
- **Payment Gateways**: `wc_get_payment_gateways`, `wc_update_payment_gateway`
- **System Status**: `wc_get_system_status`, `wc_run_system_status_tool`
- **Settings**: `wc_get_settings`, `wc_update_setting_option`
- **Webhooks**: `wc_get_webhooks`, `wc_create_webhook`, `wc_update_webhook`, `wc_delete_webhook`

## Implementation Details

### API Usage
All tools use official WooCommerce APIs:
- `wc_get_products()`, `wc_get_orders()`, `wc_get_order()` - Data fetching
- `WC_Product`, `WC_Product_Variation` - Product management
- `WC_Order` - Order management
- `WC_Customer` - Customer management
- `WC_Coupon` - Coupon management
- `WC_Tax`, `WC_Shipping_Zones`, `WC_Webhook` - System management
- `wc_create_new_customer()` - Helper functions

### Sanitization
All user inputs are sanitized using WordPress functions:
- `sanitize_text_field()` - Text inputs
- `sanitize_email()` - Email addresses
- `sanitize_key()` - Array keys and slugs
- `sanitize_title()` - Slugs
- `wp_kses_post()` - HTML content
- `esc_url_raw()` - URLs
- `intval()` - Integers
- `floatval()` - Decimals

### Error Handling
Consistent error codes:
- `-50000`: WooCommerce not active
- `-50001`: Required parameter missing
- `-50002`: Resource not found
- `-50003`: Delete operation failed
- `-50004`: WordPress error (from `WP_Error`)
- `-50005`: Unknown tool
- `-50006`: Invalid option name

### Capabilities
Tools are protected by WooCommerce capabilities:
- `edit_products`, `delete_products` - Product operations
- `manage_product_terms` - Categories/tags
- `moderate_comments` - Reviews
- `edit_shop_orders`, `delete_shop_orders` - Order operations
- `edit_users`, `delete_users` - Customer operations
- `edit_shop_coupons`, `delete_shop_coupons` - Coupon operations
- `manage_woocommerce` - System-level operations

### Intent Classification
- **Read**: Public product/category/tag listings
- **Sensitive Read**: Customers, orders, system status, settings (requires confirmation)
- **Write**: All create/update/delete operations (requires confirmation)

## Testing

### Prerequisites
1. WooCommerce plugin must be installed and activated
2. Valid MCP token configured in plugin settings
3. User must have appropriate WooCommerce capabilities

### Example Requests

#### Get Products
```json
{
  "jsonrpc": "2.0",
  "method": "tools/call",
  "params": {
    "name": "wc_get_products",
    "arguments": {
      "limit": 10,
      "status": "publish"
    }
  },
  "id": 1
}
```

#### Create Order
```json
{
  "jsonrpc": "2.0",
  "method": "tools/call",
  "params": {
    "name": "wc_create_order",
    "arguments": {
      "billing": {
        "first_name": "John",
        "last_name": "Doe",
        "email": "john@example.com"
      },
      "line_items": [
        {"product_id": 123, "quantity": 2}
      ]
    }
  },
  "id": 2
}
```

#### Get Sales Report
```json
{
  "jsonrpc": "2.0",
  "method": "tools/call",
  "params": {
    "name": "wc_get_sales_report",
    "arguments": {
      "date_min": "2024-01-01",
      "date_max": "2024-12-31"
    }
  },
  "id": 3
}
```

## Database Integration

All WooCommerce tools are seeded into `wp_SFLMCP_tools` table with categories:
- `WooCommerce - Products`
- `WooCommerce - Categories`
- `WooCommerce - Tags`
- `WooCommerce - Reviews`
- `WooCommerce - Orders`
- `WooCommerce - Customers`
- `WooCommerce - Coupons`
- `WooCommerce - Reports`
- `WooCommerce - Tax`
- `WooCommerce - Shipping`
- `WooCommerce - Gateways`
- `WooCommerce - System`
- `WooCommerce - Settings`
- `WooCommerce - Webhooks`

Tools can be enabled/disabled individually from the admin UI.

## Notes

- All WooCommerce tools only load when WooCommerce is active (`class_exists('WooCommerce')`)
- Tools follow WordPress and WooCommerce coding standards
- All tools return JSON-RPC 2.0 compliant responses
- Batch operations support multiple items in single request
- Complex objects (billing/shipping addresses) use nested arrays
- Date formats: `Y-m-d` (ISO 8601) or `Y-m-d H:i:s` for timestamps
