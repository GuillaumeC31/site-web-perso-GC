<?php

declare(strict_types=1);

namespace App\Service\Notification;

use App\Entity\Heartbeat;
use App\Repository\HeartbeatRepository;
use Psr\Clock\ClockInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * État de la relève des appuis Telegram en local (app:telegram:poll) : en développement, le webhook ne peut pas
 * joindre le poste, et sans relève les boutons ne répondent pas (cause de la panne du 2026-10-27 : relève arrêtée
 * par un vidage du cache). Active si elle a tourné il y a moins de 2 minutes (battement « telegram_poll »).
 * En production (webhook), rien n'est signalé.
 *
 * Liens :
 * - Utilisé par : Admin\SiteSettingsController (« Relève Telegram : active / arrêtée »), NotificationDispatcher
 *   (avertissement dans la notification Telegram)
 * - Utilise : HeartbeatRepository (passage noté par TelegramPollCommand)
 * - Testé par : tests/Service/NotificationDispatcherTest
 */
final readonly class TelegramPollStatus
{
    /** Délai au-delà duquel la relève est considérée comme arrêtée (secondes). */
    public const MAX_SILENCE_SECONDS = 120;

    /**
     * Reçoit les dépendances.
     *
     * @param string $environment environnement (la relève ne concerne que le développement)
     */
    public function __construct(
        private HeartbeatRepository $heartbeats,
        private ClockInterface $clock,
        #[Autowire('%kernel.environment%')]
        private string $environment,
    ) {
    }

    /**
     * Indique si la relève concerne cet environnement (développement seulement).
     */
    public function isRelevant(): bool
    {
        return 'dev' === $this->environment;
    }

    /**
     * Renvoie la date du dernier passage de la relève, ou null si elle n'a jamais tourné.
     */
    public function lastRunAt(): ?\DateTimeImmutable
    {
        return ($this->heartbeats->findAllByName()[Heartbeat::TELEGRAM_POLL] ?? null)?->getLastRunAt();
    }

    /**
     * Indique si la relève a tourné il y a moins de 2 minutes.
     */
    public function isActive(): bool
    {
        $last = $this->lastRunAt();

        return null !== $last && $this->clock->now()->getTimestamp() - $last->getTimestamp() <= self::MAX_SILENCE_SECONDS;
    }

    /**
     * Avertissement à ajouter à une notification Telegram à boutons, ou null (production, ou relève active).
     *
     * Liens :
     * - Appelée par : NotificationDispatcher (canal Telegram)
     */
    public function warning(): ?string
    {
        if (!$this->isRelevant() || $this->isActive()) {
            return null;
        }
        $last = $this->lastRunAt()?->setTimezone(new \DateTimeZone('Europe/Paris'));

        return \sprintf('Relève Telegram arrêtée (%s) : lancez « php bin/console app:telegram:poll », sinon les boutons ne répondront pas.', null === $last ? 'jamais lancée' : 'dernier passage le '.$last->format('d/m à H:i'));
    }
}
