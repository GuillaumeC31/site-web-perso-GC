<?php

declare(strict_types=1);

namespace App\Service\Dashboard;

use App\Entity\Heartbeat;
use App\Enum\NotificationChannel;
use App\Enum\RecruiterStatus;
use App\Enum\SecurityEventType;
use App\Repository\ChatMessageRepository;
use App\Repository\ContactMessageRepository;
use App\Repository\FailedEmailRepository;
use App\Repository\HeartbeatRepository;
use App\Repository\RecruiterRepository;
use App\Repository\SecurityEventRepository;
use App\Repository\SiteSettingsRepository;
use App\Repository\UnansweredQuestionRepository;
use App\Repository\WebauthnCredentialRepository;
use App\Service\Ai\AiKnowledgeBudget;
use App\Service\Ai\AiStatus;
use App\Service\AppVersion;
use App\Service\Calculator\CalculatorRegistry;
use App\Service\Content\ContentCompletenessChecker;
use App\Service\Mail\DeliverabilityChecker;
use App\Service\Mail\MailRecipients;
use App\Service\Mail\UnvalidatedEmailCap;
use App\Service\Notification\NotificationDispatcher;
use App\Service\Seo\SeoContext;
use Psr\Clock\ClockInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Contracts\Cache\CacheInterface;
use Symfony\Contracts\Cache\ItemInterface;

/**
 * Indicateurs du tableau de bord de l'admin, en tuiles : valeur, statut (OK, à surveiller, problème) et lien.
 * Seule source des chiffres : la page du tableau de bord, la pastille du menu, le récapitulatif à
 * l'ouverture et le résumé hebdomadaire par e-mail lisent tous ce service.
 *
 * Les tuiles sont gardées 60 s en cache (bouton « Actualiser » : relecture immédiate) ; tout envoi de
 * formulaire (POST) vide ce cache (DashboardCacheSubscriber), pour que l'admin voie aussitôt l'effet de
 * ses actions. Les textes sont des clés de traduction (admin.dashboard.*), les dates sont déjà formatées
 * (fuseau de Paris). Aucune donnée personnelle d'un recruteur n'y figure : seulement des nombres.
 *
 * Liens :
 * - Utilisé par : Admin\DashboardController (page), Twig\DashboardExtension (pastille du menu),
 *   Command\DashboardWeeklySummaryCommand (e-mail du lundi), EventSubscriber\DashboardCacheSubscriber
 * - Utilise : dépôts (recruteurs, messages, e-mails, journal de sécurité, passages des tâches), AiStatus,
 *   ContentCompletenessChecker, CalculatorRegistry, BusinessHours, AppVersion, SeoContext
 * - Testé par : tests/Service/Dashboard/DashboardMetricsTest
 *
 * @phpstan-type Line array{key: string, params: array<string, string|int>, status?: string}
 * @phpstan-type Item array{message: string, parameters: array<string, string|int>, route: string, route_parameters: array<string, int>, blocking: bool}
 * @phpstan-type Tile array{id: string, status: string, value: string, value_key: string, lines: list<Line>, link: array{route: string, params: array<string, string|int>, label: string}, items: list<Item>}
 */
final class DashboardMetrics
{
    /** Statuts d'une tuile, du meilleur au pire. */
    public const OK = 'ok';
    public const WARNING = 'warning';
    public const PROBLEM = 'problem';

    /** État neutre : indicateur sans objet ici (ex. tâches planifiées en local), jamais en alerte. */
    public const NOT_APPLICABLE = 'not_applicable';

    /** Durée du cache des tuiles (secondes). */
    public const CACHE_TTL = 60;

    /** Délai de réponse promis aux recruteurs (heures ouvrées). */
    public const PENDING_MAX_BUSINESS_HOURS = 24;

    /** Âge maximal de la dernière sauvegarde (heures). */
    public const BACKUP_MAX_HOURS = 48;

    /** Âge maximal du dernier passage d'une tâche quotidienne (heures, avec une marge). */
    public const DAILY_TASK_MAX_HOURS = 26;

    /** Échecs de connexion à l'admin sur 7 jours au-delà desquels la tuile Sécurité passe en « problème ». */
    public const LOGIN_FAILURES_PROBLEM = 10;

    /** Nombre de points à compléter montrés sur la tuile (les autres sous « Tout voir »). */
    public const CONTENT_ITEMS_SHOWN = 5;

