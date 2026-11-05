<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\User;
use App\Entity\WebauthnCredential;
use Doctrine\Persistence\ManagerRegistry;
use Webauthn\Bundle\Repository\CanSaveCredentialRecord;
use Webauthn\Bundle\Repository\DoctrineCredentialSourceRepository;
use Webauthn\CredentialRecord;
use Webauthn\PublicKeyCredentialDescriptor;

/**
 * Dépôt des clés de sécurité (reprise de l'ancien projet).
 *
 * Liens :
 * - Utilisé par : bundle WebAuthn (credential_repository dans webauthn.yaml), SecondFactorController,
 *   SecurityKeyController, ResetAdminWebauthnCommand
 *
 * @extends DoctrineCredentialSourceRepository<WebauthnCredential>
 */
class WebauthnCredentialRepository extends DoctrineCredentialSourceRepository implements CanSaveCredentialRecord
{
    /**
     * Déclare l'entité gérée par ce dépôt.
     *
     * @param ManagerRegistry $registry registre Doctrine
     */
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, WebauthnCredential::class);
    }

    /**
     * Enregistre une clé ; un enregistrement générique est converti en entité.
     *
     * Liens :
     * - Appelée par : bundle WebAuthn
     *
     * @param CredentialRecord $credentialRecord clé à enregistrer
     */
    public function saveCredentialRecord(CredentialRecord $credentialRecord): void
    {
        if (!$credentialRecord instanceof WebauthnCredential) {
            $credentialRecord = WebauthnCredential::fromCredentialRecord($credentialRecord, '');
        }

        parent::saveCredentialRecord($credentialRecord);
    }

    /**
     * Renvoie les clés d'un compte, de la plus récente à la plus ancienne.
     *
     * Liens :
     * - Appelée par : SecurityKeyController::index() (liste), findDescriptorsByUser()
     *
     * @param User $user compte admin
     *
     * @return list<WebauthnCredential> liste vide si aucune clé
     */
    public function findByUser(User $user): array
    {
        /** @var list<WebauthnCredential> $credentials */
        $credentials = $this->findBy(['userHandle' => $user->getWebauthnUserHandle()], ['createdAt' => 'DESC']);

        return $credentials;
    }

    /**
     * Compte les clés d'un compte.
     *
     * Liens :
     * - Appelée par : SecondFactorController::verify(), SecurityKeyController::delete() (refus de supprimer
     *   la dernière clé), DashboardController, ResetAdminWebauthnCommand
     */
    public function countByUser(User $user): int
    {
        return $this->count(['userHandle' => $user->getWebauthnUserHandle()]);
    }

    /**
     * Renvoie les descripteurs des clés d'un compte, pour les options d'une cérémonie WebAuthn.
     *
     * Liens :
     * - Appelée par : SecondFactorController::assertionOptions() (clés autorisées),
     *   SecondFactorController::registrationOptions() (clés à exclure)
     *
     * @return list<PublicKeyCredentialDescriptor>
     */
    public function findDescriptorsByUser(User $user): array
    {
        return array_map(
            static fn (WebauthnCredential $credential): PublicKeyCredentialDescriptor => $credential->getPublicKeyCredentialDescriptor(),
            $this->findByUser($user),
        );
    }

    /**
     * Supprime toutes les clés d'un compte.
     *
     * Liens :
     * - Appelée par : ResetAdminWebauthnCommand::execute()
     *
     * @return int nombre de clés supprimées
     */
    public function deleteAllForUser(User $user): int
    {
        $deleted = $this->getEntityManager()->createQueryBuilder()
            ->delete(WebauthnCredential::class, 'c')
            ->where('c.userHandle = :handle')
            ->setParameter('handle', $user->getWebauthnUserHandle())
            ->getQuery()
            ->execute();

        return (int) $deleted;
    }
}
