<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Entity\Recruiter;
use App\Entity\SecurityEvent;
use App\Entity\SiteSettings;
use App\Enum\EmailType;
use App\Enum\MailCategory;
use App\Enum\NotificationChannel;
use App\Enum\NotificationEvent;
use App\Enum\SecurityEventType;
use App\Form\CommunicationAddressesType;
use App\Form\CommunicationNotificationsType;
use App\Form\EmailTextType;
use App\Form\RemoteValidationType;
use App\Repository\EmailLogRepository;
use App\Repository\EmailTextRevisionRepository;
use App\Repository\FailedEmailRepository;
use App\Repository\SecurityEventRepository;
use App\Repository\SiteSettingsRepository;
use App\Service\ActionConfirmation;
use App\Service\Dashboard\DashboardMetrics;
use App\Service\Mail\DeliverabilityChecker;
use App\Service\Mail\EmailTexts;
use App\Service\Mail\MailFactory;
use App\Service\Mail\MailRecipients;
use App\Service\Mail\ResilientMailer;
use App\Service\Notification\DiscordApp;
use App\Service\Notification\NotificationDispatcher;
use App\Service\Notification\TelegramClient;
use App\Service\Notification\TelegramPollStatus;
use App\Service\Recruiter\AccessRequestSummary;
use App\Service\Recruiter\RemoteDecisionExecutor;
use App\Service\Recruiter\RemoteDecisionTokens;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Clock\ClockInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Form\FormError;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Mailer\Exception\TransportExceptionInterface;
use Symfony\Component\Mime\BodyRendererInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsCsrfTokenValid;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Component\Translation\TranslatorBagInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Page « Communication » de l'admin (décision du 2026-10-29) : tout ce qui concerne la façon dont le site
 * communique avec Guillaume et avec les recruteurs, en six onglets (une adresse par onglet, lisible sans
 * JavaScript) : Vue d'ensemble, E-mails, Notifications, Discord, Telegram, Validation à distance.
 *
 * Secrets (mot de passe SMTP, jeton et secret Telegram, webhook, jeton et clé publique Discord) : UNIQUEMENT dans
 * .env.local ; la page dit « configuré / non configuré », jamais la valeur, et ne permet pas de les modifier.
 *
 * Liens :
 * - Routes : admin_communication (/{ADMIN_PATH}/communication), admin_communication_emails (…/e-mails),
 *   admin_communication_email_text (…/e-mails/contenu/{type}), admin_communication_email_text_reset,
 *   admin_communication_email_test, admin_communication_deliverability, admin_communication_notifications,
 *   admin_communication_test_channel, admin_communication_discord, admin_communication_telegram,
 *   admin_communication_telegram_webhook, admin_communication_remote
 * - Rend : templates/admin/communication/*.html.twig
 * - Utilise : MailRecipients, EmailTexts, MailFactory, ResilientMailer, DeliverabilityChecker, NotificationDispatcher,
 *   DiscordApp, TelegramClient, TelegramPollStatus, RemoteDecisionTokens, dépôts (historique, échecs, journal)
 * - Testé par : tests/Controller/Admin/CommunicationControllerTest, tests/e2e/communication.spec.js
 */
#[IsGranted('ADMIN_KEY_VERIFIED')]
#[Route('/communication', name: 'communication')]
final class CommunicationController extends AbstractController
{
    /** Onglets, dans l'ordre (nom => route). */
    public const TABS = [
        'overview' => 'admin_communication',
        'emails' => 'admin_communication_emails',
        'notifications' => 'admin_communication_notifications',
        'discord' => 'admin_communication_discord',
        'telegram' => 'admin_communication_telegram',
        'remote' => 'admin_communication_remote',
        'social' => 'admin_social_link_index',
    ];

    /**
     * Reçoit les services communs aux onglets.
     *
     * @param string $environment environnement Symfony (adresses fictives refusées en prod)
     */
    public function __construct(
        private readonly MailRecipients $recipients,
        private readonly SiteSettingsRepository $settingsRepository,
        private readonly NotificationDispatcher $notifier,
        private readonly TranslatorInterface $translator,
        #[Autowire('%kernel.environment%')]
        private readonly string $environment,
    ) {
    }

