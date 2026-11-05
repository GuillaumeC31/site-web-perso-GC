<?php

declare(strict_types=1);

namespace App\Entity;

use App\Enum\NotificationChannel;
use App\Enum\NotificationEvent;
use App\Repository\SiteSettingsRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;
use Symfony\Component\Validator\Context\ExecutionContextInterface;

/**
 * Paramètres du site réglables dans l'admin (une seule ligne) : délai de réponse affiché, canaux de
 * notification de chaque événement, validation automatique des demandes recruteurs (désactivée par
 * défaut, seuil de score), assistant IA de l'espace recruteur (lot 7 : plafonds et seuil d'alerte).
 *
 * Plafonds de l'assistant (décision du 2026-10-24) : 20 questions par recruteur et par jour, 100 par jour
 * pour le site, 1 requête par seconde et 5 000 000 de jetons par mois (hypothèses prudentes à confirmer
 * avec les limites du compte Mistral), alerte à 80 % du plafond mensuel.
 *
 * Les clés et adresses des canaux restent dans .env.local : un canal coché mais non configuré est ignoré.
 *
 * Liens :
 * - Utilisée par : SiteSettingsRepository::getOrCreate(), Admin\SiteSettingsController, SiteSettingsType,
 *   NotificationDispatcher, RecruiterAccessManager, templates contact et espace recruteur
 */
#[ORM\Entity(repositoryClass: SiteSettingsRepository::class)]
#[ORM\Table(name: 'site_settings')]
class SiteSettings
{
    /** Délai de réponse affiché par défaut (TEXTES_SITE § 10 et 11). */
    public const DEFAULT_RESPONSE_DELAY = 'sous 24 h ouvrées';

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    /** Délai de réponse affiché (« sous 24 h ouvrées »). */
    #[ORM\Column(length: 80)]
    #[Assert\NotBlank]
    #[Assert\Length(max: 80)]
    private string $responseDelay = self::DEFAULT_RESPONSE_DELAY;

    /**
     * Canaux de chaque événement : valeur de NotificationEvent => liste de valeurs de NotificationChannel.
     *
     * @var array<string, list<string>>
     */
    #[ORM\Column(type: Types::JSON)]
    private array $notificationChannels = [];

    /** Validation automatique des demandes au-delà du seuil (désactivée par défaut, décision du 2026-10-23). */
    #[ORM\Column]
    private bool $autoApprovalEnabled = false;

    /** Score minimal de la validation automatique. */
    #[ORM\Column]
    #[Assert\Range(min: 0, max: 100)]
    private int $autoApprovalThreshold = 60;

    /** Questions libres à l'assistant IA activées (sinon : assistant guidé seulement). */
    #[ORM\Column(options: ['default' => true])]
    private bool $aiAssistantEnabled = true;

    /** Questions libres par recruteur et par jour. */
    #[ORM\Column(options: ['default' => 20])]
    #[Assert\Range(min: 0, max: 500)]
    private int $aiRecruiterDailyLimit = 20;

    /** Questions libres par jour pour tout le site. */
    #[ORM\Column(options: ['default' => 100])]
    #[Assert\Range(min: 0, max: 10000)]
    private int $aiSiteDailyLimit = 100;

    /** Plafond mensuel de jetons (jetons réels renvoyés par l'API : envoyés + réponse, tous usages). */
    #[ORM\Column(options: ['default' => 5000000])]
    #[Assert\Range(min: 0, max: 1000000000)]
    private int $aiMonthlyTokenLimit = 5000000;

    /** Requêtes par seconde vers le fournisseur, pour tout le site. */
    #[ORM\Column(options: ['default' => 1])]
    #[Assert\Range(min: 1, max: 20)]
    private int $aiRequestsPerSecond = 1;

    /** Seuil d'alerte du tableau de bord, en % du plafond mensuel. */
    #[ORM\Column(options: ['default' => 80])]
    #[Assert\Range(min: 1, max: 100)]
    private int $aiAlertPercent = 80;

    /** Plafond de taille des fiches actives du « Cerveau de l'IA » (jetons estimés, envoyés à chaque question). */
    #[ORM\Column(options: ['default' => 6000])]
    #[Assert\Range(min: 0, max: 50000)]
    private int $aiKnowledgeTokenLimit = 6000;

