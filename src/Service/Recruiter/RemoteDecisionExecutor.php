<?php

declare(strict_types=1);

namespace App\Service\Recruiter;

use App\Entity\RemoteDecisionToken;
use App\Enum\NotificationChannel;
use App\Enum\RemoteDecision;
use App\Enum\SecurityEventType;
use App\Repository\SiteSettingsRepository;
use App\Security\SecurityEventLogger;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Clock\ClockInterface;
use Symfony\Component\PasswordHasher\Hasher\NativePasswordHasher;

/**
 * Exécute une décision prise à distance, avec EXACTEMENT le traitement de l'admin (RecruiterAccessManager :
 * accès de 90 jours et e-mail de validation, ou refus sans e-mail), consomme le jeton et note chaque action et
 * chaque refus de sécurité dans le journal de sécurité (canal, action, numéro de la demande ; jamais de jeton
 * ni d'adresse).
 *
 * Code PIN des liens Discord : 6 chiffres au moins, gardé haché (Paramètres) ; 5 codes faux bloquent le lien.
 *
 * Liens :
 * - Utilisé par : TelegramCallbackHandler, RemoteDecisionController, Admin\SiteSettingsController (hashPin())
 * - Utilise : RecruiterAccessManager::approve() et refuse(), SecurityEventLogger, SiteSettingsRepository
 * - Testé par : tests/Controller/TelegramWebhookControllerTest, tests/Controller/RemoteDecisionControllerTest
 */
final readonly class RemoteDecisionExecutor
{
    /** Codes PIN faux permis par lien avant blocage. */
    public const MAX_PIN_ATTEMPTS = 5;

    /** Forme d'un code PIN valide : 6 à 12 chiffres. */
    public const PIN_PATTERN = '/^\d{6,12}$/';

    /**
     * Reçoit les dépendances.
     */
    public function __construct(
        private RecruiterAccessManager $accessManager,
        private SecurityEventLogger $securityLogger,
        private SiteSettingsRepository $settingsRepository,
        private EntityManagerInterface $entityManager,
        private ClockInterface $clock,
    ) {
    }

    /**
     * Applique la décision du jeton (déjà contrôlé par RemoteDecisionTokens::check()) et le consomme.
     *
     * Liens :
     * - Appelée par : TelegramCallbackHandler (« Oui »), RemoteDecisionController::confirm() (code PIN correct)
     *
     * Jeton d'essai (notification de test) : consommé, noté au journal, mais aucune demande n'est touchée.
     *
     * @return \DateTimeImmutable date de la décision (affichée : « ✅ Accepté le 27/10 à 14 h 05 »)
     */
    public function execute(RemoteDecisionToken $token): \DateTimeImmutable
    {
        $now = $this->clock->now();
        $token->markUsed($now);
        $recruiter = $token->getRecruiter();
        if (null !== $recruiter) {
            match ($token->getDecision()) {
                RemoteDecision::Approve => $this->accessManager->approve($recruiter),
                RemoteDecision::Refuse => $this->accessManager->refuse($recruiter),
            };
        }
        $this->entityManager->flush();
        $this->securityLogger->log(SecurityEventType::RemoteDecision, \sprintf('%s %s %s', $token->getChannel()->value, $token->getDecision()->value, self::subject($token)));

        return $now;
    }

    /**
     * Désigne la demande d'un jeton dans le journal : « #12 », ou « essai » (notification de test).
     */
    public static function subject(?RemoteDecisionToken $token): string
    {
        return match (true) {
            null === $token => '',
            $token->isTrial() => 'essai',
            default => '#'.(int) $token->getRecruiter()?->getId(),
        };
    }

    /**
     * Vérifie le code PIN saisi sur la page d'un lien Discord ; un code faux compte un essai (lien bloqué au 5e).
     *
     * Liens :
     * - Appelée par : RemoteDecisionController::confirm()
     *
     * @param string $pin code saisi
     *
     * @return int|null null si le code est correct ; sinon essais restants (0 : lien bloqué)
     */
    public function checkPin(RemoteDecisionToken $token, string $pin): ?int
    {
        $hash = $this->settingsRepository->getOrCreate()->getRemotePinHash();
        if (null !== $hash && 1 === preg_match(self::PIN_PATTERN, $pin) && (new NativePasswordHasher())->verify($hash, $pin)) {
            return null;
        }
        $remaining = $token->recordFailedAttempt($this->clock->now(), self::MAX_PIN_ATTEMPTS);
        $this->entityManager->flush();
        $this->reject($token->getChannel(), 0 === $remaining ? 'pin_blocked' : 'pin_invalid', $token);

        return $remaining;
    }

    /**
     * Note un refus de sécurité dans le journal (canal, motif, demande si connue).
     *
     * Liens :
     * - Appelée par : checkPin(), TelegramWebhookController, RemoteDecisionController
     *
     * @param string $reason motif court (secret_invalid, foreign_chat, unknown, expired, used, blocked, csrf…)
     */
    public function reject(NotificationChannel $channel, string $reason, ?RemoteDecisionToken $token = null): void
    {
        $this->securityLogger->log(SecurityEventType::RemoteDecisionRejected, trim(\sprintf('%s %s %s', $channel->value, $reason, self::subject($token))));
    }

    /**
     * Calcule l'empreinte d'un code PIN (seule forme gardée dans Paramètres).
     *
     * Liens :
     * - Appelée par : Admin\SiteSettingsController::edit()
     *
     * @throws \InvalidArgumentException si le code n'a pas 6 à 12 chiffres
     */
    public static function hashPin(string $pin): string
    {
        if (1 !== preg_match(self::PIN_PATTERN, $pin)) {
            throw new \InvalidArgumentException('Le code PIN doit compter 6 à 12 chiffres.');
        }

        return (new NativePasswordHasher())->hash($pin);
    }
}
