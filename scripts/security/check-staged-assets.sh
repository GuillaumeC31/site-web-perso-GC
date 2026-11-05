#!/usr/bin/env bash
# Refuse les fichiers lourds ou non optimisés dans public/ :
# - tout fichier de public/ de plus de 200 Ko ;
# - toute image de public/ qui n'est pas en WebP, SVG ou ICO, sauf UNE exception nommée :
#   l'image de partage public/partage/guillaume-couget-partage.jpg (JPEG, lu par LinkedIn et les
#   messageries, qui n'acceptent pas toujours le WebP) ;
# - tout fichier PDF de public/ (les CV PDF sont générés par le site ; le CV de référence, qui contient
#   des coordonnées privées, reste dans reference/, hors dépôt) ;
# - toute clé d'API Mistral dans un fichier versionné (MISTRAL_API_KEY renseignée, ou en-tête « Bearer »
#   écrit en dur) : la clé ne vit que dans .env.local ;
# - le plancher personnel de rémunération (ligne 🚫 « Plancher … : N k€ » de ../TEXTES_SITE.md, hors dépôt),
#   sous ses formes « N k€ », « Nk€ », « N K€ », « N 000 », « N000 » : le montant est lu dans ce fichier au
#   moment du contrôle, jamais écrit dans le script ni affiché (seuls le fichier et la ligne sont cités).
#   Sans ../TEXTES_SITE.md (CI, autre poste), ce contrôle est sauté avec un avertissement ;
# - toute valeur de SALARY_FLOOR dans un fichier versionné (plancher pour le serveur : .env.local seulement) ;
# - toute valeur des variables des messageries (DISCORD_* de l'application et du webhook, TELEGRAM_*) dans un
#   fichier versionné, hors .env.test (valeurs factices) : .env.local seulement ;
# - tout fichier d'identifiants, d'après son NOM (le contenu n'est jamais lu) : tout fichier dont le nom
#   contient « NE_PAS_PUSH », quelle que soit son extension, et tout fichier texte ou Markdown (.md, .txt, ou
#   sans extension) dont le nom contient « identifiant », « password » ou « secret » (casse ignorée). Le code
#   (ex. check-staged-secrets.sh, ResetAdminPasswordCommand.php) n'est pas concerné (décision du 2026-10-26).
# Les originaux vont dans ../medias_originaux/ (hors dépôt), convertis avec
# scripts/images/optimize-image.php.
#
# Usage :
#   bash scripts/security/check-staged-assets.sh        fichiers indexés (hook pre-commit)
#   bash scripts/security/check-staged-assets.sh --all  tous les fichiers suivis (CI)
#
# Appelé par : .githooks/pre-commit, .github/workflows/ci.yml
set -euo pipefail

MAX_BYTES=$((200 * 1024))
# Seules images non WebP permises dans public/ : l'image de partage Open Graph (lot 8) et l'icône d'écran
# d'accueil des téléphones (v2.0.2, produite par scripts/images/build-favicons.php)
SHARE_IMAGE="public/partage/guillaume-couget-partage.jpg"
TOUCH_ICON="public/apple-touch-icon.png"

if [[ "${1:-}" == "--all" ]]; then
    mapfile -t files < <(git ls-files 'public/*')
else
    mapfile -t files < <(git diff --cached --name-only --diff-filter=ACMR -- 'public/*')
fi

blocked=()
for file in "${files[@]}"; do
    [[ -f "$file" ]] || continue
    size=$(wc -c < "$file")
    if (( size > MAX_BYTES )); then
        blocked+=("$file : $((size / 1024)) Ko (maximum 200 Ko)")
    fi
    if [[ "${file,,}" =~ \.(png|jpe?g|gif|bmp|tiff?|avif|heic)$ && "$file" != "$SHARE_IMAGE" && "$file" != "$TOUCH_ICON" ]]; then
        blocked+=("$file : format non optimisé (WebP, SVG ou ICO attendu ; exceptions : $SHARE_IMAGE, $TOUCH_ICON)")
    fi
    if [[ "${file,,}" == *.pdf ]]; then
        blocked+=("$file : PDF interdit dans public/ (CV de référence : reference/, hors dépôt)")
    fi
done

# Clé Mistral : seule une valeur vide est permise hors de .env.local (.env, .env.example, .env.test : factice)
if [[ "${1:-}" == "--all" ]]; then
    mapfile -t key_hits < <(git grep -nE '^MISTRAL_API_KEY=.+' -- ':!.env.test' 2>/dev/null || true)
else
    mapfile -t key_hits < <(git diff --cached -U0 --diff-filter=ACMR -- ':!.env.test' | grep -E '^\+MISTRAL_API_KEY=.+' || true)
fi
for hit in "${key_hits[@]}"; do
    [[ -n "$hit" ]] && blocked+=("clé Mistral dans un fichier versionné : ${hit%%=*}=… (la clé va dans .env.local)")
done

# Messageries (Discord, Telegram) : jetons, secrets et identifiants de l'application jamais versionnés
# (.env.test : valeurs factices des tests). La valeur n'est jamais affichée.
CHAT_SECRETS='DISCORD_WEBHOOK_URL|DISCORD_APPLICATION_ID|DISCORD_PUBLIC_KEY|DISCORD_BOT_TOKEN|DISCORD_CHANNEL_ID|DISCORD_ADMIN_USER_ID|TELEGRAM_BOT_TOKEN|TELEGRAM_CHAT_ID|TELEGRAM_USER_ID|TELEGRAM_WEBHOOK_SECRET'
if [[ "${1:-}" == "--all" ]]; then
    mapfile -t chat_hits < <(git grep -nE "^\s*($CHAT_SECRETS)\s*=\s*[^[:space:]#]" -- ':!.env.test' 2>/dev/null | cut -d= -f1 || true)
