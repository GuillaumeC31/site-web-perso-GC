<?php

declare(strict_types=1);

namespace App\Service\Recruiter;

use App\Entity\Recruiter;
use App\Entity\RecruiterEvent;
use App\Enum\RecruiterEventType;
use App\Repository\RecruiterEventRepository;
use App\Service\Notification\NotificationDispatcher;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Clock\ClockInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Événements des recruteurs validés, en deux familles séparées (v2.0.3, point 7) :
 * - SIGNAL D'INTÉRÊT explicite (clic sur « Je suis intéressé », POST avec jeton CSRF, rien d'autre) : notification
 *   « Signal d'intérêt d'un recruteur » tout de suite, une seule par période de COOLDOWN (un second clic ne crée
 *   ni événement ni notification : signalInterest() renvoie le signal déjà transmis) ;
 * - ACTIVITÉ (première connexion, CV complet téléchargé) : notification « Activité d'un recruteur »
 *   (NotificationEvent::RecruiterActivity, réglable dans Communication › Notifications), une fois par jour et par
 *   recruteur au plus (décision du 2026-10-23) ; les suivantes du même jour partent dans le récapitulatif du
 *   lendemain (sendDigest(), commande app:recruiter:daily). Jamais titrée « Signal d'intérêt ».
 *
 * Liens :
 * - Utilisé par : RecruiterAreaController, RecruiterLoginSubscriber, RecruiterDailyCommand
 * - Utilise : RecruiterEventRepository, NotificationDispatcher, AccessRequestSummary::interestNotification() et
 *   activityNotification() (mêmes informations qu'une nouvelle demande ; lien « Voir dans l'admin » seulement)
 * - Testé par : tests/Controller/RecruiterInterestSignalTest, tests/Controller/RecruiterAreaControllerTest,
 *   tests/Command/RecruiterCommandsTest
 */