    /**
     * Génération des cookies « appareil de confiance » (lien vers l'admin sur le cœur du pied de page) : un cookie
     * d'une autre génération est refusé. Incrémentée par « Révoquer tous les appareils de confiance ».
     */
    #[ORM\Column(options: ['default' => 1])]
    private int $trustedDeviceGeneration = 1;

    /**
     * Interrupteur général du bloc « Pourquoi me recruter » de l'accueil (coupé par défaut, v2.0.2) : le bloc ne
     * s'affiche que s'il est activé ET qu'au moins un argument est marqué « Afficher ».
     */
    #[ORM\Column(options: ['default' => false])]
    private bool $recruitmentBlockEnabled = false;

    /** Valeur par défaut du plafond de taille des fiches (jetons). */
    public const DEFAULT_AI_KNOWLEDGE_TOKEN_LIMIT = 6000;

    /**
     * Hébergeur par défaut (mentions légales, LCEN) : l’hébergeur, d'après https://hebergeur.example.com/cgv/ et le
     * pied de page du site l’hébergeur, consultés le 24/10/2026.
     */
    public const DEFAULT_HOST_NAME = 'Hébergeur Exemple SAS, RCS Exemple, SIREN 000 000 000';
    public const DEFAULT_HOST_ADDRESS = '1 rue de l’Exemple, 75000 Paris, France';
    public const DEFAULT_HOST_PHONE = '01 23 45 67 89';
    public const DEFAULT_HOST_WEBSITE = 'https://hebergeur.example.com';

    /** Title de l'accueil (référencement) ; vide : texte par défaut (TEXTES_SITE § 18). */
    #[ORM\Column(length: 70, nullable: true)]
    #[Assert\Length(max: 70)]
    private ?string $seoTitle = null;

    /** Meta description de l'accueil ; vide : texte par défaut (TEXTES_SITE § 18). */
    #[ORM\Column(length: 170, nullable: true)]
    #[Assert\Length(max: 170)]
    private ?string $seoDescription = null;

    /** Hébergeur (mentions légales) : raison sociale, forme, capital, immatriculation. */
    #[ORM\Column(length: 200, options: ['default' => self::DEFAULT_HOST_NAME])]
    #[Assert\NotBlank]
    #[Assert\Length(max: 200)]
    private string $hostName = self::DEFAULT_HOST_NAME;

    /** Hébergeur : adresse postale du siège. */
    #[ORM\Column(length: 200, options: ['default' => self::DEFAULT_HOST_ADDRESS])]
    #[Assert\NotBlank]
    #[Assert\Length(max: 200)]
    private string $hostAddress = self::DEFAULT_HOST_ADDRESS;

    /** Hébergeur : téléphone (exigé par la LCEN pour l'hébergeur). */
    #[ORM\Column(length: 40, options: ['default' => self::DEFAULT_HOST_PHONE])]
    #[Assert\NotBlank]
    #[Assert\Length(max: 40)]
    private string $hostPhone = self::DEFAULT_HOST_PHONE;

    /** Hébergeur : site web. */
    #[ORM\Column(length: 255, options: ['default' => self::DEFAULT_HOST_WEBSITE])]
    #[Assert\NotBlank]
    #[Assert\Url(requireTld: true)]
    private string $hostWebsite = self::DEFAULT_HOST_WEBSITE;

    /** Mode maintenance : le site public répond 503 (l'admin reste accessible). */
    #[ORM\Column(options: ['default' => false])]
    private bool $maintenanceEnabled = false;

    /** Message affiché pendant la maintenance ; vide : message par défaut. */
    #[ORM\Column(length: 300, nullable: true)]
    #[Assert\Length(max: 300)]
    private ?string $maintenanceMessage = null;

    /** Date et heure de réactivation prévues (UTC en base, saisies à l'heure de Paris) ; null : « Retour très bientôt ». */
    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $maintenanceUntil = null;

    /**
     * Adresses IP qui voient le site normal pendant la maintenance (facultatif) : une par ligne, IPv4 ou IPv6,
     * plage CIDR acceptée (« 203.0.113.0/24 »). L'admin reste toujours accessible.
     */
    #[ORM\Column(length: 1000, nullable: true)]
    #[Assert\Length(max: 1000)]
    private ?string $maintenanceAllowedIps = null;

