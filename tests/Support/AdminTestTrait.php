<?php

declare(strict_types=1);

namespace App\Tests\Support;

use App\Entity\User;
use App\Entity\WebauthnCredential;
use App\Security\SecondFactorSession;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Component\BrowserKit\Cookie;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;
use Symfony\Component\Uid\Uuid;
use Webauthn\TrustPath\EmptyTrustPath;

/**
 * Outils des tests de l'administration : comptes, clés factices, connexion réelle.
 *
 * La connexion passe par le vrai formulaire (pare-feu, AdminLoginSubscriber) et le
 * marqueur « clé vérifiée » par le vrai service (SecondFactorSession::markVerified()) :
 * les tests utilisent les mêmes chemins que la production.
 *
 * Liens :
 * - Utilisé par : tests/Security/AdminSecondFactorTest, tests/Controller/Admin/*
 */
trait AdminTestTrait
{
    /** Mot de passe des comptes de test (jamais utilisé hors des tests). */
    private const TEST_PASSWORD = 'Test-Password-For-Phpunit-42';

    /**
     * Crée et enregistre un compte admin de test.
     *
     * @param string $username identifiant de connexion
     * @param bool   $withKey  vrai pour lui associer une clé YubiKey factice
     *
     * @return User le compte enregistré
     */
    private function createAdmin(string $username = 'admin_test', bool $withKey = true): User
    {
        $container = static::getContainer();
        $entityManager = $container->get(EntityManagerInterface::class);
        $hasher = $container->get(UserPasswordHasherInterface::class);

        $user = (new User($username))->setRoles(['ROLE_ADMIN'])->setNotificationEmail('notifications@example.test');
        $user->setPassword($hasher->hashPassword($user, self::TEST_PASSWORD));
        $entityManager->persist($user);

        if ($withKey) {
            $entityManager->persist($this->createFakeCredential($user));
        }

        $entityManager->flush();

        return $user;
    }

    /**
     * Construit une clé factice (elle ne peut signer aucun défi : seul le nombre de clés compte).
     *
     * @param User $user compte propriétaire
     */
    private function createFakeCredential(User $user, string $label = 'Clé de test'): WebauthnCredential
    {
        return WebauthnCredential::fromCredentialRecord(
            new WebauthnCredential(
                random_bytes(32),
                'public-key',
                ['usb'],
                'none',
                EmptyTrustPath::create(),
                Uuid::fromString('00000000-0000-0000-0000-000000000000'),
                random_bytes(77),
                $user->getWebauthnUserHandle(),
                0,
            ),
            $label,
        );
    }

    /**
     * Se connecte avec le vrai formulaire (identifiant + mot de passe), comme un navigateur.
     *
     * L'en-tête Origin est envoyé, comme le fait tout navigateur sur un POST : c'est lui
     * qui valide le jeton CSRF « sans état » du formulaire.
     *
     * @param KernelBrowser $client   client de test
     * @param string        $username identifiant
     * @param string        $password mot de passe (celui de test par défaut)
     */
    private function loginWithPassword(KernelBrowser $client, string $username = 'admin_test', string $password = self::TEST_PASSWORD): void
    {
        $client->request('POST', $this->adminUrl('/connexion'), [
            '_username' => $username,
            '_password' => $password,
            '_csrf_token' => 'csrf-token',
        ], [], ['HTTP_ORIGIN' => 'http://localhost']);
    }

    /**
     * Pose le marqueur « clé vérifiée » dans la session du client, via le vrai service.
     *
     * À appeler après loginWithPassword(). Le service change l'identifiant de session
     * (comme en production) : le cookie du client est mis à jour.
     *
     * @param KernelBrowser $client client de test déjà connecté par mot de passe
     * @param User          $user   compte connecté
     */
    private function markKeyVerified(KernelBrowser $client, User $user): void
    {
        $container = static::getContainer();
        $sessionFactory = $container->get('session.factory');
        $session = $sessionFactory->createSession();
        $cookie = $client->getCookieJar()->get($session->getName());
        self::assertNotNull($cookie, 'Le client doit être connecté (cookie de session absent).');

        $session->setId($cookie->getValue());
        $session->start();

        $request = new Request();
        $request->setSession($session);
        $requestStack = $container->get(RequestStack::class);
        $requestStack->push($request);
        $container->get(SecondFactorSession::class)->markVerified($user);
        $requestStack->pop();
        $session->save();

        // Remplace l'ancien cookie (même domaine et même chemin), sinon il resterait prioritaire.
        $client->getCookieJar()->expire($session->getName(), $cookie->getPath(), $cookie->getDomain());
        $client->getCookieJar()->set(new Cookie($session->getName(), $session->getId(), null, $cookie->getPath(), $cookie->getDomain()));
    }

    /**
     * Crée un jeton CSRF valable dans la session du client (pour fabriquer une requête
     * qu'aucun bouton de la page ne propose, ex. supprimer la dernière clé).
     *
     * @param KernelBrowser $client  client de test déjà connecté
     * @param string        $tokenId identifiant du jeton (ex. « delete_security_key_<id> »)
     *
     * @return string la valeur du jeton
     */
    private function generateCsrfToken(KernelBrowser $client, string $tokenId): string
    {
        $container = static::getContainer();
        $sessionFactory = $container->get('session.factory');
        $session = $sessionFactory->createSession();
        $cookie = $client->getCookieJar()->get($session->getName());
        self::assertNotNull($cookie, 'Le client doit être connecté (cookie de session absent).');
        $session->setId($cookie->getValue());
        $session->start();

        $request = new Request();
        $request->setSession($session);
        $requestStack = $container->get(RequestStack::class);
        $requestStack->push($request);
        $token = $container->get(CsrfTokenManagerInterface::class)->getToken($tokenId)->getValue();
        $requestStack->pop();
        $session->save();

        return $token;
    }

    /**
     * Raccourci : compte avec clé, connexion par mot de passe et clé vérifiée.
     *
     * @param KernelBrowser $client client de test
     *
     * @return User le compte connecté
     */
    private function loginFullyAsAdmin(KernelBrowser $client): User
    {
        $user = $this->createAdmin();
        $this->loginWithPassword($client);
        $this->markKeyVerified($client, $user);

        return $user;
    }

    /**
     * Construit une URL de l'administration à partir du paramètre app.admin_path.
     *
     * @param string $suffix suite du chemin, ex. « /connexion »
     *
     * @return string URL relative, ex. « /admin/connexion »
     */
    private function adminUrl(string $suffix = ''): string
    {
        return '/'.static::getContainer()->getParameter('app.admin_path').$suffix;
    }

    /**
     * Renvoie le gestionnaire Doctrine du conteneur de test.
     */
    private function getEntityManager(): EntityManagerInterface
    {
        return static::getContainer()->get(EntityManagerInterface::class);
    }
}