    /** Ordre des tuiles. */
    public const TILES = ['recruiters', 'messages', 'content', 'ai', 'ai_knowledge', 'communication', 'security', 'turnstile', 'tasks', 'site', 'calculator'];

    /**
     * Erreurs de configuration de Turnstile sur 24 h qui passent la tuile « Turnstile » en problème (et déclenchent
     * l'alerte « Général du site ») : une seule peut venir d'un essai isolé, trois répétées bloquent les formulaires.
     */
    public const TURNSTILE_CONFIG_ERRORS_PROBLEM = 3;

    /** Clé des tuiles dans le pool cache.dashboard (aussi vidée par DashboardCacheSubscriber). */
    public const CACHE_KEY = 'admin_dashboard_tiles';

    /**
     * Reçoit les sources des indicateurs.
     *
     * @param CacheInterface $dashboardCache pool cache.dashboard (60 s)
     * @param string         $environment    environnement Symfony (dev, test, prod)
     * @param string         $cronMonitoring DASHBOARD_CRON_MONITORING : « 1 » surveille les tâches planifiées, « 0 »
     *                                       non ; vide : seulement en production (en local, aucun cron ne tourne)
     */
    public function __construct(
        private readonly RecruiterRepository $recruiterRepository,
        private readonly ContactMessageRepository $contactMessageRepository,
        private readonly FailedEmailRepository $failedEmailRepository,
        private readonly SecurityEventRepository $securityEventRepository,
        private readonly WebauthnCredentialRepository $credentialRepository,
        private readonly ChatMessageRepository $chatMessageRepository,
        private readonly HeartbeatRepository $heartbeatRepository,
        private readonly SiteSettingsRepository $settingsRepository,
        private readonly AiStatus $aiStatus,
        private readonly AiKnowledgeBudget $knowledgeBudget,
        private readonly UnansweredQuestionRepository $unansweredRepository,
        private readonly ContentCompletenessChecker $completenessChecker,
        private readonly CalculatorRegistry $calculatorRegistry,
        private readonly BusinessHours $businessHours,
        private readonly AppVersion $appVersion,
        private readonly SeoContext $seoContext,
        private readonly ClockInterface $clock,
        #[Autowire(service: 'cache.dashboard')]
        private readonly CacheInterface $dashboardCache,
        private readonly MailRecipients $mailRecipients,
        private readonly DeliverabilityChecker $deliverability,
        private readonly NotificationDispatcher $notifier,
        private readonly UnvalidatedEmailCap $unvalidatedCap,
        #[Autowire('%kernel.environment%')]
        private readonly string $environment,
        #[Autowire('%env(default::DASHBOARD_CRON_MONITORING)%')]
        private readonly ?string $cronMonitoring = null,
    ) {
    }

    /**
     * Renvoie les tuiles du tableau de bord (cache de 60 s).
     *
     * Liens :
     * - Appelée par : Admin\DashboardController::index(), DashboardWeeklySummaryCommand, getAttentionCount()
     *
     * @param bool $refresh vrai : ignore le cache (bouton « Actualiser », résumé hebdomadaire)
     *
     * @return array{tiles: list<Tile>, computed_at: \DateTimeImmutable}
     */
    public function getTiles(bool $refresh = false): array
    {
        if ($refresh) {
            $this->invalidate();
        }

        /* @var array{tiles: list<Tile>, computed_at: \DateTimeImmutable} */
        return $this->dashboardCache->get(self::CACHE_KEY, function (ItemInterface $item): array {
            $item->expiresAfter(self::CACHE_TTL);

            return ['tiles' => $this->computeTiles(), 'computed_at' => $this->clock->now()];
        });
    }

    /**
     * Vide le cache des tuiles (après une action de l'admin ou un formulaire public envoyé).
     *
     * Liens :
     * - Appelée par : getTiles(true) ; voir aussi DashboardCacheSubscriber (requêtes POST)
     */
    public function invalidate(): void
    {
        $this->dashboardCache->delete(self::CACHE_KEY);
    }

    /**
     * Compte ce qui demande une action, pour la pastille du menu : demandes en attente, e-mails non
     * envoyés, sauvegarde en retard, problème de l'assistant IA.
     *
     * Liens :
     * - Appelée par : Twig\DashboardExtension (fonction admin_attention_count(), menu de l'admin)
     *
     * @return int nombre de points à traiter (0 : pas de pastille)
     */
    public function getAttentionCount(): int
    {
        $count = 0;
        foreach ($this->getTiles()['tiles'] as $tile) {
            $count += match ($tile['id']) {
                'recruiters', 'communication' => (int) $tile['value'],
                'tasks' => $this->hasLateBackup($tile) ? 1 : 0,
                'ai' => self::PROBLEM === $tile['status'] ? 1 : 0,
                default => 0,
            };
        }

        return $count;
    }

