<?php

declare(strict_types=1);

namespace App\Tests\Security;

use App\Entity\SecurityEvent;
use App\Entity\User;
use App\Enum\SecurityEventType;
use App\Security\EnrollmentTokenManager;
use App\Tests\Support\AdminTestTrait;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Clock\Test\ClockSensitiveTrait;
use Symfony\Component\Routing\RouterInterface;

/**
 * Prouve que la double authentification de l'admin ne se contourne pas.
 *
 * Règle testée : le mot de passe seul (ROLE_ADMIN) ne donne accès à aucune route
 * d'administration, sauf l'étape YubiKey et l'enrôlement avec un lien valide.
 * Seul le marqueur de session « clé vérifiée » ouvre l'admin.
 *
 * Liens :
 * - Couvre : SecondFactorVoter, SecondFactorSession, SecondFactorController,
 *   AdminAccessDeniedHandler, AdminLoginSubscriber, EnrollmentTokenManager, security.yaml
 */
final class AdminSecondFactorTest extends WebTestCase
{
    use AdminTestTrait;
    use ClockSensitiveTrait;

    /**
     * Routes accessibles avec le seul mot de passe (étape YubiKey et enrôlement).
     * Toute autre route admin doit refuser l'accès sans clé vérifiée.
     */
    private const PASSWORD_ONLY_ROUTES = [
        'admin_login',
        'admin_logout',
        'admin_second_factor',
        'admin_second_factor_assertion_options',
        'admin_second_factor_assertion_verify',
        'admin_second_factor_enroll_start',
        'admin_second_factor_enroll',
        'admin_second_factor_registration_options',
        'admin_second_factor_registration_verify',
    ];

    /**
     * Vérifie qu'un visiteur anonyme est renvoyé vers la connexion depuis l'admin.
     */
    public function testAnonymousVisitorIsRedirectedToLogin(): void
    {
        $client = self::createClient();
        $client->request('GET', $this->adminUrl());

        self::assertResponseRedirects('http://localhost'.$this->adminUrl('/connexion'));
    }

    /**
     * Vérifie qu'un mot de passe correct mène à l'étape YubiKey, pas au tableau de bord.
     */
    public function testCorrectPasswordLeadsToSecurityKeyStep(): void
    {
        $client = self::createClient();
        $this->createAdmin();
        $this->loginWithPassword($client);

        self::assertResponseRedirects($this->adminUrl('/securite/cle'));
        $client->followRedirect();
        self::assertResponseIsSuccessful();
        self::assertSelectorExists('[data-controller="webauthn"]');
        self::assertSame(1, $this->countEvents(SecurityEventType::PasswordSuccess));
    }

    /**
     * Vérifie qu'un mauvais mot de passe est refusé et journalisé (jamais le mot de passe lui-même).
     */
    public function testWrongPasswordIsRejectedAndLogged(): void
    {
        $client = self::createClient();
        $this->createAdmin();
        $this->loginWithPassword($client, 'admin_test', 'mauvais-mot-de-passe');

        self::assertResponseRedirects('http://localhost'.$this->adminUrl('/connexion'));
        $client->followRedirect();
        self::assertSelectorExists('[role="alert"]');
        self::assertSame(1, $this->countEvents(SecurityEventType::PasswordFailure));
    }

    /**
     * Vérifie que le formulaire de connexion est refusé sans preuve d'origine (CSRF).
     */
    public function testLoginWithoutOriginIsRejected(): void
    {
        $client = self::createClient();
        $this->createAdmin();
        $client->request('POST', $this->adminUrl('/connexion'), ['_username' => 'admin_test', '_password' => self::TEST_PASSWORD, '_csrf_token' => 'csrf-token']);

        self::assertResponseRedirects('http://localhost'.$this->adminUrl('/connexion'));
        $client->request('GET', $this->adminUrl());
        self::assertResponseRedirects('http://localhost'.$this->adminUrl('/connexion'));
    }

