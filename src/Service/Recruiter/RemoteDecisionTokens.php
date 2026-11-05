<?php

declare(strict_types=1);

namespace App\Service\Recruiter;

use App\Entity\Recruiter;
use App\Entity\RemoteDecisionToken;
use App\Enum\NotificationChannel;
use App\Enum\RecruiterStatus;
use App\Enum\RemoteDecision;
use App\Repository\RemoteDecisionTokenRepository;
use App\Repository\SiteSettingsRepository;
use App\Service\Notification\TelegramAccess;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Clock\ClockInterface;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * Jetons de la validation à distance (décision du 2026-10-26) : création (aléatoires, 256 bits, empreinte
 * SHA-256 seule gardée, liés à une demande, une action et un canal, valables 72 h), contrôle d'un jeton
 * reçu, boutons Telegram et liens Discord des notifications.
 *
 * Interrupteur actif (décision du 2026-10-27) : CHAQUE notification de nouvelle demande porte les boutons
 * (Telegram) ou les liens (Discord), en développement comme en production ; s'il manque une condition (code
 * PIN, compte Telegram), la notification le dit (telegramUnavailable(), discordUnavailable()) au lieu de
 * retirer les boutons en silence. Les jetons d'essai (sans demande) servent à la notification de test.
 *
 * Ne dépend pas de RecruiterAccessManager (qui envoie les notifications) : l'exécution des décisions est
 * dans RemoteDecisionExecutor.
 *
 * Liens :
 * - Utilisé par : NotificationDispatcher (boutons et liens), RemoteDecisionExecutor, TelegramCallbackHandler,
 *   RemoteDecisionController, Admin\SiteSettingsController (état)
 * - Utilise : RemoteDecisionTokenRepository, SiteSettingsRepository (interrupteur, code PIN), TelegramAccess
 * - Testé par : tests/Controller/TelegramWebhookControllerTest, tests/Controller/RemoteDecisionControllerTest
 */