    /**
     * Validation à distance des demandes recruteurs (boutons Telegram, liens Discord) : désactivée par
     * défaut (décision du 2026-10-26).
     */
    #[ORM\Column(options: ['default' => false])]
    private bool $remoteValidationEnabled = false;

    /** Empreinte du code PIN personnel des liens Discord (jamais le code lui-même ; null : aucun code). */
    #[ORM\Column(length: 255, nullable: true)]
    private ?string $remotePinHash = null;

    /** Sélecteur DKIM par défaut de l’hébergeur (enregistrement default._domainkey.<domaine>), voir DEFAULT_DKIM_SOURCE. */
    public const DEFAULT_DKIM_SELECTOR = 'default';

    /** Plafond quotidien par défaut des e-mails aux personnes non validées (décision du 2026-10-29). */
    public const DEFAULT_UNVALIDATED_EMAIL_CAP = 30;

    /** Délai après lequel le tableau de bord rappelle de télécharger une copie de sauvegarde (jours). */
    public const BACKUP_DOWNLOAD_DAYS = 30;

    /** Source du sélecteur DKIM par défaut (blog officiel de l’hébergeur, consulté le 29/10/2026). */
    public const DEFAULT_DKIM_SOURCE = 'https://hebergeur.example.com/aide/dkim/';

    /**
     * Adresse « Recrutement » (webmail recrutement) : demandes, signaux d'intérêt, questions, messages de contact,
     * suppressions de données ; adresse de réponse des e-mails aux recruteurs VALIDÉS. Null : adresse de
     * notification du compte admin (Mail\MailRecipients).
     */
    #[ORM\Column(length: 180, nullable: true)]
    #[Assert\Email]
    #[Assert\Length(max: 180)]
    private ?string $recruitmentEmail = null;

    /** Adresse « Général du site » (webmail général) : résumé, échecs, sécurité, tâches, IA, maintenance. Null : compte admin. */
    #[ORM\Column(length: 180, nullable: true)]
    #[Assert\Email]
    #[Assert\Length(max: 180)]
    private ?string $generalEmail = null;

    /** Adresse « Calculatrice » (suggestions, fonction à venir). Null : repli sur « Général du site ». */
    #[ORM\Column(length: 180, nullable: true)]
    #[Assert\Email]
    #[Assert\Length(max: 180)]
    private ?string $calculatorEmail = null;

    /** Nom d'expéditeur affiché ; null : MAILER_FROM_NAME (« Guillaume Couget – Espace recruteur »). */
    #[ORM\Column(length: 100, nullable: true)]
    #[Assert\Length(max: 100)]
    #[Assert\Regex(pattern: '/^[^<>"\r\n]*$/u', message: 'admin.communication.from_name_invalid')]
    private ?string $mailFromName = null;

    /** Sélecteur DKIM vérifié par la délivrabilité (l’hébergeur : « default »). */
    #[ORM\Column(length: 63, options: ['default' => self::DEFAULT_DKIM_SELECTOR])]
    #[Assert\NotBlank]
    #[Assert\Regex(pattern: '/^[a-z0-9]([a-z0-9-]{0,61}[a-z0-9])?$/i', message: 'admin.communication.dkim_selector_invalid')]
    private string $dkimSelector = self::DEFAULT_DKIM_SELECTOR;

    /**
     * Plafond quotidien des e-mails envoyés à des personnes non validées (accusé de réception, « demande déjà en cours »,
     * refus) : au-delà, ces e-mails sont suspendus jusqu'au lendemain (Mail\UnvalidatedEmailCap). Jamais appliqué aux
     * recruteurs validés ni aux notifications de Guillaume.
     */
    #[ORM\Column(options: ['default' => self::DEFAULT_UNVALIDATED_EMAIL_CAP])]
    #[Assert\Range(min: 1, max: 500)]
    private int $unvalidatedEmailDailyCap = self::DEFAULT_UNVALIDATED_EMAIL_CAP;

    /**
     * Dernière copie de sauvegarde téléchargée hors de l’hébergeur (bouton « J'ai téléchargé une copie » du tableau de bord) ;
     * null : jamais. Rappel au tableau de bord après BACKUP_DOWNLOAD_DAYS jours.
     */
    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $backupDownloadedAt = null;

