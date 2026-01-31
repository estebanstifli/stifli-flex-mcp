# =============================================================================
# WooCommerce MCP Tools Test Script
# Tests all WooCommerce tools and generates a report
# =============================================================================

param(
    [string]$BaseUrl = "http://pruebaswp.local",
    [string]$Username = "esteban",
    [string]$AppPassword = "RXcFyT9dRumPyIexLZyPPyBt"
)

$ErrorActionPreference = "Continue"
$endpoint = "$BaseUrl/wp-json/stifli-flex-mcp/v1/messages"

# Auth header
$creds = [Convert]::ToBase64String([Text.Encoding]::ASCII.GetBytes("${Username}:${AppPassword}"))
$headers = @{
    "Content-Type" = "application/json"
    "Authorization" = "Basic $creds"
}

# Results tracking
$results = @{
    Passed = @()
    Failed = @()
    Skipped = @()
}
$createdIds = @{
    product = $null
    category = $null
    tag = $null
    order = $null
    coupon = $null
    shipping_zone = $null
    tax_rate = $null
    webhook = $null
    order_note = $null
    review = $null
}

function Invoke-MCPTool {
    param(
        [string]$ToolName,
        [hashtable]$Arguments = @{},
        [int]$Id = (Get-Random -Maximum 99999)
    )
    
    $body = @{
        jsonrpc = "2.0"
        method = "tools/call"
        params = @{
            name = $ToolName
            arguments = $Arguments
        }
        id = $Id
    } | ConvertTo-Json -Depth 10
    
    try {
        $response = Invoke-WebRequest -Uri $endpoint -Method POST -Headers $headers -Body $body -UseBasicParsing -TimeoutSec 30
        $json = $response.Content | ConvertFrom-Json
        
        if ($json.error) {
            return @{
                Success = $false
                Error = $json.error.message
                Data = $null
            }
        }
        
        # Extract text content
        $text = ""
        if ($json.result.content) {
            foreach ($c in $json.result.content) {
                if ($c.type -eq "text") {
                    $text += $c.text
                }
            }
        }
        
        return @{
            Success = $true
            Error = $null
            Data = $text
        }
    }
    catch {
        return @{
            Success = $false
            Error = $_.Exception.Message
            Data = $null
        }
    }
}

function Test-Tool {
    param(
        [string]$Name,
        [string]$Description,
        [Parameter(ValueFromRemainingArguments=$false)]
        $Args,
        [scriptblock]$ExtractId = $null
    )
    
    if ($null -eq $Args) { $Args = @{} }
    
    Write-Host -NoNewline "  Testing $Name... "
    $result = Invoke-MCPTool -ToolName $Name -Arguments $Args
    
    if ($result.Success) {
        Write-Host "PASS" -ForegroundColor Green
        $responsePreview = if ($result.Data.Length -gt 200) { $result.Data.Substring(0, 200) } else { $result.Data }
        $script:results.Passed += @{
            Tool = $Name
            Description = $Description
            Response = $responsePreview
        }
        
        # Extract created ID if needed - try multiple patterns
        if ($ExtractId -and $result.Data) {
            $id = & $ExtractId $result.Data
            Write-Host "    (Created ID: $id)"
            return $id
        }
        return $true
    }
    else {
        Write-Host "FAIL - $($result.Error)" -ForegroundColor Red
        $script:results.Failed += @{
            Tool = $Name
            Description = $Description
            Error = $result.Error
        }
        return $false
    }
}

function Write-Section {
    param([string]$Title)
    Write-Host "`n$("=" * 60)" -ForegroundColor Cyan
    Write-Host " $Title" -ForegroundColor Cyan
    Write-Host "$("=" * 60)" -ForegroundColor Cyan
}

# =============================================================================
# START TESTS
# =============================================================================

Write-Host "`n"
Write-Host "=========================================" -ForegroundColor Yellow
Write-Host " WooCommerce MCP Tools Test Suite" -ForegroundColor Yellow
Write-Host " Endpoint: $endpoint" -ForegroundColor Yellow
Write-Host "=========================================" -ForegroundColor Yellow

# -----------------------------------------------------------------------------
# PRODUCTS
# -----------------------------------------------------------------------------
Write-Section "PRODUCTS"

# Get products
Test-Tool -Name "wc_get_products" -Description "List products" -Args @{ limit = 5 }

# Create product
$productId = Test-Tool -Name "wc_create_product" -Description "Create product" -Args @{
    name = "Test Product $(Get-Date -Format 'HHmmss')"
    regular_price = "99.99"
    description = "Test product created by automated test"
    status = "publish"
} -ExtractId { param($data) if ($data -match 'ID:\s*(\d+)') { $matches[1] } }

