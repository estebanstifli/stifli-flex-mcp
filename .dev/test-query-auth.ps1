# MCP Test Client - Token via Query String
$baseUrl = "https://100blogs.ovh/30/index.php/wp-json/easy-visual-mcp/v1/messages"

# Configure your token here
$token = "amigomio"

Write-Host "=== Easy Visual MCP Test Client (Query String Auth) ===" -ForegroundColor Cyan
Write-Host "Base URL: $baseUrl" -ForegroundColor Yellow
Write-Host ""

# Build URL with token query parameter
if ($token -and $token -ne "YOUR_TOKEN_HERE") {
    $mcpUrl = "${baseUrl}?token=${token}"
    Write-Host "Token configured (query string)" -ForegroundColor Green
    Write-Host "Full URL: $mcpUrl" -ForegroundColor Gray
    Write-Host ""
} else {
    $mcpUrl = $baseUrl
    Write-Host "No token configured - using public access" -ForegroundColor Yellow
    Write-Host ""
}

Write-Host "=== Testing tools/list ===" -ForegroundColor Cyan

$request = @{
    jsonrpc = "2.0"
    method = "tools/list"
    params = @{}
    id = 1
} | ConvertTo-Json

$headers = @{
    "Content-Type" = "application/json"
}

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

# Test mcp_ping tool
Write-Host ""
Write-Host "=== Testing mcp_ping ===" -ForegroundColor Cyan

$pingRequest = @{
    jsonrpc = "2.0"
    method = "tools/call"
    params = @{
        name = "mcp_ping"
        arguments = @{}
    }
    id = 2
} | ConvertTo-Json

try {
    $pingResponse = Invoke-RestMethod -Uri $mcpUrl -Method Post -Headers $headers -Body $pingRequest
    
    if ($pingResponse.result) {
        Write-Host ""
        Write-Host "Ping successful!" -ForegroundColor Green
        
        if ($pingResponse.result.content) {
            foreach ($content in $pingResponse.result.content) {
                if ($content.text) {
                    Write-Host $content.text -ForegroundColor Gray
                }
            }
        }
    } else {
        Write-Host ""
        Write-Host "Ping failed!" -ForegroundColor Red
        $pingResponse | ConvertTo-Json -Depth 5
    }
    
} catch {
    Write-Host ""
    Write-Host "Ping error: $($_.Exception.Message)" -ForegroundColor Red
}

Write-Host ""

# Test wp_upload_image_from_url with Unsplash URL
Write-Host "=== Testing wp_upload_image_from_url with Unsplash ===" -ForegroundColor Cyan

$imageUrl = "https://images.unsplash.com/photo-1559839734-2b71ea197ec2?w=1200&q=80"

$uploadRequest = @{
    jsonrpc = "2.0"
    method = "tools/call"
    params = @{
        name = "wp_upload_image_from_url"
        arguments = @{
            url = $imageUrl
            alt_text = "Beautiful landscape from Unsplash"
            title = "Unsplash Test Image"
        }
    }
    id = 3
} | ConvertTo-Json -Depth 5

try {
    Write-Host ""
    Write-Host "Downloading and uploading image from: $imageUrl" -ForegroundColor Gray
    Write-Host "This may take a few seconds..." -ForegroundColor Gray
    Write-Host ""
    
    $uploadResponse = Invoke-RestMethod -Uri $mcpUrl -Method Post -Headers $headers -Body $uploadRequest
    
    if ($uploadResponse.result) {
        Write-Host "Image upload successful!" -ForegroundColor Green
        Write-Host ""
        
        if ($uploadResponse.result.content) {
            foreach ($content in $uploadResponse.result.content) {
                if ($content.text) {
                    Write-Host $content.text -ForegroundColor Gray
                }
            }
        }
        
        # Try to parse attachment ID from response
        if ($uploadResponse.result.content[0].text -match "Attachment ID: (\d+)") {
            $attachmentId = $Matches[1]
            Write-Host ""
            Write-Host "Attachment ID: $attachmentId" -ForegroundColor Yellow
            Write-Host "You can view it at: $($baseUrl -replace '/wp-json/.*', '')/wp-admin/post.php?post=$attachmentId&action=edit" -ForegroundColor Cyan
        }
        
    } else {
        Write-Host ""
        Write-Host "Image upload failed!" -ForegroundColor Red
        if ($uploadResponse.error) {
            Write-Host "Error: $($uploadResponse.error.message)" -ForegroundColor Red
        }
        $uploadResponse | ConvertTo-Json -Depth 5
    }
    
} catch {
    Write-Host ""
    Write-Host "Upload error: $($_.Exception.Message)" -ForegroundColor Red
    Write-Host ""
    if ($_.ErrorDetails.Message) {
        try {
            $errorObj = $_.ErrorDetails.Message | ConvertFrom-Json
            if ($errorObj.error) {
                Write-Host "Error details: $($errorObj.error.message)" -ForegroundColor Red
            }
        } catch {
            Write-Host "Error details: $($_.ErrorDetails.Message)" -ForegroundColor Red
        }
    }
}

Write-Host ""
