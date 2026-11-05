<?php

declare(strict_types=1);

namespace App\Tests\Security;

use App\Entity\SecurityEvent;
use App\Enum\SecurityEventType;
use App\Tests\Support\AdminTestTrait;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Clock\Test\ClockSensitiveTrait;

/**
 * Tests des durées maximales de la session admin : 1 heure d'inactivité, 8 heures au total.
 *
 * Liens :
 * - Couvre : AdminSessionLifetimeSubscriber, SecondFactorSession (horodatage)
 */
final class AdminSessionLifetimeTest extends WebTestCase
{
    use AdminTestTrait;
    use ClockSensitiveTrait;

    /**
     * Vérifie qu'une session active depuis moins d'une heure reste ouverte.
     */
    public function testSessionStaysOpenWithinIdleLimit(): void
    {
        self::mockTime('2026-10-21 09:00:00');
        $client = self::createClient();
        $this->loginFullyAsAdmin($client);

        self::mockTime('2026-10-21 09:59:00');
        $client->request('GET', $this->adminUrl());

        self::assertResponseIsSuccessful();
    }

    /**
     * Vérifie qu'après plus d'une heure sans activité, la session est fermée et journalisée.
     */
    public function testSessionExpiresAfterOneHourOfInactivity(): void
    {
        self::mockTime('2026-10-21 09:00:00');
        $client = self::createClient();
        $this->loginFullyAsAdmin($client);

        self::mockTime('2026-10-21 10:01:00');
        $client->request('GET', $this->adminUrl());

        self::assertResponseRedirects($this->adminUrl('/connexion'));
        $this->assertSessionExpiredLogged();

        // La session détruite ne permet plus rien, même en revenant tout de suite.
        $client->request('GET', $this->adminUrl());
        self::assertResponseRedirects('http://localhost'.$this->adminUrl('/connexion'));
    }

    /**
     * Vérifie qu'une session active en permanence est quand même fermée au bout de 8 heures.
     */
    public function testSessionExpiresAfterEightHoursEvenWhenActive(): void
    {
        self::mockTime('2026-10-21 08:00:00');
        $client = self::createClient();
        $this->loginFullyAsAdmin($client);

        // Une requête toutes les 50 minutes : jamais 1 heure d'inactivité.
        for ($minutes = 50; $minutes <= 450; $minutes += 50) {
            self::mockTime((new \DateTimeImmutable('2026-10-21 08:00:00'))->modify(\sprintf('+%d minutes', $minutes)));
            $client->request('GET', $this->adminUrl());
            self::assertResponseIsSuccessful(\sprintf('Après %d minutes', $minutes));
        }

        self::mockTime('2026-10-21 16:01:00');
        $client->request('GET', $this->adminUrl());

        self::assertResponseRedirects($this->adminUrl('/connexion'));
        $this->assertSessionExpiredLogged();
    }

    /**
     * Vérifie qu'une ligne « session expirée » a été écrite dans le journal.
     */
    private function assertSessionExpiredLogged(): void
    {
        self::assertSame(1, $this->getEntityManager()->getRepository(SecurityEvent::class)->count(['type' => SecurityEventType::SessionExpired]));
    }
}
