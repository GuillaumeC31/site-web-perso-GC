<?php

declare(strict_types=1);

namespace App\Command;

use App\Entity\Recruiter;
use App\Entity\User;
use App\Enum\RecruiterStatus;
use App\Security\SecondFactorSession;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Clock\ClockInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\DependencyInjection\Attribute\When;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Session\SessionFactoryInterface;
use Symfony\Component\HttpFoundation\Session\SessionInterface;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;
use Symfony\Component\Security\Http\LoginLink\LoginLinkHandlerInterface;

/**
 * Sessions de test des pages privées pour l'audit d'accessibilité de Playwright (lot 8).
 * Commande enregistrée SEULEMENT en développement (#[When('dev')]) : elle n'existe pas en production.
 *
 * - prepare : crée (ou reprend) un compte admin de test SANS clé YubiKey (« e2e_axe », mot de passe
 *   aléatoire jamais affiché) et ouvre sa session avec le marqueur « clé vérifiée » posé par le vrai service
 *   (comme tests/Support/AdminTestTrait) ; crée un recruteur de test validé et donne un vrai lien de
 *   connexion (signé, à usage unique). Écrit le résultat en JSON.
 * - admin-session : ouvre une session admin de plus (identifiant affiché), pour un test qui lit des messages
 *   flash sans les partager avec les autres tests ;
 * - cleanup : supprime ces deux comptes, les échanges du recruteur avec l'assistant et la session admin.
 *
 * Usage : php bin/console app:e2e:private-sessions prepare --base-url=https://localhost:8000
 *         php bin/console app:e2e:private-sessions cleanup
 *
 * Liens :
 * - Appelée par : tests/e2e/support/global-setup.js et global-teardown.js
 * - Utilise : SecondFactorSession, LoginLinkHandlerInterface (pare-feu « recruiter »), session.factory
 */
#[When(env: 'dev')]
#[AsCommand(name: 'app:e2e:private-sessions', description: 'Développement : sessions de test des pages privées pour l\'audit d\'accessibilité (prepare / cleanup)')]
final class E2ePrivateSessionsCommand extends Command
{
    /** Identifiant du compte admin de test. */
    public const ADMIN_USERNAME = 'e2e_axe';

    /** Adresse du recruteur de test. */
    public const RECRUITER_EMAIL = 'e2e.axe@example.test';