if ($productId -and $productId -ne $true) {
    $createdIds.product = $productId
    
    # Update product
    Test-Tool -Name "wc_update_product" -Description "Update product" -Args @{
        product_id = [int]$productId
        regular_price = "79.99"
        sale_price = "69.99"
    }
    
    # Update stock
    Test-Tool -Name "wc_update_stock" -Description "Update stock quantity" -Args @{
        product_id = [int]$productId
        quantity = 100
    }
    
    # Set stock status
    Test-Tool -Name "wc_set_stock_status" -Description "Set stock status" -Args @{
        product_id = [int]$productId
        status = "instock"
    }
}

# Batch update
Test-Tool -Name "wc_batch_update_products" -Description "Batch update products" -Args @{
    updates = @(@{ product_id = 145; regular_price = "31.99" })
}

# -----------------------------------------------------------------------------
# PRODUCT CATEGORIES
# -----------------------------------------------------------------------------
Write-Section "PRODUCT CATEGORIES"

Test-Tool -Name "wc_get_product_categories" -Description "List categories"

$catId = Test-Tool -Name "wc_create_product_category" -Description "Create category" -Args @{
    name = "Test Category $(Get-Date -Format 'HHmmss')"
    description = "Auto-created test category"
} -ExtractId { param($data) if ($data -match 'ID:\s*(\d+)') { $matches[1] } }

if ($catId -and $catId -ne $true) {
    $createdIds.category = $catId
    
    Test-Tool -Name "wc_update_product_category" -Description "Update category" -Args @{
        category_id = [int]$catId
        description = "Updated description"
    }
}

# -----------------------------------------------------------------------------
# PRODUCT TAGS
# -----------------------------------------------------------------------------
Write-Section "PRODUCT TAGS"

Test-Tool -Name "wc_get_product_tags" -Description "List tags"

$tagId = Test-Tool -Name "wc_create_product_tag" -Description "Create tag" -Args @{
    name = "TestTag$(Get-Date -Format 'HHmmss')"
} -ExtractId { param($data) if ($data -match 'ID:\s*(\d+)') { $matches[1] } }

if ($tagId -and $tagId -ne $true) {
    $createdIds.tag = $tagId
    
    Test-Tool -Name "wc_update_product_tag" -Description "Update tag" -Args @{
        tag_id = [int]$tagId
        description = "Updated tag"
    }
}

# -----------------------------------------------------------------------------
# PRODUCT REVIEWS
# -----------------------------------------------------------------------------
Write-Section "PRODUCT REVIEWS"

Test-Tool -Name "wc_get_product_reviews" -Description "List reviews"

if ($createdIds.product) {
    $reviewId = Test-Tool -Name "wc_create_product_review" -Description "Create review" -Args @{
        product_id = [int]$createdIds.product
        content = "Excellent test product! 5 stars!"
        author = "Test Reviewer"
        email = "test@example.com"
        rating = 5
    } -ExtractId { param($data) if ($data -match 'ID:\s*(\d+)') { $matches[1] } }
    
    if ($reviewId -and $reviewId -ne $true) {
        $createdIds.review = $reviewId
        
        Test-Tool -Name "wc_update_product_review" -Description "Update review" -Args @{
            review_id = [int]$reviewId
            content = "Updated review text"
        }
    }
}

# -----------------------------------------------------------------------------
# PRODUCT VARIATIONS (with variable product)
# -----------------------------------------------------------------------------
Write-Section "PRODUCT VARIATIONS"

# Create a variable product first
$variableProductId = Test-Tool -Name "wc_create_product" -Description "Create variable product" -Args @{
    name = "Variable Test Product $(Get-Date -Format 'HHmmss')"
    type = "variable"
    regular_price = "50.00"
    attributes = @(
        @{
            name = "Size"
            options = @("Small", "Medium", "Large")
            visible = $true
            variation = $true
        }
    )
}

if ($variableProductId -and $variableProductId -ne $true) {
    $createdIds.variable_product = $variableProductId
    
    Test-Tool -Name "wc_get_product_variations" -Description "Get variations" -Args @{
        product_id = [int]$variableProductId
    }
    
    # Cleanup variable product
    Test-Tool -Name "wc_delete_product" -Description "Delete variable product" -Args @{
        product_id = [int]$variableProductId
        force = $true
    }
}

# -----------------------------------------------------------------------------
# ORDERS
# -----------------------------------------------------------------------------
Write-Section "ORDERS"

Test-Tool -Name "wc_get_orders" -Description "List orders" -Args @{ limit = 5 }

