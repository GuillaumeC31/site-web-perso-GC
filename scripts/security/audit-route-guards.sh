#!/usr/bin/env bash
# Vérifie la protection de l'administration :
# 1. security.yaml exige ADMIN_KEY_VERIFIED (mot de passe + clé vérifiée) sur le préfixe admin ;
# 2. chaque contrôleur de src/Controller/Admin/ porte #[IsGranted('ADMIN_KEY_VERIFIED')], sauf :
#    - SecurityController (page de connexion, publique par nature),
#    - SecondFactorController (étape YubiKey : #[IsGranted('ROLE_ADMIN')] obligatoire),
#    - AbstractCrudController (classe abstraite : ce sont ses classes filles qui portent la règle) ;
# 3. aucune route de test ou de débogage n'existe en production ;
# 4. l'espace recruteur exige ROLE_RECRUITER (hors pages publiques : demande, connexion, lien invalide,
#    prolongation, déconnexion).
#
# Usage : bash scripts/security/audit-route-guards.sh
# Appelé par : .github/workflows/ci.yml
set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
SECURITY_FILE="$ROOT/config/packages/security.yaml"
errors=0

if ! grep -qF "{ path: '^/%app.admin_path%(/|\$)', roles: ADMIN_KEY_VERIFIED }" "$SECURITY_FILE"; then
    echo "✖ Règle ADMIN_KEY_VERIFIED sur le préfixe admin absente de security.yaml"
    errors=$((errors + 1))
fi

if ! grep -qF "{ path: '^/espace-recruteur/', roles: ROLE_RECRUITER }" "$SECURITY_FILE"; then
    echo "✖ Règle ROLE_RECRUITER sur l'espace recruteur absente de security.yaml"
    errors=$((errors + 1))
fi

while IFS= read -r -d '' file; do
    name="$(basename "$file")"
    case "$name" in
        SecurityController.php|AbstractCrudController.php)
            continue
            ;;
        SecondFactorController.php)
            if ! grep -qE "#\[IsGranted\('ROLE_ADMIN'\)\]" "$file"; then
                echo "✖ SecondFactorController sans #[IsGranted('ROLE_ADMIN')]"
                errors=$((errors + 1))
            fi
            continue
            ;;
    esac
    if ! grep -qE "#\[IsGranted\('ADMIN_KEY_VERIFIED'\)\]" "$file"; then
        echo "✖ Contrôleur admin sans #[IsGranted('ADMIN_KEY_VERIFIED')] : $name"
        errors=$((errors + 1))
    fi
done < <(find "$ROOT/src/Controller/Admin" -name '*Controller.php' -print0)

# Routes de test interdites en production
if command -v php >/dev/null 2>&1 && [[ -f "$ROOT/vendor/autoload.php" ]]; then
    routes="$(cd "$ROOT" && APP_ENV=prod APP_DEBUG=0 php bin/console debug:router --env=prod --no-debug 2>/dev/null || true)"
    if echo "$routes" | grep -qiE '(^|[[:space:]])/?(test|_test|debug|_profiler|_wdt)'; then
        echo "✖ Route de test ou de débogage présente en production :"
        echo "$routes" | grep -iE '(test|debug|_profiler|_wdt)'
        errors=$((errors + 1))
    fi
fi

if [[ $errors -gt 0 ]]; then
    echo ""
    echo "Audit des routes : $errors problème(s)."
    exit 1
fi

echo "✓ Audit des routes OK (security.yaml, contrôleurs admin, routes de production)."
