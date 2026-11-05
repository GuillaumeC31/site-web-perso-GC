<?php

declare(strict_types=1);

use App\Routing\AdminPath;
use Symfony\Component\Routing\Loader\Configurator\RoutingConfigurator;

/*
 * Importe les contrôleurs de l'administration sous le préfixe ADMIN_PATH.
 *
 * Les noms de routes sont préfixés par « admin_ » (ex. admin_dashboard).
 * Voir App\Routing\AdminPath et config/packages/security.yaml.
 */
return static function (RoutingConfigurator $routes): void {
    $routes->import(
        ['path' => '../../src/Controller/Admin/', 'namespace' => 'App\Controller\Admin'],
        'attribute',
    )
        // false : le tableau de bord est à /admin et non /admin/
        ->prefix('/'.AdminPath::fromEnvironment(), false)
        ->namePrefix('admin_');
};
