#!/usr/bin/env bash
# Vérifie les en-têtes de sécurité renvoyés par le site en ligne, sur quatre types de réponses :
# une page HTML, un fichier de /build/ (servi par Apache), une page 404 et un PDF.
#
# Chaque en-tête attendu doit être présent EXACTEMENT une fois (v2.0.1 : Apache et Symfony les envoyaient tous
# les deux ; un doublon est signalé). Les réponses de Symfony portent aussi la CSP et la Permissions-Policy.
#
# Usage : bash scripts/security/check-prod-headers.sh https://guillaume-couget.fr/
# À lancer depuis votre poste après chaque déploiement.
# Environnement de test protégé par mot de passe : CHECK_AUTH='<identifiant>:<mot de passe>' bash … (sinon la
# réponse est la page 401 d'Apache, sans les en-têtes du site).
#
# Liens :
# - Sources des en-têtes : src/EventSubscriber/SecurityHeadersSubscriber.php (Symfony), public/.htaccess (statiques)
# - Testé par : tests/Security/CheckProdHeadersScriptTest.php
set -euo pipefail

if [[ $# -lt 1 ]]; then
    echo "Usage : $0 <url>"
    exit 1
fi

BASE="${1%/}"
auth=()
if [[ -n "${CHECK_AUTH:-}" ]]; then
    auth=(-u "${CHECK_AUTH}")
fi

# En-têtes communs à toutes les réponses, puis ceux propres aux réponses de Symfony
common=("strict-transport-security" "x-content-type-options" "x-frame-options" "referrer-policy")
symfony_only=("content-security-policy" "permissions-policy")

problems=()

# Vérifie une adresse : présence unique de chaque en-tête attendu, et contenu de la CSP.
#   $1 libellé, $2 adresse, $3 « symfony » (page, 404, PDF) ou « static » (fichier de build/),
#   $4 « error » pour une réponse d'erreur (Cache-Control doit contenir no-store : le cache de l’hébergeur garde
#   sinon les 404, v2.0.2)
check_url() {
    local label="$1" url="$2" kind="$3" error="${4:-}"
    local headers keys count line
    headers="$(curl -sS -o /dev/null -D - ${auth[@]+"${auth[@]}"} "$url" | tr -d '\r')"
    echo ""
    echo "${label} : ${url} ($(echo "$headers" | head -n 1))"

    keys=("${common[@]}")
    if [[ "$kind" == "symfony" ]]; then
        keys+=("${symfony_only[@]}")
    fi

    for key in "${keys[@]}"; do
        count="$(echo "$headers" | grep -ic "^${key}:" || true)"
        line="$(echo "$headers" | grep -i "^${key}:" | head -n 1 || true)"
        if [[ "$count" -eq 0 ]]; then
            echo "  ✗ ${key} : MANQUANT"
            problems+=("${label}:${key}-manquant")
        elif [[ "$count" -gt 1 ]]; then
            echo "  ✗ ${key} : présent ${count} fois (doublon)"
            problems+=("${label}:${key}-doublon")
        else
            echo "  ✓ ${line}"
        fi
    done

    if [[ "$kind" == "symfony" ]]; then
        local csp
        csp="$(echo "$headers" | grep -i '^content-security-policy:' | head -n 1 || true)"
        if echo "$csp" | grep -q "unsafe-eval"; then
            echo "  ✗ La CSP autorise unsafe-eval"
            problems+=("${label}:csp-unsafe-eval")
        fi
        if echo "$csp" | grep -qE "script-src[^;]*'unsafe-inline'"; then
            echo "  ✗ La CSP autorise les scripts en ligne sans nonce"
            problems+=("${label}:csp-unsafe-inline")
        fi
    fi
    if [[ "$error" == "error" ]]; then
        if echo "$headers" | grep -i '^cache-control:' | grep -qi 'no-store'; then
            echo "  ✓ cache-control : no-store (erreur jamais gardée en cache)"
        else
            echo "  ✗ cache-control sans no-store sur une réponse d'erreur"
            problems+=("${label}:cache-control-sans-no-store")
        fi
    fi
    if echo "$headers" | grep -qi '^x-powered-by:'; then
        echo "  ✗ X-Powered-By exposé"
        problems+=("${label}:x-powered-by")
    fi
}

# Page privée ou avec formulaire (v2.0.3) : demandée deux fois, jamais gardée par un cache (Cloudflare, cache de l’hébergeur) :
# Cache-Control avec no-store, et pas de « x-cache: HIT » à la seconde demande.
#   $1 libellé, $2 adresse
check_private() {
    local label="$1" url="$2" headers second
    curl -sS -o /dev/null ${auth[@]+"${auth[@]}"} "$url" > /dev/null
    headers="$(curl -sS -o /dev/null -D - ${auth[@]+"${auth[@]}"} "$url" | tr -d '\r')"
    echo ""
    echo "${label} : ${url} ($(echo "$headers" | head -n 1))"
    if echo "$headers" | grep -i '^cache-control:' | grep -qi 'no-store'; then
        echo "  ✓ cache-control : no-store"
    else
        echo "  ✗ cache-control sans no-store"
        problems+=("${label}:cache-control-sans-no-store")
    fi
    second="$(echo "$headers" | grep -i '^x-cache:' | head -n 1 || true)"
    if echo "$second" | grep -qi 'hit'; then
        echo "  ✗ servie depuis un cache (${second})"
        problems+=("${label}:cache-hit")
    else
        echo "  ✓ jamais servie depuis un cache${second:+ (${second})}"
    fi
}

echo "En-têtes de sécurité de ${BASE}/"

check_url "Page HTML" "${BASE}/" symfony

# Premier fichier de /build/ cité par la page d'accueil (nom versionné par Vite)
asset="$(curl -sS ${auth[@]+"${auth[@]}"} "${BASE}/" | grep -oE '/build/[^"'"'"' >]+\.(css|js)' | head -n 1 || true)"
if [[ -n "$asset" ]]; then
    check_url "Fichier de build" "${BASE}${asset}" static
else
    echo ""
    echo "  ✗ Aucun fichier /build/ trouvé dans la page d'accueil"
    problems+=("build-introuvable")
fi

# Adresse unique à chaque lancement : jamais une 404 gardée par un cache placé devant le site
check_url "Page 404" "${BASE}/controle-en-tetes-page-inexistante-$(date +%s)-${RANDOM}" symfony error
check_url "PDF" "${BASE}/cv/pdf/ats" symfony

check_private "Page avec formulaire" "${BASE}/espace-recruteur"
check_private "Page privée" "${BASE}/espace-recruteur/accueil"

if [[ ${#problems[@]} -gt 0 ]]; then
    echo ""
    echo "Problèmes : ${problems[*]}"
    exit 2
fi

echo ""
echo "✓ En-têtes de sécurité OK (une seule fois chacun, sur la page, le fichier de build, la 404 et le PDF ; pages privées jamais en cache)."
