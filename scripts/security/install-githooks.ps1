# Active les hooks Git du projet (dossier .githooks), dont le contrôle anti-secrets.
# Usage : pwsh scripts/security/install-githooks.ps1
param()

$ErrorActionPreference = "Stop"

git config core.hooksPath .githooks
Write-Host "Hooks Git activés : core.hooksPath=.githooks"
