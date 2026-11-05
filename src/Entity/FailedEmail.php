<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\FailedEmailRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * E-mail du site non envoyé (serveur SMTP injoignable, refus du serveur) : gardé pour être renvoyé depuis
 * le tableau de bord de l'admin (« X e-mails non envoyés » → « Renvoyer »), puis supprimé une fois parti.
 *
 * Contenu : l'e-mail tel qu'il aurait dû partir (expéditeur, destinataire, objet, textes), sérialisé. Les
 * e-mails à lien de connexion (validation, renouvellement, lien) ne sont pas renvoyés tels quels : un
 * nouveau lien est créé au renvoi (l'ancien a pu expirer), d'où le recruteur lié.
 *
 * Liens :
 * - Créé par : App\Service\Mail\ResilientMailer::send()
 * - Renvoyé par : App\Service\Mail\FailedEmailResender::resendAll()
 * - Affiché par : Admin\DashboardController::index() (alerte)
 */
#[ORM\Entity(repositoryClass: FailedEmailRepository::class)]
#[ORM\Table(name: 'failed_email')]
class FailedEmail
{
    /** Identifiant technique. */
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    /** Date du premier échec. */
    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    /** Nature de l'e-mail (ResilientMailer::KIND_*). */
    #[ORM\Column(length: 40)]
    private string $kind;

    /** Destinataire. */
    #[ORM\Column(length: 180)]
    private string $recipient;

    /** Objet. */
    #[ORM\Column(length: 255)]
    private string $subject;

    /** E-mail complet sérialisé (Symfony\Component\Mime\Email). */
    #[ORM\Column(type: Types::TEXT)]
    private string $payload;

    /** Recruteur concerné (e-mails à lien de connexion : lien recréé au renvoi), ou null. */
    #[ORM\Column(nullable: true)]
    private ?int $recruiterId = null;

    /** Nombre de tentatives d'envoi. */
    #[ORM\Column]
    private int $attempts = 1;

    /** Dernière erreur du transport (sans donnée personnelle). */
    #[ORM\Column(length: 255)]
    private string $lastError = '';

    /**
     * Crée l'enregistrement d'un e-mail non envoyé.
     *
     * @param string   $kind        nature de l'e-mail (ResilientMailer::KIND_*)
     * @param string   $recipient   destinataire
     * @param string   $subject     objet
     * @param string   $payload     e-mail sérialisé
     * @param int|null $recruiterId recruteur concerné, ou null
     */
    public function __construct(\DateTimeImmutable $createdAt, string $kind, string $recipient, string $subject, string $payload, ?int $recruiterId = null)
    {
        $this->createdAt = $createdAt;
        $this->kind = $kind;
        $this->recipient = mb_substr($recipient, 0, 180);
        $this->subject = mb_substr($subject, 0, 255);
        $this->payload = $payload;
        $this->recruiterId = $recruiterId;
    }

    /** Renvoie l'identifiant technique. */
    public function getId(): ?int
    {
        return $this->id;
    }

    /** Renvoie la date du premier échec. */
    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    /** Renvoie la nature de l'e-mail. */
    public function getKind(): string
    {
        return $this->kind;
    }

    /** Renvoie le destinataire. */
    public function getRecipient(): string
    {
        return $this->recipient;
    }

    /** Renvoie l'objet. */
    public function getSubject(): string
    {
        return $this->subject;
    }

    /** Renvoie l'e-mail sérialisé. */
    public function getPayload(): string
    {
        return $this->payload;
    }

    /** Renvoie le recruteur concerné, ou null. */
    public function getRecruiterId(): ?int
    {
        return $this->recruiterId;
    }

    /** Renvoie le nombre de tentatives. */
    public function getAttempts(): int
    {
        return $this->attempts;
    }

    /** Renvoie la dernière erreur du transport. */
    public function getLastError(): string
    {
        return $this->lastError;
    }

    /**
     * Note un nouvel échec (tentative de plus, erreur).
     *
     * Liens :
     * - Appelée par : ResilientMailer::send(), FailedEmailResender::resendAll()
     *
     * @param string $error message du transport (sans donnée personnelle)
     */
    public function recordFailure(string $error): static
    {
        ++$this->attempts;
        $this->lastError = mb_substr($error, 0, 255);

        return $this;
    }

    /**
     * Note l'erreur du premier échec.
     *
     * @param string $error message du transport (sans donnée personnelle)
     */
    public function setLastError(string $error): static
    {
        $this->lastError = mb_substr($error, 0, 255);

        return $this;
    }
}
