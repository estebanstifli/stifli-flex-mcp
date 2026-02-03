# =============================================================================
# Custom Tools MCP Test Script
# Tests custom tools that don't require external plugins
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
        [bool]$ExpectId = $false
    )
    
    Write-Host "  Testing $Name... " -NoNewline
    
    $result = Invoke-MCPTool -ToolName $Name -Arguments $Args
    
    if ($result.Success) {
        Write-Host "PASS" -ForegroundColor Green
        $script:results.Passed += @{ Name = $Name; Description = $Description }
        
        if ($ExpectId -and $result.Data) {
            if ($result.Data -match '(?:ID|id)[:\s]+(\d+)') {
                $id = $matches[1]
                Write-Host "    (ID: $id)" -ForegroundColor Cyan
                return $id
            }
        }
        
        Write-Host $result.Success
        return $result.Data
    }
    else {
        Write-Host "FAIL - $($result.Error)" -ForegroundColor Red
        $script:results.Failed += @{ Name = $Name; Description = $Description; Error = $result.Error }
        Write-Host $result.Success
        return $false
    }
}

function Enable-CustomTool {
    param([string]$ToolName)
    
    # Enable the tool via direct database or AJAX
    # For now we'll test if the tool exists first
    Write-Host "  Enabling $ToolName... " -NoNewline
    
    $body = @{
        jsonrpc = "2.0"
        method = "tools/list"
        id = 1
    } | ConvertTo-Json
    
    $response = Invoke-WebRequest -Uri $endpoint -Method POST -Headers $headers -Body $body -UseBasicParsing
    $json = $response.Content | ConvertFrom-Json
    
    $tool = $json.result.tools | Where-Object { $_.name -eq $ToolName }
    if ($tool) {
        Write-Host "ENABLED" -ForegroundColor Green
        return $true
    }
    else {
        Write-Host "NOT FOUND (needs enabling in admin)" -ForegroundColor Yellow
        return $false
    }
}

function Write-Section {
    param([string]$Title)
    Write-Host ""
    Write-Host ("=" * 60) -ForegroundColor Cyan
    Write-Host " $Title" -ForegroundColor Cyan
    Write-Host ("=" * 60) -ForegroundColor Cyan
}

# =============================================================================
# MAIN TEST SCRIPT
# =============================================================================

Write-Host ""
Write-Host "=========================================" -ForegroundColor Yellow
Write-Host " Custom Tools MCP Test Suite" -ForegroundColor Yellow
Write-Host " Endpoint: $endpoint" -ForegroundColor Yellow
Write-Host "=========================================" -ForegroundColor Yellow

# First, check which custom tools are enabled
Write-Section "CHECKING ENABLED CUSTOM TOOLS"

$body = @{
    jsonrpc = "2.0"
    method = "tools/list"
    id = 1
} | ConvertTo-Json

$response = Invoke-WebRequest -Uri $endpoint -Method POST -Headers $headers -Body $body -UseBasicParsing
$json = $response.Content | ConvertFrom-Json
$customTools = $json.result.tools | Where-Object { $_.name -like "custom_*" }

Write-Host "  Found $($customTools.Count) custom tools enabled:" -ForegroundColor Cyan
foreach ($tool in $customTools) {
    Write-Host "    - $($tool.name)" -ForegroundColor Gray
}

if ($customTools.Count -eq 0) {
    Write-Host ""
    Write-Host "  No custom tools enabled!" -ForegroundColor Red
    Write-Host "  Please enable custom tools in WordPress Admin:" -ForegroundColor Yellow
    Write-Host "  wp-admin/admin.php?page=stifli-flex-mcp&tab=custom" -ForegroundColor Yellow
    Write-Host ""
    Write-Host "  Tools to enable for this test (no plugins needed):" -ForegroundColor Yellow
    Write-Host "    - custom_get_weather" -ForegroundColor Gray
    Write-Host "    - custom_get_ip_info" -ForegroundColor Gray
    Write-Host "    - custom_wp_search" -ForegroundColor Gray
    Write-Host "    - custom_get_page_seo" -ForegroundColor Gray
    Write-Host "    - custom_get_site_info" -ForegroundColor Gray
    Write-Host "    - custom_flush_rewrites" -ForegroundColor Gray
    Write-Host "    - custom_run_cron" -ForegroundColor Gray
    Write-Host ""
    exit 0
}

