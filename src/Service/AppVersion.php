<?php

declare(strict_types=1);

namespace App\Service;

use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Twig\Attribute\AsTwigFunction;

/**
 * Version de l'application, lue dans le fichier VERSION à la racine du projet : seule source de la
 * version (SemVer, ex. « 2.0.0-alpha.7 »), mise à jour avec CHANGELOG.md à chaque tag.
 *
 * Version complète dans l'admin (pied de page, tableau de bord). Sur le site public (décision du 2026-10-29),
 * seulement le numéro du site sans préversion (« 2.0.0 » pour « 2.0.0-alpha.9 ») : jamais la version de
 * Symfony, de PHP ni d'aucune dépendance.
 *
 * Liens :
 * - Utilisée par : Admin\DashboardController, templates/admin/base.html.twig (fonction Twig app_version()),
 *   templates/_partials/_footer.html.twig (fonction Twig app_public_version())
 * - Voir aussi : VERSION, CHANGELOG.md, scripts/deploy.sh (vérifie le tag)
 */
final readonly class AppVersion
{
    /**
     * Reçoit la racine du projet.
     *
     * @param string $projectDir racine du projet (fichier VERSION)
     */
    public function __construct(
        #[Autowire('%kernel.project_dir%')]
        private string $projectDir,
    ) {
    }

    /**
     * Renvoie la version (chaîne vide si le fichier VERSION est absent).
     *
     * Liens :
     * - Appelée par : la fonction Twig app_version(), Admin\DashboardController::index()
     */
    #[AsTwigFunction('app_version')]
    public function get(): string
    {
        $file = $this->projectDir.'/VERSION';

        return is_file($file) ? trim((string) file_get_contents($file)) : '';
    }

    /**
     * Renvoie le numéro affiché sur le site public : majeur.mineur.correctif seulement, sans préversion ni
     * métadonnées (« 2.0.0-rc.1+abc » donne « 2.0.0 »).
     *
     * Liens :
     * - Appelée par : la fonction Twig app_public_version() (templates/_partials/_footer.html.twig)
     * - Testée par : tests/Service/AppVersionTest
     *
     * @return string numéro public, ou chaîne vide si VERSION est absent ou illisible
     */
    #[AsTwigFunction('app_public_version')]
    public function getPublic(): string
    {
        return 1 === preg_match('/^v?(\d+\.\d+\.\d+)/', $this->get(), $matches) ? $matches[1] : '';
    }
}