    /**
     * Vue d'ensemble : état de chaque canal d'un coup d'œil (icône et texte), avec un lien vers l'onglet concerné.
     *
     * Liens :
     * - Route : admin_communication (/{ADMIN_PATH}/communication) ; menu « Communication », tuile du tableau de bord
     * - Rend : templates/admin/communication/overview.html.twig
     */
    #[Route('', name: '', methods: ['GET'])]
    public function overview(DeliverabilityChecker $deliverability, DiscordApp $discordApp, TelegramPollStatus $telegramPoll, FailedEmailRepository $failedEmails): Response
    {
        $settings = $this->settingsRepository->getOrCreate();

        return $this->render('admin/communication/overview.html.twig', [
            'tab' => 'overview',
            'addresses' => $this->addresses(),
            'address_problems' => $this->recipients->findProblems(),
            'failed_emails' => $failedEmails->count([]),
            'deliverability' => $deliverability->check(),
            'discord' => ['configured' => $this->notifier->isConfigured(NotificationChannel::Discord), 'app' => $discordApp->isConfigured()],
            'telegram' => ['configured' => $this->notifier->isConfigured(NotificationChannel::Telegram), 'poll' => $telegramPoll->isRelevant() ? ['active' => $telegramPoll->isActive(), 'last' => $telegramPoll->lastRunAt()] : null],
            'remote_enabled' => $settings->isRemoteValidationEnabled(),
        ]);
    }

