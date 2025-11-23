[CmdletBinding()]
param(
    [string]$OutputDirectory,
    [string]$VersionTag,
    [switch]$SkipCleanup
)

$repoRoot = (Split-Path $PSScriptRoot -Parent)
$pluginSlug = Split-Path $repoRoot -Leaf
if (-not $OutputDirectory) {
    $OutputDirectory = Join-Path $repoRoot 'dist'
}
if (-not (Test-Path $OutputDirectory)) {
    New-Item -ItemType Directory -Path $OutputDirectory | Out-Null
}

$zipName = if ($VersionTag) { "$pluginSlug-$VersionTag.zip" } else { "$pluginSlug.zip" }
$zipPath = Join-Path $OutputDirectory $zipName
$stagingRoot = Join-Path ([System.IO.Path]::GetTempPath()) ("evmcp-build-" + [System.Guid]::NewGuid().ToString('N'))
$stagingPlugin = Join-Path $stagingRoot $pluginSlug

$excludeDirectories = @('.git', '.github', 'dev', 'dist')
$excludeFiles = @('*.zip', '*.code-workspace', '.DS_Store', 'Thumbs.db')

function Test-ShouldExclude {
    param(
        [string]$RelativePath,
        [bool]$IsDirectory
    )

    if (-not $RelativePath) {
        return $false
    }

    $normalized = $RelativePath -replace '\\', '/'
    foreach ($dir in $excludeDirectories) {
        if ($normalized -eq $dir -or $normalized.StartsWith("$dir/", [System.StringComparison]::OrdinalIgnoreCase)) {
            return $true
        }
    }

    $leaf = Split-Path $normalized -Leaf
    foreach ($pattern in $excludeFiles) {
        if (-not $IsDirectory -and $leaf -like $pattern) {
            return $true
        }
    }

    return $false
}

function Copy-PluginTree {
    param(
        [string]$SourcePath,
        [string]$DestinationPath,
        [string]$RootPath
    )

    foreach ($item in Get-ChildItem -LiteralPath $SourcePath -Force) {
        $relative = $item.FullName.Substring($RootPath.Length).TrimStart([System.IO.Path]::DirectorySeparatorChar)
        if (Test-ShouldExclude -RelativePath $relative -IsDirectory:$item.PSIsContainer) {
            continue
        }

        $target = Join-Path $DestinationPath $item.Name
        if ($item.PSIsContainer) {
            if (-not (Test-Path $target)) {
                New-Item -ItemType Directory -Path $target | Out-Null
            }
            Copy-PluginTree -SourcePath $item.FullName -DestinationPath $target -RootPath $RootPath
        } else {
            Copy-Item -LiteralPath $item.FullName -Destination $target -Force
        }
    }
}

try {
    if (Test-Path $stagingRoot) {
        Remove-Item -LiteralPath $stagingRoot -Recurse -Force
    }
    New-Item -ItemType Directory -Path $stagingPlugin -Force | Out-Null

    Copy-PluginTree -SourcePath $repoRoot -DestinationPath $stagingPlugin -RootPath $repoRoot

    if (Test-Path $zipPath) {
        Remove-Item -LiteralPath $zipPath -Force
    }

    Add-Type -AssemblyName System.IO.Compression.FileSystem
    [System.IO.Compression.ZipFile]::CreateFromDirectory(
        $stagingPlugin,
        $zipPath,
        [System.IO.Compression.CompressionLevel]::Optimal,
        $true
    )

    Write-Host "Created package:" $zipPath
}
finally {
    if (-not $SkipCleanup) {
        if (Test-Path $stagingRoot) {
            Remove-Item -LiteralPath $stagingRoot -Recurse -Force
        }
    } else {
        Write-Host "Staging directory preserved at" $stagingRoot
    }
}
