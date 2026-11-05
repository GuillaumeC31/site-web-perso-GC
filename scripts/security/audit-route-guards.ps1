# Vérifie la protection de l'administration (version PowerShell de audit-route-guards.sh) :
# 1. security.yaml exige ADMIN_KEY_VERIFIED (mot de passe + clé vérifiée) sur le préfixe admin ;
# 2. chaque contrôleur de src/Controller/Admin/ porte #[IsGranted('ADMIN_KEY_VERIFIED')], sauf
#    SecurityController (connexion), SecondFactorController (ROLE_ADMIN obligatoire) et
#    AbstractCrudController (classe abstraite) ;
# 3. aucune route de test ou de débogage n'existe en production ;
# 4. l'espace recruteur exige ROLE_RECRUITER (hors pages publiques).
#
# Usage : pwsh scripts/security/audit-route-guards.ps1
param()

$ErrorActionPreference = "Stop"

$root = Resolve-Path (Join-Path $PSScriptRoot "../..")
$securityFile = Join-Path $root "config/packages/security.yaml"
$errors = 0

$rule = "{ path: '^/%app.admin_path%(/|`$)', roles: ADMIN_KEY_VERIFIED }"
if (-not (Select-String -Path $securityFile -SimpleMatch -Pattern $rule -Quiet)) {
    Write-Host "✖ Règle ADMIN_KEY_VERIFIED sur le préfixe admin absente de security.yaml" -ForegroundColor Red
    $errors++
}

$recruiterRule = "{ path: '^/espace-recruteur/', roles: ROLE_RECRUITER }"
if (-not (Select-String -Path $securityFile -SimpleMatch -Pattern $recruiterRule -Quiet)) {
    Write-Host "✖ Règle ROLE_RECRUITER sur l'espace recruteur absente de security.yaml" -ForegroundColor Red
    $errors++
}

foreach ($file in Get-ChildItem -Path (Join-Path $root "src/Controller/Admin") -Filter "*Controller.php" -Recurse) {
    if ($file.Name -in @("SecurityController.php", "AbstractCrudController.php")) {
        continue
    }
    if ($file.Name -eq "SecondFactorController.php") {
        if (-not (Select-String -Path $file.FullName -Pattern "#\[IsGranted\('ROLE_ADMIN'\)\]" -Quiet)) {
            Write-Host "✖ SecondFactorController sans #[IsGranted('ROLE_ADMIN')]" -ForegroundColor Red
            $errors++
        }
        continue
    }
    if (-not (Select-String -Path $file.FullName -Pattern "#\[IsGranted\('ADMIN_KEY_VERIFIED'\)\]" -Quiet)) {
        Write-Host "✖ Contrôleur admin sans #[IsGranted('ADMIN_KEY_VERIFIED')] : $($file.Name)" -ForegroundColor Red
        $errors++
    }
}

Push-Location $root
try {
    $env:APP_ENV = "prod"
    $env:APP_DEBUG = "0"
    $routes = php bin/console debug:router --env=prod --no-debug 2>$null
    $suspicious = $routes | Select-String -Pattern '(test|debug|_profiler|_wdt)'
    if ($suspicious) {
        Write-Host "✖ Route de test ou de débogage présente en production :" -ForegroundColor Red
        $suspicious | ForEach-Object { Write-Host "  $_" }
        $errors++
    }
} finally {
    Remove-Item Env:APP_ENV, Env:APP_DEBUG -ErrorAction SilentlyContinue
    Pop-Location
}

if ($errors -gt 0) {
    Write-Host ""
    Write-Host "Audit des routes : $errors problème(s)." -ForegroundColor Red
    exit 1
}

Write-Host "✓ Audit des routes OK (security.yaml, contrôleurs admin, routes de production)." -ForegroundColor Green
