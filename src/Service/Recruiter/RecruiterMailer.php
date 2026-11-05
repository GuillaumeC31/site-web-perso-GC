<?php

declare(strict_types=1);

namespace App\Service\Recruiter;

use App\Entity\Recruiter;
use App\Enum\EmailType;
use App\Repository\SiteSettingsRepository;
use App\Service\Mail\MailFactory;
use App\Service\Mail\ResilientMailer;
use App\Service\Mail\UnvalidatedEmailCap;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Security\Http\LoginLink\LoginLinkHandlerInterface;

/**
 * E-mails envoyés aux recruteurs : accusé de réception de la demande, demande déjà en cours, refus, lien de
 * connexion (30 minutes, usage unique), accès validé ou renouvelé (avec un lien de connexion), rappel 7 jours
 * avant l'échéance (avec un lien de prolongation). E-mails sobres : texte et HTML simples, lien bien visible,
 * aucune pièce jointe. Objet, introduction et signature se règlent dans Communication › E-mails (variables
 * {nom}, {entreprise}, {delai}).
 *
 * Adresse de réponse : seulement pour les recruteurs validés (MailFactory::createTyped()) ; l'accusé, la demande
 * en cours et le refus renvoient vers le formulaire de contact du site.
 *
 * Liens :
 * - Utilisé par : RecruiterAccessManager, RecruiterDailyCommand, FailedEmailResender
 * - Utilise : MailFactory, ResilientMailer (un échec SMTP ne fait jamais échouer la page : e-mail gardé
 *   pour un renvoi), gestionnaire de liens de connexion du pare-feu « recruiter », SiteSettingsRepository (délai),
 *   templates/email/recruiter/*.twig
 * - Voir aussi : FailedEmailResender (renvoi : les e-mails à lien sont recréés avec un lien neuf),
 *   Mail\UnvalidatedEmailCap (plafond quotidien des e-mails aux personnes non validées)
 */
