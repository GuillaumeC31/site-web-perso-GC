<?php

declare(strict_types=1);

namespace App\Service\Mail;

use App\Enum\MailCategory;
use App\Repository\SiteSettingsRepository;
use App\Repository\UserRepository;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Adresses de réception du site, par catégorie (Communication › E-mails, décision du 2026-10-29), et adresse
 * de réponse des e-mails aux recruteurs validés.
 *
 * Adresse effective :
 * - Recrutement et Général du site : adresse enregistrée, sinon adresse de notification du compte admin ;
 * - Calculatrice : adresse enregistrée, sinon celle de « Général du site ».
 * Adresse de réponse (Reply-To) : adresse « Recrutement » enregistrée, sinon MAILER_REPLY_TO (simple repli).
 *
 * Les adresses fictives (example.test, example.com, localhost…) sont refusées en production : saisie
 * (Admin\CommunicationController) et déploiement (app:communication:check, scripts/deploy.sh).
 *
 * Liens :
 * - Utilisé par : NotificationDispatcher, MailFactory (adresse de réponse, nom d'expéditeur),
 *   DashboardWeeklySummaryCommand, Admin\CommunicationController, Command\CommunicationCheckCommand,
 *   DashboardMetrics (tuile Communication)
 * - Utilise : SiteSettingsRepository, UserRepository::findNotificationEmail()
 * - Testé par : tests/Service/Mail/MailRecipientsTest
 */
final readonly class MailRecipients
{
    /** Domaines réservés aux exemples et aux tests (RFC 2606 et 6761) : jamais une vraie boîte. */
    private const FICTITIOUS_DOMAINS = ['example.test', 'example.com', 'example.org', 'example.net', 'localhost'];

    /** Suffixes réservés (RFC 2606 et 6761). */
    private const FICTITIOUS_SUFFIXES = ['.test', '.example', '.invalid', '.localhost', '.local'];

    /**
     * Reçoit les sources des adresses.
     *
     * @param string $replyToFallback MAILER_REPLY_TO (repli de l'adresse de réponse ; vide : aucun)
     * @param string $fromName        MAILER_FROM_NAME (nom d'expéditeur par défaut)
     */
    public function __construct(
        private SiteSettingsRepository $settingsRepository,
        private UserRepository $userRepository,
        #[Autowire('%env(MAILER_REPLY_TO)%')]
        private string $replyToFallback,
        #[Autowire('%env(MAILER_FROM_NAME)%')]
        private string $fromName,
    ) {
    }

    /**
     * Renvoie l'adresse effective d'une catégorie.
     *
     * Liens :
     * - Appelée par : NotificationDispatcher (canal e-mail), DashboardWeeklySummaryCommand, Admin\CommunicationController
     *   (e-mail de test), CommunicationCheckCommand
     *
     * @return string|null adresse, ou null si aucune n'est connue (ni enregistrée ni sur le compte admin)
     */
    public function addressFor(MailCategory $category): ?string
    {
        $settings = $this->settingsRepository->getOrCreate();

        return match ($category) {
            MailCategory::Recruitment => $settings->getRecruitmentEmail() ?? $this->userRepository->findNotificationEmail(),
            MailCategory::General => $settings->getGeneralEmail() ?? $this->userRepository->findNotificationEmail(),
            MailCategory::Calculator => $settings->getCalculatorEmail() ?? $this->addressFor(MailCategory::General),
        };
    }

    /**
     * Indique d'où vient l'adresse effective d'une catégorie (affichage dans l'admin).
     *
     * @return string « saved » (enregistrée), « account » (compte admin), « general » (repli de la Calculatrice) ou « none »
     */
    public function sourceOf(MailCategory $category): string
    {
        $settings = $this->settingsRepository->getOrCreate();
        $saved = match ($category) {
            MailCategory::Recruitment => $settings->getRecruitmentEmail(),
            MailCategory::General => $settings->getGeneralEmail(),
            MailCategory::Calculator => $settings->getCalculatorEmail(),
        };

        return match (true) {
            null !== $saved => 'saved',
            null === $this->addressFor($category) => 'none',
            MailCategory::Calculator === $category => 'general',
            default => 'account',
        };
    }

    /**
     * Renvoie l'adresse de réponse des e-mails envoyés aux recruteurs VALIDÉS : adresse « Recrutement »
     * enregistrée, sinon MAILER_REPLY_TO. Jamais utilisée pour une demande non validée.
     *
     * Liens :
     * - Appelée par : MailFactory::createTyped() (EmailType::isForValidatedRecruiter())
     *
     * @return string|null adresse, ou null si aucune n'est réglée
     */
    public function replyToForValidatedRecruiters(): ?string
    {
        $saved = $this->settingsRepository->getOrCreate()->getRecruitmentEmail();
        if (null !== $saved) {
            return $saved;
        }

        return '' === trim($this->replyToFallback) ? null : trim($this->replyToFallback);
    }

    /**
     * Renvoie le nom d'expéditeur affiché (réglé dans l'admin, sinon MAILER_FROM_NAME).
     *
     * Liens :
     * - Appelée par : MailFactory
     */
    public function fromName(): string
    {
        return $this->settingsRepository->getOrCreate()->getMailFromName() ?? $this->fromName;
    }

    /**
     * Indique si une adresse est fictive (domaine réservé aux exemples ou aux tests, ou adresse locale).
     *
     * Liens :
     * - Appelée par : Admin\CommunicationController (saisie refusée en production), CommunicationCheckCommand,
     *   DashboardMetrics (tuile Communication)
     */
    public static function isFictitious(string $email): bool
    {
        $at = strrpos($email, '@');
        $domain = mb_strtolower(trim(false === $at ? $email : substr($email, $at + 1)));
        if ('' === $domain || !str_contains($domain, '.') && 'localhost' !== $domain) {
            return true;
        }
        foreach (self::FICTITIOUS_DOMAINS as $fictitious) {
            if ($domain === $fictitious || str_ends_with($domain, '.'.$fictitious)) {
                return true;
            }
        }
        foreach (self::FICTITIOUS_SUFFIXES as $suffix) {
            if (str_ends_with($domain, $suffix)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Renvoie les catégories dont l'adresse effective manque ou est fictive (Recrutement et Général du site
     * seulement : la Calculatrice est une fonction à venir).
     *
     * Liens :
     * - Appelée par : CommunicationCheckCommand (déploiement), DashboardMetrics (tuile Communication),
     *   Admin\CommunicationController (vue d'ensemble)
     *
     * @return array<string, string> catégorie => « missing » ou « fictitious » ; vide si tout va bien
     */
    public function findProblems(): array
    {
        $problems = [];
        foreach ([MailCategory::Recruitment, MailCategory::General] as $category) {
            $address = $this->addressFor($category);
            if (null === $address) {
                $problems[$category->value] = 'missing';
            } elseif (self::isFictitious($address)) {
                $problems[$category->value] = 'fictitious';
            }
        }

        return $problems;
    }
}
