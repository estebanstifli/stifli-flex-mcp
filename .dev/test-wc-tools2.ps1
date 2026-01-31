# =============================================================================
# WooCommerce MCP Tools Test Suite - Part 2 (Complete)
# Tests: Variations, Order Notes, Refunds, Full CRUD for tax/shipping/webhooks/coupons
# =============================================================================

$endpoint = "http://pruebaswp.local/wp-json/stifli-flex-mcp/v1/messages"
$username = "esteban"
$appPassword = "RXcF yT9d RumP yIex LZyP PyBt"
$auth = [Convert]::ToBase64String([Text.Encoding]::ASCII.GetBytes("${username}:${appPassword}"))

$results = @{
    Passed = @()
    Failed = @()
}

function Invoke-MCPTool {
    param(
        [string]$ToolName,
        $Arguments = @{}
    )
    
    $body = @{
        jsonrpc = "2.0"
        method = "tools/call"
        params = @{
            name = $ToolName
            arguments = $Arguments
        }
        id = [System.Guid]::NewGuid().ToString()
    } | ConvertTo-Json -Depth 10
    
    try {
        $response = Invoke-RestMethod -Uri $endpoint -Method POST -Headers @{
            "Authorization" = "Basic $auth"
            "Content-Type" = "application/json"
        } -Body $body -ErrorAction Stop
        
        return $response
    }
    catch {
        return @{ error = @{ message = $_.Exception.Message } }
    }
}

function Test-Tool {
    param(
        [string]$Name,
        [string]$Description = "",
        $ToolArgs = @{}
    )
    
    Write-Host "  Testing $Name..." -NoNewline
    
    $response = Invoke-MCPTool -ToolName $Name -Arguments $ToolArgs
    
    if ($response.error) {
        Write-Host " FAIL - $($response.error.message)" -ForegroundColor Red
        $script:results.Failed += @{ Tool = $Name; Error = $response.error.message }
        return $null
    }
    
    # Check for result content
    $text = ""
    if ($response.result -and $response.result.content) {
        $text = ($response.result.content | Where-Object { $_.type -eq 'text' } | Select-Object -First 1).text
    }
    
    if ($text -match 'error|failed' -and $text -notmatch 'No .* found|Found 0') {
        Write-Host " FAIL - $text" -ForegroundColor Red
        $script:results.Failed += @{ Tool = $Name; Error = $text }
        return $null
    }
    
    Write-Host " PASS" -ForegroundColor Green
    $script:results.Passed += @{ Tool = $Name; Description = $Description }
    
    # Try to extract ID
    if ($text -match 'ID:\s*(\d+)') {
        $id = $matches[1]
        Write-Host "    (ID: $id)" -ForegroundColor Cyan
        return [int]$id
    }
    
    return $true
}

function Write-Section {
    param([string]$Title)
    Write-Host "`n============================================================" -ForegroundColor Yellow
    Write-Host " $Title" -ForegroundColor Yellow
    Write-Host "============================================================" -ForegroundColor Yellow
}

# =============================================================================
Write-Host "`n=========================================" -ForegroundColor Cyan
Write-Host " WooCommerce MCP Tools Test Suite - Part 2" -ForegroundColor Cyan
Write-Host " Endpoint: $endpoint" -ForegroundColor Cyan
Write-Host "=========================================" -ForegroundColor Cyan

# =============================================================================
# VARIABLE PRODUCTS & VARIATIONS
# =============================================================================
Write-Section "VARIABLE PRODUCTS & VARIATIONS"

# Create a variable product with attributes
$variableProductId = Test-Tool -Name "wc_create_product" -Description "Create variable product" -ToolArgs @{
    name = "Camiseta Variable Test $(Get-Date -Format 'HHmmss')"
    type = "variable"
    description = "Test variable product for MCP testing"
    status = "publish"
    attributes = @(
        @{
            name = "Color"
            visible = $true
            variation = $true
            options = @("Rojo", "Azul", "Verde")
        },
        @{
            name = "Talla"
            visible = $true
            variation = $true
            options = @("S", "M", "L", "XL")
        }
    )
}