    /** Crée les paramètres par défaut : canaux par défaut de chaque événement (NotificationEvent::defaultChannels()). */
    public function __construct()
    {
        foreach (NotificationEvent::cases() as $event) {
            $this->setChannels($event, $event->defaultChannels());
        }
    }

    /** Renvoie l'adresse « Recrutement » enregistrée (null : repli sur le compte admin). */
    public function getRecruitmentEmail(): ?string
    {
        return $this->recruitmentEmail;
    }

    /** Définit l'adresse « Recrutement » (vide : null). */
    public function setRecruitmentEmail(?string $email): static
    {
        $this->recruitmentEmail = self::normalizeEmail($email);

        return $this;
    }

    /** Renvoie l'adresse « Général du site » enregistrée (null : repli sur le compte admin). */
    public function getGeneralEmail(): ?string
    {
        return $this->generalEmail;
    }

    /** Définit l'adresse « Général du site » (vide : null). */
    public function setGeneralEmail(?string $email): static
    {
        $this->generalEmail = self::normalizeEmail($email);

        return $this;
    }

    /** Renvoie l'adresse « Calculatrice » enregistrée (null : repli sur « Général du site »). */
    public function getCalculatorEmail(): ?string
    {
        return $this->calculatorEmail;
    }

    /** Définit l'adresse « Calculatrice » (vide : null). */
    public function setCalculatorEmail(?string $email): static
    {
        $this->calculatorEmail = self::normalizeEmail($email);

        return $this;
    }

    /** Renvoie le nom d'expéditeur choisi (null : MAILER_FROM_NAME). */
    public function getMailFromName(): ?string
    {
        return $this->mailFromName;
    }

    /** Définit le nom d'expéditeur (vide : null). */
    public function setMailFromName(?string $name): static
    {
        $name = trim((string) $name);
        $this->mailFromName = '' === $name ? null : $name;

        return $this;
    }

    /** Renvoie le plafond quotidien des e-mails aux personnes non validées. */
    public function getUnvalidatedEmailDailyCap(): int
    {
        return $this->unvalidatedEmailDailyCap;
    }

    /** Définit le plafond quotidien des e-mails aux personnes non validées (vide : valeur par défaut). */
    public function setUnvalidatedEmailDailyCap(?int $cap): static
    {
        $this->unvalidatedEmailDailyCap = $cap ?? self::DEFAULT_UNVALIDATED_EMAIL_CAP;

        return $this;
    }

    /** Renvoie la date de la dernière copie de sauvegarde téléchargée, ou null (jamais). */
    public function getBackupDownloadedAt(): ?\DateTimeImmutable
    {
        return $this->backupDownloadedAt;
    }

    /** Note la date de la dernière copie de sauvegarde téléchargée. */
    public function setBackupDownloadedAt(?\DateTimeImmutable $backupDownloadedAt): static
    {
        $this->backupDownloadedAt = $backupDownloadedAt;

        return $this;
    }

    /** Renvoie le sélecteur DKIM vérifié. */
    public function getDkimSelector(): string
    {
        return $this->dkimSelector;
    }

    /** Définit le sélecteur DKIM (vide : celui de l’hébergeur). */
    public function setDkimSelector(?string $selector): static
    {
        $selector = trim((string) $selector);
        $this->dkimSelector = '' === $selector ? self::DEFAULT_DKIM_SELECTOR : $selector;

        return $this;
    }

    /** Adresse nettoyée (minuscules, espaces retirés) ou null si vide. */
    private static function normalizeEmail(?string $email): ?string
    {
        $email = mb_strtolower(trim((string) $email));

        return '' === $email ? null : $email;
    }

    /** Renvoie l'identifiant technique. */
    public function getId(): ?int
    {
        return $this->id;
    }

    /** Renvoie le délai de réponse affiché. */
    public function getResponseDelay(): string
    {
        return $this->responseDelay;
    }

    /** Définit le délai de réponse affiché. */
    public function setResponseDelay(?string $responseDelay): static
    {
        $this->responseDelay = trim((string) $responseDelay);

        return $this;
    }

