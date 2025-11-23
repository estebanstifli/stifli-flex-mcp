# MCP Test - Ping Tool
$baseUrl = "https://100blogs.ovh/30/index.php/wp-json/easy-visual-mcp/v1/messages"
$token = "amigomio"

Write-Host "=== Testing mcp_ping ===" -ForegroundColor Cyan
Write-Host ""

$mcpUrl = "${baseUrl}?token=${token}"
$headers = @{
    "Content-Type" = "application/json"
}

$payload = @{
    jsonrpc = "2.0"
    method  = "tools/call"
    params  = @{
        name      = "mcp_ping"
        arguments = @{}
    }
    id = 1
} | ConvertTo-Json -Depth 5

try {
    Write-Host "Calling mcp_ping via JSON-RPC..." -ForegroundColor Yellow
    $response = Invoke-RestMethod -Uri $mcpUrl -Method Post -Headers $headers -Body $payload
    Write-Host ""

    if ($response.result) {
        Write-Host "✅ Ping successful" -ForegroundColor Green
        if ($response.result.content) {
            foreach ($item in $response.result.content) {
                if ($item.type -eq "text" -and $item.text) {
                    Write-Host $item.text -ForegroundColor Gray
                }
            }
        } else {
            $response | ConvertTo-Json -Depth 5 | Write-Host
        }
    } elseif ($response.error) {
        Write-Host "❌ Ping failed" -ForegroundColor Red
        Write-Host "Error code: $($response.error.code)" -ForegroundColor Red
        Write-Host "Message: $($response.error.message)" -ForegroundColor Red
    } else {
        Write-Host "Unexpected response:" -ForegroundColor Yellow
        $response | ConvertTo-Json -Depth 5 | Write-Host
    }
}
catch {
    Write-Host "❌ Error calling mcp_ping" -ForegroundColor Red
    Write-Host $_.Exception.Message -ForegroundColor Red
    if ($_.ErrorDetails.Message) {
        Write-Host "Details: $($_.ErrorDetails.Message)" -ForegroundColor DarkRed
    }
}

Write-Host ""
