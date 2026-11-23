param(
  [ValidateSet('chrome', 'firefox', 'both')]
  [string]$Target = 'both'
)

$ErrorActionPreference = 'Stop'
$root = $PSScriptRoot
$packDir = Join-Path $root 'dist-pack'
if (-not (Test-Path $packDir)) {
  New-Item -ItemType Directory -Path $packDir -Force | Out-Null
}

Add-Type -AssemblyName System.IO.Compression
Add-Type -AssemblyName System.IO.Compression.FileSystem

function Compress-ExtensionFolder([string]$SourceDir, [string]$ZipPath) {
  if (Test-Path $ZipPath) { Remove-Item $ZipPath -Force }

  # Use forward-slash entry names — required by Firefox (.xpi / moz-extension).
  $zip = [System.IO.Compression.ZipFile]::Open(
    $ZipPath,
    [System.IO.Compression.ZipArchiveMode]::Create
  )
  try {
    $rootFull = (Resolve-Path $SourceDir).Path.TrimEnd('\', '/')
    Get-ChildItem -Path $SourceDir -Recurse -File -Force | ForEach-Object {
      $full = $_.FullName
      $rel = $full.Substring($rootFull.Length).TrimStart('\', '/')
      $entryName = ($rel -replace '\\', '/')
      [void][System.IO.Compression.ZipFileExtensions]::CreateEntryFromFile(
        $zip,
        $full,
        $entryName,
        [System.IO.Compression.CompressionLevel]::Optimal
      )
    }
  }
  finally {
    $zip.Dispose()
  }
}

function Build-Target([string]$Name) {
  $out = Join-Path $root "dist-$Name"
  if (Test-Path $out) { Remove-Item -Recurse -Force $out }
  New-Item -ItemType Directory -Path $out | Out-Null

  Copy-Item -Recurse (Join-Path $root 'background') (Join-Path $out 'background')
  Copy-Item -Recurse (Join-Path $root 'shared') (Join-Path $out 'shared')
  Copy-Item -Recurse (Join-Path $root 'popup') (Join-Path $out 'popup')
  Copy-Item -Recurse (Join-Path $root 'options') (Join-Path $out 'options')
  Copy-Item -Recurse (Join-Path $root 'icons') (Join-Path $out 'icons')

  $manifestSrc = Join-Path $root "manifest.$Name.json"
  Copy-Item $manifestSrc (Join-Path $out 'manifest.json')
  Write-Host "Built $out"

  if ($Name -eq 'firefox') {
    $xpi = Join-Path $packDir 'desiderata-firefox.xpi'
    Compress-ExtensionFolder $out $xpi
    Write-Host "Packed $xpi"
  }

  if ($Name -eq 'chrome') {
    $chromeZip = Join-Path $packDir 'desiderata-chrome.zip'
    Compress-ExtensionFolder $out $chromeZip
    Write-Host "Packed $chromeZip"
  }
}

if ($Target -eq 'chrome' -or $Target -eq 'both') { Build-Target 'chrome' }
if ($Target -eq 'firefox' -or $Target -eq 'both') { Build-Target 'firefox' }