# -----------------------------------------------------------------------------
# EXTERNAL APIs (no plugins needed)
# -----------------------------------------------------------------------------
Write-Section "EXTERNAL APIs"

# Test weather API
$weatherEnabled = $customTools | Where-Object { $_.name -eq "custom_get_weather" }
if ($weatherEnabled) {
    Test-Tool -Name "custom_get_weather" -Description "Get weather from wttr.in" -Args @{
        location = "Madrid"
    }
}
else {
    Write-Host "  custom_get_weather: SKIPPED (not enabled)" -ForegroundColor Yellow
    $results.Skipped += @{ Name = "custom_get_weather"; Description = "Get weather"; Error = "Not enabled" }
}

# Test IP info API
$ipInfoEnabled = $customTools | Where-Object { $_.name -eq "custom_get_ip_info" }
if ($ipInfoEnabled) {
    Test-Tool -Name "custom_get_ip_info" -Description "Get IP geolocation" -Args @{
        ip = "8.8.8.8"
    }
}
else {
    Write-Host "  custom_get_ip_info: SKIPPED (not enabled)" -ForegroundColor Yellow
    $results.Skipped += @{ Name = "custom_get_ip_info"; Description = "Get IP info"; Error = "Not enabled" }
}

# -----------------------------------------------------------------------------
# INTERNAL WordPress REST API
# -----------------------------------------------------------------------------
Write-Section "INTERNAL WordPress REST API"

# Test WP Search
$wpSearchEnabled = $customTools | Where-Object { $_.name -eq "custom_wp_search" }
if ($wpSearchEnabled) {
    Test-Tool -Name "custom_wp_search" -Description "Search WordPress posts" -Args @{
        term = "hello"
        limit = 5
        status = "publish"
    }
}
else {
    Write-Host "  custom_wp_search: SKIPPED (not enabled)" -ForegroundColor Yellow
    $results.Skipped += @{ Name = "custom_wp_search"; Description = "WP Search"; Error = "Not enabled" }
}

# Test Get Page SEO - need a page ID first
$pageSeoEnabled = $customTools | Where-Object { $_.name -eq "custom_get_page_seo" }
if ($pageSeoEnabled) {
    # Get a page ID first using wp_get_pages
    $pageResult = Invoke-MCPTool -ToolName "wp_get_pages" -Arguments @{ limit = 1 }
    if ($pageResult.Success -and $pageResult.Data -match 'ID[:\s]+(\d+)') {
        $pageId = [int]$matches[1]
        Test-Tool -Name "custom_get_page_seo" -Description "Get page SEO data" -Args @{
            id = $pageId
        }
    }
    else {
        # Try with page ID 2 (sample page)
        Test-Tool -Name "custom_get_page_seo" -Description "Get page SEO data" -Args @{
            id = 2
        }
    }
}
else {
    Write-Host "  custom_get_page_seo: SKIPPED (not enabled)" -ForegroundColor Yellow
    $results.Skipped += @{ Name = "custom_get_page_seo"; Description = "Page SEO"; Error = "Not enabled" }
}

# Test Get Site Info
$siteInfoEnabled = $customTools | Where-Object { $_.name -eq "custom_get_site_info" }
if ($siteInfoEnabled) {
    Test-Tool -Name "custom_get_site_info" -Description "Get WordPress site info" -Args @{}
}
else {
    Write-Host "  custom_get_site_info: SKIPPED (not enabled)" -ForegroundColor Yellow
    $results.Skipped += @{ Name = "custom_get_site_info"; Description = "Site Info"; Error = "Not enabled" }
}

# -----------------------------------------------------------------------------
# WordPress Core ACTIONS
# -----------------------------------------------------------------------------
Write-Section "WordPress Core ACTIONS"

# Test Flush Rewrites
$flushRewritesEnabled = $customTools | Where-Object { $_.name -eq "custom_flush_rewrites" }
if ($flushRewritesEnabled) {
    Test-Tool -Name "custom_flush_rewrites" -Description "Flush WordPress rewrite rules" -Args @{}
}
else {
    Write-Host "  custom_flush_rewrites: SKIPPED (not enabled)" -ForegroundColor Yellow
    $results.Skipped += @{ Name = "custom_flush_rewrites"; Description = "Flush rewrites"; Error = "Not enabled" }
}

