<?php

declare(strict_types=1);

use App\Service\Dev\E2eMode;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;

/*
 * Développement : pas de barre de débogage pour les requêtes des tests de bout en bout (navigateurs de test, E2eMode).
 * Ces requêtes ont leur propre conteneur (dossier de cache var/cache/dev_e2e, App\Kernel::getCacheDir()) : ce réglage
 * ne s'applique qu'à elles. Sur mobile, la barre ouverte recouvrait parfois les boutons des fenêtres de confirmation.
 * Vos visites gardent la barre (config/packages/web_profiler.yaml).
 *
 * Chargé après config/packages/*.yaml (dossier de l'environnement) : il remplace « toolbar: true ».
 */
return static function (ContainerConfigurator $container): void {
    if (E2eMode::isActive('dev')) {
        $container->extension('web_profiler', ['toolbar' => false]);
    }
};
