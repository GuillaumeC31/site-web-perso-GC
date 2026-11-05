<?php

declare(strict_types=1);

namespace App\Tests\Support;

use App\Entity\Recruiter;
use App\Entity\SecurityEvent;
use App\Enum\NotificationChannel;
use App\Enum\RecruiterStatus;
use App\Enum\SecurityEventType;
use App\Repository\SiteSettingsRepository;
use App\Service\Recruiter\RemoteDecisionExecutor;
use App\Service\Recruiter\RemoteDecisionTokens;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Outils des tests de la validation à distance : interrupteur et code PIN, demande en attente, jetons émis,
 * lignes du journal de sécurité.
 *
 * Liens :
 * - Utilisé par : tests/Controller/TelegramWebhookControllerTest, RemoteDecisionControllerTest,
 *   tests/Service/NotificationDispatcherTest
 */
trait RemoteDecisionTestTrait
{
    /** Code PIN des tests (fictif). */
    private const TEST_PIN = '482913';

    /**
     * Active (ou désactive) la validation à distance, avec le code PIN de test si demandé.
     *
     * @param bool $withPin  vrai : code PIN TEST_PIN défini (boutons Telegram et liens Discord proposés)
     * @param bool $clearPin vrai : code PIN retiré
     */
    private function enableRemoteValidation(bool $enabled = true, bool $withPin = false, bool $clearPin = false): void
    {
        $settings = static::getContainer()->get(SiteSettingsRepository::class)->getOrCreate();
        $settings->setRemoteValidationEnabled($enabled);
        if ($withPin) {
            $settings->setRemotePinHash(RemoteDecisionExecutor::hashPin(self::TEST_PIN));
        }
        if ($clearPin) {
            $settings->setRemotePinHash(null);
        }
        $entityManager = static::getContainer()->get(EntityManagerInterface::class);
        $entityManager->persist($settings);
        $entityManager->flush();
    }

    /**
     * Crée une demande d'accès en attente.
     */
    private function createPendingRequest(string $email = 'rh@acme.test'): Recruiter
    {
        $entityManager = static::getContainer()->get(EntityManagerInterface::class);
        $recruiter = (new Recruiter(new \DateTimeImmutable('-1 hour')))->setEmail($email)->setFullName('Camille Martin')->setCompany('Acme')
            ->setJobTitle('Chargée de recrutement')->setMessage('Poste de responsable BE à Lyon.')->setStatus(RecruiterStatus::Pending);
        $entityManager->persist($recruiter);
        $entityManager->flush();

        return $recruiter;
    }

    /**
     * Émet les jetons « accepter » et « refuser » d'une demande (null : essai) sur un canal.
     *
     * @return array{approve: string, refuse: string}
     */
    private function issueTokens(?Recruiter $recruiter, NotificationChannel $channel): array
    {
        return static::getContainer()->get(RemoteDecisionTokens::class)->issue($recruiter, $channel);
    }

    /**
     * Relit l'état d'une demande en base.
     */
    private function reloadStatus(Recruiter $recruiter): RecruiterStatus
    {
        $entityManager = static::getContainer()->get(EntityManagerInterface::class);
        $entityManager->clear();

        return $entityManager->getRepository(Recruiter::class)->find($recruiter->getId())?->getStatus() ?? throw new \LogicException('Demande introuvable.');
    }

    /**
     * Renvoie les identifiants notés dans le journal de sécurité pour un type d'évènement.
     *
     * @return list<string|null>
     */
    private function securityUsernames(SecurityEventType $type): array
    {
        $events = static::getContainer()->get(EntityManagerInterface::class)->getRepository(SecurityEvent::class)->findBy(['type' => $type], ['id' => 'ASC']);

        return array_map(static fn (SecurityEvent $event): ?string => $event->getUsername(), $events);
    }
}