if ($variableProductId -and $variableProductId -ne $true) {
    # Get variations (should be empty initially)
    Test-Tool -Name "wc_get_product_variations" -Description "Get variations (empty)" -ToolArgs @{
        product_id = $variableProductId
    }
    
    # Create a variation
    $variationId = Test-Tool -Name "wc_create_product_variation" -Description "Create variation" -ToolArgs @{
        product_id = $variableProductId
        regular_price = "29.99"
        sku = "VAR-TEST-$(Get-Date -Format 'HHmmss')"
        stock_quantity = 50
        attributes = @{
            Color = "Rojo"
            Talla = "M"
        }
    }
    
    if ($variationId -and $variationId -ne $true) {
        # Update the variation
        Test-Tool -Name "wc_update_product_variation" -Description "Update variation" -ToolArgs @{
            product_id = $variableProductId
            variation_id = $variationId
            regular_price = "24.99"
            sale_price = "19.99"
            stock_quantity = 100
        }
        
        # Get variations again
        Test-Tool -Name "wc_get_product_variations" -Description "Get variations (with data)" -ToolArgs @{
            product_id = $variableProductId
        }
        
        # Delete variation
        Test-Tool -Name "wc_delete_product_variation" -Description "Delete variation" -ToolArgs @{
            product_id = $variableProductId
            variation_id = $variationId
            force = $true
        }
    }
    
    # Delete variable product
    Test-Tool -Name "wc_delete_product" -Description "Delete variable product" -ToolArgs @{
        product_id = $variableProductId
        force = $true
    }
}

# =============================================================================
# STOCK MANAGEMENT
# =============================================================================
Write-Section "STOCK MANAGEMENT"

Test-Tool -Name "wc_get_low_stock_products" -Description "Get low stock products" -ToolArgs @{
    threshold = 10
    limit = 5
}

# =============================================================================
# ORDER NOTES
# =============================================================================
Write-Section "ORDER NOTES"

# Create an order first
$orderId = Test-Tool -Name "wc_create_order" -Description "Create order for notes test" -ToolArgs @{
    status = "pending"
    billing = @{
        first_name = "Test"
        last_name = "Notes"
        email = "test-notes@example.com"
    }
}

if ($orderId -and $orderId -ne $true) {
    # Get order notes
    Test-Tool -Name "wc_get_order_notes" -Description "Get order notes" -ToolArgs @{
        order_id = $orderId
    }
    
    # Create order note
    $noteId = Test-Tool -Name "wc_create_order_note" -Description "Create order note" -ToolArgs @{
        order_id = $orderId
        note = "Test note from automated MCP test suite"
        is_customer_note = $false
    }
    
    # Create another note (customer visible)
    Test-Tool -Name "wc_create_order_note" -Description "Create customer note" -ToolArgs @{
        order_id = $orderId
        note = "Your order is being processed!"
        is_customer_note = $true
    }
    
    # Delete order note if we got the ID
    if ($noteId -and $noteId -ne $true) {
        Test-Tool -Name "wc_delete_order_note" -Description "Delete order note" -ToolArgs @{
            order_id = $orderId
            note_id = $noteId
        }
    }
    
    # Create refund
    $refundId = Test-Tool -Name "wc_create_refund" -Description "Create refund" -ToolArgs @{
        order_id = $orderId
        amount = "5.00"
        reason = "Test refund from MCP test suite"
    }
    
    if ($refundId -and $refundId -ne $true) {
        # Get refunds for order
        Test-Tool -Name "wc_get_refunds" -Description "Get refunds for order" -ToolArgs @{
            order_id = $orderId
        }
        
        # Delete refund
        Test-Tool -Name "wc_delete_refund" -Description "Delete refund" -ToolArgs @{
            refund_id = $refundId
            force = $true
        }
    }
    
    # Delete order
    Test-Tool -Name "wc_delete_order" -Description "Delete order" -ToolArgs @{
        order_id = $orderId
        force = $true
    }
}

# =============================================================================
# COUPONS (Full CRUD)
# =============================================================================
Write-Section "COUPONS (Full CRUD)"

$couponId = Test-Tool -Name "wc_create_coupon" -Description "Create coupon" -ToolArgs @{
    code = "TEST$(Get-Date -Format 'HHmmss')"
    discount_type = "percent"
    amount = "15"
    description = "Test coupon from MCP"
    usage_limit = 100
    individual_use = $true
}

if ($couponId -and $couponId -ne $true) {
    Test-Tool -Name "wc_update_coupon" -Description "Update coupon" -ToolArgs @{
        id = $couponId
        amount = "20"
        description = "Updated test coupon"
    }
    
    Test-Tool -Name "wc_delete_coupon" -Description "Delete coupon" -ToolArgs @{
        id = $couponId
        force = $true
    }
}

# =============================================================================
# TAX RATES (Full CRUD)
# =============================================================================
Write-Section "TAX RATES (Full CRUD)"

$taxRateId = Test-Tool -Name "wc_create_tax_rate" -Description "Create tax rate" -ToolArgs @{
    country = "ES"
    state = "B"
    rate = "21.00"
    name = "IVA Test"
    priority = 1
    compound = $false
    shipping = $true
}

