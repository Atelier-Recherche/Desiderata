# Builds Desiderata monorepo artifacts into dist/
# - sync shared-php → wordpress-plugin + standalone
# - extension dist-chrome / dist-firefox
# - dist/desiderata.zip (WP plugin)
# - dist/desiderata-api.zip (standalone)

$ErrorActionPreference = 'Stop'

$Root        = $PSScriptRoot
$Packages    = Join-Path $Root 'packages'
$PluginDir   = Join-Path $Packages 'wordpress-plugin'
$ExtDir      = Join-Path $Packages 'extension'
$Standalone  = Join-Path $Packages 'standalone'
$Shared      = Join-Path $Packages 'shared-php'
$OutDir      = Join-Path $Root 'dist'
$PluginSlug  = 'desiderata'
$ApiSlug     = 'desiderata-api'

if (-not (Test-Path (Join-Path $PluginDir 'desiderata.php'))) {
	throw "Plugin bootstrap not found: $(Join-Path $PluginDir 'desiderata.php')"
}

function Sync-SharedPhp {
	Write-Host '→ sync shared-php'
	$targets = @(
		(Join-Path $PluginDir 'includes\shared'),
		(Join-Path $Standalone 'lib\shared')
	)
	foreach ($dest in $targets) {
		New-Item -ItemType Directory -Path $dest -Force | Out-Null
		Copy-Item (Join-Path $Shared '*.php') $dest -Force
	}
}

function Build-Extension {
	Write-Host '→ extension build.ps1'
	& (Join-Path $ExtDir 'build.ps1')
	$packDir = Join-Path $ExtDir 'dist-pack'
	foreach ($name in @('desiderata-firefox.xpi', 'desiderata-chrome.zip')) {
		$src = Join-Path $packDir $name
		if (Test-Path $src) {
			Copy-Item $src (Join-Path $OutDir $name) -Force
			Write-Host ("OK → {0}" -f (Join-Path $OutDir $name))
		}
	}
}

function New-PluginZip {
	Write-Host '→ pack wordpress-plugin'
	$ZipPath  = Join-Path $OutDir 'desiderata.zip'
	$StageDir = Join-Path $OutDir '_stage_plugin'
	if (Test-Path $StageDir) { Remove-Item $StageDir -Recurse -Force }
	if (Test-Path $ZipPath)  { Remove-Item $ZipPath  -Force }
	$Dest = Join-Path $StageDir $PluginSlug
	New-Item -ItemType Directory -Path $Dest -Force | Out-Null

	$ExcludeDirNames = @('node_modules', 'vendor', '.git', '.github', 'dist', 'tests')
	$ExcludeFileNames = @(
		'package-lock.json', 'composer.lock', 'composer.json', 'package.json',
		'webpack.config.js', 'phpunit.xml.dist', 'phpcs.xml.dist',
		'.DS_Store', '.gitignore', 'README.md'
	)
	$ExcludePatterns = @('*.map', '*.local.ps1', '*.zip')

	Get-ChildItem -Path $PluginDir -Force | ForEach-Object {
		$name = $_.Name
		if ($ExcludeDirNames -contains $name) { return }
		if ($ExcludeFileNames -contains $name) { return }
		$target = Join-Path $Dest $name
		if ($_.PSIsContainer) {
			Copy-Item $_.FullName $target -Recurse -Force
		} else {
			$skip = $false
			foreach ($pat in $ExcludePatterns) {
				if ($name -like $pat) { $skip = $true; break }
			}
			if (-not $skip) {
				Copy-Item $_.FullName $target -Force
			}
		}
	}

	Get-ChildItem -Path $Dest -Recurse -Force -Include *.map, .DS_Store |
		Remove-Item -Force -ErrorAction SilentlyContinue

	Compress-Archive -Path (Join-Path $StageDir $PluginSlug) -DestinationPath $ZipPath -Force
	Start-Sleep -Milliseconds 200
	Remove-Item $StageDir -Recurse -Force -ErrorAction SilentlyContinue
	$size = (Get-Item $ZipPath).Length
	Write-Host ("OK → {0} ({1:N1} KB)" -f $ZipPath, ($size / 1KB))
}

function New-StandaloneZip {
	Write-Host '→ pack standalone'
	$ZipPath  = Join-Path $OutDir 'desiderata-api.zip'
	$StageDir = Join-Path $OutDir '_stage_api'
	if (Test-Path $StageDir) { Remove-Item $StageDir -Recurse -Force }
	if (Test-Path $ZipPath)  { Remove-Item $ZipPath  -Force }
	$Dest = Join-Path $StageDir $ApiSlug
	New-Item -ItemType Directory -Path $Dest -Force | Out-Null

	$ExcludeNames = @('config.php', '.git', 'public')
	Get-ChildItem -Path $Standalone -Force | ForEach-Object {
		if ($ExcludeNames -contains $_.Name) { return }
		$target = Join-Path $Dest $_.Name
		if ($_.PSIsContainer) {
			Copy-Item $_.FullName $target -Recurse -Force
		} else {
			Copy-Item $_.FullName $target -Force
		}
	}

	Compress-Archive -Path (Join-Path $StageDir $ApiSlug) -DestinationPath $ZipPath -Force
	Start-Sleep -Milliseconds 200
	Remove-Item $StageDir -Recurse -Force -ErrorAction SilentlyContinue
	$size = (Get-Item $ZipPath).Length
	Write-Host ("OK → {0} ({1:N1} KB)" -f $ZipPath, ($size / 1KB))
}

if (-not (Test-Path $OutDir)) {
	New-Item -ItemType Directory -Path $OutDir -Force | Out-Null
}

Sync-SharedPhp
Build-Extension
New-PluginZip
New-StandaloneZip
Write-Host '→ build complete' -ForegroundColor Green
