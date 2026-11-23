# Builds zips and publishes a GitHub Release when the working tree is clean.
#
# Usage:
#   .\release.ps1
#   .\release.ps1 -DryRun
#   .\release.ps1 -Force
#   .\release.ps1 -Notes "ISBN, Zotero, standalone"

[CmdletBinding()]
param(
	[switch] $DryRun,
	[switch] $Force,
	[string] $Notes = ''
)

$ErrorActionPreference = 'Stop'

$Root       = $PSScriptRoot
$Bootstrap  = Join-Path $Root 'packages\wordpress-plugin\desiderata.php'
$BuildZip   = Join-Path $Root 'build.ps1'
$PluginZip  = Join-Path $Root 'dist\desiderata.zip'
$ApiZip     = Join-Path $Root 'dist\desiderata-api.zip'

function Assert-Command([string] $Name) {
	if (-not (Get-Command $Name -ErrorAction SilentlyContinue)) {
		throw "Command not found: $Name"
	}
}

Assert-Command git
Assert-Command gh

if (-not (Test-Path $Bootstrap)) {
	throw "Plugin bootstrap not found: $Bootstrap"
}
if (-not (Test-Path $BuildZip)) {
	throw 'Missing build.ps1'
}

Push-Location $Root
try {
	$status = git status --porcelain
	if ($status) {
		Write-Host "Working tree is not clean:`n$status" -ForegroundColor Red
		throw 'Commit or stash changes before releasing.'
	}

	$header = Get-Content -LiteralPath $Bootstrap -Raw
	if ($header -notmatch '(?m)^\s*\*\s*Version:\s*(\S+)') {
		throw 'Could not read Version from desiderata.php plugin header.'
	}
	$version = $Matches[1]
	$tag = "v$version"

	$existing = git tag -l $tag
	if ($existing -and -not $Force) {
		throw "Git tag already exists: $tag (use -Force to replace tag + GitHub release)"
	}

	$remote = (git rev-parse --abbrev-ref --symbolic-full-name '@{u}' 2>$null)
	if (-not $remote) {
		throw 'Current branch has no upstream. Run: git push -u origin HEAD'
	}

	$branch = (git rev-parse --abbrev-ref HEAD).Trim()
	Write-Host "→ Release Desiderata $version ($tag) on $branch" -ForegroundColor Cyan
	if ($DryRun) {
		Write-Host 'DryRun: would build zips, create tag, push, and publish GitHub release.' -ForegroundColor Yellow
		return
	}

	Write-Host '→ build.ps1'
	& $BuildZip
	if (-not (Test-Path $PluginZip)) { throw "Zip not produced: $PluginZip" }
	if (-not (Test-Path $ApiZip)) { throw "Zip not produced: $ApiZip" }

	$statusAfter = git status --porcelain
	# dist/ and synced shared copies may appear — only fail on unexpected tracked dirt
	$dirtyTracked = $statusAfter | Where-Object { $_ -notmatch '^\?\?' -and $_ -notmatch ' dist/' }
	if ($dirtyTracked) {
		Write-Host "Build dirtied tracked files:`n$dirtyTracked" -ForegroundColor Red
		throw 'Release aborted: unexpected uncommitted files after build.'
	}

	if ($Force -and $existing) {
		Write-Host "→ delete GitHub release $tag (if any)"
		gh release delete $tag --yes 2>$null | Out-Null
		Write-Host "→ delete local/remote tag $tag"
		git tag -d $tag | Out-Null
		git push origin ":refs/tags/$tag" 2>$null | Out-Null
	}

	$msg = "Desiderata $version"
	Write-Host "→ git tag $tag"
	git tag -a $tag -m $msg

	Write-Host '→ git push (branch + tag)'
	git push
	git push origin $tag

	$ghArgs = @(
		'release', 'create', $tag,
		$PluginZip,
		$ApiZip,
		'--title', $msg,
		'--verify-tag'
	)
	if ($Notes) {
		$ghArgs += @('--notes', $Notes)
	} else {
		$ghArgs += '--generate-notes'
	}

	Write-Host '→ gh release create'
	& gh @ghArgs

	$url = gh release view $tag --json url -q .url
	Write-Host "OK → $url" -ForegroundColor Green
	Write-Host "    assets: $PluginZip ; $ApiZip"
}
finally {
	Pop-Location
}