    /**
     * Renvoie les canaux d'un événement ; un événement ajouté après coup (jamais réglé) prend ses canaux par
     * défaut.
     *
     * Liens :
     * - Appelée par : NotificationDispatcher::notify(), Form\CommunicationNotificationsType
     *
     * @return list<NotificationChannel>
     */
    public function getChannels(NotificationEvent $event): array
    {
        if (!\array_key_exists($event->value, $this->notificationChannels)) {
            return $event->defaultChannels();
        }

        return array_values(array_filter(array_map(NotificationChannel::tryFrom(...), $this->notificationChannels[$event->value])));
    }

    /**
     * Définit les canaux d'un événement.
     *
     * @param list<NotificationChannel> $channels
     */
    public function setChannels(NotificationEvent $event, array $channels): static
    {
        $this->notificationChannels[$event->value] = array_map(static fn (NotificationChannel $c): string => $c->value, $channels);

        return $this;
    }

    /** Indique si la validation automatique est activée. */
    public function isAutoApprovalEnabled(): bool
    {
        return $this->autoApprovalEnabled;
    }

    /** Active ou désactive la validation automatique. */
    public function setAutoApprovalEnabled(bool $autoApprovalEnabled): static
    {
        $this->autoApprovalEnabled = $autoApprovalEnabled;

        return $this;
    }

    /** Renvoie le seuil de la validation automatique. */
    public function getAutoApprovalThreshold(): int
    {
        return $this->autoApprovalThreshold;
    }

    /** Définit le seuil de la validation automatique. */
    public function setAutoApprovalThreshold(?int $autoApprovalThreshold): static
    {
        $this->autoApprovalThreshold = (int) $autoApprovalThreshold;

        return $this;
    }

    /** Indique si les questions libres à l'assistant IA sont activées. */
    public function isAiAssistantEnabled(): bool
    {
        return $this->aiAssistantEnabled;
    }

    /** Active ou désactive les questions libres à l'assistant IA. */
    public function setAiAssistantEnabled(bool $aiAssistantEnabled): static
    {
        $this->aiAssistantEnabled = $aiAssistantEnabled;

        return $this;
    }

    /** Renvoie le plafond de questions libres par recruteur et par jour. */
    public function getAiRecruiterDailyLimit(): int
    {
        return $this->aiRecruiterDailyLimit;
    }

    /** Définit le plafond de questions libres par recruteur et par jour. */
    public function setAiRecruiterDailyLimit(?int $limit): static
    {
        $this->aiRecruiterDailyLimit = (int) $limit;

        return $this;
    }

    /** Renvoie le plafond de questions libres par jour pour le site. */
    public function getAiSiteDailyLimit(): int
    {
        return $this->aiSiteDailyLimit;
    }

    /** Définit le plafond de questions libres par jour pour le site. */
    public function setAiSiteDailyLimit(?int $limit): static
    {
        $this->aiSiteDailyLimit = (int) $limit;

        return $this;
    }

    /** Renvoie le plafond mensuel de jetons. */
    public function getAiMonthlyTokenLimit(): int
    {
        return $this->aiMonthlyTokenLimit;
    }

    /** Définit le plafond mensuel de jetons. */
    public function setAiMonthlyTokenLimit(?int $limit): static
    {
        $this->aiMonthlyTokenLimit = (int) $limit;

        return $this;
    }

    /** Renvoie le nombre de requêtes par seconde autorisées vers le fournisseur. */
    public function getAiRequestsPerSecond(): int
    {
        return $this->aiRequestsPerSecond;
    }

    /** Définit le nombre de requêtes par seconde autorisées vers le fournisseur. */
    public function setAiRequestsPerSecond(?int $requestsPerSecond): static
    {
        $this->aiRequestsPerSecond = max(1, (int) $requestsPerSecond);

        return $this;
    }

    /** Renvoie le seuil d'alerte (% du plafond mensuel). */
    public function getAiAlertPercent(): int
    {
        return $this->aiAlertPercent;
    }

    /** Définit le seuil d'alerte (% du plafond mensuel). */
    public function setAiAlertPercent(?int $percent): static
    {
        $this->aiAlertPercent = (int) $percent;

        return $this;
    }

    /** Renvoie le title de l'accueil choisi dans l'admin (null : texte par défaut). */
    public function getSeoTitle(): ?string
    {
        return $this->seoTitle;
    }