    /**
     * Vérifie, route par route, qu'avec le bon mot de passe mais sans clé vérifiée,
     * aucune route d'administration ne répond avec succès.
     */
    public function testPasswordAloneGivesAccessToNoAdminRoute(): void
    {
        $client = self::createClient();
        $user = $this->createAdmin();
        $this->loginWithPassword($client);
        $checked = 0;

        foreach ($this->getProtectedAdminRoutes() as $name => [$path, $methods]) {
            $method = \in_array('GET', $methods, true) || [] === $methods ? 'GET' : 'POST';
            $client->request($method, $path, [], [], ['HTTP_ORIGIN' => 'http://localhost']);
            $status = $client->getResponse()->getStatusCode();

            self::assertFalse($client->getResponse()->isSuccessful(), \sprintf('La route %s (%s %s) répond %d sans clé vérifiée.', $name, $method, $path, $status));
            if ($client->getResponse()->isRedirect()) {
                self::assertStringEndsWith($this->adminUrl('/securite/cle'), (string) $client->getResponse()->headers->get('Location'), 'Route '.$name);
            }
            ++$checked;
        }

        self::assertGreaterThan(30, $checked, 'Le test doit couvrir toutes les routes d\'administration.');
        self::assertNotNull($user->getId());
    }

    /**
     * Vérifie que, avec la clé vérifiée, les écrans d'administration s'ouvrent.
     */
    public function testVerifiedKeyGivesAccessToAdmin(): void
    {
        $client = self::createClient();
        $this->loginFullyAsAdmin($client);

        foreach (['', '/profil', '/experiences', '/competences', '/piliers', '/formations', '/certifications', '/langues', '/chiffres-cles', '/securite/mes-cles', '/securite/journal'] as $suffix) {
            $client->request('GET', $this->adminUrl($suffix));
            self::assertResponseIsSuccessful('Écran '.$suffix);
        }
    }

    /**
     * Vérifie qu'un marqueur posé avant une réinitialisation par SSH n'ouvre plus l'admin.
     */
    public function testSecurityVersionChangeInvalidatesVerifiedMarker(): void
    {
        $client = self::createClient();
        $user = $this->loginFullyAsAdmin($client);

        $managedUser = $this->getEntityManager()->find(User::class, $user->getId());
        self::assertNotNull($managedUser);
        $managedUser->incrementSecurityVersion();
        $this->getEntityManager()->flush();

        $client->request('GET', $this->adminUrl());
        self::assertResponseRedirects($this->adminUrl('/securite/cle'));
    }

    /**
     * Vérifie qu'avec le mot de passe seul, l'enregistrement d'une clé est refusé
     * (même avec un jeton CSRF valide) : il faut un lien d'enrôlement.
     */
    public function testPasswordAloneCannotRegisterKey(): void
    {
        $client = self::createClient();
        $this->createAdmin(withKey: true);
        $this->loginWithPassword($client);
        $csrfToken = $this->readCsrfTokenFromVerifyPage($client);

        $this->postJson($client, '/securite/enrolement/options', $csrfToken);
        self::assertResponseStatusCodeSame(403);

        $client->request('GET', $this->adminUrl('/securite/enrolement'));
        self::assertResponseRedirects($this->adminUrl('/securite/cle'));
    }

    /**
     * Vérifie le parcours d'enrôlement : lien valide, page d'enregistrement, options WebAuthn,
     * et l'admin reste fermé tant qu'aucune clé n'est enregistrée.
     */
    public function testValidEnrollmentLinkOpensKeyRegistrationOnly(): void
    {
        $client = self::createClient();
        $user = $this->createAdmin(withKey: false);
        $token = $this->issueEnrollmentToken($user);
        $this->loginWithPassword($client);

        $client->request('GET', $this->adminUrl('/securite/enrolement/'.$token));
        self::assertResponseRedirects($this->adminUrl('/securite/enrolement'));
        $crawler = $client->followRedirect();
        self::assertResponseIsSuccessful();

        $csrfToken = (string) $crawler->filter('[data-controller="webauthn"]')->attr('data-webauthn-csrf-token-value');
        $this->postJson($client, '/securite/enrolement/options', $csrfToken);
        self::assertResponseIsSuccessful();
        $options = json_decode((string) $client->getResponse()->getContent(), true);
        self::assertIsArray($options);
        self::assertSame('ok', $options['status']);
        self::assertArrayHasKey('challenge', $options);

        // L'enrôlement n'est pas une vérification : le tableau de bord reste fermé.
        $client->request('GET', $this->adminUrl());
        self::assertResponseRedirects($this->adminUrl('/securite/cle'));
        self::assertSame(1, $this->countEvents(SecurityEventType::EnrollmentLinkUsed));
    }

