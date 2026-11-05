#!/usr/bin/env bash
# Bloque un commit qui contiendrait un fichier sensible ou un secret.
#
# Usage :
#   bash scripts/security/check-staged-secrets.sh        fichiers indexés (hook pre-commit)
#   bash scripts/security/check-staged-secrets.sh --all  tous les fichiers suivis (CI)
#
# Appelé par : .githooks/pre-commit, .github/workflows/ci.yml
set -euo pipefail

# Noms de fichiers interdits dans Git
patterns=(
    '(^|/)\.env$'
    '(^|/)\.env\.local(\..+)?$'
    '(^|/)\.env\.[a-z]+\.local$'
    '\.sql(\.gz)?$'
    '\.pem$'
    '\.key$'
    '\.p12$'
    '\.pfx$'
    'credentials\.json$'
    'prod\.decrypt\.private\.php$'
)

# Variable sensible suivie d'une valeur non vide (une valeur vide est autorisée)
content_patterns=(
    '^[[:space:]]*(APP_SECRET|DATABASE_URL|MAILER_DSN|GEMINI_API_KEY|MISTRAL_API_KEY|TELEGRAM_DSN|DISCORD_DSN|TURNSTILE_SECRET_KEY|DEV_ADMIN_PASSWORD_HASH)=[^[:space:]#]'
    '-----BEGIN ([A-Z]+ )?PRIVATE KEY-----'
    'AIza[0-9A-Za-z_-]{35}'
)

# Fichiers dispensés du contrôle de contenu : valeurs factices (test) ou exemples (documentation)
allowed_content_files=(
    '.env.test'
    '.env.example'
    'scripts/security/check-staged-secrets.sh'
    'scripts/security/check-staged-secrets.ps1'
    'README.md'
)

if [[ "${1:-}" == "--all" ]]; then
    mapfile -t files < <(git ls-files)
else
    mapfile -t files < <(git diff --cached --name-only --diff-filter=ACMR)
fi

[[ ${#files[@]} -eq 0 ]] && exit 0

blocked=()

for file in "${files[@]}"; do
    for pattern in "${patterns[@]}"; do
        if [[ "$file" =~ $pattern ]]; then
            blocked+=("Fichier sensible : $file")
            break
        fi
    done
done

for file in "${files[@]}"; do
    [[ -f "$file" ]] || continue
    skip=false
    for allowed in "${allowed_content_files[@]}"; do
        [[ "$file" == "$allowed" ]] && skip=true && break
    done
    $skip && continue
    # Fichiers binaires ignorés (-I)
    for pattern in "${content_patterns[@]}"; do
        if grep -IqE -- "$pattern" "$file" 2>/dev/null; then
            blocked+=("Secret potentiel dans $file (motif : $pattern)")
        fi
    done
done

if [[ ${#blocked[@]} -gt 0 ]]; then
    echo ""
    echo "✖ Commit bloqué : secrets ou fichiers sensibles détectés."
    for item in "${blocked[@]}"; do
        echo "  - $item"
    done
    echo ""
    echo "Mettez les valeurs dans .env.local (ignoré par Git) ou dans le coffre Symfony."
    exit 1
fi

exit 0