    /** Définit le title de l'accueil (vide : texte par défaut). */
    public function setSeoTitle(?string $seoTitle): static
    {
        $this->seoTitle = '' === trim((string) $seoTitle) ? null : trim((string) $seoTitle);

        return $this;
    }

    /** Renvoie la meta description de l'accueil choisie dans l'admin (null : texte par défaut). */
    public function getSeoDescription(): ?string
    {
        return $this->seoDescription;
    }

    /** Définit la meta description de l'accueil (vide : texte par défaut). */
    public function setSeoDescription(?string $seoDescription): static
    {
        $this->seoDescription = '' === trim((string) $seoDescription) ? null : trim((string) $seoDescription);

        return $this;
    }

    /** Indique si le mode maintenance est actif. */
    public function isMaintenanceEnabled(): bool
    {
        return $this->maintenanceEnabled;
    }

    /** Active ou désactive le mode maintenance. */
    public function setMaintenanceEnabled(bool $maintenanceEnabled): static
    {
        $this->maintenanceEnabled = $maintenanceEnabled;

        return $this;
    }

    /** Renvoie la date de réactivation prévue (null : aucune date annoncée). */
    public function getMaintenanceUntil(): ?\DateTimeImmutable
    {
        return $this->maintenanceUntil;
    }

    /** Définit la date de réactivation prévue (null : « Retour très bientôt »). */
    public function setMaintenanceUntil(?\DateTimeImmutable $maintenanceUntil): static
    {
        $this->maintenanceUntil = $maintenanceUntil;

        return $this;
    }

    /** Renvoie la liste brute des adresses IP autorisées pendant la maintenance (null : aucune). */
    public function getMaintenanceAllowedIps(): ?string
    {
        return $this->maintenanceAllowedIps;
    }

    /** Définit les adresses IP autorisées (une par ligne ; vide : null). */
    public function setMaintenanceAllowedIps(?string $maintenanceAllowedIps): static
    {
        $value = trim((string) $maintenanceAllowedIps);
        $this->maintenanceAllowedIps = '' === $value ? null : $value;

        return $this;
    }

    /**
     * Renvoie les adresses IP autorisées pendant la maintenance, une par élément (lignes, virgules ou espaces).
     *
     * Liens :
     * - Appelée par : MaintenanceSubscriber::onRequest(), validateMaintenanceAllowedIps()
     *
     * @return list<string> adresses ou plages CIDR ; liste vide si aucune
     */
    public function getMaintenanceAllowedIpList(): array
    {
        return array_values(array_filter(preg_split('/[\s,;]+/', (string) $this->maintenanceAllowedIps) ?: [], static fn (string $ip): bool => '' !== $ip));
    }

    /**
     * Vérifie que chaque adresse autorisée est une IPv4 ou une IPv6, avec ou sans plage CIDR.
     *
     * Liens :
     * - Appelée par : le validateur (formulaire SiteSettingsType)
     */
    #[Assert\Callback]
    public function validateMaintenanceAllowedIps(ExecutionContextInterface $context): void
    {
        foreach ($this->getMaintenanceAllowedIpList() as $entry) {
            [$ip, $mask] = array_pad(explode('/', $entry, 2), 2, null);
            $isV6 = false !== filter_var($ip, \FILTER_VALIDATE_IP, \FILTER_FLAG_IPV6);
            $validIp = false !== filter_var($ip, \FILTER_VALIDATE_IP);
            $validMask = null === $mask || (ctype_digit($mask) && (int) $mask <= ($isV6 ? 128 : 32));
            if (!$validIp || !$validMask) {
                $context->buildViolation('admin.site_settings.maintenance_ip_invalid')->setParameter('%ip%', $entry)->atPath('maintenanceAllowedIps')->addViolation();
            }
        }
    }

    /** Indique si la validation à distance (Telegram, Discord) est activée. */
    public function isRemoteValidationEnabled(): bool
    {
        return $this->remoteValidationEnabled;
    }

    /** Active ou désactive la validation à distance. */
    public function setRemoteValidationEnabled(bool $remoteValidationEnabled): static
    {
        $this->remoteValidationEnabled = $remoteValidationEnabled;

        return $this;
    }

