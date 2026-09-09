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
$errors = [System.Collections.Generic.List[string]]::new()
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
}

if ($errors.Count -gt 0) {
    $errors | ForEach-Object { Write-Error $_ }
    exit 1
}

Write-Host "Schema audit passed for $($tools.Count) tools." -ForegroundColor Green