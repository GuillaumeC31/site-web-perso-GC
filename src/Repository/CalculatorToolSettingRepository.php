<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\CalculatorToolSetting;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * Dépôt des réglages des outils de la calculatrice.
 *
 * Liens :
 * - Utilisé par : CalculatorRegistry, Admin\CalculatorToolController
 *
 * @extends ServiceEntityRepository<CalculatorToolSetting>
 */
class CalculatorToolSettingRepository extends ServiceEntityRepository
{
    /**
     * Déclare l'entité gérée par ce dépôt.
     *
     * Liens :
     * - Appelée par : le conteneur Symfony (autowiring)
     *
     * @param ManagerRegistry $registry registre Doctrine
     */
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, CalculatorToolSetting::class);
    }

    /**
     * Renvoie tous les réglages, indexés par clé d'outil (« categorie/outil »).
     *
     * Liens :
     * - Appelée par : CalculatorRegistry::load()
     *
     * @return array<string, CalculatorToolSetting>
     */
    public function findAllIndexedByKey(): array
    {
        $settings = [];
        foreach ($this->findAll() as $setting) {
            $settings[$setting->getToolKey()] = $setting;
        }

        return $settings;
    }

    /**
     * Renvoie les réglages d'un outil, ou un réglage neuf (non enregistré) s'il n'en a pas encore.
     *
     * Liens :
     * - Appelée par : Admin\CalculatorToolController::edit()
     *
     * @param string $toolKey clé « categorie/outil »
     */
    public function findOrCreate(string $toolKey): CalculatorToolSetting
    {
        return $this->findOneBy(['toolKey' => $toolKey]) ?? new CalculatorToolSetting($toolKey);
    }
}
