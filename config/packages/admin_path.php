<?php

declare(strict_types=1);

use App\Routing\AdminPath;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;

/*
 * Déclare le paramètre app.admin_path (segment d'URL de l'administration).
 *
 * Il est calculé à la compilation pour pouvoir servir dans security.yaml
 * (access_control) et dans Twig. Voir App\Routing\AdminPath.
 */
return static function (ContainerConfigurator $container): void {
    $container->parameters()->set('app.admin_path', AdminPath::fromEnvironment());
};