    /**
     * Résume l'état des tuiles (tuile => « statut:valeur ») pour savoir si quelque chose a changé depuis
     * la dernière visite de l'admin (récapitulatif à l'ouverture).
     *
     * Liens :
     * - Appelée par : Admin\DashboardController::index() (comparaison avec User::getDashboardSeen())
     *
     * @param list<Tile> $tiles tuiles (getTiles())
     *
     * @return array<string, string> tuile => « statut:valeur »
     */
    public function getDigest(array $tiles): array
    {
        $digest = [];
        foreach ($tiles as $tile) {
            $digest[$tile['id']] = $tile['status'].':'.$tile['value'];
        }

        return $digest;
    }

    /**
     * Renvoie les tuiles qui ont changé depuis l'état déjà vu.
     *
     * @param list<Tile>                 $tiles tuiles actuelles
     * @param array<string, string>|null $seen  état déjà vu (null : première visite, rien à signaler)
     *
     * @return list<Tile> tuiles changées (vide : pas de récapitulatif)
     */
    public function getChangedTiles(array $tiles, ?array $seen): array
    {
        if (null === $seen) {
            return [];
        }
        $digest = $this->getDigest($tiles);

        return array_values(array_filter($tiles, static fn (array $tile): bool => ($seen[$tile['id']] ?? null) !== $digest[$tile['id']]));
    }

    /**
     * Calcule toutes les tuiles (sans cache).
     *
     * @return list<Tile>
     */
    private function computeTiles(): array
    {
        $now = $this->clock->now();
        $heartbeats = $this->heartbeatRepository->findAllByName();

        return [
            $this->recruiters($now),
            $this->messages(),
            $this->content(),
            $this->ai($now, $heartbeats[Heartbeat::AI_MODELS] ?? null),
            $this->aiKnowledge($now),
            $this->communication($heartbeats[Heartbeat::MAIL_SENT] ?? null, $heartbeats[Heartbeat::EMAIL_CAP] ?? null),
            $this->security($now),
            $this->turnstile($now),
            $this->tasks($now, $heartbeats),
            $this->site(),
            $this->calculator(),
        ];
    }

    /**
     * Tuile « Recruteurs » : total, accès ouverts, demandes en attente (et âge de la plus ancienne en
     * heures ouvrées), accès qui se ferment dans les 7 jours, refus.
     *
     * @return Tile
     */
    private function recruiters(\DateTimeImmutable $now): array
    {
        $pending = $this->recruiterRepository->countByStatus(RecruiterStatus::Pending);
        $oldest = $this->recruiterRepository->findOldestPendingRequestedAt();
        $waited = null === $oldest ? 0.0 : $this->businessHours->between($oldest, $now);
        $late = $waited > self::PENDING_MAX_BUSINESS_HOURS;

        $lines = [
            $this->line('admin.dashboard.line.recruiters_total', ['%count%' => $this->recruiterRepository->count([])]),
            $this->line('admin.dashboard.line.recruiters_active', ['%count%' => $this->recruiterRepository->countActive($now)]),
            $this->line('admin.dashboard.line.recruiters_expiring', ['%count%' => $this->recruiterRepository->countExpiringBetween($now, $now->modify('+7 days'))]),
            $this->line('admin.dashboard.line.recruiters_refused', ['%count%' => $this->recruiterRepository->countByStatus(RecruiterStatus::Refused)]),
        ];
        if (null !== $oldest) {
            array_unshift($lines, $this->line(
                $late ? 'admin.dashboard.line.pending_late' : 'admin.dashboard.line.pending_oldest',
                ['%hours%' => (int) floor($waited), '%max%' => self::PENDING_MAX_BUSINESS_HOURS],
                $late ? self::PROBLEM : self::WARNING,
            ));
        }

        return $this->tile(
            'recruiters',
            match (true) {
                $late => self::PROBLEM,
                $pending > 0 => self::WARNING,
                default => self::OK,
            },
            (string) $pending,
            'admin.dashboard.value.pending',
            $lines,
            ['route' => 'admin_recruiter_index', 'params' => ['statut' => 'pending'], 'label' => 'admin.dashboard.link.recruiters'],
        );
    }