    /**
     * Onglet E-mails : adresses de réception (trois catégories), nom d'expéditeur, expéditeur et serveur (lecture
     * seule), adresses gérées dans le panneau de l’hébergeur, santé de l'envoi, délivrabilité, contenu des e-mails, historique.
     *
     * Liens :
     * - Route : admin_communication_emails (/{ADMIN_PATH}/communication/e-mails, GET et POST)
     * - Rend : templates/admin/communication/emails.html.twig
     *
     * @param string $fromAddress expéditeur (MAILER_FROM, lecture seule)
     * @param string $mailerDsn   MAILER_DSN : seuls l'hôte, le port et le schéma sont lus, jamais le mot de passe
     */
    #[Route('/e-mails', name: '_emails', methods: ['GET', 'POST'])]
    public function emails(Request $request, EntityManagerInterface $entityManager, DeliverabilityChecker $deliverability, EmailLogRepository $logs, FailedEmailRepository $failedEmails, EmailTexts $texts, #[Autowire('%env(MAILER_FROM)%')] string $fromAddress, #[Autowire('%env(MAILER_DSN)%')] string $mailerDsn): Response
    {
        $settings = $this->settingsRepository->getOrCreate();
        $form = $this->createForm(CommunicationAddressesType::class, $settings, ['reject_fictitious' => 'prod' === $this->environment]);
        $form->handleRequest($request);
        if ($form->isSubmitted() && $form->isValid()) {
            $entityManager->persist($settings);
            $entityManager->flush();
            $this->addFlash('success', 'admin.communication.flash.addresses_saved');

            return $this->redirectToRoute('admin_communication_emails', [], Response::HTTP_SEE_OTHER);
        }
        if ($form->isSubmitted() && $entityManager->contains($settings)) {
            // Formulaire invalide : rien n'est gardé de la saisie (l'entité partagée reste celle de la base)
            $entityManager->refresh($settings);
        }

        $dsn = parse_url($mailerDsn) ?: [];

        return $this->render('admin/communication/emails.html.twig', [
            'tab' => 'emails',
            'form' => $form,
            'addresses' => $this->addresses(),
            'sender' => [
                'address' => $fromAddress,
                'name' => $this->recipients->fromName(),
                'host' => $dsn['host'] ?? null,
                'port' => $dsn['port'] ?? null,
                'configured' => !\in_array($dsn['scheme'] ?? 'null', ['null', ''], true),
                'domain' => $deliverability->domain(),
            ],
            'last_success' => $logs->findLastSuccessAt(),
            'failed_emails' => $failedEmails->findAllOldestFirst(),
            'deliverability' => $deliverability->check(),
            'dkim_source' => SiteSettings::DEFAULT_DKIM_SOURCE,
            'email_types' => array_map(static fn (EmailType $type): array => ['type' => $type, 'modified' => $texts->raw($type)['modified']], EmailType::cases()),
            'logs' => $logs->findLatest(),
            'categories' => MailCategory::cases(),
        ], new Response(null, $form->isSubmitted() ? Response::HTTP_UNPROCESSABLE_ENTITY : Response::HTTP_OK));
    }

    /**
     * Contenu d'un e-mail : objet, introduction et signature modifiables, aperçu HTML et texte AVANT
     * d'enregistrer (bouton « Aperçu »), historique des modifications.
     *
     * Liens :
     * - Route : admin_communication_email_text (/{ADMIN_PATH}/communication/e-mails/contenu/{type}, GET et POST)
     * - Rend : templates/admin/communication/email_text.html.twig
     * - Utilise : EmailTexts (validate(), save(), resolve()), MailFactory::createTyped() (aperçu), BodyRendererInterface
     */
    #[Route('/e-mails/contenu/{type}', name: '_email_text', methods: ['GET', 'POST'])]
    public function emailText(EmailType $type, Request $request, EmailTexts $texts, EmailTextRevisionRepository $revisions, MailFactory $mailFactory, BodyRendererInterface $bodyRenderer, AccessRequestSummary $summary, DashboardMetrics $metrics, ClockInterface $clock): Response
    {
        $raw = $texts->raw($type);
        $form = $this->createForm(EmailTextType::class, ['subject' => $raw['subject'], 'intro' => $raw['intro'], 'signature' => $raw['signature']]);
        $form->handleRequest($request);
        $preview = null;
        $status = Response::HTTP_OK;
        if ($form->isSubmitted() && $form->isValid()) {
            /** @var array{subject: string, intro: string, signature: string} $data */
            $data = $form->getData();
            $errors = $texts->validate($data['subject'], $data['intro'], $data['signature']);
            foreach ($errors as $field => $key) {
                $form->get($field)->addError(new FormError($this->translator->trans($key, ['%variables%' => implode(', ', EmailTexts::VARIABLES)])));
            }
            if ([] === $errors && 'save' === $request->request->getString('action')) {
                $texts->save($type, $data['subject'], $data['intro'], $data['signature']);
                $this->addFlash('success', 'admin.communication.flash.text_saved');

                return $this->redirectToRoute('admin_communication_email_text', ['type' => $type->value], Response::HTTP_SEE_OTHER);
            }
            if ([] === $errors) {
                $preview = $this->renderPreview($type, $data, $mailFactory, $bodyRenderer, $summary, $metrics, $clock);
            } else {
                $status = Response::HTTP_UNPROCESSABLE_ENTITY;
            }
        } elseif ($form->isSubmitted()) {
            $status = Response::HTTP_UNPROCESSABLE_ENTITY;
        } else {
            // Première ouverture : aperçu des textes actuels
            $preview = $this->renderPreview($type, null, $mailFactory, $bodyRenderer, $summary, $metrics, $clock);
        }

        return $this->render('admin/communication/email_text.html.twig', [
            'tab' => 'emails',
            'type' => $type,
            'form' => $form,
            'modified' => $raw['modified'],
            'preview' => $preview,
            'revisions' => $revisions->findLatest($type),
            'variables' => EmailTexts::VARIABLES,
        ], new Response(null, $status));
    }

    /**
     * Rétablit les textes d'origine d'un e-mail (confirmation ; sans JavaScript, page de confirmation).
     *
     * Liens :
     * - Route : admin_communication_email_text_reset (/{ADMIN_PATH}/communication/e-mails/contenu/{type}/retablir, POST)
     * - Utilise : EmailTexts::reset(), ActionConfirmation
     */
    #[Route('/e-mails/contenu/{type}/retablir', name: '_email_text_reset', methods: ['POST'])]
    #[IsCsrfTokenValid('email_text_reset')]
    public function resetEmailText(EmailType $type, Request $request, EmailTexts $texts, ActionConfirmation $confirmation): Response
    {
        if (!$confirmation->isConfirmed($request)) {
            return $confirmation->render(
                $request,
                $this->translator->trans('admin.communication.confirm_reset_title'),
                $this->translator->trans('admin.communication.confirm_reset_text'),
                $this->translator->trans('admin.communication.reset'),
                $this->generateUrl('admin_communication_email_text', ['type' => $type->value]),
            );
        }
        $texts->reset($type);
        $this->addFlash('success', 'admin.communication.flash.text_reset');

        return $this->redirectToRoute('admin_communication_email_text', ['type' => $type->value], Response::HTTP_SEE_OTHER);
    }

    /**
     * Envoie un e-mail de test à l'adresse d'une catégorie : toast de réussite, erreur claire dans la page.
     *
     * Liens :
     * - Route : admin_communication_email_test (/{ADMIN_PATH}/communication/e-mails/test/{category}, POST)
     * - Rend : templates/email/test.html.twig et .txt.twig (via MailFactory::createTyped())
     * - Utilise : ResilientMailer::sendOrFail() (erreur du serveur SMTP montrée telle quelle, sans mot de passe)
     *
     * @param string $mailerDsnScheme schéma du transport (smtp, null…), affiché pour le diagnostic
     */
    #[Route('/e-mails/test/{category}', name: '_email_test', methods: ['POST'])]
    #[IsCsrfTokenValid('email_test')]
    public function sendTestEmail(MailCategory $category, MailFactory $mailFactory, ResilientMailer $mailer, ClockInterface $clock, #[Autowire('%env(key:scheme:url:MAILER_DSN)%')] string $mailerDsnScheme): Response
    {
        $label = $this->translator->trans($category->translationKey());
        $address = $this->recipients->addressFor($category);
        if (null === $address) {
            $this->addFlash('danger', $this->translator->trans('admin.communication.flash.test_no_address', ['%category%' => $label]));

            return $this->redirectToRoute('admin_communication_emails', [], Response::HTTP_SEE_OTHER);
        }
        try {
            $mailer->sendOrFail($mailFactory->createTyped(EmailType::Test, $address, [
                'sent_at' => $clock->now(), 'transport' => $mailerDsnScheme, 'category_label' => $label,
            ], subjectSuffix: ' — '.$label), ResilientMailer::KIND_TEST, $category);
            $this->addFlash('success', $this->translator->trans('admin.communication.flash.test_sent', ['%category%' => $label, '%address%' => $address]));
        } catch (TransportExceptionInterface $exception) {
            $this->addFlash('danger', $this->translator->trans('admin.communication.flash.test_failed', ['%category%' => $label, '%error%' => $mailer->describe($exception)]));
        }

        return $this->redirectToRoute('admin_communication_emails', [], Response::HTTP_SEE_OTHER);
    }

    /**
     * Vérifie la délivrabilité tout de suite (cache de 1 h vidé).
     *
     * Liens :
     * - Route : admin_communication_deliverability (/{ADMIN_PATH}/communication/e-mails/delivrabilite, POST)
     * - Utilise : DeliverabilityChecker::check(true)
     */
    #[Route('/e-mails/delivrabilite', name: '_deliverability', methods: ['POST'])]
    #[IsCsrfTokenValid('deliverability')]
    public function checkDeliverability(DeliverabilityChecker $deliverability): Response
    {
        $result = $deliverability->check(true);
        $this->addFlash($result['ok'] ? 'success' : 'warning', $result['applicable'] ? ($result['ok'] ? 'admin.communication.flash.deliverability_ok' : 'admin.communication.flash.deliverability_problem') : 'admin.communication.flash.deliverability_na');

        return $this->redirect($this->generateUrl('admin_communication_emails').'#delivrabilite', Response::HTTP_SEE_OTHER);
    }

    /**
     * Onglet Notifications : tableau « événement → canaux », avec la catégorie (adresse) de chaque événement.
     *
     * Liens :
     * - Route : admin_communication_notifications (/{ADMIN_PATH}/communication/notifications, GET et POST) ;
     *   l'ancienne adresse /{ADMIN_PATH}/parametres/notifications y redirige (SiteSettingsController)
     * - Rend : templates/admin/communication/notifications.html.twig
     */
    #[Route('/notifications', name: '_notifications', methods: ['GET', 'POST'])]
    public function notifications(Request $request, EntityManagerInterface $entityManager): Response
    {
        $settings = $this->settingsRepository->getOrCreate();
        $form = $this->createForm(CommunicationNotificationsType::class, $settings);
        $form->handleRequest($request);
        if ($form->isSubmitted() && $form->isValid()) {
            $entityManager->persist($settings);
            $entityManager->flush();
            $this->addFlash('success', 'admin.communication.flash.notifications_saved');

            return $this->redirectToRoute('admin_communication_notifications', [], Response::HTTP_SEE_OTHER);
        }

        return $this->render('admin/communication/notifications.html.twig', [
            'tab' => 'notifications',
            'form' => $form,
            'events' => NotificationEvent::cases(),
            'addresses' => $this->addresses(),
            'configured' => $this->channelStates(),
        ], new Response(null, $form->isSubmitted() ? Response::HTTP_UNPROCESSABLE_ENTITY : Response::HTTP_OK));
    }

    /**
     * Envoie une notification de test sur un canal ; le résultat s'affiche en toast (erreur claire si le canal
     * n'est pas configuré ou si le service refuse l'envoi). Toujours envoyée : seule exception à la règle
     * « aucun envoi réel vers Discord ou Telegram sans demande de Guillaume » (c'est sa demande).
     *
     * Liens :
     * - Route : admin_communication_test_channel (/{ADMIN_PATH}/communication/notifications/{channel}/test, POST)
     * - Utilise : NotificationDispatcher::sendTest()
     */
    #[Route('/notifications/{channel}/test', name: '_test_channel', methods: ['POST'])]
    public function testChannel(NotificationChannel $channel, Request $request): Response
    {
        if (!$this->isCsrfTokenValid('notification_test_'.$channel->value, $request->request->getString('_token'))) {
            throw $this->createAccessDeniedException();
        }
        $label = $this->translator->trans($channel->translationKey());
        $error = $this->notifier->sendTest($channel);
        if (null === $error) {
            $this->addFlash('success', $this->translator->trans('admin.site_settings.test_sent', ['%channel%' => $label]));
        } else {
            $this->addFlash('danger', $this->translator->trans('admin.site_settings.test_failed', ['%channel%' => $label, '%error%' => $error]));
        }
        $back = match ($request->request->getString('back')) {
            'discord' => 'admin_communication_discord',
            'telegram' => 'admin_communication_telegram',
            default => 'admin_communication_notifications',
        };

        return $this->redirectToRoute($back, [], Response::HTTP_SEE_OTHER);
    }

    /**
     * Onglet Discord : webhook et application (bot, clé publique, salon, compte admin : configuré ou non), mode
     * utilisé, notification de test, rappel de la validation en deux étapes.
     *
     * Liens :
     * - Route : admin_communication_discord (/{ADMIN_PATH}/communication/discord)
     * - Rend : templates/admin/communication/discord.html.twig
     *
     * @param string $discordWebhook DISCORD_WEBHOOK_URL (seulement « renseignée ou non »)
     */
    #[Route('/discord', name: '_discord', methods: ['GET'])]
    public function discord(DiscordApp $discordApp, #[Autowire('%env(DISCORD_WEBHOOK_URL)%')] string $discordWebhook): Response
    {
        $webhook = '' !== trim($discordWebhook);

        return $this->render('admin/communication/discord.html.twig', [
            'tab' => 'discord',
            'webhook' => $webhook,
            'discord_app' => $discordApp->getConfigurationStatus(),
            'app_configured' => $discordApp->isConfigured(),
            'mode' => match (true) {
                $discordApp->isConfigured() => 'app',
                $webhook => 'webhook',
                default => 'none',
            },
        ]);
    }

    /**
     * Onglet Telegram : jeton, conversation, secret du webhook (configuré ou non), webhook déclaré (vérifié à la
     * demande), relève en développement, notification de test, rappel de la validation en deux étapes.
     *
     * Liens :
     * - Route : admin_communication_telegram (/{ADMIN_PATH}/communication/telegram)
     * - Rend : templates/admin/communication/telegram.html.twig
     *
     * @param string $chatId        TELEGRAM_CHAT_ID (seulement « renseigné ou non »)
     * @param string $webhookSecret TELEGRAM_WEBHOOK_SECRET (seulement « renseigné ou non »)
     */
    #[Route('/telegram', name: '_telegram', methods: ['GET'])]
    public function telegram(TelegramClient $telegram, TelegramPollStatus $telegramPoll, #[Autowire('%env(TELEGRAM_CHAT_ID)%')] string $chatId, #[Autowire('%env(TELEGRAM_WEBHOOK_SECRET)%')] string $webhookSecret): Response
    {
        return $this->render('admin/communication/telegram.html.twig', [
            'tab' => 'telegram',
            'token' => $telegram->hasToken(),
            'chat_id' => '' !== trim($chatId),
            'secret' => '' !== trim($webhookSecret),
            'configured' => $this->notifier->isConfigured(NotificationChannel::Telegram),
            'poll' => $telegramPoll->isRelevant() ? ['active' => $telegramPoll->isActive(), 'last' => $telegramPoll->lastRunAt()] : null,
        ]);
    }

    /**
     * Demande à Telegram si un webhook est déclaré (lecture seule : getWebhookInfo, aucun message envoyé) ; le
     * résultat s'affiche en message, sans l'adresse complète ni le jeton.
     *
     * Liens :
     * - Route : admin_communication_telegram_webhook (/{ADMIN_PATH}/communication/telegram/webhook, POST)
     * - Utilise : TelegramClient::call('getWebhookInfo')
     */
    #[Route('/telegram/webhook', name: '_telegram_webhook', methods: ['POST'])]
    #[IsCsrfTokenValid('telegram_webhook_check')]
    public function checkTelegramWebhook(TelegramClient $telegram): Response
    {
        if (!$telegram->hasToken()) {
            $this->addFlash('warning', 'admin.communication.flash.telegram_no_token');

            return $this->redirectToRoute('admin_communication_telegram', [], Response::HTTP_SEE_OTHER);
        }
        try {
            $info = $telegram->call('getWebhookInfo', []);
            $url = \is_array($info) ? (string) ($info['url'] ?? '') : '';
            $host = '' === $url ? null : parse_url($url, \PHP_URL_HOST);
            $this->addFlash('' === $url ? 'info' : 'success', $this->translator->trans('' === $url ? 'admin.communication.flash.telegram_no_webhook' : 'admin.communication.flash.telegram_webhook', ['%host%' => (string) $host]));
        } catch (\RuntimeException $exception) {
            $this->addFlash('danger', $this->translator->trans('admin.communication.flash.telegram_error', ['%error%' => $exception->getMessage()]));
        }

        return $this->redirectToRoute('admin_communication_telegram', [], Response::HTTP_SEE_OTHER);
    }

    /**
     * Onglet Validation à distance : interrupteur, code PIN (saisi deux fois et confirmé, jamais affiché), rappel
     * de sécurité, journal des dernières actions à distance (date, action, canal, résultat ; jamais de jeton ni de PIN).
     *
     * Liens :
     * - Route : admin_communication_remote (/{ADMIN_PATH}/communication/validation-a-distance, GET et POST)
     * - Rend : templates/admin/communication/remote.html.twig
     * - Utilise : RemoteDecisionExecutor::hashPin(), RemoteDecisionTokens (état), SecurityEventRepository (journal)
     */
    #[Route('/validation-a-distance', name: '_remote', methods: ['GET', 'POST'])]
    public function remote(Request $request, EntityManagerInterface $entityManager, RemoteDecisionTokens $remoteDecisions, SecurityEventRepository $securityEvents): Response
    {
        $settings = $this->settingsRepository->getOrCreate();
        $form = $this->createForm(RemoteValidationType::class, $settings);
        $form->handleRequest($request);
        if ($form->isSubmitted() && $form->isValid()) {
            // Code PIN : seule son empreinte est gardée ; champ vide : code inchangé
            $pin = (string) $form->get('remotePin')->getData();
            if ('' !== $pin) {
                $settings->setRemotePinHash(RemoteDecisionExecutor::hashPin($pin));
            }
            $entityManager->persist($settings);
            $entityManager->flush();
            $this->addFlash('success', '' !== $pin ? 'admin.communication.flash.pin_changed' : 'admin.communication.flash.remote_saved');

            return $this->redirectToRoute('admin_communication_remote', [], Response::HTTP_SEE_OTHER);
        }
        if ($form->isSubmitted() && $entityManager->contains($settings)) {
            $entityManager->refresh($settings);
        }

        return $this->render('admin/communication/remote.html.twig', [
            'tab' => 'remote',
            'form' => $form,
            'readiness' => [
                'telegram' => $remoteDecisions->isTelegramReady(),
                'discord' => $remoteDecisions->isDiscordReady(),
                'pin' => $remoteDecisions->hasPin(),
            ],
            'journal' => array_map($this->describeRemoteEvent(...), $securityEvents->findLatestByTypes([SecurityEventType::RemoteDecision, SecurityEventType::RemoteDecisionRejected])),
        ], new Response(null, $form->isSubmitted() ? Response::HTTP_UNPROCESSABLE_ENTITY : Response::HTTP_OK));
    }

    /**
     * Ligne du journal de la validation à distance : la ligne du journal de sécurité porte « canal action sujet »
     * (RemoteDecisionExecutor), jamais de jeton ni de code. Action ou motif traduit s'il est connu, sinon le code.
     *
     * @return array{date: \DateTimeImmutable, channel: string, action: string, success: bool}
     */
    private function describeRemoteEvent(SecurityEvent $event): array
    {
        $parts = explode(' ', (string) $event->getUsername());
        $key = 'admin.communication.remote.action_'.($parts[1] ?? '');
        $known = $this->translator instanceof TranslatorBagInterface && $this->translator->getCatalogue()->has($key);

        return [
            'date' => $event->getOccurredAt(),
            'channel' => $parts[0],
            'action' => $known ? $this->translator->trans($key) : ($parts[1] ?? '—'),
            'success' => SecurityEventType::RemoteDecision === $event->getType(),
        ];
    }

    /**
     * Adresses de chaque catégorie : effective, origine (enregistrée, compte admin, repli), fictive ou non.
     *
     * @return array<string, array{address: string|null, source: string, fictitious: bool}>
     */
    private function addresses(): array
    {
        $addresses = [];
        foreach (MailCategory::cases() as $category) {
            $address = $this->recipients->addressFor($category);
            $addresses[$category->value] = ['address' => $address, 'source' => $this->recipients->sourceOf($category), 'fictitious' => null !== $address && MailRecipients::isFictitious($address)];
        }

        return $addresses;
    }

    /**
     * État de chaque canal (configuré ou non).
     *
     * @return array<string, bool> canal => configuré ?
     */
    private function channelStates(): array
    {
        $states = [];
        foreach (NotificationChannel::cases() as $channel) {
            $states[$channel->value] = $this->notifier->isConfigured($channel);
        }

        return $states;
    }

    /**
     * Rend l'aperçu HTML et texte d'un e-mail avec des données d'exemple (aucun envoi, aucune écriture en base).
     *
     * @param array{subject: string, intro: string, signature: string}|null $draft textes à essayer, ou null (textes actuels)
     *
     * @return array{subject: string, html: string, text: string}
     */
    private function renderPreview(EmailType $type, ?array $draft, MailFactory $mailFactory, BodyRendererInterface $bodyRenderer, AccessRequestSummary $summary, DashboardMetrics $metrics, ClockInterface $clock): array
    {
        $now = $clock->now();
        $recruiter = (new Recruiter($now))->setEmail('camille.exemple@entreprise-exemple.test')->setFullName('Camille Exemple')->setCompany('Entreprise Exemple')
            ->setAccessExpiresAt($now->modify(\sprintf('+%d days', Recruiter::ACCESS_DAYS)));
        $exampleUrl = 'https://guillaume-couget.fr/espace-recruteur/lien-exemple';
        $context = match ($type) {
            EmailType::LoginLink, EmailType::Approval, EmailType::Renewal => ['recruiter' => $recruiter, 'url' => $exampleUrl, 'minutes' => 30, 'renewed' => EmailType::Renewal === $type],
            EmailType::ExpiryReminder => ['recruiter' => $recruiter, 'extension_url' => $exampleUrl, 'stop_reminders_url' => $exampleUrl],
            EmailType::Notification => ['notification' => $summary->trialNotification()],
            EmailType::DashboardSummary => ['tiles' => $metrics->getTiles()['tiles'], 'computed_at' => $now],
            EmailType::Test => ['sent_at' => $now, 'transport' => 'smtp', 'category_label' => $this->translator->trans(MailCategory::General->translationKey())],
            default => ['recruiter' => $recruiter],
        };
        $suffix = match ($type) {
            EmailType::Notification => ' '.$summary->trialNotification()->title,
            EmailType::DashboardSummary => ' — '.$now->setTimezone(new \DateTimeZone('Europe/Paris'))->format('d/m/Y'),
            default => '',
        };
        $email = $mailFactory->createTyped($type, 'apercu@example.test', $context, [
            'nom' => $recruiter->getFullName(), 'entreprise' => $recruiter->getCompany(), 'delai' => $this->settingsRepository->getOrCreate()->getResponseDelay(),
        ], $suffix, $draft);
        $bodyRenderer->render($email);

        return ['subject' => (string) $email->getSubject(), 'html' => (string) $email->getHtmlBody(), 'text' => (string) $email->getTextBody()];
    }
}