$orderId = Test-Tool -Name "wc_create_order" -Description "Create order" -Args @{
    status = "pending"
    billing = @{
        first_name = "Test"
        last_name = "Customer"
        email = "test@example.com"
        address_1 = "123 Test Street"
        city = "Test City"
        postcode = "12345"
        country = "ES"
    }
    line_items = @(
        @{
            product_id = 145
            quantity = 2
        }
    )
} -ExtractId { param($data) if ($data -match 'ID:\s*(\d+)') { $matches[1] } }

if ($orderId -and $orderId -ne $true) {
    $createdIds.order = $orderId
    
    Test-Tool -Name "wc_update_order" -Description "Update order status" -Args @{
        order_id = [int]$orderId
        status = "processing"
    }
    
    # Order notes
    $noteId = Test-Tool -Name "wc_create_order_note" -Description "Create order note" -Args @{
        order_id = [int]$orderId
        note = "Test note added by automated script"
    } -ExtractId { param($data) if ($data -match 'ID:\s*(\d+)') { $matches[1] } }
    
    if ($noteId -and $noteId -ne $true) {
        $createdIds.order_note = $noteId
    }
    
    Test-Tool -Name "wc_get_order_notes" -Description "Get order notes" -Args @{
        order_id = [int]$orderId
    }
}

# Batch update orders
Test-Tool -Name "wc_batch_update_orders" -Description "Batch update orders" -Args @{
    updates = @(@{ order_id = if($createdIds.order) { [int]$createdIds.order } else { 1 }; status = "on-hold" })
}

# -----------------------------------------------------------------------------
# REFUNDS
# -----------------------------------------------------------------------------
Write-Section "REFUNDS"

Test-Tool -Name "wc_get_refunds" -Description "List refunds" -Args @{ order_id = if($createdIds.order) { [int]$createdIds.order } else { 1 } }

# Skip refund creation as it requires a paid order

# -----------------------------------------------------------------------------
# COUPONS
# -----------------------------------------------------------------------------
Write-Section "COUPONS"

Test-Tool -Name "wc_get_coupons" -Description "List coupons"

$couponId = Test-Tool -Name "wc_create_coupon" -Description "Create coupon" -Args @{
    code = "TEST$(Get-Date -Format 'HHmmss')"
    discount_type = "percent"
    amount = "15"
    description = "Test coupon 15% off"
} -ExtractId { param($data) if ($data -match 'ID:\s*(\d+)') { $matches[1] } }

if ($couponId -and $couponId -ne $true) {
    $createdIds.coupon = $couponId
    
    Test-Tool -Name "wc_update_coupon" -Description "Update coupon" -Args @{
        id = [int]$couponId
        amount = "20"
    }
}

# -----------------------------------------------------------------------------
# TAX
# -----------------------------------------------------------------------------
Write-Section "TAX SETTINGS"

Test-Tool -Name "wc_get_tax_classes" -Description "List tax classes"
Test-Tool -Name "wc_get_tax_rates" -Description "List tax rates"

$taxId = Test-Tool -Name "wc_create_tax_rate" -Description "Create tax rate" -Args @{
    country = "ES"
    rate = "21"
    name = "IVA Test"
    class = "standard"
} -ExtractId { param($data) if ($data -match 'ID:\s*(\d+)') { $matches[1] } }

if ($taxId -and $taxId -ne $true) {
    $createdIds.tax_rate = $taxId
    
    Test-Tool -Name "wc_update_tax_rate" -Description "Update tax rate" -Args @{
        id = [int]$taxId
        rate = "10"
    }
}

# -----------------------------------------------------------------------------
# SHIPPING
# -----------------------------------------------------------------------------
Write-Section "SHIPPING"

Test-Tool -Name "wc_get_shipping_zones" -Description "List shipping zones"

$zoneId = Test-Tool -Name "wc_create_shipping_zone" -Description "Create shipping zone" -Args @{
    name = "Test Zone $(Get-Date -Format 'HHmmss')"
} -ExtractId { param($data) if ($data -match 'ID:\s*(\d+)') { $matches[1] } }

if ($zoneId -and $zoneId -ne $true) {
    $createdIds.shipping_zone = $zoneId
    
    Test-Tool -Name "wc_update_shipping_zone" -Description "Update shipping zone" -Args @{
        id = [int]$zoneId
        name = "Updated Zone Name"
    }
    
    Test-Tool -Name "wc_get_shipping_zone_methods" -Description "Get zone methods" -Args @{
        zone_id = [int]$zoneId
    }
}

