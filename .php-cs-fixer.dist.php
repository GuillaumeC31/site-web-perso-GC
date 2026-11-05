<?php

/*
 * Configuration de PHP-CS-Fixer (jeu de règles @Symfony, compatible PSR-12).
 * Vérification : vendor/bin/php-cs-fixer fix --dry-run --diff
 */

$finder = (new PhpCsFixer\Finder())
    ->in(__DIR__)
    ->exclude(['var', 'node_modules', 'public/build'])
    ->notPath([
        'config/bundles.php',
        'config/reference.php',
    ])
;

return (new PhpCsFixer\Config())
    ->setRules([
        '@Symfony' => true,
        'declare_strict_types' => true,
    ])
    ->setRiskyAllowed(true)
    ->setFinder($finder)
;