    /**
     * Vérifie qu'un lien d'enrôlement ne sert qu'une fois et que l'empreinte est effacée.
     */
    public function testEnrollmentLinkIsSingleUse(): void
    {
        $client = self::createClient();
        $user = $this->createAdmin(withKey: false);
        $token = $this->issueEnrollmentToken($user);
        $this->loginWithPassword($client);

        $client->request('GET', $this->adminUrl('/securite/enrolement/'.$token));
        self::assertResponseRedirects($this->adminUrl('/securite/enrolement'));

        $client->request('GET', $this->adminUrl('/securite/enrolement/'.$token));
        self::assertResponseStatusCodeSame(403);

        $this->getEntityManager()->clear();
        $reloaded = $this->getEntityManager()->find(User::class, $user->getId());
        self::assertNull($reloaded?->getEnrollmentTokenHash());
    }

    /**
     * Vérifie qu'un lien d'enrôlement expiré (plus de 30 minutes) est refusé.
     */
    public function testExpiredEnrollmentLinkIsRejected(): void
    {
        self::mockTime('2026-10-21 10:00:00');
        $client = self::createClient();
        $user = $this->createAdmin(withKey: false);
        $token = $this->issueEnrollmentToken($user);

        self::mockTime('2026-10-21 10:31:00');
        $this->loginWithPassword($client);
        $client->request('GET', $this->adminUrl('/securite/enrolement/'.$token));

        self::assertResponseStatusCodeSame(403);
        self::assertSame(1, $this->countEvents(SecurityEventType::EnrollmentLinkRejected));
    }

    /**
     * Vérifie qu'un lien d'enrôlement ne fonctionne pas pour un autre compte.
     */
    public function testEnrollmentLinkOfAnotherAccountIsRejected(): void
    {
        $client = self::createClient();
        $owner = $this->createAdmin('owner_account', withKey: false);
        $token = $this->issueEnrollmentToken($owner);
        $this->createAdmin('other_account', withKey: false);
        $this->loginWithPassword($client, 'other_account');

        $client->request('GET', $this->adminUrl('/securite/enrolement/'.$token));
        self::assertResponseStatusCodeSame(403);
    }

    /**
     * Vérifie que le lien d'enrôlement exige d'être connecté : sinon, connexion d'abord,
     * puis retour automatique au lien.
     */
    public function testEnrollmentLinkRequiresPasswordLoginFirst(): void
    {
        $client = self::createClient();
        $user = $this->createAdmin(withKey: false);
        $token = $this->issueEnrollmentToken($user);

        $client->request('GET', $this->adminUrl('/securite/enrolement/'.$token));
        self::assertResponseRedirects('http://localhost'.$this->adminUrl('/connexion'));

        $this->loginWithPassword($client);
        self::assertResponseRedirects($this->adminUrl('/securite/enrolement/'.$token));
    }

    /**
     * Vérifie que la vérification d'une fausse réponse de clé échoue (401) et est journalisée.
     */
    public function testForgedAssertionIsRejected(): void
    {
        $client = self::createClient();
        $this->createAdmin(withKey: true);
        $this->loginWithPassword($client);
        $csrfToken = $this->readCsrfTokenFromVerifyPage($client);

        $this->postJson($client, '/securite/cle/options', $csrfToken);
        self::assertResponseIsSuccessful();

        $this->postJson($client, '/securite/cle/verification', $csrfToken, ['id' => 'AAAA', 'rawId' => 'AAAA', 'type' => 'public-key', 'response' => []]);
        self::assertResponseStatusCodeSame(401);
        self::assertSame(1, $this->countEvents(SecurityEventType::KeyFailure));

        $client->request('GET', $this->adminUrl());
        self::assertResponseRedirects($this->adminUrl('/securite/cle'));
    }

