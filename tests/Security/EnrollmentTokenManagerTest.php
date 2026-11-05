<?php

declare(strict_types=1);

namespace App\Tests\Security;

use App\Entity\User;
use App\Security\EnrollmentTokenManager;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\MockClock;

/**
 * Tests unitaires des jetons d'enrôlement (App\Security\EnrollmentTokenManager).
 */
final class EnrollmentTokenManagerTest extends TestCase
{
    /**
     * Vérifie que seule l'empreinte du jeton est stockée, avec une limite à 30 minutes.
     */
    public function testIssueStoresOnlyHashWithThirtyMinuteLimit(): void
    {
        $clock = new MockClock('2026-10-21 10:00:00');
        $user = new User('admin_test');

        $token = (new EnrollmentTokenManager($clock))->issue($user);

        self::assertMatchesRegularExpression('/^[A-Za-z0-9_-]{43}$/', $token);
        self::assertSame(hash('sha256', $token), $user->getEnrollmentTokenHash());
        self::assertNotSame($token, $user->getEnrollmentTokenHash());
        self::assertEquals(new \DateTimeImmutable('2026-10-21 10:30:00'), $user->getEnrollmentTokenExpiresAt());
    }

    /**
     * Vérifie qu'un jeton valable est accepté une seule fois.
     */
    public function testConsumeAcceptsValidTokenOnlyOnce(): void
    {
        $clock = new MockClock('2026-10-21 10:00:00');
        $manager = new EnrollmentTokenManager($clock);
        $user = new User('admin_test');
        $token = $manager->issue($user);

        $clock->sleep(29 * 60);
        self::assertNotNull($manager->consume($user, $token));
        self::assertNull($user->getEnrollmentTokenHash());
        self::assertNull($manager->consume($user, $token));
    }

    /**
     * Vérifie qu'un jeton expiré est refusé et effacé.
     */
    public function testConsumeRejectsExpiredToken(): void
    {
        $clock = new MockClock('2026-10-21 10:00:00');
        $manager = new EnrollmentTokenManager($clock);
        $user = new User('admin_test');
        $token = $manager->issue($user);

        $clock->sleep(30 * 60);
        self::assertNull($manager->consume($user, $token));
        self::assertNull($user->getEnrollmentTokenHash());
    }

    /**
     * Vérifie qu'un mauvais jeton est refusé sans effacer le bon (pas de déni de service).
     */
    public function testConsumeRejectsWrongTokenAndKeepsTheRealOne(): void
    {
        $manager = new EnrollmentTokenManager(new MockClock('2026-10-21 10:00:00'));
        $user = new User('admin_test');
        $token = $manager->issue($user);

        self::assertNull($manager->consume($user, 'un-autre-jeton-de-quarante-trois-caracteres'));
        self::assertNull($manager->consume($user, ''));
        self::assertNotNull($manager->consume($user, $token));
    }

    /**
     * Vérifie qu'un nouveau jeton remplace l'ancien.
     */
    public function testNewTokenReplacesPreviousOne(): void
    {
        $manager = new EnrollmentTokenManager(new MockClock('2026-10-21 10:00:00'));
        $user = new User('admin_test');
        $first = $manager->issue($user);
        $second = $manager->issue($user);

        self::assertNull($manager->consume($user, $first));
        self::assertNotNull($manager->consume($user, $second));
    }
}
