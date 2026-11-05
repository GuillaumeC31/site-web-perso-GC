# Refuse les fichiers lourds ou non optimisés dans public/ (version PowerShell de check-staged-assets.sh) :
# - tout fichier de public/ de plus de 200 Ko ;
# - toute image de public/ qui n'est pas en WebP, SVG ou ICO, sauf UNE exception nommée :
#   l'image de partage public/partage/guillaume-couget-partage.jpg (JPEG pour LinkedIn) ;
# - tout fichier PDF de public/ (CV de référence : reference/, hors dépôt) ;
# - toute clé d'API Mistral dans un fichier versionné (la clé ne vit que dans .env.local) ;
# - le plancher personnel de rémunération (ligne 🚫 « Plancher … : N k€ » de ../TEXTES_SITE.md, hors dépôt),
#   sous ses formes « N k€ », « Nk€ », « N K€ », « N 000 », « N000 » : montant lu au moment du contrôle,
#   jamais écrit dans le script ni affiché. Sans ../TEXTES_SITE.md, contrôle sauté avec un avertissement.
#
# Usage :
#   pwsh scripts/security/check-staged-assets.ps1        fichiers indexés (hook pre-commit)
#   pwsh scripts/security/check-staged-assets.ps1 -All   tous les fichiers suivis
param(
    [switch]$All
)

$ErrorActionPreference = "Stop"
$maxBytes = 200 * 1024
# Seules images non WebP permises dans public/ : l'image de partage Open Graph (lot 8) et l'icône d'écran
# d'accueil des téléphones (v2.0.2, produite par scripts/images/build-favicons.php)
$shareImage = 'public/partage/guillaume-couget-partage.jpg'
$touchIcon = 'public/apple-touch-icon.png'

$files = if ($All) { git ls-files 'public/*' } else { git diff --cached --name-only --diff-filter=ACMR -- 'public/*' }
$blocked = @()

foreach ($file in $files) {
    if (-not (Test-Path -LiteralPath $file -PathType Leaf)) { continue }
    $size = (Get-Item -LiteralPath $file).Length
    if ($size -gt $maxBytes) {
        $blocked += "$file : $([math]::Floor($size / 1024)) Ko (maximum 200 Ko)"
    }
    if ($file -match '\.(png|jpe?g|gif|bmp|tiff?|avif|heic)$' -and $file -ne $shareImage -and $file -ne $touchIcon) {
        $blocked += "$file : format non optimisé (WebP, SVG ou ICO attendu ; exceptions : $shareImage, $touchIcon)"
    }
    if ($file -match '\.pdf$') {
        $blocked += "$file : PDF interdit dans public/ (CV de référence : reference/, hors dépôt)"
    }
}

# Clé Mistral : seule une valeur vide est permise hors de .env.local (.env.test : clé factice)
$keyHits = if ($All) { git grep -nE '^MISTRAL_API_KEY=.+' -- ':!.env.test' } else { git diff --cached -U0 --diff-filter=ACMR -- ':!.env.test' | Select-String -Pattern '^\+MISTRAL_API_KEY=.+' }
foreach ($hit in $keyHits) {
    if ("$hit" -ne '') { $blocked += "clé Mistral dans un fichier versionné : $(("$hit" -split '=')[0])=… (la clé va dans .env.local)" }
}

# Plancher personnel : montant lu dans la ligne 🚫 de ../TEXTES_SITE.md (jamais écrit ici)
$textesFile = Join-Path (git rev-parse --show-toplevel) '../TEXTES_SITE.md'
if (Test-Path -LiteralPath $textesFile) {
    $floorLine = Get-Content -LiteralPath $textesFile -Encoding utf8 | Where-Object { $_.Contains([char]::ConvertFromUtf32(0x1F6AB)) -and $_ -match '(?i)plancher' } | Select-Object -First 1
    if ($floorLine -and $floorLine -match '(?i)plancher[^:]*:[^0-9]*([0-9]+)') {
        $floorRe = '(?<![0-9])' + $Matches[1] + '[\s\u00A0\u202F.]?(?:[kK]€|000)(?![0-9])'
        $floorHits = @()
        if ($All) {
            foreach ($tracked in (git ls-files)) {
                if (-not (Test-Path -LiteralPath $tracked -PathType Leaf)) { continue }
                $found = Select-String -LiteralPath $tracked -Pattern $floorRe -Encoding utf8 -ErrorAction SilentlyContinue
                foreach ($f in $found) { $floorHits += "$($tracked):$($f.LineNumber)" }
            }
        } else {
            [Console]::OutputEncoding = [System.Text.Encoding]::UTF8
            $current = ''
            $line = 0
            foreach ($row in (git -c core.quotepath=off diff --cached -U0 --diff-filter=ACMR --no-color)) {
                if ($row -match '^\+\+\+ b/(.+)$') { $current = $Matches[1]; continue }
                if ($row -match '^@@ -[0-9,]+ \+([0-9]+)') { $line = [int]$Matches[1]; continue }
                if ($row.StartsWith('+')) {
                    if ($row.Substring(1) -match $floorRe) { $floorHits += "$($current):$line" }
                    $line++
                }
            }
        }
        foreach ($hit in $floorHits) { $blocked += "$hit : plancher personnel de rémunération (ligne 🚫 de TEXTES_SITE.md) : à retirer" }
    }
} else {
    Write-Host "⚠ ../TEXTES_SITE.md absent : contrôle du plancher personnel sauté." -ForegroundColor Yellow
}

if ($blocked.Count -gt 0) {
    Write-Host ""
    Write-Host "✖ Commit bloqué : fichiers lourds, non optimisés ou PDF dans public/, clé d'API ou plancher personnel versionnés." -ForegroundColor Red
    $blocked | ForEach-Object { Write-Host "  - $_" -ForegroundColor Yellow }
    Write-Host ""
    Write-Host "Ranger l'original dans ../medias_originaux/ et versionner une copie WebP :" -ForegroundColor Cyan
    Write-Host "  php scripts/images/optimize-image.php <original> public/images/<nom>.webp" -ForegroundColor Cyan
    exit 1
}

exit 0