final readonly class InterestSignalNotifier
{
    /** Fuseau des journées (« une notification par jour ») et des heures affichées. */
    private const TIMEZONE = 'Europe/Paris';

    /** Période pendant laquelle un nouveau clic sur « Je suis intéressé » est un doublon (ni événement, ni notification). */
    public const COOLDOWN = '7 days';

    /**
     * Reçoit les dépendances.
     */
    public function __construct(
        private RecruiterEventRepository $eventRepository,
        private NotificationDispatcher $notifier,
        private AccessRequestSummary $summary,
        private EntityManagerInterface $entityManager,
        private TranslatorInterface $translator,
        private ClockInterface $clock,
    ) {
    }

    /**
     * Signal d'intérêt explicite (clic sur « Je suis intéressé ») : enregistré et notifié tout de suite, sauf s'il a
     * déjà été transmis pendant la période COOLDOWN (doublon : rien n'est enregistré ni envoyé).
     *
     * Liens :
     * - Appelée par : RecruiterAreaController::interest() (POST, jeton CSRF)
     *
     * @return RecruiterEvent|null null : signal transmis ; sinon, le signal déjà transmis (doublon)
     */
    public function signalInterest(Recruiter $recruiter): ?RecruiterEvent
    {
        $now = $this->clock->now();
        $previous = $this->eventRepository->findLastSince($recruiter, RecruiterEventType::Interested, $now->modify('-'.self::COOLDOWN));
        if (null !== $previous) {
            return $previous;
        }
        $event = new RecruiterEvent($recruiter, RecruiterEventType::Interested, $now);
        $this->entityManager->persist($event);
        $recruiter->touch($now);
        $this->entityManager->flush();
        $this->send($recruiter, [$event], $now);

        return null;
    }

    /**
     * Activité d'un recruteur (première connexion, CV téléchargé) : enregistrée, et notifiée « Activité d'un
     * recruteur » si c'est la première activité notifiée du jour ; sinon, elle attend le récapitulatif.
     *
     * Liens :
     * - Appelée par : RecruiterAreaController::cvPdf(), RecruiterLoginSubscriber
     *
     * @return bool vrai si une notification est partie tout de suite
     *
     * @throws \LogicException si le type est le signal d'intérêt explicite (signalInterest() seulement)
     */
    public function recordActivity(Recruiter $recruiter, RecruiterEventType $type): bool
    {
        if ($type->isExplicitSignal()) {
            throw new \LogicException('Le signal d\'intérêt passe par signalInterest() (bouton seulement).');
        }
        $now = $this->clock->now();
        $this->entityManager->persist(new RecruiterEvent($recruiter, $type, $now));
        $recruiter->touch($now);
        $this->entityManager->flush();
        if ($this->eventRepository->hasNotificationSince($recruiter, $this->startOfDay($now), self::activityTypes())) {
            return false;
        }
        $pending = array_values(array_filter(
            $this->eventRepository->findPendingBefore($now->modify('+1 second')),
            static fn (RecruiterEvent $e): bool => $e->getRecruiter() === $recruiter && !$e->getType()->isExplicitSignal(),
        ));
        $this->send($recruiter, $pending, $now);

        return true;
    }

    /**
     * Envoie le récapitulatif des événements en attente des jours précédents, groupés par recruteur et par famille :
     * l'activité (si aucune activité n'a été notifiée aujourd'hui) et, s'il en reste d'une version antérieure, les
     * signaux d'intérêt explicites (notification « Signal d'intérêt » à part, jamais mélangée à l'activité).
     *
     * Liens :
     * - Appelée par : RecruiterDailyCommand
     *
     * @return int nombre de notifications envoyées
     */
    public function sendDigest(): int
    {
        $now = $this->clock->now();
        $today = $this->startOfDay($now);
        $groups = [];
        foreach ($this->eventRepository->findPendingBefore($today) as $event) {
            $family = $event->getType()->isExplicitSignal() ? 'interest' : 'activity';
            $groups[(int) $event->getRecruiter()->getId().'-'.$family][] = $event;
        }
        $sent = 0;
        foreach ($groups as $events) {
            $recruiter = $events[0]->getRecruiter();
            $activity = !$events[0]->getType()->isExplicitSignal();
            if ($activity && $this->eventRepository->hasNotificationSince($recruiter, $today, self::activityTypes())) {
                continue;
            }
            $this->send($recruiter, $events, $now);
            ++$sent;
        }

        return $sent;
    }

    /**
     * Envoie une notification qui liste les événements (tous de la même famille), puis les marque comme notifiés.
     *
     * @param list<RecruiterEvent> $events événements à inclure (signal explicite, ou activité)
     */
    private function send(Recruiter $recruiter, array $events, \DateTimeImmutable $now): void
    {
        if ([] === $events) {
            return;
        }
        $labels = array_map(fn (RecruiterEvent $e): string => \sprintf('%s (%s)', $this->translator->trans($e->getType()->translationKey()), $e->getOccurredAt()->setTimezone(new \DateTimeZone(self::TIMEZONE))->format('d/m H:i')), $events);
        $this->notifier->notify($events[0]->getType()->isExplicitSignal()
            ? $this->summary->interestNotification($recruiter, $labels)
            : $this->summary->activityNotification($recruiter, $labels));
        foreach ($events as $event) {
            $event->markNotified($now);
        }
        $this->entityManager->flush();
    }

    /**
     * Types d'événements de la famille « activité » (tout sauf le signal explicite).
     *
     * @return list<RecruiterEventType>
     */
    private static function activityTypes(): array
    {
        return array_values(array_filter(RecruiterEventType::cases(), static fn (RecruiterEventType $t): bool => !$t->isExplicitSignal()));
    }

    /**
     * Début de la journée en cours, à l'heure française (PHP tourne en UTC).
     *
     * Renvoyé dans le fuseau de PHP : Doctrine enregistre les dates sans fuseau, la comparaison en base
     * doit se faire dans celui des dates stockées.
     *
     * Liens :
     * - Appelée par : recordActivity(), sendDigest()
     */
    private function startOfDay(\DateTimeImmutable $now): \DateTimeImmutable
    {
        return $now->setTimezone(new \DateTimeZone(self::TIMEZONE))->setTime(0, 0)->setTimezone($now->getTimezone());
    }
}