    /**
     * Tuile « Messages » : messages non lus de la boîte (hors archivés), messages lus en attente de réponse ; lien
     * vers la liste (filtres mémorisés de l'admin, sans les changer).
     *
     * @return Tile
     */
    private function messages(): array
    {
        $unread = $this->contactMessageRepository->countNew();
        $awaiting = $this->contactMessageRepository->countAwaitingReply();

        return $this->tile('messages', $unread > 0 ? self::WARNING : self::OK, (string) $unread, 'admin.dashboard.value.unread', [
            $this->line('admin.dashboard.line.messages_awaiting_reply', ['%count%' => $awaiting]),
        ], [
            'route' => 'admin_contact_message_index', 'params' => [], 'label' => 'admin.dashboard.link.messages',
        ]);
    }

    /**
     * Renvoie le nombre de messages non lus (valeur de la tuile « Messages », cache de 60 s).
     *
     * Liens :
     * - Appelée par : Twig\DashboardExtension (pastille du menu « Messages »)
     */
    public function getUnreadMessages(): int
    {
        foreach ($this->getTiles()['tiles'] as $tile) {
            if ('messages' === $tile['id']) {
                return (int) $tile['value'];
            }
        }

        return 0;
    }

    /**
     * Tuile « Contenu à compléter » : nombre de points, liste courte (ceux qui bloquent le TOP d'abord).
     *
     * @return Tile
     */
    private function content(): array
    {
        $items = $this->completenessChecker->findMissingItems();
        // Tri stable : les points bloquants en premier, l'ordre du contrôle ensuite
        usort($items, static fn (array $a, array $b): int => (int) $b['blocking'] <=> (int) $a['blocking']);
        $blocking = \count(array_filter($items, static fn (array $item): bool => $item['blocking']));
        // Deux compteurs : bloquant pour le TOP (rouge s'il y en a), facultatif (information,
        // jamais en alerte : réalisations chiffrées, études de cas, normes de la calculatrice, projets en brouillon…)
        $lines = [
            $this->line('admin.dashboard.line.content_blocking', ['%count%' => $blocking], $blocking > 0 ? self::PROBLEM : self::OK),
            $this->line('admin.dashboard.line.content_optional', ['%count%' => \count($items) - $blocking]),
        ];

        return $this->tile(
            'content',
            $blocking > 0 ? self::PROBLEM : self::OK,
            (string) $blocking,
            'admin.dashboard.value.blocking',
            $lines,
            ['route' => 'admin_profile_edit', 'params' => [], 'label' => 'admin.dashboard.link.content'],
            $items,
        );
    }

    /**
     * Tuile « Assistant IA » : état, modèles chez Mistral AI (dernière vérification), consommation du jour
     * et du mois en pourcentage du plafond, échanges suspects des 7 derniers jours.
     *
     * @return Tile
     */
    private function ai(\DateTimeImmutable $now, ?Heartbeat $modelsCheck): array
    {
        $summary = $this->aiStatus->getSummary();
        $todayPercent = $summary['today']['site_limit'] > 0 ? (int) round(100 * $summary['today']['questions'] / $summary['today']['site_limit']) : 0;
        $suspicious = $this->chatMessageRepository->countSuspiciousSince($now->modify('-7 days'));
        $modelProblem = [] !== array_filter($summary['model_alerts'], static fn (array $alert): bool => 'unchecked' !== $alert['problem']);
        $checkLate = null === $modelsCheck || $modelsCheck->getLastRunAt() < $now->modify(\sprintf('-%d hours', self::DAILY_TASK_MAX_HOURS));

        $lines = [
            $this->line('admin.dashboard.line.ai_models', ['%primary%' => $summary['primary_model'] ?: '—', '%fallback%' => $summary['fallback_model'] ?: '—'], $modelProblem ? self::PROBLEM : null),
            null === $modelsCheck
                ? $this->line('admin.dashboard.line.ai_models_never', [], self::WARNING)
                : $this->line('admin.dashboard.line.ai_models_checked', ['%date%' => $this->formatDate($modelsCheck->getLastRunAt())], $checkLate ? self::WARNING : null),
            $this->line('admin.dashboard.line.ai_today', ['%percent%' => $todayPercent, '%count%' => $summary['today']['questions'], '%limit%' => $summary['today']['site_limit']], $todayPercent >= 100 ? self::WARNING : null),
            $this->line('admin.dashboard.line.ai_month', ['%percent%' => (int) round($summary['month']['percent'])], $summary['month']['alert'] ? self::WARNING : null),
            $this->line('admin.dashboard.line.ai_suspicious', ['%count%' => $suspicious], $suspicious > 0 ? self::WARNING : null),
        ];
        foreach ($summary['model_alerts'] as $alert) {
            $lines[] = $this->line('admin.dashboard.line.ai_model_alert.'.$alert['problem'], ['%model%' => $alert['model'] ?: '—', '%role%' => 'admin.assistant.role.'.$alert['role']], 'unchecked' === $alert['problem'] ? self::WARNING : self::PROBLEM);
        }

        return $this->tile(
            'ai',
            match (true) {
                $modelProblem, 'limit_reached' === $summary['state'] => self::PROBLEM,
                'active' !== $summary['state'], $summary['month']['alert'], $suspicious > 0, $checkLate, [] !== $summary['model_alerts'] => self::WARNING,
                default => self::OK,
            },
            $summary['state'],
            'admin.dashboard.value.ai_state',
            $lines,
            ['route' => 'admin_assistant_index', 'params' => [], 'label' => 'admin.dashboard.link.ai'],
        );
    }