else
    # « fichier:ligne:NOM » des lignes ajoutées (même lecture du diff que pour SALARY_FLOOR)
    mapfile -t chat_hits < <(git diff --cached -U0 --diff-filter=ACMR --no-color -- ':!.env.test' \
        | awk '/^\+\+\+ b\//{file=substr($0,7)} /^@@/{split($3,a,/[+,]/); line=a[2]; next} /^\+/{if (substr($0,1,3)!="+++") {print file ":" line ":" substr($0,2)}; line++}' \
        | grep -E "^[^:]+:[0-9]+:[[:space:]]*($CHAT_SECRETS)[[:space:]]*=[[:space:]]*[^[:space:]#]" | cut -d= -f1 || true)
fi
for hit in "${chat_hits[@]}"; do
    [[ -n "$hit" ]] && blocked+=("${hit%:*} : ${hit##*:} renseigné dans un fichier versionné (jeton ou identifiant de messagerie : .env.local seulement)")
done

# Plancher pour le serveur (SALARY_FLOOR) : jamais de valeur dans un fichier versionné (montant jamais affiché)
if [[ "${1:-}" == "--all" ]]; then
    mapfile -t floor_env_hits < <(git grep -nE '^\s*SALARY_FLOOR\s*=\s*["'"'"']?[0-9]' 2>/dev/null | cut -d: -f1,2 || true)
else
    mapfile -t floor_env_hits < <(git diff --cached -U0 --diff-filter=ACMR --no-color         | awk '/^\+\+\+ b\//{file=substr($0,7)} /^@@/{split($3,a,/[+,]/); line=a[2]; next} /^\+/{if (substr($0,1,3)!="+++") {print file ":" line ":" substr($0,2)}; line++}'         | grep -E "^[^:]+:[0-9]+:[[:space:]]*SALARY_FLOOR[[:space:]]*=[[:space:]]*[\"']?[0-9]" | cut -d: -f1,2 || true)
fi
for hit in "${floor_env_hits[@]}"; do
    [[ -n "$hit" ]] && blocked+=("$hit : SALARY_FLOOR renseigné dans un fichier versionné (valeur dans .env.local du serveur uniquement)")
done

# Fichiers d'identifiants, d'après leur nom seulement (aucun contenu lu)
if [[ "${1:-}" == "--all" ]]; then
    mapfile -t named_files < <(git ls-files)
else
    mapfile -t named_files < <(git diff --cached --name-only --diff-filter=ACMR)
fi
for file in "${named_files[@]}"; do
    [[ -n "$file" ]] || continue
    name="${file##*/}"
    lower="${name,,}"
    if [[ "$lower" == *ne_pas_push* ]]; then
        blocked+=("$file : fichier « NE_PAS_PUSH » (identifiants) : ne doit jamais être versionné")
    elif [[ "$lower" =~ (identifiant|password|secret) && ( "$lower" =~ \.(md|txt)$ || "$name" != *.* ) ]]; then
        blocked+=("$file : fichier texte ou Markdown nommé comme un fichier d'identifiants (identifiant, password, secret) : ne doit jamais être versionné")
    fi
done

# Plancher personnel : montant lu dans la ligne 🚫 de ../TEXTES_SITE.md (jamais écrit ici)
TEXTES_FILE="$(git rev-parse --show-toplevel)/../TEXTES_SITE.md"
if [[ -f "$TEXTES_FILE" ]]; then
    floor="$(grep '🚫' "$TEXTES_FILE" | grep -i 'plancher' | head -n 1 | sed -nE 's/.*[Pp]lancher[^:]*:[^0-9]*([0-9]+).*/\1/p')"
    if [[ -n "$floor" ]]; then
        nbsp=$'\xc2\xa0'
        nnbsp=$'\xe2\x80\xaf'
        floor_re="(^|[^0-9])${floor}([[:space:]]|${nbsp}|${nnbsp}|\.)?([kK]€|000)([^0-9]|$)"
        if [[ "${1:-}" == "--all" ]]; then
            mapfile -t floor_hits < <(git grep -nIE "$floor_re" -- . ':!*.lock' 2>/dev/null | cut -d: -f1,2 || true)
        else
            mapfile -t floor_hits < <(git diff --cached -U0 --diff-filter=ACMR --no-color \
                | awk '/^\+\+\+ b\//{file=substr($0,7)} /^@@/{split($3,a,/[+,]/); line=a[2]; next} /^\+/{if (substr($0,1,3)!="+++") {print file ":" line ":" substr($0,2)}; line++}' \
                | grep -E "^[^:]+:[0-9]+:.*${floor_re}" | cut -d: -f1,2 || true)
        fi
        for hit in "${floor_hits[@]}"; do
            [[ -n "$hit" ]] && blocked+=("$hit : plancher personnel de rémunération (ligne 🚫 de TEXTES_SITE.md) : à retirer")
        done
    fi
else
    echo "⚠ ../TEXTES_SITE.md absent : contrôle du plancher personnel sauté." >&2
fi

if [[ ${#blocked[@]} -gt 0 ]]; then
    echo ""
    echo "✖ Commit bloqué : fichiers lourds, non optimisés ou PDF dans public/, clé d'API, plancher personnel ou fichier d'identifiants versionnés."
    for item in "${blocked[@]}"; do
        echo "  - $item"
    done
    echo ""
    echo "Ranger l'original dans ../medias_originaux/ et versionner une copie WebP :"
    echo "  php scripts/images/optimize-image.php <original> public/images/<nom>.webp"
    exit 1
fi

exit 0
