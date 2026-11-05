<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Component\Security\Core\Exception\UnsupportedUserException;
use Symfony\Component\Security\Core\User\PasswordAuthenticatedUserInterface;
use Symfony\Component\Security\Core\User\PasswordUpgraderInterface;

/**
 * Dépôt des comptes d'administration.
 *
 * Liens :
 * - Utilisé par : security.yaml (fournisseur app_user_provider), commandes app:create-admin,
 *   app:admin:reset-password, app:admin:reset-webauthn, WebauthnUserEntityRepository
 *
 * @extends ServiceEntityRepository<User>
 */
class UserRepository extends ServiceEntityRepository implements PasswordUpgraderInterface
{
    /**
     * Déclare l'entité gérée par ce dépôt.
     *
     * @param ManagerRegistry $registry registre Doctrine
     */
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, User::class);
    }

    /**
     * Recherche un compte par son identifiant de connexion.
     *
     * Liens :
     * - Appelée par : commandes de réinitialisation, WebauthnUserEntityRepository::findOneByUsername()
     *
     * @param string $username identifiant de connexion
     *
     * @return User|null le compte, ou null s'il n'existe pas
     */
    public function findOneByUsername(string $username): ?User
    {
        return $this->findOneBy(['username' => $username]);
    }

    /**
     * Indique si un compte administrateur existe déjà.
     *
     * Liens :
     * - Appelée par : CreateAdminCommand (un seul compte admin sur ce site)
     */
    public function hasAdmin(): bool
    {
        foreach ($this->findAll() as $user) {
            if ($user->isAdmin()) {
                return true;
            }
        }

        return false;
    }

    /**
     * Renvoie l'adresse de notification du compte admin : seule adresse des notifications par e-mail et de
     * l'e-mail de test (décision du 2026-10-26 ; remplace NOTIFICATION_EMAIL). Saisie par app:create-admin,
     * modifiable dans l'admin (Paramètres › Notifications).
     *
     * Liens :
     * - Appelée par : NotificationDispatcher (envoi, canal configuré ?), Admin\MailController (e-mail de test),
     *   Admin\SiteSettingsController (affichage et modification)
     *
     * @return string|null l'adresse du premier compte admin qui en a une, ou null (canal e-mail non configuré)
     */
    public function findNotificationEmail(): ?string
    {
        foreach ($this->findBy([], ['id' => 'ASC']) as $user) {
            if ($user->isAdmin() && '' !== trim((string) $user->getNotificationEmail())) {
                return $user->getNotificationEmail();
            }
        }

        return null;
    }

    /**
     * Met à niveau l'empreinte du mot de passe quand l'algorithme évolue.
     *
     * Liens :
     * - Appelée par : le composant Security, après une connexion réussie
     *
     * @param PasswordAuthenticatedUserInterface $user              compte connecté
     * @param string                             $newHashedPassword nouvelle empreinte
     *
     * @throws UnsupportedUserException si le compte n'est pas un User
     */
    public function upgradePassword(PasswordAuthenticatedUserInterface $user, string $newHashedPassword): void
    {
        if (!$user instanceof User) {
            throw new UnsupportedUserException(\sprintf('Type de compte non pris en charge : %s.', $user::class));
        }

        $user->setPassword($newHashedPassword);
        $this->getEntityManager()->flush();
    }
}
