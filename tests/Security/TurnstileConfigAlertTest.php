<?php

declare(strict_types=1);

namespace App\Tests\Security;

use App\Entity\SecurityEvent;
use App\Enum\SecurityEventType;
use App\Security\TurnstileVerifier;
use App\Service\Dashboard\DashboardMetrics;
use App\Tests\Support\MockHttpResponseFactory;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * Alerte « Turnstile mal configuré » (v2.0.3, après la suppression du widget par erreur le 05/11/2026) : seules les
 * erreurs de CONFIGURATION sont notées (clé secrète refusée côté serveur, clé de site invalide ou nom d'hôte non
 * autorisé côté navigateur), jamais les refus normaux des robots ; tuile « Turnstile » du tableau de bord ;
 * notification « Général du site » par app:communication:alerts, une fois par jour au plus.
 *
 * Liens :
 * - Couvre : Security\TurnstileVerifier, Service\Dashboard\DashboardMetrics::turnstile(),
 *   Command\CommunicationAlertsCommand (NotificationEvent::TurnstileAlert)
 */
final class TurnstileConfigAlertTest extends WebTestCase
{
    /** Valeurs valides du formulaire de contact (personne fictive). */
    private const VALUES = [
        'contact[name]' => 'Camille Exemple', 'contact[email]' => 'camille@acme.test', 'contact[company]' => 'Acme',
        'contact[subject]' => 'technical', 'contact[message]' => 'Bonjour, une question sur le dimensionnement d\'une chambre froide.', 'contact[consent]' => '1',
    ];

    /**
     * Remet les réponses simulées à zéro.
     */
    protected function setUp(): void
    {
        MockHttpResponseFactory::reset();
    }

    /**
     * Clé secrète refusée par Cloudflare (invalid-input-secret) : erreur de configuration notée, jeton refusé.
     */
    public function testServerSecretErrorIsRecorded(): void
    {
        $client = self::createClient();
        $this->sendContact($client, MockHttpResponseFactory::BAD_SECRET_TOKEN);

        self::assertResponseStatusCodeSame(422);
        self::assertSame(['serveur invalid-input-secret'], $this->configErrors());
    }

    /**
     * Refus normaux (jeton faux, expiré, absent sans code) : rien n'est noté.
     */
    public function testNormalRefusalsAreNotRecorded(): void
    {
        $client = self::createClient();
        $this->sendContact($client, MockHttpResponseFactory::INVALID_TOKEN);
        $this->sendContact($client, MockHttpResponseFactory::DUPLICATE_TOKEN);
        $this->sendContact($client, '');

        self::assertSame([], $this->configErrors());
    }

    /**
     * Code d'erreur du widget posté par le navigateur : seuls les codes de configuration sont notés.
     *
     * @param string      $code     code posté dans turnstile_error
     * @param string|null $expected identifiant noté, ou null si rien n'est noté
     */
    #[DataProvider('provideClientCodes')]
    public function testClientCodes(string $code, ?string $expected): void
    {
        $client = self::createClient();
        $this->sendContact($client, '', [TurnstileVerifier::CLIENT_ERROR_FIELD => $code]);

        self::assertSame(null === $expected ? [] : [$expected], $this->configErrors());
    }

    /**
     * Codes du widget : clé de site invalide, nom d'hôte non autorisé, clé désactivée (configuration) ; défi échoué,
     * délai dépassé, valeur farfelue (pas de configuration).
     *
     * @return iterable<string, array{string, string|null}>
     */
    public static function provideClientCodes(): iterable
    {
        yield 'clé de site invalide' => ['110100', 'navigateur 110100'];
        yield 'nom d\'hôte non autorisé' => ['110200', 'navigateur 110200'];
        yield 'clé de site désactivée' => ['400070', 'navigateur 400070'];
        yield 'défi échoué (robot)' => ['600010', null];
        yield 'délai dépassé' => ['300030', null];
        yield 'valeur farfelue' => ['<script>', null];
    }

    /**
     * Tuile « Turnstile » : à surveiller dès une erreur, en problème à partir de 3 erreurs en 24 h ; puis alerte
     * « Général du site » envoyée une seule fois par jour.
     */
    public function testTileAndAlert(): void
    {
        self::bootKernel();
        $metrics = self::getContainer()->get(DashboardMetrics::class);
        self::assertSame(DashboardMetrics::OK, $this->tile($metrics)['status']);

        $this->recordErrors(1);
        self::assertSame(DashboardMetrics::WARNING, $this->tile($metrics)['status']);

        $this->recordErrors(2);
        $tile = $this->tile($metrics);
        self::assertSame(DashboardMetrics::PROBLEM, $tile['status']);
        self::assertSame('3', $tile['value']);

        $tester = new CommandTester((new Application(self::$kernel))->find('app:communication:alerts'));
        $tester->execute(['--send' => true]);
        // D'autres tuiles de la base de test peuvent aussi être en alerte : seule celle de Turnstile est vérifiée
        self::assertStringNotContainsString('turnstile_alert : déjà signalé', $tester->getDisplay());
        $sent = implode("\n", array_map(static fn (array $r): string => $r['body'], MockHttpResponseFactory::requestsTo('discord')));
        self::assertStringContainsString('Turnstile', $sent);

        $tester->execute(['--send' => true]);
        self::assertStringContainsString('turnstile_alert : déjà signalé', $tester->getDisplay());
    }

    /**
     * Ouvre le formulaire de contact et l'envoie avec un jeton donné (champs supplémentaires possibles).
     *
     * @param KernelBrowser         $client client de test
     * @param string                $token  jeton Turnstile posté (vide : aucun jeton)
     * @param array<string, string> $extra  champs supplémentaires (ex. code d'erreur du widget)
     */
    private function sendContact(KernelBrowser $client, string $token, array $extra = []): void
    {
        $crawler = $client->request('GET', '/contact');
        $parameters = $crawler->selectButton('Envoyer le message')->form(self::VALUES)->getPhpValues();
        $parameters['cf-turnstile-response'] = $token;
        $client->request('POST', '/contact', $parameters + $extra, [], ['HTTP_ORIGIN' => 'http://localhost']);
    }

    /**
     * Identifiants des erreurs de configuration notées au journal de sécurité.
     *
     * @return list<string>
     */
    private function configErrors(): array
    {
        $events = self::getContainer()->get(EntityManagerInterface::class)->getRepository(SecurityEvent::class)->findBy(['type' => SecurityEventType::TurnstileConfigError]);

        return array_map(static fn (SecurityEvent $event): string => (string) $event->getUsername(), $events);
    }

    /**
     * Note des erreurs de configuration au journal (comme TurnstileVerifier).
     *
     * @param int $count nombre d'erreurs
     */
    private function recordErrors(int $count): void
    {
        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        for ($i = 0; $i < $count; ++$i) {
            $entityManager->persist(new SecurityEvent(SecurityEventType::TurnstileConfigError, 'serveur invalid-input-secret', null, null));
        }
        $entityManager->flush();
    }

    /**
     * Renvoie la tuile « Turnstile », sans cache.
     *
     * @param DashboardMetrics $metrics indicateurs du tableau de bord
     *
     * @return array<string, mixed>
     */
    private function tile(DashboardMetrics $metrics): array
    {
        return array_column($metrics->getTiles(true)['tiles'], null, 'id')['turnstile'];
    }
}
