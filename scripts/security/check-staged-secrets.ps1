# Bloque un commit qui contiendrait un fichier sensible ou un secret (version PowerShell).
#
# Usage :
#   pwsh scripts/security/check-staged-secrets.ps1        fichiers indexés (hook pre-commit)
#   pwsh scripts/security/check-staged-secrets.ps1 -All   tous les fichiers suivis
#
# Mêmes règles que check-staged-secrets.sh. Appelé par : .githooks/pre-commit (si pwsh est disponible).
param(
    [switch]$All
)

$ErrorActionPreference = "Stop"

$patterns = @(
    '(^|/)\.env$',
    '(^|/)\.env\.local(\..+)?$',
    '(^|/)\.env\.[a-z]+\.local$',
    '\.sql(\.gz)?$',
    '\.pem$',
    '\.key$',
    '\.p12$',
    '\.pfx$',
    'credentials\.json$',
    'prod\.decrypt\.private\.php$'
)

# Variable sensible suivie d'une valeur non vide (une valeur vide est autorisée)
$contentPatterns = @(
    '(?m)^\s*(APP_SECRET|DATABASE_URL|MAILER_DSN|GEMINI_API_KEY|MISTRAL_API_KEY|TELEGRAM_DSN|DISCORD_DSN|TURNSTILE_SECRET_KEY|DEV_ADMIN_PASSWORD_HASH)=[^\s#]',
    '-----BEGIN ([A-Z]+ )?PRIVATE KEY-----',
    'AIza[0-9A-Za-z_-]{35}'
)

$allowedContentFiles = @(
    '.env.test',
    '.env.example',
    'scripts/security/check-staged-secrets.sh',
    'scripts/security/check-staged-secrets.ps1',
    'README.md'
)

$files = if ($All) { git ls-files } else { git diff --cached --name-only --diff-filter=ACMR }
if (-not $files) {
    exit 0
}

$blocked = @()

foreach ($file in $files) {
    foreach ($pattern in $patterns) {
        if ($file -match $pattern) {
            $blocked += "Fichier sensible : $file"
            break
        }
    }
}

foreach ($file in $files) {
    $normalized = $file -replace '\\', '/'
    if ($allowedContentFiles -contains $normalized) { continue }
    if (-not (Test-Path -LiteralPath $file -PathType Leaf)) { continue }
    try {
        $content = Get-Content -LiteralPath $file -Raw -ErrorAction Stop
    } catch {
        continue
    }
    if ($null -eq $content) { continue }
    foreach ($pattern in $contentPatterns) {
        if ($content -match $pattern) {
            $blocked += "Secret potentiel dans $file (motif : $pattern)"
        }
    }
}

if ($blocked.Count -gt 0) {
    Write-Host ""
    Write-Host "✖ Commit bloqué : secrets ou fichiers sensibles détectés." -ForegroundColor Red
    $blocked | ForEach-Object { Write-Host "  - $_" -ForegroundColor Yellow }
    Write-Host ""
    Write-Host "Mettez les valeurs dans .env.local (ignoré par Git) ou dans le coffre Symfony." -ForegroundColor Cyan
    exit 1
}

exit 0
