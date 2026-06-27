# Sync mirrored EAES docs between repo root and Web/
# Usage (from repo root):
#   .\scripts\sync-mirrored-docs.ps1
#   .\scripts\sync-mirrored-docs.ps1 -Direction root-to-web
#   .\scripts\sync-mirrored-docs.ps1 -Direction web-to-root

param(
    [ValidateSet('auto', 'root-to-web', 'web-to-root')]
    [string] $Direction = 'auto'
)

$ErrorActionPreference = 'Stop'
$repoRoot = Split-Path $PSScriptRoot -Parent
$webDir = Join-Path $repoRoot 'Web'

$mirrored = @('AGENTS.md', 'PRD_V1.md', 'PRD_PROGRESS_AUDIT.md')

function Get-FileHashHex([string] $path) {
    (Get-FileHash -Path $path -Algorithm SHA256).Hash
}

$exitCode = 0

foreach ($name in $mirrored) {
    $rootPath = Join-Path $repoRoot $name
    $webPath = Join-Path $webDir $name

    if (-not (Test-Path $rootPath)) {
        Write-Error "Missing root file: $rootPath"
    }
    if (-not (Test-Path $webPath)) {
        Write-Error "Missing Web file: $webPath"
    }

    $rootHash = Get-FileHashHex $rootPath
    $webHash = Get-FileHashHex $webPath

    if ($rootHash -eq $webHash) {
        Write-Host "[ok] $name - already in sync"
        continue
    }

    $rootTime = (Get-Item $rootPath).LastWriteTimeUtc
    $webTime = (Get-Item $webPath).LastWriteTimeUtc

    $copyFrom = $null
    $copyTo = $null

    switch ($Direction) {
        'root-to-web' {
            $copyFrom = $rootPath
            $copyTo = $webPath
        }
        'web-to-root' {
            $copyFrom = $webPath
            $copyTo = $rootPath
        }
        default {
            if ($rootTime -gt $webTime) {
                $copyFrom = $rootPath
                $copyTo = $webPath
            }
            elseif ($webTime -gt $rootTime) {
                $copyFrom = $webPath
                $copyTo = $rootPath
            }
            else {
                Write-Warning "[conflict] $name - content differs with same timestamp; copying root -> Web (edit both or pass -Direction)"
                $copyFrom = $rootPath
                $copyTo = $webPath
                $exitCode = 1
            }
        }
    }

    Copy-Item -Path $copyFrom -Destination $copyTo -Force
    $label = if ($copyFrom -eq $rootPath) { 'root -> Web' } else { 'Web -> root' }
    Write-Host "[sync] $name - $label"
}

exit $exitCode