    /**
     * Tuile « Cerveau de l'IA » : questions sans réponse à traiter, réponses bloquées par le contrôle avant
     * affichage sur 7 jours, taille des fiches actives (problème au-delà du plafond).
     *
     * @return Tile
     */
    private function aiKnowledge(\DateTimeImmutable $now): array
    {
        $pending = $this->unansweredRepository->countPending();
        $blocked = $this->chatMessageRepository->countBlockedSince($now->modify('-7 days'));
        $budget = $this->knowledgeBudget->getSummary();

        return $this->tile(
            'ai_knowledge',
            match (true) {
                $budget['over_limit'] => self::PROBLEM,
                $pending > 0, $blocked > 0, $budget['warning'] => self::WARNING,
                default => self::OK,
            },
            (string) $pending,
            'admin.dashboard.value.unanswered',
            [
                $this->line('admin.dashboard.line.blocked_answers', ['%count%' => $blocked], $blocked > 0 ? self::WARNING : null),
                $this->line('admin.dashboard.line.knowledge_size', ['%total%' => $budget['total'], '%limit%' => $budget['limit']], $budget['over_limit'] ? self::PROBLEM : ($budget['warning'] ? self::WARNING : null)),
            ],
            ['route' => 'admin_ai_knowledge_unanswered', 'params' => [], 'label' => 'admin.dashboard.link.ai_knowledge'],
        );
    }

    /**
     * Tuile « Communication » : e-mails non envoyés (valeur, bouton « Renvoyer » sur la page), dernier e-mail parti,
     * adresses « Recrutement » et « Général du site » manquantes ou fictives, délivrabilité (dernier résultat gardé en
     * cache, jamais de requête DNS ici), plafond des e-mails aux personnes non validées (problème le jour où il est atteint),
     * canaux Discord et Telegram non configurés. Lien vers la page Communication.
     *
     * @return Tile
     */
    private function communication(?Heartbeat $lastSent, ?Heartbeat $emailCap): array
    {
        $failed = $this->failedEmailRepository->count([]);
        $cap = $this->settingsRepository->getOrCreate()->getUnvalidatedEmailDailyCap();
        $suspended = $this->unvalidatedCap->suspendedToday($emailCap);
        $problems = $this->mailRecipients->findProblems();
        // Adresse fictive : problème en production seulement (en local, les comptes de test en ont)
        $addressProblem = [] !== array_filter($problems, fn (string $problem): bool => 'missing' === $problem || 'prod' === $this->environment);
        $deliverable = $this->deliverability->cachedStatus();
        $missingChannels = array_values(array_filter(
            [NotificationChannel::Discord, NotificationChannel::Telegram],
            fn (NotificationChannel $channel): bool => !$this->notifier->isConfigured($channel),
        ));

        $lines = [null === $lastSent
            ? $this->line('admin.dashboard.line.mail_never')
            : $this->line('admin.dashboard.line.mail_last_sent', ['%date%' => $this->formatDate($lastSent->getLastRunAt())])];
        $lines[] = [] === $problems
            ? $this->line('admin.dashboard.line.addresses_ok')
            : $this->line('admin.dashboard.line.addresses_problem', ['%count%' => \count($problems)], $addressProblem ? self::PROBLEM : self::WARNING);
        $lines[] = match ($deliverable) {
            null => $this->line('admin.dashboard.line.deliverability_unknown'),
            true => $this->line('admin.dashboard.line.deliverability_ok'),
            false => $this->line('admin.dashboard.line.deliverability_problem', [], self::WARNING),
        };
        // Plafond des e-mails aux personnes non validées : problème le jour où il est atteint (e-mails suspendus)
        $lines[] = $suspended > 0
            ? $this->line('admin.dashboard.line.email_cap_reached', ['%cap%' => $cap, '%count%' => $suspended], self::PROBLEM)
            : $this->line('admin.dashboard.line.email_cap_today', ['%cap%' => $cap, '%count%' => $this->unvalidatedCap->countToday()]);
        if ([] !== $missingChannels) {
            $lines[] = $this->line('admin.dashboard.line.channels_missing', ['%channels%' => implode(', ', array_map(static fn (NotificationChannel $c): string => ucfirst($c->value), $missingChannels))]);
        }

        return $this->tile(
            'communication',
            match (true) {
                $failed > 0, $addressProblem, $suspended > 0 => self::PROBLEM,
                [] !== $problems, false === $deliverable => self::WARNING,
                default => self::OK,
            },
            (string) $failed,
            'admin.dashboard.value.failed_emails',
            $lines,
            ['route' => 'admin_communication', 'params' => [], 'label' => 'admin.dashboard.link.communication'],
        );
    }