# Test Run Cron
$runCronEnabled = $customTools | Where-Object { $_.name -eq "custom_run_cron" }
if ($runCronEnabled) {
    Test-Tool -Name "custom_run_cron" -Description "Run WordPress cron tasks" -Args @{}
}
else {
    Write-Host "  custom_run_cron: SKIPPED (not enabled)" -ForegroundColor Yellow
    $results.Skipped += @{ Name = "custom_run_cron"; Description = "Run cron"; Error = "Not enabled" }
}

# Test Admin Notify (custom action defined in plugin)
$adminNotifyEnabled = $customTools | Where-Object { $_.name -eq "custom_admin_notify" }
if ($adminNotifyEnabled) {
    Test-Tool -Name "custom_admin_notify" -Description "Send admin email notification" -Args @{
        subject = "Test from MCP"
        message = "This is a test message from the Custom Tools test suite."
    }
}
else {
    Write-Host "  custom_admin_notify: SKIPPED (not enabled)" -ForegroundColor Yellow
    $results.Skipped += @{ Name = "custom_admin_notify"; Description = "Admin notify"; Error = "Not enabled" }
}

# Test Maintenance Mode
$maintenanceEnabled = $customTools | Where-Object { $_.name -eq "custom_maintenance_mode" }
if ($maintenanceEnabled) {
    # Only test enable - disable won't work once enabled (documented limitation)
    Write-Host "  custom_maintenance_mode: SKIPPED (would lock out API)" -ForegroundColor Yellow
    $results.Skipped += @{ Name = "custom_maintenance_mode"; Description = "Maintenance mode"; Error = "Would lock out API" }
}
else {
    Write-Host "  custom_maintenance_mode: SKIPPED (not enabled)" -ForegroundColor Yellow
    $results.Skipped += @{ Name = "custom_maintenance_mode"; Description = "Maintenance mode"; Error = "Not enabled" }
}

# -----------------------------------------------------------------------------
# PLUGIN-SPECIFIC: WooCommerce Custom Tools
# -----------------------------------------------------------------------------
Write-Section "WooCommerce Custom Tools"

$wcProductBySkuEnabled = $customTools | Where-Object { $_.name -eq "custom_wc_product_by_sku" }
if ($wcProductBySkuEnabled) {
    Test-Tool -Name "custom_wc_product_by_sku" -Description "Search WC product by SKU" -Args @{
        sku = "test-sku-123"
    }
}
else {
    Write-Host "  custom_wc_product_by_sku: SKIPPED (not enabled)" -ForegroundColor Yellow
    $results.Skipped += @{ Name = "custom_wc_product_by_sku"; Description = "WC Product by SKU"; Error = "Not enabled" }
}

$wcCancelUnpaidEnabled = $customTools | Where-Object { $_.name -eq "custom_wc_cancel_unpaid" }
if ($wcCancelUnpaidEnabled) {
    Test-Tool -Name "custom_wc_cancel_unpaid" -Description "Cancel unpaid WC orders" -Args @{}
}
else {
    Write-Host "  custom_wc_cancel_unpaid: SKIPPED (not enabled)" -ForegroundColor Yellow
    $results.Skipped += @{ Name = "custom_wc_cancel_unpaid"; Description = "WC Cancel unpaid"; Error = "Not enabled" }
}

$wcCleanupSessionsEnabled = $customTools | Where-Object { $_.name -eq "custom_wc_cleanup_sessions" }
if ($wcCleanupSessionsEnabled) {
    Test-Tool -Name "custom_wc_cleanup_sessions" -Description "Cleanup WC sessions" -Args @{}
}
else {
    Write-Host "  custom_wc_cleanup_sessions: SKIPPED (not enabled)" -ForegroundColor Yellow
    $results.Skipped += @{ Name = "custom_wc_cleanup_sessions"; Description = "WC Cleanup sessions"; Error = "Not enabled" }
}

# -----------------------------------------------------------------------------
# PLUGIN-SPECIFIC: Contact Form 7
# -----------------------------------------------------------------------------
Write-Section "Contact Form 7"

