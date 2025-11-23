# Simple MCP Test Client
$mcpUrl = "https://100blogs.ovh/30/index.php/wp-json/easy-visual-mcp/v1/messages"

# Prompt for token
Write-Host "=== Easy Visual MCP Test Client ===" -ForegroundColor Cyan
Write-Host "URL: $mcpUrl" -ForegroundColor Yellow
Write-Host ""

$token = Read-Host "Enter your MCP token (or press Enter to skip authentication)"

$headers = @{
    "Content-Type" = "application/json"
}

if ($token -and $token.Trim() -ne "") {
    $headers["Authorization"] = "Bearer $token"
    Write-Host "Token configured" -ForegroundColor Green
    Write-Host ""
} else {
    Write-Host "No token provided - using public access" -ForegroundColor Yellow
    Write-Host ""
}

Write-Host "=== Testing tools/list ===" -ForegroundColor Cyan

$request = @{
    jsonrpc = "2.0"
    method = "tools/list"
    params = @{}
    id = 1
} | ConvertTo-Json

try {
    $response = Invoke-RestMethod -Uri $mcpUrl -Method Post -Headers $headers -Body $request
    
    if ($response.result.tools) {
        $toolCount = $response.result.tools.Count
        Write-Host ""
        Write-Host "=== Found $toolCount tools ===" -ForegroundColor Yellow
        Write-Host ""
        
        # Group by category
        $grouped = $response.result.tools | Group-Object { 
            if ($_.name -match '^wc_') { 'WooCommerce' } 
            elseif ($_.name -match '^wp_') { 'WordPress' }
            else { 'Core' }
        }
        
        foreach ($group in $grouped) {
            $categoryName = $group.Name
            $categoryCount = $group.Count
            Write-Host "$categoryName ($categoryCount tools):" -ForegroundColor Cyan
            
            $group.Group | Select-Object -First 5 | ForEach-Object {
                Write-Host "  - $($_.name)"
            }
            
            if ($group.Count -gt 5) {
                $remaining = $group.Count - 5
                Write-Host "  ... and $remaining more"
            }
            Write-Host ""
        }
        
        # Show summary
        Write-Host "=== Summary ===" -ForegroundColor Green
        foreach ($group in $grouped) {
            Write-Host "$($group.Name): $($group.Count) tools" -ForegroundColor Gray
        }
        
    } else {
        Write-Host ""
        Write-Host "No tools in response!" -ForegroundColor Red
        Write-Host ""
        Write-Host "Full response:" -ForegroundColor Gray
        $response | ConvertTo-Json -Depth 10
    }
    
} catch {
    Write-Host ""
    Write-Host "Error: $($_.Exception.Message)" -ForegroundColor Red
    Write-Host ""
    $_.Exception | Format-List -Force
}
