<?php

declare(strict_types=1);

namespace App\Security;

use App\Entity\SecurityEvent;
use App\Enum\SecurityEventType;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * Écrit une ligne dans le journal des connexions à l'admin (table security_event), ainsi que les limites
 * de débit atteintes (connexion, clé, formulaires publics, assistant : tuile « Sécurité » du tableau de bord).
 *
 * Les échecs sont aussi envoyés au journal applicatif (niveau warning). Aucun mot de
 * passe ni jeton n'est jamais transmis à ce service.
 *
 * Liens :
 * - Utilise : SecurityEvent, EntityManagerInterface, RequestStack (IP et navigateur)
 * - Utilisé par : AdminLoginSubscriber, AdminSessionLifetimeSubscriber, SecondFactorController, SecurityKeyController,
 *   ContactController, RecruiterAreaController (limites atteintes)
 */
final readonly class SecurityEventLogger
{
    /**
     * Reçoit les dépendances.
     *
     * @param EntityManagerInterface $entityManager gestionnaire Doctrine
     * @param RequestStack           $requestStack  requête courante (IP, navigateur)
     * @param LoggerInterface        $logger        journal applicatif
     */
    public function __construct(
        private EntityManagerInterface $entityManager,
        private RequestStack $requestStack,
        private LoggerInterface $logger,
    ) {
    }

    /**
     * Enregistre un évènement de sécurité et l'écrit immédiatement en base.
     *
     * Liens :
     * - Appelée par : AdminLoginSubscriber, AdminSessionLifetimeSubscriber, SecondFactorController, SecurityKeyController
     *
     * @param SecurityEventType $type     type d'évènement
     * @param string|null       $username identifiant concerné (saisi ou connecté)
     */
    public function log(SecurityEventType $type, ?string $username): void
    {
        $request = $this->requestStack->getCurrentRequest();
        $event = new SecurityEvent(
            $type,
            $username,
            $request?->getClientIp(),
            $request?->headers->get('User-Agent'),
        );

        $this->entityManager->persist($event);
        $this->entityManager->flush();

        if ($type->isFailure()) {
            $this->logger->warning('Échec de sécurité sur l\'administration', [
                'type' => $type->value,
                'username' => $username,
                'ip' => $event->getIpAddress(),
            ]);
        }
    }
}