    /**
     * Tuile « Sécurité » : échecs de connexion à l'admin et limites atteintes sur 7 jours, validation à distance
     * (décisions et refus de sécurité sur 7 jours), dernière connexion complète (clé vérifiée), nombre de clés
     * YubiKey (2 conseillées).
     *
     * @return Tile
     */
    private function security(\DateTimeImmutable $now): array
    {
        $since = $now->modify('-7 days');
        $failures = $this->securityEventRepository->countByTypesSince([SecurityEventType::PasswordFailure, SecurityEventType::KeyFailure], $since);
        $rateLimited = $this->securityEventRepository->countByTypesSince([SecurityEventType::RateLimited], $since);
        $remoteRejected = $this->securityEventRepository->countByTypesSince([SecurityEventType::RemoteDecisionRejected], $since);
        $remoteDone = $this->securityEventRepository->countByTypesSince([SecurityEventType::RemoteDecision], $since);
        $lastLogin = $this->securityEventRepository->findLastOccurredAt(SecurityEventType::KeySuccess);
        $keys = $this->credentialRepository->count([]);

        return $this->tile(
            'security',
            match (true) {
                $failures >= self::LOGIN_FAILURES_PROBLEM, 0 === $keys => self::PROBLEM,
                $failures > 0, $rateLimited > 0, $remoteRejected > 0, $keys < 2 => self::WARNING,
                default => self::OK,
            },
            (string) $failures,
            'admin.dashboard.value.login_failures',
            [
                $this->line('admin.dashboard.line.rate_limited', ['%count%' => $rateLimited], $rateLimited > 0 ? self::WARNING : null),
                // Validation à distance : refus de sécurité (secret faux, autre conversation, lien expiré, code PIN faux…)
                $this->settingsRepository->getOrCreate()->isRemoteValidationEnabled()
                    ? $this->line('admin.dashboard.line.remote_validation', ['%done%' => $remoteDone, '%rejected%' => $remoteRejected], $remoteRejected > 0 ? self::WARNING : null)
                    : $this->line('admin.dashboard.line.remote_validation_off', ['%rejected%' => $remoteRejected], $remoteRejected > 0 ? self::WARNING : null),
                null === $lastLogin ? $this->line('admin.dashboard.line.last_login_none') : $this->line('admin.dashboard.line.last_login', ['%date%' => $this->formatDate($lastLogin)]),
                $this->line('admin.dashboard.line.security_keys', ['%count%' => $keys], $keys < 2 ? self::WARNING : null),
            ],
            ['route' => 'admin_security_event_index', 'params' => [], 'label' => 'admin.dashboard.link.security'],
        );
    }