$cf7FormsEnabled = $customTools | Where-Object { $_.name -eq "custom_cf7_forms" }
if ($cf7FormsEnabled) {
    Test-Tool -Name "custom_cf7_forms" -Description "List CF7 forms" -Args @{}
}
else {
    Write-Host "  custom_cf7_forms: SKIPPED (not enabled)" -ForegroundColor Yellow
    $results.Skipped += @{ Name = "custom_cf7_forms"; Description = "CF7 Forms list"; Error = "Not enabled" }
}

# -----------------------------------------------------------------------------
# PLUGIN-SPECIFIC: Yoast SEO
# -----------------------------------------------------------------------------
Write-Section "Yoast SEO"

$yoastReindexEnabled = $customTools | Where-Object { $_.name -eq "custom_yoast_reindex" }
if ($yoastReindexEnabled) {
    Test-Tool -Name "custom_yoast_reindex" -Description "Trigger Yoast SEO reindex" -Args @{}
}
else {
    Write-Host "  custom_yoast_reindex: SKIPPED (not enabled)" -ForegroundColor Yellow
    $results.Skipped += @{ Name = "custom_yoast_reindex"; Description = "Yoast reindex"; Error = "Not enabled" }
}

# -----------------------------------------------------------------------------
# PLUGIN-SPECIFIC: Cache Plugins
# -----------------------------------------------------------------------------
Write-Section "Cache Plugins"

$wpscClearEnabled = $customTools | Where-Object { $_.name -eq "custom_wpsc_clear" }
if ($wpscClearEnabled) {
    Test-Tool -Name "custom_wpsc_clear" -Description "Clear WP Super Cache" -Args @{}
}
else {
    Write-Host "  custom_wpsc_clear: SKIPPED (not enabled)" -ForegroundColor Yellow
    $results.Skipped += @{ Name = "custom_wpsc_clear"; Description = "WP Super Cache clear"; Error = "Not enabled" }
}

$w3tcFlushEnabled = $customTools | Where-Object { $_.name -eq "custom_w3tc_flush" }
if ($w3tcFlushEnabled) {
    Test-Tool -Name "custom_w3tc_flush" -Description "Flush W3 Total Cache" -Args @{}
}
else {
    Write-Host "  custom_w3tc_flush: SKIPPED (not enabled)" -ForegroundColor Yellow
    $results.Skipped += @{ Name = "custom_w3tc_flush"; Description = "W3TC flush"; Error = "Not enabled" }
}

# =============================================================================
# REPORT
# =============================================================================

Write-Host ""
Write-Host "=========================================" -ForegroundColor Yellow
Write-Host " TEST REPORT - Custom Tools" -ForegroundColor Yellow
Write-Host "=========================================" -ForegroundColor Yellow
Write-Host ""

$total = $results.Passed.Count + $results.Failed.Count
$passRate = if ($total -gt 0) { [math]::Round(($results.Passed.Count / $total) * 100, 1) } else { 0 }

Write-Host "Summary:"
Write-Host "  Total Tests: $total"
Write-Host "  Passed: $($results.Passed.Count)" -ForegroundColor Green
Write-Host "  Failed: $($results.Failed.Count)" -ForegroundColor Red
Write-Host "  Skipped: $($results.Skipped.Count)" -ForegroundColor Yellow
Write-Host "  Pass Rate: $passRate%"

if ($results.Failed.Count -gt 0) {
    Write-Host ""
    Write-Host "Failed Tests:" -ForegroundColor Red
    foreach ($f in $results.Failed) {
        Write-Host "  - $($f.Name): $($f.Error)" -ForegroundColor Red
    }
}

if ($results.Skipped.Count -gt 0) {
    Write-Host ""
    Write-Host "Skipped Tests (enable in admin):" -ForegroundColor Yellow
    foreach ($s in $results.Skipped) {
        Write-Host "  - $($s.Name)" -ForegroundColor Yellow
    }
}

if ($results.Passed.Count -gt 0) {
    Write-Host ""
    Write-Host "Passed Tests:" -ForegroundColor Green
    foreach ($p in $results.Passed) {
        Write-Host "  - $($p.Name)" -ForegroundColor Green
    }
}

# Save report
$reportPath = Join-Path $PSScriptRoot "custom-test-report-$(Get-Date -Format 'yyyyMMdd-HHmmss').json"
$results | ConvertTo-Json -Depth 5 | Out-File $reportPath -Encoding UTF8
Write-Host ""
Write-Host "Report saved to: $reportPath" -ForegroundColor Gray