if ($taxRateId -and $taxRateId -ne $true) {
    Test-Tool -Name "wc_update_tax_rate" -Description "Update tax rate" -ToolArgs @{
        id = $taxRateId
        rate = "10.00"
        name = "IVA Reducido Test"
    }
    
    Test-Tool -Name "wc_delete_tax_rate" -Description "Delete tax rate" -ToolArgs @{
        id = $taxRateId
    }
}

# =============================================================================
# SHIPPING ZONES (Full CRUD)
# =============================================================================
Write-Section "SHIPPING ZONES (Full CRUD)"

$zoneId = Test-Tool -Name "wc_create_shipping_zone" -Description "Create shipping zone" -ToolArgs @{
    name = "Test Zone $(Get-Date -Format 'HHmmss')"
}

if ($zoneId -and $zoneId -ne $true) {
    Test-Tool -Name "wc_update_shipping_zone" -Description "Update shipping zone" -ToolArgs @{
        id = $zoneId
        name = "Updated Test Zone"
    }
    
    Test-Tool -Name "wc_get_shipping_zone_methods" -Description "Get zone methods" -ToolArgs @{
        zone_id = $zoneId
    }
    
    Test-Tool -Name "wc_delete_shipping_zone" -Description "Delete shipping zone" -ToolArgs @{
        id = $zoneId
    }
}

# =============================================================================
# WEBHOOKS (Full CRUD)
# =============================================================================
Write-Section "WEBHOOKS (Full CRUD)"

$webhookId = Test-Tool -Name "wc_create_webhook" -Description "Create webhook" -ToolArgs @{
    name = "Test Webhook $(Get-Date -Format 'HHmmss')"
    topic = "order.created"
    delivery_url = "https://example.com/webhook-test"
    status = "active"
}

if ($webhookId -and $webhookId -ne $true) {
    Test-Tool -Name "wc_update_webhook" -Description "Update webhook" -ToolArgs @{
        id = $webhookId
        status = "paused"
        name = "Updated Test Webhook"
    }
    
    Test-Tool -Name "wc_delete_webhook" -Description "Delete webhook" -ToolArgs @{
        id = $webhookId
    }
}

# =============================================================================
# SYSTEM TOOLS
# =============================================================================
Write-Section "SYSTEM TOOLS"

Test-Tool -Name "wc_run_system_status_tool" -Description "Run clear_transients" -ToolArgs @{
    tool = "clear_transients"
}

Test-Tool -Name "wc_get_settings" -Description "Get general settings" -ToolArgs @{
    group = "general"
}

Test-Tool -Name "wc_get_settings" -Description "Get products settings" -ToolArgs @{
    group = "products"
}

# =============================================================================
# REPORT
# =============================================================================
Write-Host "`n"
Write-Host "=========================================" -ForegroundColor Yellow
Write-Host " TEST REPORT - Part 2" -ForegroundColor Yellow
Write-Host "=========================================" -ForegroundColor Yellow

$total = $results.Passed.Count + $results.Failed.Count
$passRate = if ($total -gt 0) { [math]::Round(($results.Passed.Count / $total) * 100, 1) } else { 0 }

Write-Host "`nSummary:" -ForegroundColor White
Write-Host "  Total Tests: $total"
Write-Host "  Passed: $($results.Passed.Count)" -ForegroundColor Green
Write-Host "  Failed: $($results.Failed.Count)" -ForegroundColor Red
Write-Host "  Pass Rate: $passRate%"

if ($results.Failed.Count -gt 0) {
    Write-Host "`nFailed Tests:" -ForegroundColor Red
    foreach ($fail in $results.Failed) {
        Write-Host "  - $($fail.Tool): $($fail.Error)" -ForegroundColor Red
    }
}

Write-Host "`nPassed Tests:" -ForegroundColor Green
foreach ($pass in $results.Passed) {
    Write-Host "  - $($pass.Tool)" -ForegroundColor Green
}

# Export report
$reportPath = Join-Path $PSScriptRoot "wc-test-report2-$(Get-Date -Format 'yyyyMMdd-HHmmss').json"
@{
    timestamp = Get-Date -Format "yyyy-MM-dd HH:mm:ss"
    endpoint = $endpoint
    summary = @{
        total = $total
        passed = $results.Passed.Count
        failed = $results.Failed.Count
        passRate = $passRate
    }
    passed = $results.Passed
    failed = $results.Failed
} | ConvertTo-Json -Depth 10 | Out-File $reportPath -Encoding UTF8

Write-Host "`nReport saved to: $reportPath" -ForegroundColor Cyan
Write-Host "`n"
