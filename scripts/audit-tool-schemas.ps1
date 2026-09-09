param(
    [Parameter(Mandatory = $true)]
    [string]$Endpoint,

    [string]$Token = ''
)

$ErrorActionPreference = 'Stop'

function Test-SchemaNode {
    param(
        [Parameter(Mandatory = $true)]
        $Node,

        [Parameter(Mandatory = $true)]
        [string]$Path
    )

    $errors = [System.Collections.Generic.List[string]]::new()
    if ($null -eq $Node -or -not ($Node -is [pscustomobject])) {
        $errors.Add("${Path}: schema node must be a JSON object")
        return $errors
    }

    $propertyNames = @($Node.PSObject.Properties.Name)
    $types = @($Node.type)
    if ($types -contains 'object') {
        if (-not ($propertyNames -contains 'properties') -or -not ($Node.properties -is [pscustomobject])) {
            $errors.Add("${Path}: object schema must define properties as a JSON object")
        } else {
            foreach ($property in $Node.properties.PSObject.Properties) {
                foreach ($errorMessage in (Test-SchemaNode -Node $property.Value -Path "${Path}.properties.$($property.Name)")) {
                    $errors.Add($errorMessage)
                }
            }

            foreach ($requiredName in @($Node.required)) {
                if (-not ($Node.properties.PSObject.Properties.Name -contains $requiredName)) {
                    $errors.Add("${Path}: required property '$requiredName' is not defined")
                }
            }
        }
    }

    if ($types -contains 'array') {
        if (-not ($propertyNames -contains 'items') -or -not ($Node.items -is [pscustomobject])) {
            $errors.Add("${Path}: array schema must define items as a JSON object")
        } else {
            foreach ($errorMessage in (Test-SchemaNode -Node $Node.items -Path "${Path}.items")) {
                $errors.Add($errorMessage)
            }
        }
    }

    if (($propertyNames -contains 'additionalProperties') -and ($Node.additionalProperties -is [pscustomobject])) {
        foreach ($errorMessage in (Test-SchemaNode -Node $Node.additionalProperties -Path "${Path}.additionalProperties")) {
            $errors.Add($errorMessage)
        }
    }

    foreach ($compoundKey in @('oneOf', 'anyOf', 'allOf')) {
        if (-not ($propertyNames -contains $compoundKey)) {
            continue
        }
        $index = 0
        foreach ($subSchema in @($Node.$compoundKey)) {
            foreach ($errorMessage in (Test-SchemaNode -Node $subSchema -Path "${Path}.${compoundKey}[${index}]")) {
                $errors.Add($errorMessage)
            }
            $index++
        }
    }

    return $errors
}

$headers = @{ 'Content-Type' = 'application/json' }
if (-not [string]::IsNullOrWhiteSpace($Token)) {
    $headers.Authorization = "Bearer $Token"
}

$errors = [System.Collections.Generic.List[string]]::new()
$initializeBody = @{
    jsonrpc = '2.0'
    method = 'initialize'
    params = @{
        protocolVersion = '2025-11-25'
        capabilities = @{}
        clientInfo = @{
            name = 'stifli-schema-auditor'
            version = '1.0'
        }
    }
    id = 'initialize-audit'
} | ConvertTo-Json -Depth 10

$initializeResponse = Invoke-RestMethod -Uri $Endpoint -Method Post -Headers $headers -Body $initializeBody
if ($initializeResponse.error) {
    throw "initialize failed: $($initializeResponse.error.message)"
}

$initializeResult = $initializeResponse.result
if ([string]::IsNullOrWhiteSpace([string]$initializeResult.protocolVersion)) {
    $errors.Add('initialize: protocolVersion is required')
}
if ($null -eq $initializeResult.serverInfo -or [string]::IsNullOrWhiteSpace([string]$initializeResult.serverInfo.name)) {
    $errors.Add('initialize: serverInfo.name is required')
}
if ($null -eq $initializeResult.serverInfo -or [string]::IsNullOrWhiteSpace([string]$initializeResult.serverInfo.version)) {
    $errors.Add('initialize: serverInfo.version is required')
}

if ($null -ne $initializeResult.serverInfo -and ($initializeResult.serverInfo.PSObject.Properties.Name -contains 'icons')) {
    $iconIndex = 0
    foreach ($icon in @($initializeResult.serverInfo.icons)) {
        if ($null -eq $icon -or [string]::IsNullOrWhiteSpace([string]$icon.src)) {
            $errors.Add("initialize.serverInfo.icons[${iconIndex}]: src is required")
        } else {
            $iconUri = $null
            if (-not [System.Uri]::TryCreate([string]$icon.src, [System.UriKind]::Absolute, [ref]$iconUri) -or $iconUri.Scheme -notin @('http', 'https')) {
                $errors.Add("initialize.serverInfo.icons[${iconIndex}]: src must be an absolute HTTP(S) URL")
            }
        }

        if ($null -ne $icon -and ($icon.PSObject.Properties.Name -contains 'mimeType') -and [string]::IsNullOrWhiteSpace([string]$icon.mimeType)) {
            $errors.Add("initialize.serverInfo.icons[${iconIndex}]: mimeType cannot be empty")
        }
        if ($null -ne $icon -and ($icon.PSObject.Properties.Name -contains 'sizes')) {
            foreach ($size in @($icon.sizes)) {
                if ([string]$size -notmatch '^(?:[1-9][0-9]*x[1-9][0-9]*|any)$') {
                    $errors.Add("initialize.serverInfo.icons[${iconIndex}]: invalid size '$size'")
                }
            }
        }
        $iconIndex++
    }
}

$body = @{
    jsonrpc = '2.0'
    method = 'tools/list'
    params = @{}
    id = 'schema-audit'
} | ConvertTo-Json -Depth 10

$response = Invoke-RestMethod -Uri $Endpoint -Method Post -Headers $headers -Body $body
if ($response.error) {
    throw "tools/list failed: $($response.error.message)"
}

$tools = @($response.result.tools)
$duplicateNames = $tools | Group-Object name | Where-Object Count -gt 1
foreach ($duplicate in $duplicateNames) {
    $errors.Add("Duplicate tool name: $($duplicate.Name)")
}

foreach ($tool in $tools) {
    if ([string]::IsNullOrWhiteSpace([string]$tool.name)) {
        $errors.Add('Tool without a name')
        continue
    }
    foreach ($errorMessage in (Test-SchemaNode -Node $tool.inputSchema -Path "$($tool.name).inputSchema")) {
        $errors.Add($errorMessage)
    }

    if ($tool.PSObject.Properties.Name -contains 'outputSchema') {
        if (@($tool.outputSchema.type) -notcontains 'object') {
            $errors.Add("$($tool.name).outputSchema: root type must be object")
        }
        foreach ($errorMessage in (Test-SchemaNode -Node $tool.outputSchema -Path "$($tool.name).outputSchema")) {
            $errors.Add($errorMessage)
        }
    }
}

if ($errors.Count -gt 0) {
    $errors | ForEach-Object { Write-Error $_ }
    exit 1
}

Write-Host "MCP initialize and schema audit passed for $($tools.Count) tools." -ForegroundColor Green