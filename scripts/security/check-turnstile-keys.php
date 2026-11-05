<?php

declare(strict_types=1);

/*
 * Refuse des clés Cloudflare Turnstile inutilisables sur un serveur (production et environnement de test) :
 * - clé du site ou clé secrète vide ;
 * - clés de TEST de Cloudflare (préfixes 1x0000…, 2x0000…, 3x0000…, ex. 1x00000000000000000000AA pour le site,
 *   1x0000000000000000000000000000000AA pour le secret) : elles acceptent ou refusent tout, le formulaire ne serait
 *   plus protégé (ou plus utilisable).
 * Les valeurs sont lues dans le fichier d'environnement (dernière occurrence) et jamais affichées.
 *
 * PHP seul, sans Composer ni Symfony : le contrôle tourne avant « composer install », sur le poste Windows comme sur
 * l’hébergeur (Linux). Remplace l'ancien check-turnstile-keys.sh (chemins temporaires non portables, 2026-10-29).
 *
 * Usage : php scripts/security/check-turnstile-keys.php .env.local
 * Code de sortie : 0 si les deux clés sont des vraies clés, 1 sinon (message en français).
 *
 * Appelé par : scripts/deploy.sh (étape 0) ; testé par tests/Deploy/TurnstileKeysCheckTest
 */

$envFile = $argv[1] ?? '.env.local';
if (!is_file($envFile)) {
    fwrite(\STDERR, "Erreur : {$envFile} introuvable.\n");
    exit(1);
}

/**
 * Valeur d'une variable : dernière ligne NOM=…, guillemets, espaces et retours chariot retirés (jamais affichée).
 *
 * @param list<string> $lines lignes du fichier d'environnement
 */
$valueOf = static function (array $lines, string $name): string {
    $value = '';
    foreach ($lines as $line) {
        if (str_starts_with($line, $name.'=')) {
            $value = substr($line, \strlen($name) + 1);
        }
    }

    return str_replace(['"', "'", ' ', "\r"], '', $value);
};

$lines = preg_split('/\n/', (string) file_get_contents($envFile)) ?: [];
$status = 0;
foreach (['TURNSTILE_SITE_KEY', 'TURNSTILE_SECRET_KEY'] as $name) {
    $value = $valueOf($lines, $name);
    if ('' === $value) {
        fwrite(\STDERR, "Erreur : {$name} est vide dans {$envFile} (widget Turnstile à créer).\n");
        $status = 1;
    } elseif (1 === preg_match('/^[1-3]x0000/', $value)) {
        fwrite(\STDERR, "Erreur : {$name} est une clé de TEST Cloudflare (".substr($value, 0, 6).'…) : les formulaires ne seraient pas protégés. Mettez les vraies clés du widget.'."\n");
        $status = 1;
    }
}

if (0 === $status) {
    echo "Clés Turnstile : OK (vraies clés).\n";
}
exit($status);
