<?php

declare(strict_types=1);

namespace App\Service\Mail;

use App\Enum\EmailType;
use Symfony\Bridge\Twig\Mime\TemplatedEmail;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Mime\Address;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * Prépare les e-mails du site : expéditeur noreply@example.test (nom réglable dans Communication ›
 * E-mails, sinon MAILER_FROM_NAME), textes modifiables (objet, introduction, signature : EmailTexts), versions
 * texte et HTML simples, aucune pièce jointe (délivrabilité, décision du 2026-10-23).
 *
 * Règle de confidentialité (décision du 2026-10-29) : l'adresse « Recrutement » n'est donnée en adresse de
 * réponse (Reply-To) QUE sur les e-mails aux recruteurs VALIDÉS (lien de connexion, bienvenue, prolongation,
 * rappel d'échéance). Les e-mails aux demandes non validées (accusé de réception, demande déjà en cours, refus)
 * n'ont aucune adresse de réponse et indiquent d'écrire par le formulaire de contact du site ; aucun webmail
 * n'y apparaît. Le site n'envoie jamais « de la part de » un webmail.
 *
 * Liens :
 * - Utilisé par : RecruiterMailer, NotificationDispatcher, DashboardWeeklySummaryCommand,
 *   Admin\CommunicationController (e-mails de test, aperçu)
 * - Utilise : EmailTexts, MailRecipients (adresse de réponse, nom d'expéditeur), route contact_show
 * - Voir aussi : templates/email/
 * - Testé par : tests/Service/Mail/ReplyToRuleTest
 */
final readonly class MailFactory
{
    /**
     * Reçoit l'adresse de l'expéditeur et les services des textes et des adresses.
     *
     * @param string $fromAddress expéditeur (MAILER_FROM, noreply@…)
     */
    public function __construct(
        #[Autowire('%env(MAILER_FROM)%')]
        private string $fromAddress,
        private MailRecipients $recipients,
        private EmailTexts $texts,
        private UrlGeneratorInterface $urlGenerator,
    ) {
    }

    /**
     * Crée un e-mail d'un type donné (gabarit templates/email/<type>.html.twig et .txt.twig), avec ses textes
     * modifiables et la règle de l'adresse de réponse.
     *
     * Liens :
     * - Appelée par : RecruiterMailer, NotificationDispatcher::send(), DashboardWeeklySummaryCommand,
     *   Admin\CommunicationController::sendTestEmail()
     *
     * Exemple : createTyped(EmailType::LoginLink, 'jean@acme.test', ['recruiter' => $r, 'url' => …], ['nom' => 'Jean Martin'])
     *
     * @param EmailType                                                               $type          type d'e-mail
     * @param string                                                                  $to            destinataire
     * @param array<string, mixed>                                                    $context       variables du gabarit
     * @param array{nom?: string|null, entreprise?: string|null, delai?: string|null} $values        valeurs des variables des textes
     * @param string                                                                  $subjectSuffix ajouté à l'objet (titre de la notification, date du résumé)
     * @param array{subject?: string, intro?: string, signature?: string}|null        $draft         textes à essayer (aperçu), ou null
     */
    public function createTyped(EmailType $type, string $to, array $context = [], array $values = [], string $subjectSuffix = '', ?array $draft = null): TemplatedEmail
    {
        $texts = $this->texts->resolve($type, $values, $draft);
        $subject = trim($texts['subject'].$subjectSuffix);
        $replyTo = $type->isForValidatedRecruiter() ? $this->recipients->replyToForValidatedRecruiters() : null;

        $email = (new TemplatedEmail())
            ->from(new Address($this->fromAddress, $this->recipients->fromName()))
            ->to($to)
            ->subject($subject)
            ->htmlTemplate('email/'.$type->template().'.html.twig')
            ->textTemplate('email/'.$type->template().'.txt.twig')
            ->context($context + [
                'subject' => $subject,
                'texts' => $texts,
                'email_type' => $type->value,
                'can_reply' => null !== $replyTo,
                // Demande non validée : seul moyen d'écrire à Guillaume, le formulaire de contact
                'contact_url' => $type->isForUnvalidatedRequest() ? $this->urlGenerator->generate('contact_show', [], UrlGeneratorInterface::ABSOLUTE_URL) : null,
            ]);
        if (null !== $replyTo) {
            $email->replyTo($replyTo);
        }

        return $email;
    }
}