    /**
     * Reçoit les dépendances.
     *
     * @param string $projectDir dossier du projet (fichier de la session admin, sessions de développement)
     */
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly UserPasswordHasherInterface $passwordHasher,
        #[Autowire(service: 'session.factory')]
        private readonly SessionFactoryInterface $sessionFactory,
        private readonly RequestStack $requestStack,
        private readonly SecondFactorSession $secondFactorSession,
        private readonly LoginLinkHandlerInterface $loginLinkHandler,
        private readonly ClockInterface $clock,
        #[Autowire('%app.admin_path%')]
        private readonly string $adminPath,
        #[Autowire('%kernel.project_dir%')]
        private readonly string $projectDir,
    ) {
        parent::__construct();
    }

    /**
     * Déclare l'action et l'adresse de base.
     */
    protected function configure(): void
    {
        $this
            ->addArgument('action', InputArgument::REQUIRED, 'prepare, admin-session ou cleanup')
            ->addOption('base-url', null, InputOption::VALUE_REQUIRED, 'Adresse du site testé (lien de connexion du recruteur)', 'https://localhost:8000');
    }

    /**
     * Prépare ou supprime les comptes et sessions de test.
     *
     * Liens :
     * - Appelée par : la console Symfony (Playwright, global-setup.js et global-teardown.js)
     *
     * @return int Command::SUCCESS, ou Command::INVALID si l'action est inconnue
     */
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        return match ($input->getArgument('action')) {
            'prepare' => $this->prepare(rtrim((string) $input->getOption('base-url'), '/'), $output),
            'cleanup' => $this->cleanup($output),
            'admin-session' => $this->extraAdminSession($output),
            default => Command::INVALID,
        };
    }

    /**
     * Crée les comptes de test, ouvre la session admin et écrit le JSON (cookie, session, chemin, lien).
     */
    private function prepare(string $baseUrl, OutputInterface $output): int
    {
        $now = $this->clock->now();
        $admin = $this->entityManager->getRepository(User::class)->findOneBy(['username' => self::ADMIN_USERNAME]);
        if (null === $admin) {
            $admin = (new User(self::ADMIN_USERNAME))->setRoles(['ROLE_ADMIN'])->setNotificationEmail('e2e@example.test');
            $admin->setPassword($this->passwordHasher->hashPassword($admin, bin2hex(random_bytes(24))));
            $this->entityManager->persist($admin);
        }
        $recruiter = $this->entityManager->getRepository(Recruiter::class)->findOneBy(['email' => self::RECRUITER_EMAIL]);
        if (null === $recruiter) {
            $recruiter = (new Recruiter($now->modify('-1 day')))->setEmail(self::RECRUITER_EMAIL)->setFullName('Recruteur Accessibilité')->setCompany('Test e2e');
            $this->entityManager->persist($recruiter);
        }
        $recruiter->setStatus(RecruiterStatus::Approved)->setDecidedAt($now->modify('-1 day'))->setAccessExpiresAt($now->modify('+30 days'));
        $this->entityManager->flush();

        $session = $this->openAdminSession($admin);

        // Lien de connexion du recruteur : vrai lien signé, à usage unique
        $linkRequest = Request::create($baseUrl.'/espace-recruteur/accueil');
        $this->requestStack->push($linkRequest);
        $recruiterLink = $this->loginLinkHandler->createLoginLink($recruiter, $linkRequest)->getUrl();
        $this->requestStack->pop();

        $output->writeln((string) json_encode([
            'cookie' => $session->getName(),
            'admin_session' => $session->getId(),
            'admin_path' => $this->adminPath,
            'recruiter_link' => $recruiterLink,
        ], \JSON_UNESCAPED_SLASHES));

        return Command::SUCCESS;
    }

    /**
     * Ouvre une session admin de plus pour le compte de test (un test qui lit des messages flash a la sienne,
     * sans les partager avec les tests lancés en parallèle) et écrit son identifiant.
     *
     * @return int Command::SUCCESS, Command::FAILURE si « prepare » n'a pas été lancé
     */
    private function extraAdminSession(OutputInterface $output): int
    {
        $admin = $this->entityManager->getRepository(User::class)->findOneBy(['username' => self::ADMIN_USERNAME]);
        if (null === $admin) {
            $output->writeln('Lancer d\'abord « prepare ».');

            return Command::FAILURE;
        }
        $output->writeln($this->openAdminSession($admin)->getId());

        return Command::SUCCESS;
    }

    /**
     * Ouvre une session admin : jeton du pare-feu « main », marqueur « clé vérifiée », début de session
     * admin ; l'identifiant est noté pour « cleanup ».
     */
    private function openAdminSession(User $admin): SessionInterface
    {
        $session = $this->sessionFactory->createSession();
        $session->start();
        $request = new Request();
        $request->setSession($session);
        $this->requestStack->push($request);
        $session->set('_security_main', serialize(new UsernamePasswordToken($admin, 'main', $admin->getRoles())));
        $this->secondFactorSession->markVerified($admin);
        $this->secondFactorSession->startAdminSession();
        $this->requestStack->pop();
        $session->save();
        $sessionFile = $this->sessionIdFile();
        if (!is_dir(\dirname($sessionFile))) {
            mkdir(\dirname($sessionFile), 0700, true);
        }
        file_put_contents($sessionFile, $session->getId()."\n", \FILE_APPEND);

        return $session;
    }

    /**
     * Supprime les comptes de test, les échanges du recruteur et les sessions admin.
     */
    private function cleanup(OutputInterface $output): int
    {
        $this->entityManager->getConnection()->executeStatement('DELETE FROM chat_message WHERE recruiter_id IN (SELECT id FROM recruiter WHERE email = ?)', [self::RECRUITER_EMAIL]);
        $recruiter = $this->entityManager->getRepository(Recruiter::class)->findOneBy(['email' => self::RECRUITER_EMAIL]);
        if (null !== $recruiter) {
            $this->entityManager->remove($recruiter);
        }
        $admin = $this->entityManager->getRepository(User::class)->findOneBy(['username' => self::ADMIN_USERNAME]);
        if (null !== $admin) {
            $this->entityManager->remove($admin);
        }
        $this->entityManager->flush();

        $sessionFile = $this->sessionIdFile();
        if (is_file($sessionFile)) {
            foreach (array_filter(array_map('trim', file($sessionFile) ?: [])) as $sessionId) {
                $sessionPath = $this->projectDir.'/var/sessions/dev/sess_'.$sessionId;
                if (is_file($sessionPath)) {
                    unlink($sessionPath);
                }
            }
            unlink($sessionFile);
        }
        $output->writeln('Comptes de test supprimés.');

        return Command::SUCCESS;
    }

    /**
     * Fichier qui garde l'identifiant de la session admin de test (hors dépôt).
     */
    private function sessionIdFile(): string
    {
        return $this->projectDir.'/tests/e2e/.auth/admin-session-id';
    }
}