    /**
     * Tuile « Turnstile » (v2.0.3) : erreurs de CONFIGURATION de l'anti-robot (clé secrète refusée, clé de site
     * invalide, nom d'hôte non autorisé) sur 24 h et 7 jours, et la dernière (origine et code). Les refus normaux des
     * robots n'y comptent jamais. Problème à partir de TURNSTILE_CONFIG_ERRORS_PROBLEM erreurs en 24 h (formulaires
     * publics bloqués : alerte « Général du site ») ; à surveiller dès une erreur en 7 jours.
     *
     * Liens :
     * - Appelée par : computeTiles()
     * - Utilise : SecurityEventRepository (SecurityEventType::TurnstileConfigError, notée par Security\TurnstileVerifier)
     * - Voir aussi : Command\CommunicationAlertsCommand (NotificationEvent::TurnstileAlert)
     *
     * @return Tile
     */
    private function turnstile(\DateTimeImmutable $now): array
    {
        $types = [SecurityEventType::TurnstileConfigError];
        $lastDay = $this->securityEventRepository->countByTypesSince($types, $now->modify('-24 hours'));
        $lastWeek = $this->securityEventRepository->countByTypesSince($types, $now->modify('-7 days'));
        $last = $this->securityEventRepository->findLatestByTypes($types, 1)[0] ?? null;

        return $this->tile(
            'turnstile',
            match (true) {
                $lastDay >= self::TURNSTILE_CONFIG_ERRORS_PROBLEM => self::PROBLEM,
                $lastWeek > 0 => self::WARNING,
                default => self::OK,
            },
            (string) $lastDay,
            'admin.dashboard.value.turnstile_config_errors',
            [
                $this->line('admin.dashboard.line.turnstile_week', ['%count%' => $lastWeek], $lastWeek > 0 ? self::WARNING : null),
                null === $last
                    ? $this->line('admin.dashboard.line.turnstile_last_none')
                    : $this->line('admin.dashboard.line.turnstile_last', ['%date%' => $this->formatDate($last->getOccurredAt()), '%code%' => (string) $last->getUsername()], $lastDay >= self::TURNSTILE_CONFIG_ERRORS_PROBLEM ? self::PROBLEM : null),
                $this->line('admin.dashboard.line.turnstile_help'),
            ],
            ['route' => 'admin_security_event_index', 'params' => [], 'label' => 'admin.dashboard.link.security'],
        );
    }

    /**
     * Tuile « Sauvegardes et tâches planifiées » : dernière sauvegarde (date, taille ; alerte au-delà de
     * 48 h), dernier passage de chaque tâche quotidienne.
     *
     * @param array<string, Heartbeat> $heartbeats passages, par nom
     *
     * @return Tile
     */
    private function tasks(\DateTimeImmutable $now, array $heartbeats): array
    {
        if (!$this->isCronMonitored()) {
            return $this->tile(
                'tasks',
                self::NOT_APPLICABLE,
                '—',
                'admin.dashboard.value.not_applicable',
                [$this->line('admin.dashboard.line.tasks_local', ['%env%' => $this->environment])],
                ['route' => 'admin_dashboard', 'params' => ['actualiser' => 1], 'label' => 'admin.dashboard.link.tasks'],
            );
        }
        $backup = $heartbeats[Heartbeat::BACKUP] ?? null;
        $backupLate = null === $backup || !$backup->isSuccess() || $backup->getLastRunAt() < $now->modify(\sprintf('-%d hours', self::BACKUP_MAX_HOURS));
        $lines = [null === $backup
            ? $this->line('admin.dashboard.line.backup_never', [], self::PROBLEM)
            : $this->line(
                $backup->isSuccess() ? 'admin.dashboard.line.backup' : 'admin.dashboard.line.backup_failed',
                ['%date%' => $this->formatDate($backup->getLastRunAt()), '%size%' => $this->formatSize((int) ($backup->getDetails()['size'] ?? 0))],
                $backupLate ? self::PROBLEM : null,
            )];

        $tasksLate = false;
        foreach (Heartbeat::CRON_COMMANDS as $name => $command) {
            if (Heartbeat::BACKUP === $name) {
                continue;
            }
            $heartbeat = $heartbeats[$name] ?? null;
            $late = null === $heartbeat || !$heartbeat->isSuccess() || $heartbeat->getLastRunAt() < $now->modify(\sprintf('-%d hours', self::DAILY_TASK_MAX_HOURS));
            $tasksLate = $tasksLate || $late;
            $lines[] = null === $heartbeat
                ? $this->line('admin.dashboard.line.task_never', ['%command%' => $command], self::WARNING)
                : $this->line('admin.dashboard.line.task', ['%command%' => $command, '%date%' => $this->formatDate($heartbeat->getLastRunAt())], $late ? self::WARNING : null);
        }

        return $this->tile(
            'tasks',
            match (true) {
                $backupLate => self::PROBLEM,
                $tasksLate => self::WARNING,
                default => self::OK,
            },
            null === $backup ? '—' : $this->formatDate($backup->getLastRunAt()),
            'admin.dashboard.value.last_backup',
            $lines,
            ['route' => 'admin_dashboard', 'params' => ['actualiser' => 1], 'label' => 'admin.dashboard.link.tasks'],
        );
    }

