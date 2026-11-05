<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\SiteSettings;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * Dépôt des paramètres du site (une seule ligne en base).
 *
 * Liens :
 * - Utilisé par : Admin\SiteSettingsController, NotificationDispatcher, RecruiterAccessManager,
 *   ContactController, RecruiterAreaController
 *
 * @extends ServiceEntityRepository<SiteSettings>
 */
class SiteSettingsRepository extends ServiceEntityRepository
{
    /**
     * Déclare l'entité gérée par ce dépôt.
     *
     * @param ManagerRegistry $registry registre Doctrine
     */
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, SiteSettings::class);
    }

    /**
     * Renvoie les paramètres enregistrés, ou des paramètres par défaut (non enregistrés).
     *
     * Liens :
     * - Appelée par : Admin\SiteSettingsController::edit(), NotificationDispatcher::notify(), contrôleurs publics
     */
    public function getOrCreate(): SiteSettings
    {
        return $this->findOneBy([], ['id' => 'ASC']) ?? new SiteSettings();
    }
}
