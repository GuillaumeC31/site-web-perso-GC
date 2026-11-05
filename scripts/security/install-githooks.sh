#!/usr/bin/env bash
# Active les hooks Git du projet (dossier .githooks), dont le contrôle anti-secrets.
# Usage : bash scripts/security/install-githooks.sh
set -euo pipefail

cd "$(dirname "$0")/../.."
git config core.hooksPath .githooks
chmod +x .githooks/* 2>/dev/null || true
echo "Hooks Git activés : core.hooksPath=.githooks"