# -----------------------------------------------------------------------------
# PAYMENT GATEWAYS
# -----------------------------------------------------------------------------
Write-Section "PAYMENT GATEWAYS"

Test-Tool -Name "wc_get_payment_gateways" -Description "List payment gateways"

Test-Tool -Name "wc_update_payment_gateway" -Description "Update payment gateway" -Args @{
    id = "cod"
    enabled = $true
    title = "Cash on Delivery (Test)"
}

# -----------------------------------------------------------------------------
# SYSTEM & SETTINGS
# -----------------------------------------------------------------------------
Write-Section "SYSTEM & SETTINGS"

Test-Tool -Name "wc_get_system_status" -Description "Get system status"
Test-Tool -Name "wc_get_sales_report" -Description "Get sales report"
Test-Tool -Name "wc_get_top_sellers_report" -Description "Get top sellers report" -Args @{ period = "month" }
Test-Tool -Name "wc_get_settings" -Description "Get settings" -Args @{ group = "general" }

Test-Tool -Name "wc_update_setting_option" -Description "Update setting" -Args @{
    group = "general"
    id = "woocommerce_store_city"
    value = "Test City"
}

# System status tools (be careful with these)
# Test-Tool -Name "wc_run_system_status_tool" -Description "Run system tool" -Args @{ tool_id = "clear_transients" }

# -----------------------------------------------------------------------------
# WEBHOOKS
# -----------------------------------------------------------------------------
Write-Section "WEBHOOKS"

Test-Tool -Name "wc_get_webhooks" -Description "List webhooks"

$webhookId = Test-Tool -Name "wc_create_webhook" -Description "Create webhook" -Args @{
    name = "Test Webhook $(Get-Date -Format 'HHmmss')"
    topic = "order.created"
    delivery_url = "https://example.com/webhook-test"
    status = "disabled"
} -ExtractId { param($data) if ($data -match 'ID:\s*(\d+)') { $matches[1] } }

if ($webhookId -and $webhookId -ne $true) {
    $createdIds.webhook = $webhookId
    
    Test-Tool -Name "wc_update_webhook" -Description "Update webhook" -Args @{
        id = [int]$webhookId
        name = "Updated Webhook Name"
    }
}

# -----------------------------------------------------------------------------
# CLEANUP
# -----------------------------------------------------------------------------
Write-Section "CLEANUP (Deleting test data)"

# Delete in reverse order of dependencies
if ($createdIds.webhook) {
    Test-Tool -Name "wc_delete_webhook" -Description "Delete webhook" -Args @{ id = [int]$createdIds.webhook }
}

if ($createdIds.order_note -and $createdIds.order) {
    Test-Tool -Name "wc_delete_order_note" -Description "Delete order note" -Args @{
        order_id = [int]$createdIds.order
        note_id = [int]$createdIds.order_note
    }
}

if ($createdIds.order) {
    Test-Tool -Name "wc_delete_order" -Description "Delete order" -Args @{ order_id = [int]$createdIds.order; force = $true }
}

if ($createdIds.review) {
    Test-Tool -Name "wc_delete_product_review" -Description "Delete review" -Args @{ review_id = [int]$createdIds.review }
}

if ($createdIds.product) {
    Test-Tool -Name "wc_delete_product" -Description "Delete product" -Args @{ product_id = [int]$createdIds.product; force = $true }
}

if ($createdIds.category) {
    Test-Tool -Name "wc_delete_product_category" -Description "Delete category" -Args @{ category_id = [int]$createdIds.category }
}

if ($createdIds.tag) {
    Test-Tool -Name "wc_delete_product_tag" -Description "Delete tag" -Args @{ tag_id = [int]$createdIds.tag }
}

if ($createdIds.coupon) {
    Test-Tool -Name "wc_delete_coupon" -Description "Delete coupon" -Args @{ id = [int]$createdIds.coupon; force = $true }
}

if ($createdIds.tax_rate) {
    Test-Tool -Name "wc_delete_tax_rate" -Description "Delete tax rate" -Args @{ id = [int]$createdIds.tax_rate }
}

if ($createdIds.shipping_zone) {
    Test-Tool -Name "wc_delete_shipping_zone" -Description "Delete shipping zone" -Args @{ id = [int]$createdIds.shipping_zone }
}

# =============================================================================
# REPORT
# =============================================================================
Write-Host "`n"
Write-Host "=========================================" -ForegroundColor Yellow
Write-Host " TEST REPORT" -ForegroundColor Yellow
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

# Export report to file
$reportPath = Join-Path $PSScriptRoot "wc-test-report-$(Get-Date -Format 'yyyyMMdd-HHmmss').json"
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
