#!/usr/bin/env bash
# Extrait de CHANGELOG.md la section d'une version, pour les notes d'une Release GitHub.
#
# Liens :
# - Lit : CHANGELOG.md (format Keep a Changelog, titres « ## [X.Y.Z] — date »)
#
# Usage : bash scripts/git/changelog-section.sh 2.0.1 > var/release-notes.md
# Sortie : le contenu de la section, sans son titre, jusqu'au titre de version suivant.
# Code de sortie 1 si la version est absente ou si sa section est vide.
set -euo pipefail

version="${1:-}"
version="${version#v}"
if [ -z "$version" ]; then
    echo "Usage : $0 X.Y.Z" >&2
    exit 1
fi

changelog="$(dirname "$0")/../../CHANGELOG.md"

# Comparaison littérale du début de ligne (pas d'expression régulière : les points et crochets restent du texte)
header="## [${version}]"

notes="$(awk -v head="$header" '
    index($0, head) == 1 { inside = 1; next }
    inside && /^## \[/ { exit }
    inside { print }
' "$changelog" | sed -e '/./,$!d')"

if [ -z "${notes//[$'\n\r\t ']/}" ]; then
    echo "Section [$version] introuvable ou vide dans CHANGELOG.md" >&2
    exit 1
fi

printf '%s\n' "$notes"