    /** Renvoie l'empreinte du code PIN des liens Discord (null : aucun code défini). */
    public function getRemotePinHash(): ?string
    {
        return $this->remotePinHash;
    }

    /**
     * Enregistre l'empreinte du code PIN (calculée par Service\Recruiter\RemoteDecisionExecutor::hashPin()).
     *
     * Liens :
     * - Appelée par : Admin\SiteSettingsController::edit()
     */
    public function setRemotePinHash(?string $remotePinHash): static
    {
        $this->remotePinHash = $remotePinHash;

        return $this;
    }

    /** Renvoie le message de maintenance (null : message par défaut). */
    public function getMaintenanceMessage(): ?string
    {
        return $this->maintenanceMessage;
    }

    /** Définit le message de maintenance. */
    public function setMaintenanceMessage(?string $maintenanceMessage): static
    {
        $this->maintenanceMessage = '' === trim((string) $maintenanceMessage) ? null : trim((string) $maintenanceMessage);

        return $this;
    }

    /** Renvoie la raison sociale et l'identification de l'hébergeur (mentions légales). */
    public function getHostName(): string
    {
        return $this->hostName;
    }

    /** Définit la raison sociale et l'identification de l'hébergeur. */
    public function setHostName(?string $hostName): static
    {
        $this->hostName = trim((string) $hostName);

        return $this;
    }

    /** Renvoie l'adresse de l'hébergeur (mentions légales). */
    public function getHostAddress(): string
    {
        return $this->hostAddress;
    }

    /** Définit l'adresse de l'hébergeur. */
    public function setHostAddress(?string $hostAddress): static
    {
        $this->hostAddress = trim((string) $hostAddress);

        return $this;
    }

    /** Renvoie le téléphone de l'hébergeur (mentions légales). */
    public function getHostPhone(): string
    {
        return $this->hostPhone;
    }

    /** Définit le téléphone de l'hébergeur. */
    public function setHostPhone(?string $hostPhone): static
    {
        $this->hostPhone = trim((string) $hostPhone);

        return $this;
    }

    /** Renvoie le site de l'hébergeur (mentions légales). */
    public function getHostWebsite(): string
    {
        return $this->hostWebsite;
    }

    /** Définit le site de l'hébergeur. */
    public function setHostWebsite(?string $hostWebsite): static
    {
        $this->hostWebsite = trim((string) $hostWebsite);

        return $this;
    }

    /** Renvoie le plafond de taille des fiches actives (jetons estimés). */
    public function getAiKnowledgeTokenLimit(): int
    {
        return $this->aiKnowledgeTokenLimit;
    }

    /** Définit le plafond de taille des fiches actives (jetons estimés). */
    public function setAiKnowledgeTokenLimit(?int $limit): static
    {
        $this->aiKnowledgeTokenLimit = max(0, (int) $limit);

        return $this;
    }

    /**
     * Renvoie la génération actuelle des cookies « appareil de confiance ».
     *
     * Liens :
     * - Appelée par : Security\TrustedDeviceCookie (signature et contrôle du cookie)
     */
    public function getTrustedDeviceGeneration(): int
    {
        return $this->trustedDeviceGeneration;
    }

    /**
     * Révoque tous les appareils de confiance en passant à la génération suivante.
     *
     * Liens :
     * - Appelée par : Admin\SecurityKeyController::revokeTrustedDevices()
     *
     * @return int la nouvelle génération
     */
    public function revokeTrustedDevices(): int
    {
        return ++$this->trustedDeviceGeneration;
    }

    /**
     * Indique si le bloc « Pourquoi me recruter » est activé (interrupteur général).
     *
     * Liens :
     * - Appelée par : Service\Content\RecruitmentArguments::getDisplayed(), Admin\RecruitmentArgumentController
     */
    public function isRecruitmentBlockEnabled(): bool
    {
        return $this->recruitmentBlockEnabled;
    }

    /**
     * Active ou coupe le bloc « Pourquoi me recruter ».
     *
     * Liens :
     * - Appelée par : Admin\RecruitmentArgumentController::toggleBlock()
     */
    public function setRecruitmentBlockEnabled(bool $enabled): static
    {
        $this->recruitmentBlockEnabled = $enabled;

        return $this;
    }
}