    /**
     * Tuile « Site » : version (lien vers le journal des versions), environnement, mode maintenance,
     * exclusion des moteurs de recherche (noindex), version de PHP.
     *
     * @return Tile
     */
    private function site(): array
    {
        $maintenance = $this->settingsRepository->getOrCreate()->isMaintenanceEnabled();
        $noIndex = $this->seoContext->isSiteNoIndex();

        return $this->tile(
            'site',
            $maintenance ? self::WARNING : self::OK,
            $this->appVersion->get() ?: '—',
            'admin.dashboard.value.version',
            [
                $this->line('admin.dashboard.line.environment', ['%env%' => $this->environment]),
                $this->line($maintenance ? 'admin.dashboard.line.maintenance_on' : 'admin.dashboard.line.maintenance_off', [], $maintenance ? self::WARNING : null),
                $this->line($noIndex ? 'admin.dashboard.line.noindex_on' : 'admin.dashboard.line.noindex_off'),
                $this->line('admin.dashboard.line.php', ['%version%' => \PHP_VERSION]),
            ],
            ['route' => 'admin_changelog', 'params' => [], 'label' => 'admin.dashboard.link.site'],
        );
    }

    /**
     * Tuile « Calculatrice » : outils publiés, outils dont la norme n'est pas encore vérifiée.
     *
     * @return Tile
     */
    private function calculator(): array
    {
        $unverified = \count($this->calculatorRegistry->getToolsWithUnverifiedReference());

        return $this->tile(
            'calculator',
            // Normes à vérifier : information (norme masquée en public d'ici là, décision du 2026-10-25)
            self::OK,
            (string) $this->calculatorRegistry->countPublicTools(),
            'admin.dashboard.value.public_tools',
            [$this->line('admin.dashboard.line.unverified_references', ['%count%' => $unverified])],
            ['route' => 'admin_calculator_tool_index', 'params' => [], 'label' => 'admin.dashboard.link.calculator'],
        );
    }

    /**
     * Indique si les tâches planifiées sont surveillées : DASHBOARD_CRON_MONITORING (« 1 » ou « 0 »), sinon
     * seulement en production (en local aucun cron ne tourne : « Non applicable en local »).
     */
    private function isCronMonitored(): bool
    {
        $setting = trim((string) $this->cronMonitoring);

        return '' === $setting ? 'prod' === $this->environment : filter_var($setting, \FILTER_VALIDATE_BOOL);
    }

    /**
     * Indique si la tuile des tâches signale une sauvegarde en retard (pastille du menu).
     *
     * @param Tile $tile tuile « tasks »
     */
    private function hasLateBackup(array $tile): bool
    {
        return self::PROBLEM === $tile['status'];
    }

    /**
     * Construit une tuile.
     *
     * @param list<Line>                                                             $lines détails
     * @param array{route: string, params: array<string, string|int>, label: string} $link  lien de la tuile
     * @param list<Item>                                                             $items points listés (contenu à compléter)
     *
     * @return Tile
     */
    private function tile(string $id, string $status, string $value, string $valueKey, array $lines, array $link, array $items = []): array
    {
        return ['id' => $id, 'status' => $status, 'value' => $value, 'value_key' => $valueKey, 'lines' => $lines, 'link' => $link, 'items' => $items];
    }

    /**
     * Construit une ligne de détail (clé de traduction, paramètres, statut facultatif).
     *
     * @param array<string, string|int> $params paramètres de la traduction
     *
     * @return Line
     */
    private function line(string $key, array $params = [], ?string $status = null): array
    {
        $line = ['key' => $key, 'params' => $params];
        if (null !== $status) {
            $line['status'] = $status;
        }

        return $line;
    }

    /**
     * Formate une date pour l'admin (fuseau de Paris), ex. « 26/10/2026 à 14:05 ».
     */
    private function formatDate(\DateTimeImmutable $date): string
    {
        return $date->setTimezone(new \DateTimeZone('Europe/Paris'))->format('d/m/Y \à H:i');
    }

    /**
     * Formate une taille de fichier (Ko, Mo).
     *
     * @param int $bytes taille en octets
     */
    private function formatSize(int $bytes): string
    {
        return $bytes >= 1048576
            ? number_format($bytes / 1048576, 1, ',', ' ').' Mo'
            : number_format($bytes / 1024, 0, ',', ' ').' Ko';
    }
}
