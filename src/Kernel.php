<?php

declare(strict_types=1);

namespace App;

use App\Service\Dev\E2eMode;
use Symfony\Bundle\FrameworkBundle\Kernel\MicroKernelTrait;
use Symfony\Component\HttpKernel\Kernel as BaseKernel;

class Kernel extends BaseKernel
{
    use MicroKernelTrait;

    /**
     * Dossier du cache : en développement, les requêtes et commandes des tests de bout en bout (E2eMode) ont le leur
     * (var/cache/dev_e2e) : tableau de bord, limiteurs de débit et cache de l'IA ne se mélangent jamais avec ceux de
     * vos visites (décision du 2026-10-29). Ailleurs : dossier habituel.
     *
     * Liens :
     * - Utilise : Service\Dev\E2eMode::isActive()
     */
    public function getCacheDir(): string
    {
        $dir = parent::getCacheDir();

        return E2eMode::isActive($this->environment) ? $dir.E2eMode::DATABASE_SUFFIX : $dir;
    }

    /**
     * @return list<string> An array of allowed values for APP_ENV
     */
    private function getAllowedEnvs(): array
    {
        return ['prod', 'dev', 'test'];
    }
}