final readonly class RemoteDecisionTokens
{
    /** Durée de validité d'un bouton ou d'un lien (heures). */
    public const VALIDITY_HOURS = 72;

    /** Forme d'un jeton : 32 octets aléatoires en base64url, sans remplissage (43 caractères). */
    public const TOKEN_PATTERN = '[A-Za-z0-9_-]{43}';

    /** Motifs de refus d'un jeton reçu. */
    public const ERROR_DISABLED = 'disabled';
    public const ERROR_UNKNOWN = 'unknown';
    public const ERROR_EXPIRED = 'expired';
    public const ERROR_USED = 'used';
    public const ERROR_BLOCKED = 'blocked';
    public const ERROR_PROCESSED = 'processed';

    /**
     * Reçoit les dépendances.
     */
    public function __construct(
        private RemoteDecisionTokenRepository $repository,
        private SiteSettingsRepository $settingsRepository,
        private EntityManagerInterface $entityManager,
        private UrlGeneratorInterface $urlGenerator,
        private ClockInterface $clock,
        private TelegramAccess $telegramAccess,
    ) {
    }

    /**
     * Indique si la validation à distance est activée dans Paramètres.
     */
    public function isEnabled(): bool
    {
        return $this->settingsRepository->getOrCreate()->isRemoteValidationEnabled();
    }

    /**
     * Indique si les boutons Telegram peuvent être proposés : conversation et compte autorisés connus.
     *
     * Liens :
     * - Appelée par : telegramKeyboard(), Admin\SiteSettingsController
     */
    public function isTelegramReady(): bool
    {
        return null === $this->telegramAccess->missing();
    }

    /**
     * Indique si les liens Discord peuvent être proposés : code PIN défini dans Paramètres.
     *
     * Liens :
     * - Appelée par : discordLinks(), Admin\SiteSettingsController
     */
    public function isDiscordReady(): bool
    {
        return $this->hasPin();
    }

    /**
     * Indique si le code PIN est défini (exigé sur Telegram et sur Discord, décision du 2026-10-27).
     *
     * Liens :
     * - Appelée par : isDiscordReady(), telegramKeyboard(), TelegramCallbackHandler, DiscordInteractionHandler
     */
    public function hasPin(): bool
    {
        return null !== $this->settingsRepository->getOrCreate()->getRemotePinHash();
    }

    /**
     * Raison pour laquelle les boutons Telegram manquent alors que l'interrupteur est actif, ou null.
     *
     * Liens :
     * - Appelée par : NotificationDispatcher (ligne d'avertissement dans le message)
     */
    public function telegramUnavailable(): ?string
    {
        $missing = $this->telegramAccess->missing();
        if (!$this->isEnabled()) {
            return null;
        }

        return match (true) {
            null !== $missing => 'Boutons indisponibles : '.$missing.' (.env.local).',
            !$this->hasPin() => 'Boutons indisponibles : aucun code PIN (Paramètres › Validation à distance).',
            default => null,
        };
    }

    /**
     * Raison pour laquelle les liens Discord manquent alors que l'interrupteur est actif, ou null.
     *
     * Liens :
     * - Appelée par : NotificationDispatcher (ligne d'avertissement dans le message)
     */
    public function discordUnavailable(): ?string
    {
        return $this->isEnabled() && !$this->isDiscordReady() ? 'Liens indisponibles : aucun code PIN (Paramètres › Validation à distance).' : null;
    }

    /**
     * Crée un jeton par décision (accepter, refuser) pour une demande (ou un essai) et un canal.
     *
     * Liens :
     * - Appelée par : telegramKeyboard(), discordLinks(), TelegramCallbackHandler (« Non » : boutons neufs)
     *
     * @param Recruiter|null $recruiter demande ; null : jetons d'essai (notification de test)
     *
     * @return array{approve: string, refuse: string} jetons en clair (à placer dans la notification, jamais gardés)
     */
    public function issue(?Recruiter $recruiter, NotificationChannel $channel): array
    {
        $now = $this->clock->now();
        $tokens = [];
        foreach (RemoteDecision::cases() as $decision) {
            $token = rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
            $this->entityManager->persist(new RemoteDecisionToken($recruiter, $decision, $channel, self::hash($token), $now, $now->modify(\sprintf('+%d hours', self::VALIDITY_HOURS))));
            $tokens[$decision->value] = $token;
        }
        $this->entityManager->flush();

        return ['approve' => $tokens['approve'], 'refuse' => $tokens['refuse']];
    }

    /**
     * Rend inutilisables les jetons encore valides d'une demande sur un canal (Telegram : « Non » à la
     * confirmation, de nouveaux boutons les remplacent).
     *
     * Liens :
     * - Appelée par : TelegramCallbackHandler
     */
    public function revokeUnused(RemoteDecisionToken $token): void
    {
        $now = $this->clock->now();
        $recruiter = $token->getRecruiter();
        $tokens = null === $recruiter ? [$token] : $this->repository->findUnused($recruiter, $token->getChannel());
        foreach ($tokens as $unused) {
            $unused->markUsed($now);
        }
        $this->entityManager->flush();
    }

    /**
     * Contrôle un jeton reçu : validation à distance activée, jeton connu sur ce canal, non expiré, non utilisé,
     * non bloqué, demande toujours en attente (sauf essai).
     *
     * Liens :
     * - Appelée par : TelegramCallbackHandler, RemoteDecisionController
     *
     * @param string $plainToken jeton reçu (bouton ou lien)
     *
     * @return array{token: RemoteDecisionToken|null, error: string|null} jeton valide (error null), ou motif du refus
     *                                                                    (token renseigné dès qu'il est connu)
     */
    public function check(string $plainToken, NotificationChannel $channel): array
    {
        if (!$this->isEnabled()) {
            return ['token' => null, 'error' => self::ERROR_DISABLED];
        }
        $token = 1 === preg_match('/^'.self::TOKEN_PATTERN.'$/', $plainToken) ? $this->repository->findOneByHash(self::hash($plainToken)) : null;
        if (null === $token || $token->getChannel() !== $channel) {
            return ['token' => null, 'error' => self::ERROR_UNKNOWN];
        }

        return ['token' => $token, 'error' => $this->stateError($token)];
    }

    /**
     * Contrôle un jeton déjà retrouvé (réponse Telegram portant le code PIN, fenêtre Discord) : interrupteur,
     * utilisation, blocage, expiration, demande toujours en attente.
     *
     * Liens :
     * - Appelée par : check(), TelegramCallbackHandler::handleMessage(), DiscordInteractionHandler
     *
     * @return string|null motif du refus (ERROR_*), ou null si le jeton est valable
     */
    public function stateError(RemoteDecisionToken $token): ?string
    {
        if (!$this->isEnabled()) {
            return self::ERROR_DISABLED;
        }

        return match (true) {
            null !== $token->getUsedAt() => self::ERROR_USED,
            $token->isBlocked() => self::ERROR_BLOCKED,
            $token->isExpired($this->clock->now()) => self::ERROR_EXPIRED,
            !$token->isTrial() && RecruiterStatus::Pending !== $token->getRecruiter()?->getStatus() => self::ERROR_PROCESSED,
            default => null,
        };
    }

    /**
     * Retrouve un jeton par son texte (bouton Discord interactif), sans le contrôler.
     *
     * Liens :
     * - Appelée par : DiscordInteractionHandler
     *
     * @return RemoteDecisionToken|null null si le texte n'a pas la forme d'un jeton ou ne correspond à aucun jeton
     */
    public function find(string $plainToken): ?RemoteDecisionToken
    {
        return 1 === preg_match('/^'.self::TOKEN_PATTERN.'$/', $plainToken) ? $this->repository->findOneByHash(self::hash($plainToken)) : null;
    }

    /**
     * Retrouve le jeton dont le message Telegram demande le code PIN.
     *
     * Liens :
     * - Appelée par : TelegramCallbackHandler::handleMessage()
     */
    public function findByTelegramPrompt(int $promptMessageId): ?RemoteDecisionToken
    {
        return $this->repository->findOneByTelegramPrompt($promptMessageId);
    }

    /**
     * Construit les boutons Telegram : « ✅ Accepter », « ❌ Refuser » (callback_data) pour une demande en
     * attente ou un essai, si l'interrupteur est actif et Telegram prêt ; « 🔗 Voir dans l'admin » (lien) si
     * l'adresse est publique (Telegram refuse localhost : en local, le lien est dans le texte).
     *
     * Liens :
     * - Appelée par : NotificationDispatcher (canal Telegram), TelegramCallbackHandler (« Non »)
     *
     * @param Recruiter|null $recruiter demande en attente, ou null
     * @param bool           $trial     vrai : boutons d'essai (notification de test)
     *
     * @return array{inline_keyboard: list<list<array<string, string>>>}|null clavier, ou null s'il est vide
     */
    public function telegramKeyboard(?Recruiter $recruiter, string $adminUrl, bool $trial = false): ?array
    {
        $rows = [];
        if ((null !== $recruiter || $trial) && $this->isEnabled() && $this->isTelegramReady() && $this->hasPin()) {
            $tokens = $this->issue($recruiter, NotificationChannel::Telegram);
            $rows[] = [
                ['text' => '✅ Accepter', 'callback_data' => 'p:'.$tokens['approve']],
                ['text' => '❌ Refuser', 'callback_data' => 'p:'.$tokens['refuse']],
            ];
        }
        if (self::isPublicUrl($adminUrl)) {
            $rows[] = [['text' => '🔗 Voir dans l\'admin', 'url' => $adminUrl]];
        }

        return [] === $rows ? null : ['inline_keyboard' => $rows];
    }

    /**
     * Construit les liens Discord « Accepter » et « Refuser » d'une demande en attente (ou d'un essai), ou null
     * si l'interrupteur est éteint ou si aucun code PIN n'est défini.
     *
     * Liens :
     * - Appelée par : NotificationDispatcher (canal Discord)
     * - Route : remote_decision_show (/validation-distance/{token})
     *
     * @param bool $trial vrai : liens d'essai (notification de test)
     *
     * @return array{approve: string, refuse: string}|null
     */
    public function discordLinks(?Recruiter $recruiter, bool $trial = false): ?array
    {
        $tokens = $this->discordTokens($recruiter, $trial);

        return null === $tokens ? null : $this->linksFor($tokens);
    }

    /**
     * Émet les jetons Discord « accepter » et « refuser » d'une demande en attente (ou d'un essai), ou null si
     * l'interrupteur est éteint ou si aucun code PIN n'est défini.
     *
     * Liens :
     * - Appelée par : discordLinks(), NotificationDispatcher (boutons interactifs de l'application Discord)
     *
     * @return array{approve: string, refuse: string}|null jetons en clair, ou null
     */
    public function discordTokens(?Recruiter $recruiter, bool $trial = false): ?array
    {
        if ((null === $recruiter && !$trial) || !$this->isEnabled() || !$this->isDiscordReady()) {
            return null;
        }

        return $this->issue($recruiter, NotificationChannel::Discord);
    }

    /**
     * Construit les liens vers la page de confirmation des jetons Discord (boutons-liens, ou liens du texte).
     *
     * Liens :
     * - Appelée par : discordLinks(), NotificationDispatcher (repli sur le webhook)
     * - Route : remote_decision_show (/validation-distance/{token})
     *
     * @param array{approve: string, refuse: string} $tokens jetons en clair
     *
     * @return array{approve: string, refuse: string}
     */
    public function linksFor(array $tokens): array
    {
        return array_map(fn (string $token): string => $this->urlGenerator->generate('remote_decision_show', ['token' => $token], UrlGeneratorInterface::ABSOLUTE_URL), $tokens);
    }

    /**
     * Indique si une adresse peut servir de bouton-lien Telegram (https, hors localhost : Telegram refuse les
     * autres).
     */
    public static function isPublicUrl(string $url): bool
    {
        $host = (string) parse_url($url, \PHP_URL_HOST);

        return str_starts_with($url, 'https://') && '' !== $host && 'localhost' !== $host && !str_ends_with($host, '.localhost') && false === filter_var($host, \FILTER_VALIDATE_IP);
    }

    /**
     * Indique si une adresse vise le poste local (liens Discord utilisables seulement depuis ce PC).
     */
    public static function isLocalUrl(string $url): bool
    {
        $host = (string) parse_url($url, \PHP_URL_HOST);

        return 'localhost' === $host || str_ends_with($host, '.localhost') || '127.0.0.1' === $host || '::1' === trim($host, '[]');
    }

    /**
     * Empreinte d'un jeton (seule forme gardée en base).
     */
    public static function hash(string $plainToken): string
    {
        return hash('sha256', $plainToken);
    }
}
