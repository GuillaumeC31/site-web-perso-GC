<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\WebauthnCredentialRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Ulid;
use Symfony\Component\Uid\Uuid;
use Webauthn\CredentialRecord;
use Webauthn\TrustPath\TrustPath;

/**
 * Clé de sécurité (YubiKey) enregistrée pour un compte admin.
 *
 * Reprise de l'ancien projet. Les champs WebAuthn (clé publique, compteur, etc.) sont
 * hérités de CredentialRecord et mappés par le bundle ; on ajoute un nom lisible et
 * les dates d'ajout et de dernière utilisation.
 *
 * Liens :
 * - Utilisée par : WebauthnCredentialRepository, SecurityKeyController, ResetAdminWebauthnCommand
 * - Reliée à : User par userHandle (= User::getWebauthnUserHandle())
 */
#[ORM\Entity(repositoryClass: WebauthnCredentialRepository::class)]
#[ORM\Table(name: 'webauthn_credential')]
#[ORM\Index(name: 'idx_webauthn_credential_user_handle', columns: ['user_handle'])]
class WebauthnCredential extends CredentialRecord
{
    /** Identifiant technique (ULID, 26 caractères). */
    #[ORM\Id]
    #[ORM\Column(length: 26)]
    private string $id;

    /** Nom donné à la clé par l'admin (ex. « YubiKey principale »). */
    #[ORM\Column(length: 80)]
    private string $label = '';

    /** Date d'enregistrement de la clé. */
    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    /** Date de la dernière connexion réussie avec cette clé. */
    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $lastUsedAt = null;

    /**
     * Crée l'enregistrement d'une clé à partir des données validées par la bibliothèque WebAuthn.
     *
     * Liens :
     * - Appelée par : WebauthnCredential::fromCredentialRecord()
     *
     * @param string[]                  $transports moyens de connexion de la clé (usb, nfc…)
     * @param array<string, mixed>|null $otherUI    données d'affichage facultatives
     */
    public function __construct(
        string $publicKeyCredentialId,
        string $type,
        array $transports,
        string $attestationType,
        TrustPath $trustPath,
        Uuid $aaguid,
        string $credentialPublicKey,
        string $userHandle,
        int $counter,
        ?array $otherUI = null,
        ?bool $backupEligible = null,
        ?bool $backupStatus = null,
        ?bool $uvInitialized = null,
    ) {
        $this->id = (string) new Ulid();
        $this->createdAt = new \DateTimeImmutable();
        parent::__construct(
            $publicKeyCredentialId,
            $type,
            $transports,
            $attestationType,
            $trustPath,
            $aaguid,
            $credentialPublicKey,
            $userHandle,
            $counter,
            $otherUI,
            $backupEligible,
            $backupStatus,
            $uvInitialized,
        );
    }

    /**
     * Convertit l'enregistrement renvoyé par le validateur WebAuthn en entité.
     *
     * Liens :
     * - Appelée par : SecondFactorController::verifyRegistration(), WebauthnCredentialRepository::saveCredentialRecord()
     *
     * @param CredentialRecord $record données de la clé validées
     * @param string           $label  nom lisible de la clé
     *
     * @return self l'entité prête à être enregistrée
     */
    public static function fromCredentialRecord(CredentialRecord $record, string $label): self
    {
        $credential = new self(
            $record->publicKeyCredentialId,
            $record->type,
            $record->transports,
            $record->attestationType,
            $record->trustPath,
            $record->aaguid,
            $record->credentialPublicKey,
            $record->userHandle,
            $record->counter,
            $record->otherUI,
            $record->backupEligible,
            $record->backupStatus,
            $record->uvInitialized,
        );
        $credential->label = $label;

        return $credential;
    }

    /** Renvoie l'identifiant technique. */
    public function getId(): string
    {
        return $this->id;
    }

    /** Renvoie le nom lisible de la clé. */
    public function getLabel(): string
    {
        return $this->label;
    }

    /** Renvoie la date d'enregistrement de la clé. */
    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    /** Renvoie la date de dernière utilisation, ou null si jamais utilisée. */
    public function getLastUsedAt(): ?\DateTimeImmutable
    {
        return $this->lastUsedAt;
    }

    /**
     * Note une utilisation réussie et le nouveau compteur de signatures.
     *
     * Le compteur permet de détecter une clé clonée : il doit toujours augmenter
     * (contrôle fait par la bibliothèque WebAuthn).
     *
     * Liens :
     * - Appelée par : SecondFactorController::verifyAssertion()
     *
     * @param int $counter nouveau compteur renvoyé par la clé
     */
    public function markUsed(int $counter): void
    {
        $this->counter = $counter;
        $this->lastUsedAt = new \DateTimeImmutable();
    }
}