final readonly class RecruiterMailer
{
    /** Durée de validité d'un lien de connexion (minutes), affichée dans l'e-mail (security.yaml : 1 800 s). */
    public const LINK_MINUTES = 30;

    /**
     * Reçoit les dépendances.
     *
     * @param ResilientMailer           $mailer             envoi (échec gardé pour un renvoi)
     * @param MailFactory               $mailFactory        e-mails du site
     * @param LoginLinkHandlerInterface $loginLinkHandler   liens de connexion du pare-feu « recruiter »
     * @param SiteSettingsRepository    $settingsRepository délai de réponse affiché (variable {delai})
     * @param UnvalidatedEmailCap       $unvalidatedCap     plafond quotidien des e-mails aux personnes non validées
     */
    public function __construct(
        private ResilientMailer $mailer,
        private MailFactory $mailFactory,
        #[Autowire(service: 'security.authenticator.login_link_handler.recruiter')]
        private LoginLinkHandlerInterface $loginLinkHandler,
        private SiteSettingsRepository $settingsRepository,
        private UnvalidatedEmailCap $unvalidatedCap,
    ) {
    }

    /**
     * Accuse réception d'une demande d'accès (réponse annoncée dans le délai réglé), sans lien de connexion ni
     * adresse de réponse.
     *
     * Liens :
     * - Appelée par : RecruiterAccessManager::requestAccess() (demande nouvelle ou reprise, non validée automatiquement)
     */
    public function sendRequestReceived(Recruiter $recruiter): bool
    {
        return $this->send(EmailType::RequestReceived, $recruiter, ['recruiter' => $recruiter]);
    }

    /**
     * Prévient le recruteur que sa demande est déjà en cours d'examen (il vient d'en envoyer une nouvelle),
     * sans lien de connexion ni adresse de réponse. L'admin n'est pas notifié une seconde fois.
     *
     * Liens :
     * - Appelée par : RecruiterAccessManager::requestAccess() (au plus une fois par 24 h et par adresse)
     *
     * @return bool vrai si l'e-mail est parti ; faux s'il a été gardé pour un renvoi
     */
    public function sendRequestPending(Recruiter $recruiter): bool
    {
        return $this->send(EmailType::RequestPending, $recruiter, ['recruiter' => $recruiter]);
    }

    /**
     * Prévient le demandeur que sa demande n'est pas retenue : sans motif, sans adresse de réponse (formulaire de
     * contact du site seulement). Décision du 2026-10-29 : avant, le refus était silencieux.
     *
     * Liens :
     * - Appelée par : RecruiterAccessManager::refuse() (admin et validation à distance)
     *
     * @return bool vrai si l'e-mail est parti ; faux s'il a été gardé pour un renvoi
     */
    public function sendRefusal(Recruiter $recruiter): bool
    {
        return $this->send(EmailType::Refusal, $recruiter, ['recruiter' => $recruiter]);
    }

    /**
     * Envoie un lien de connexion.
     *
     * Liens :
     * - Appelée par : RecruiterAccessManager::sendLoginLink(), FailedEmailResender (lien neuf)
     *
     * @return bool vrai si l'e-mail est parti ; faux s'il a été gardé pour un renvoi
     */
    public function sendLoginLink(Recruiter $recruiter): bool
    {
        return $this->send(EmailType::LoginLink, $recruiter, [
            'recruiter' => $recruiter, 'url' => $this->loginLinkHandler->createLoginLink($recruiter)->getUrl(), 'minutes' => self::LINK_MINUTES,
        ]);
    }

    /**
     * Annonce un accès validé (ou renouvelé), avec un lien de connexion.
     *
     * Liens :
     * - Appelée par : RecruiterAccessManager::approve(), RecruiterAccessManager::renew(), FailedEmailResender (lien neuf)
     *
     * @param bool $renewed vrai : renouvellement d'un accès existant
     *
     * @return bool vrai si l'e-mail est parti ; faux s'il a été gardé pour un renvoi
     */
    public function sendApproval(Recruiter $recruiter, bool $renewed): bool
    {
        return $this->send($renewed ? EmailType::Renewal : EmailType::Approval, $recruiter, [
            'recruiter' => $recruiter, 'renewed' => $renewed, 'url' => $this->loginLinkHandler->createLoginLink($recruiter)->getUrl(), 'minutes' => self::LINK_MINUTES,
        ]);
    }

    /**
     * Rappelle l'échéance de l'accès, avec un lien pour demander une prolongation et un lien « Ne plus recevoir ces
     * rappels » (aussi dans les en-têtes List-Unsubscribe, désinscription en un clic des messageries, RFC 8058).
     *
     * Liens :
     * - Appelée par : RecruiterDailyCommand
     * - Voir aussi : RecruiterAreaController::stopReminders() (route recruiter_reminders_stop)
     *
     * @param string $extensionUrl     lien signé de demande de prolongation
     * @param string $stopRemindersUrl lien signé de refus des rappels
     *
     * @return bool vrai si l'e-mail est parti ; faux s'il a été gardé pour un renvoi
     */
    public function sendExpiryReminder(Recruiter $recruiter, string $extensionUrl, string $stopRemindersUrl): bool
    {
        return $this->send(EmailType::ExpiryReminder, $recruiter, ['recruiter' => $recruiter, 'extension_url' => $extensionUrl, 'stop_reminders_url' => $stopRemindersUrl], [
            'List-Unsubscribe' => '<'.$stopRemindersUrl.'>',
            'List-Unsubscribe-Post' => 'List-Unsubscribe=One-Click',
        ]);
    }

    /**
     * Prépare et envoie un e-mail au recruteur (variables {nom}, {entreprise}, {delai}).
     *
     * @param array<string, mixed>  $context variables du gabarit
     * @param array<string, string> $headers en-têtes ajoutés (nom => valeur)
     *
     * @return bool vrai si l'e-mail est parti ; faux s'il a été gardé pour un renvoi ou suspendu (plafond quotidien des
     *              e-mails aux personnes non validées atteint : la demande reste enregistrée)
     */
    private function send(EmailType $type, Recruiter $recruiter, array $context, array $headers = []): bool
    {
        if (!$this->unvalidatedCap->allows($type)) {
            return false;
        }
        $email = $this->mailFactory->createTyped($type, $recruiter->getEmail(), $context, [
            'nom' => $recruiter->getFullName(),
            'entreprise' => $recruiter->getCompany(),
            'delai' => $this->settingsRepository->getOrCreate()->getResponseDelay(),
        ]);
        foreach ($headers as $name => $value) {
            $email->getHeaders()->addTextHeader($name, $value);
        }

        return $this->mailer->send($email, $type->kind(), $recruiter->getId());
    }
}