    /**
     * Vérifie que les appels JSON de l'étape YubiKey exigent le jeton CSRF.
     */
    public function testSecondFactorJsonEndpointsRequireCsrfToken(): void
    {
        $client = self::createClient();
        $this->createAdmin(withKey: true);
        $this->loginWithPassword($client);

        $this->postJson($client, '/securite/cle/options', 'jeton-invalide');
        self::assertResponseStatusCodeSame(403);
    }

    /**
     * Vérifie la limite de l'étape YubiKey : 10 tentatives acceptées, la 11e refusée.
     */
    public function testSecondFactorRateLimitAllowsTenAttempts(): void
    {
        self::bootKernel();
        $factory = static::getContainer()->get('limiter.admin_second_factor');
        self::assertInstanceOf(\Symfony\Component\RateLimiter\RateLimiterFactoryInterface::class, $factory);
        $limiter = $factory->create('admin_test|127.0.0.1');

        for ($attempt = 1; $attempt <= 10; ++$attempt) {
            self::assertTrue($limiter->consume()->isAccepted(), 'Tentative '.$attempt);
        }
        self::assertFalse($limiter->consume()->isAccepted());
    }

    /**
     * Liste les routes d'administration qui doivent exiger la clé vérifiée, avec une URL d'exemple.
     *
     * @return array<string, array{0: string, 1: list<string>}> nom => [chemin, méthodes]
     */
    private function getProtectedAdminRoutes(): array
    {
        $router = static::getContainer()->get(RouterInterface::class);
        $routes = [];

        foreach ($router->getRouteCollection()->all() as $name => $route) {
            if (!str_starts_with($name, 'admin_') || \in_array($name, self::PASSWORD_ONLY_ROUTES, true)) {
                continue;
            }

            $parameters = [];
            foreach ($route->compile()->getPathVariables() as $variable) {
                $parameters[$variable] = match ($variable) {
                    'direction' => 'haut',
                    'id' => str_contains($name, 'security_key') ? '01JAAAAAAAAAAAAAAAAAAAAAAA' : '1',
                    default => 'test',
                };
            }

            /** @var list<string> $methods */
            $methods = $route->getMethods();
            $routes[$name] = [$router->generate($name, $parameters), $methods];
        }

        return $routes;
    }

    /**
     * Ouvre l'étape YubiKey et lit le jeton CSRF des appels JSON.
     */
    private function readCsrfTokenFromVerifyPage(KernelBrowser $client): string
    {
        $crawler = $client->request('GET', $this->adminUrl('/securite/cle'));
        self::assertResponseIsSuccessful();

        return (string) $crawler->filter('[data-controller="webauthn"]')->attr('data-webauthn-csrf-token-value');
    }

    /**
     * Envoie un appel JSON à l'étape YubiKey.
     *
     * @param array<string, mixed> $body corps de la requête
     */
    private function postJson(KernelBrowser $client, string $suffix, string $csrfToken, array $body = []): void
    {
        $client->request('POST', $this->adminUrl($suffix), [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_ACCEPT' => 'application/json',
            'HTTP_X_CSRF_TOKEN' => $csrfToken,
            'HTTP_ORIGIN' => 'http://localhost',
        ], (string) json_encode($body));
    }

    /**
     * Crée un jeton d'enrôlement pour un compte (comme la commande console).
     */
    private function issueEnrollmentToken(User $user): string
    {
        $entityManager = $this->getEntityManager();
        $managedUser = $entityManager->find(User::class, $user->getId());
        self::assertNotNull($managedUser);
        $token = static::getContainer()->get(EnrollmentTokenManager::class)->issue($managedUser);
        $entityManager->flush();

        return $token;
    }

    /**
     * Compte les lignes du journal d'un type donné.
     */
    private function countEvents(SecurityEventType $type): int
    {
        return $this->getEntityManager()->getRepository(SecurityEvent::class)->count(['type' => $type]);
    }
}
