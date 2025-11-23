# MCP Test - Upload Image from Base64
$baseUrl = "https://100blogs.ovh/30/index.php/wp-json/easy-visual-mcp/v1/messages"
$token = "amigomio"

Write-Host "=== Testing wp_upload_image with Base64 ===" -ForegroundColor Cyan
Write-Host ""

# Download a small test image
$imageUrl = "https://images.unsplash.com/photo-1559839734-2b71ea197ec2?w=400&q=80"
Write-Host "Downloading image from: $imageUrl" -ForegroundColor Gray

try {
    # Download image to temp file
    $tempFile = [System.IO.Path]::GetTempFileName()
    Invoke-WebRequest -Uri $imageUrl -OutFile $tempFile
    
    Write-Host "Image downloaded to: $tempFile" -ForegroundColor Gray
    
    # Read file and convert to base64
    $imageBytes = [System.IO.File]::ReadAllBytes($tempFile)
    $base64 = [System.Convert]::ToBase64String($imageBytes)
    
    Write-Host "Image converted to base64 (length: $($base64.Length) chars)" -ForegroundColor Gray
    Write-Host ""
    
    # Clean up temp file
    Remove-Item $tempFile
    
    # Prepare JSON-RPC request
    $mcpUrl = "${baseUrl}?token=${token}"
    
    $uploadRequest = @{
        jsonrpc = "2.0"
        method = "tools/call"
        params = @{
            name = "wp_upload_image"
            arguments = @{
                image_data = $base64
                filename = "unsplash-base64-test.jpg"
                alt_text = "Test image uploaded via base64"
                title = "Base64 Upload Test"
            }
        }
        id = 1
    } | ConvertTo-Json -Depth 5
    
    $headers = @{
        "Content-Type" = "application/json"
    }
    
    Write-Host "Uploading image via wp_upload_image..." -ForegroundColor Yellow
    Write-Host ""
    
    $response = Invoke-RestMethod -Uri $mcpUrl -Method Post -Headers $headers -Body $uploadRequest
    
    if ($response.result) {
        Write-Host "✅ Upload successful!" -ForegroundColor Green
        Write-Host ""
        
        if ($response.result.content) {
            foreach ($content in $response.result.content) {
                if ($content.text) {
                    Write-Host $content.text -ForegroundColor Gray
                }
            }
        }
        
        # Try to parse attachment ID
        if ($response.result.content[0].text -match "Attachment ID: (\d+)") {
            $attachmentId = $Matches[1]
            Write-Host ""
            Write-Host "Attachment ID: $attachmentId" -ForegroundColor Yellow
            Write-Host "View at: https://100blogs.ovh/30/wp-admin/post.php?post=$attachmentId&action=edit" -ForegroundColor Cyan
        }
        
    } else {
        Write-Host "❌ Upload failed!" -ForegroundColor Red
        if ($response.error) {
            Write-Host "Error: $($response.error.message)" -ForegroundColor Red
        }
        $response | ConvertTo-Json -Depth 5
    }
    
} catch {
    Write-Host "❌ Error: $($_.Exception.Message)" -ForegroundColor Red
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
