# Vérifie les en-têtes de sécurité renvoyés par le site en ligne (version PowerShell), sur quatre types de
# réponses : une page HTML, un fichier de /build/ (servi par Apache), une page 404 et un PDF.
#
# Chaque en-tête attendu doit être présent EXACTEMENT une fois (v2.0.1 : Apache et Symfony les envoyaient tous
# les deux ; un doublon est signalé). Même contrôle que scripts/security/check-prod-headers.sh.
#
# Usage : pwsh scripts/security/check-prod-headers.ps1 -Url https://guillaume-couget.fr/
# Environnement de test protégé par mot de passe : -Credential (Get-Credential) (sinon la réponse est la page
# 401 d'Apache, sans les en-têtes du site).
param(
    [Parameter(Mandatory = $true)]
    [string]$Url,
    [pscredential]$Credential
)

$ErrorActionPreference = "Stop"
$base = $Url.TrimEnd('/')

$common = @("Strict-Transport-Security", "X-Content-Type-Options", "X-Frame-Options", "Referrer-Policy")
$symfonyOnly = @("Content-Security-Policy", "Permissions-Policy")
$problems = [System.Collections.Generic.List[string]]::new()

# Envoie une requête GET (sans suivre les redirections) et renvoie la réponse.
function Get-Response([string]$target) {
    $options = @{ Uri = $target; Method = 'Get'; MaximumRedirection = 0; SkipHttpErrorCheck = $true }
    if ($Credential) { $options.Credential = $Credential; $options.Authentication = 'Basic' }
    return Invoke-WebRequest @options
}

# Vérifie une adresse : chaque en-tête attendu une seule fois, contenu de la CSP, pas de X-Powered-By.
# $isError : réponse d'erreur, Cache-Control doit contenir no-store (le cache de l’hébergeur garde sinon les 404).
function Test-Url([string]$label, [string]$target, [bool]$isSymfony, [bool]$isError = $false) {
    $response = Get-Response $target
    $headers = $response.Headers
    Write-Host ""
    Write-Host "${label} : $target ($($response.StatusCode))"

    $keys = $common
    if ($isSymfony) { $keys = $common + $symfonyOnly }
    foreach ($key in $keys) {
        $values = @($headers[$key] | Where-Object { $_ })
        if ($values.Count -eq 0) {
            Write-Host "  ✗ ${key} : MANQUANT" -ForegroundColor Red
            $problems.Add("${label}:${key}-manquant")
        } elseif ($values.Count -gt 1) {
            Write-Host "  ✗ ${key} : présent $($values.Count) fois (doublon)" -ForegroundColor Red
            $problems.Add("${label}:${key}-doublon")
        } else {
            Write-Host "  ✓ ${key}: $($values[0])" -ForegroundColor Green
        }
    }

    if ($isSymfony) {
        $csp = [string]($headers["Content-Security-Policy"] | Select-Object -First 1)
        if ($csp -match "unsafe-eval") {
            Write-Host "  ✗ La CSP autorise unsafe-eval" -ForegroundColor Red
            $problems.Add("${label}:csp-unsafe-eval")
        }
        if ($csp -match "script-src[^;]*'unsafe-inline'") {
            Write-Host "  ✗ La CSP autorise les scripts en ligne sans nonce" -ForegroundColor Red
            $problems.Add("${label}:csp-unsafe-inline")
        }
    }
    if ($isError) {
        $cacheControl = [string]($headers["Cache-Control"] -join ', ')
        if ($cacheControl -match 'no-store') {
            Write-Host "  ✓ Cache-Control : no-store (erreur jamais gardée en cache)" -ForegroundColor Green
        } else {
            Write-Host "  ✗ Cache-Control sans no-store sur une réponse d'erreur" -ForegroundColor Red
            $problems.Add("${label}:cache-control-sans-no-store")
        }
    }
    if ($headers["X-Powered-By"]) {
        Write-Host "  ✗ X-Powered-By exposé" -ForegroundColor Red
        $problems.Add("${label}:x-powered-by")
    }
}

# Page privée ou avec formulaire (v2.0.3) : demandée deux fois, jamais gardée par un cache (Cloudflare, cache de l’hébergeur).
function Test-Private([string]$label, [string]$target) {
    $null = Get-Response $target
    $response = Get-Response $target
    Write-Host ""
    Write-Host "${label} : $target ($($response.StatusCode))"
    $cacheControl = [string]($response.Headers["Cache-Control"] -join ', ')
    if ($cacheControl -match 'no-store') {
        Write-Host "  ✓ Cache-Control : no-store" -ForegroundColor Green
    } else {
        Write-Host "  ✗ Cache-Control sans no-store" -ForegroundColor Red
        $problems.Add("${label}:cache-control-sans-no-store")
    }
    $xCache = [string]($response.Headers["X-Cache"] -join ', ')
    if ($xCache -match 'hit') {
        Write-Host "  ✗ servie depuis un cache ($xCache)" -ForegroundColor Red
        $problems.Add("${label}:cache-hit")
    } else {
        Write-Host "  ✓ jamais servie depuis un cache $xCache" -ForegroundColor Green
    }
}

Write-Host "En-têtes de sécurité de $base/"
Test-Url "Page HTML" "$base/" $true

# Premier fichier de /build/ cité par la page d'accueil (nom versionné par Vite)
$homePage = Get-Response "$base/"
$asset = [regex]::Match([string]$homePage.Content, '/build/[^"'' >]+\.(css|js)').Value
if ($asset) {
    Test-Url "Fichier de build" "$base$asset" $false
} else {
    Write-Host "  ✗ Aucun fichier /build/ trouvé dans la page d'accueil" -ForegroundColor Red
    $problems.Add("build-introuvable")
}

# Adresse unique à chaque lancement : jamais une 404 gardée par un cache placé devant le site
Test-Url "Page 404" "$base/controle-en-tetes-page-inexistante-$([DateTimeOffset]::UtcNow.ToUnixTimeSeconds())-$(Get-Random)" $true $true
Test-Url "PDF" "$base/cv/pdf/ats" $true

Test-Private "Page avec formulaire" "$base/espace-recruteur"
Test-Private "Page privée" "$base/espace-recruteur/accueil"

Write-Host ""
if ($problems.Count -gt 0) {
    Write-Host "Problèmes : $($problems -join ' ')" -ForegroundColor Red
    exit 2
}
Write-Host "✓ En-têtes de sécurité OK (une seule fois chacun, sur la page, le fichier de build, la 404 et le PDF)." -ForegroundColor Green
