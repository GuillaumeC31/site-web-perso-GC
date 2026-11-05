<?php

declare(strict_types=1);

namespace App\Security;

use App\Entity\User;
use App\Repository\UserRepository;
use Webauthn\Bundle\Repository\PublicKeyCredentialUserEntityRepositoryInterface;
use Webauthn\PublicKeyCredentialUserEntity;

/**
 * Fait le lien entre nos comptes (User) et la représentation WebAuthn d'un utilisateur.
 *
 * Reprise de l'ancien projet, simplifiée : l'identifiant WebAuthn (user handle) est
 * créé avec le compte, il n'est plus généré à la volée. Le nom transmis à la clé est
 * l'identifiant de connexion, jamais l'e-mail.
 *
 * Liens :
 * - Utilisée par : bundle WebAuthn (user_repository dans webauthn.yaml), SecondFactorController
 * - Utilise : UserRepository
 */
final readonly class WebauthnUserEntityRepository implements PublicKeyCredentialUserEntityRepositoryInterface
{
    /**
     * Reçoit le dépôt des comptes.
     *
     * @param UserRepository $userRepository dépôt des comptes admin
     */
    public function __construct(private UserRepository $userRepository)
    {
    }

    /**
     * Trouve l'utilisateur WebAuthn d'un identifiant de connexion.
     *
     * Liens :
     * - Appelée par : bundle WebAuthn
     *
     * @param string $username identifiant de connexion
     *
     * @return PublicKeyCredentialUserEntity|null null si le compte n'existe pas
     */
    public function findOneByUsername(string $username): ?PublicKeyCredentialUserEntity
    {
        $user = $this->userRepository->findOneByUsername($username);

        return null === $user ? null : self::toUserEntity($user);
    }

    /**
     * Trouve l'utilisateur WebAuthn d'un identifiant WebAuthn (user handle).
     *
     * Liens :
     * - Appelée par : bundle WebAuthn
     *
     * @param string $userHandle identifiant WebAuthn
     *
     * @return PublicKeyCredentialUserEntity|null null si aucun compte ne correspond
     */
    public function findOneByUserHandle(string $userHandle): ?PublicKeyCredentialUserEntity
    {
        $user = $this->userRepository->findOneBy(['webauthnUserHandle' => $userHandle]);

        return null === $user ? null : self::toUserEntity($user);
    }

    /**
     * Construit la représentation WebAuthn d'un compte.
     *
     * Liens :
     * - Appelée par : findOneByUsername(), findOneByUserHandle(), SecondFactorController::registrationOptions()
     *
     * @param User $user compte admin
     *
     * @return PublicKeyCredentialUserEntity nom = identifiant de connexion, id = user handle
     */
    public static function toUserEntity(User $user): PublicKeyCredentialUserEntity
    {
        return PublicKeyCredentialUserEntity::create(
            $user->getUsername(),
            $user->getWebauthnUserHandle(),
            $user->getUsername(),
        );
    }
}
