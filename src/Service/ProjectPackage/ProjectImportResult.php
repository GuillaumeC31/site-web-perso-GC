<?php

declare(strict_types=1);

namespace App\Service\ProjectPackage;

use App\Entity\Project;

/**
 * Résultat de l'import d'une archive : projet créé (en brouillon) et messages pour l'admin.
 *
 * Liens :
 * - Créé par : ProjectImporter::import()
 * - Utilisé par : Admin\ProjectController::import() (messages flash)
 */
final readonly class ProjectImportResult
{
    /**
     * Rassemble le résultat.
     *
     * @param Project      $project  projet créé
     * @param bool         $legacy   vrai si l'archive venait de l'ancien site
     * @param int          $images   nombre d'images converties
     * @param list<string> $warnings points à vérifier (images écartées, slug modifié…)
     */
    public function __construct(
        public Project $project,
        public bool $legacy,
        public int $images,
        public array $warnings,
    ) {
    }
}
