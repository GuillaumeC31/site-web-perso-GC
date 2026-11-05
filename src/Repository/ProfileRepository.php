<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Profile;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * Dépôt du profil (une seule ligne en base).
 *
 * Liens :
 * - Utilisé par : Admin\ProfileController ; pages publiques (lot 3)
 *
 * @extends ServiceEntityRepository<Profile>
 */
class ProfileRepository extends ServiceEntityRepository
{
    /** Nom affiché par défaut, à la création du profil. */
    public const DEFAULT_FULL_NAME = 'Guillaume Couget';

    /** Titre de poste retenu le 2026-10-21 (TEXTES_SITE §0.1, option A). */
    public const DEFAULT_JOB_TITLE = 'Responsable Bureau d\'Études — Génie climatique & électrique';

    /**
     * Déclare l'entité gérée par ce dépôt.
     *
     * @param ManagerRegistry $registry registre Doctrine
     */
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Profile::class);
    }

    /**
     * Renvoie le profil, ou un profil neuf (non enregistré) prérempli avec le nom et le titre.
     *
     * Liens :
     * - Appelée par : Admin\ProfileController::edit()
     *
     * @return Profile le profil existant ou un nouveau profil à enregistrer
     */
    public function getOrCreate(): Profile
    {
        $profile = $this->findOneBy([], ['id' => 'ASC']);

        return $profile ?? (new Profile())
            ->setFullName(self::DEFAULT_FULL_NAME)
            ->setJobTitle(self::DEFAULT_JOB_TITLE);
    }
}
