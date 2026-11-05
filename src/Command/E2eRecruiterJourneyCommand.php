<?php

declare(strict_types=1);

namespace App\Command;

use App\Entity\Recruiter;
use App\Repository\ProfileRepository;
use App\Service\Ai\E2eFakeAiProvider;
use App\Service\Notification\NotificationDispatcher;
use Doctrine\ORM\EntityManagerInterface;
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
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;
use Symfony\Component\Security\Http\LoginLink\LoginLinkHandlerInterface;

/**
 * Aides du parcours recruteur de bout en bout (Playwright, tests/e2e/recruiter-journey.spec.js).
 * Commande enregistrée SEULEMENT en développement (#[When('dev')]) : elle n'existe pas en production.
 *
 * - start : pose le repère de l'IA simulée (E2eFakeAiProvider : aucune question n'atteint l'API Mistral) et
 *   celui qui coupe Discord et Telegram (NotificationDispatcher::E2E_SILENT_FLAG : rien vers les vraies messageries) ;
 *   si le profil n'a pas encore de coordonnées privées, y met des valeurs FICTIVES (retirées par cleanup) ;
 *   écrit en JSON les valeurs privées attendues dans l'espace recruteur (téléphone, e-mail, prétentions) ;
 * - aged-link : donne un vrai lien de connexion « émis il y a N minutes » (horloge simulée : sa date
 *   d'expiration est déjà passée si N ≥ 30), pour vérifier le refus d'un lien expiré dans le navigateur ;
 * - age-access : fait comme si N jours s'étaient écoulés depuis la validation (horloge simulée : échéance
 *   de l'accès ramenée à validation + 90 jours, dans le passé si N > 90), pour vérifier l'accès échu ;
 * - age-pending-notice : fait comme si le dernier e-mail « demande déjà en cours d'examen » datait de N heures,
 *   pour vérifier la limite d'un e-mail par 24 h ;
 * - saturate : épuise la limite « lien de connexion » d'une adresse (clé par adresse du limiteur), pour
 *   vérifier le message de limite de débit sans bloquer l'adresse IP des autres tests ;
 * - cleanup : supprime les recruteurs de test (adresses @e2e-parcours.example.test, avec leurs évènements et
 *   leurs échanges avec l'assistant), remet à zéro les limites des adresses saturées, retire les coordonnées
 *   fictives et le repère.
 *
 * Usage : php bin/console app:e2e:recruiter-journey aged-link --email=a@e2e-parcours.example.test --minutes=31
 *
 * Liens :
 * - Appelée par : tests/e2e/recruiter-journey.spec.js
 * - Utilise : LoginLinkHandlerInterface (pare-feu « recruiter »), limiteur limiter.recruiter_login_link,
 *   E2eFakeAiProvider::FLAG_FILE
 */
#[When(env: 'dev')]
#[AsCommand(name: 'app:e2e:recruiter-journey', description: 'Développement : aides du parcours recruteur de Playwright (start / aged-link / age-access / saturate / cleanup)')]
final class E2eRecruiterJourneyCommand extends Command
{
    /** Domaine réservé aux adresses de test du parcours. */
    public const EMAIL_DOMAIN = 'e2e-parcours.example.test';

    /** Téléphone fictif posé le temps du parcours (jamais une vraie coordonnée). */
    public const FICTIVE_PHONE = '06 00 00 00 00';

    /** E-mail fictif posé le temps du parcours. */
    public const FICTIVE_EMAIL = 'contact@'.self::EMAIL_DOMAIN;

    /** Repère « coordonnées fictives posées par start », relatif au dossier du projet. */
    private const FICTIVE_CONTACT_FILE = '/var/e2e/fictive-contact';

    /** Durée de validité d'un lien de connexion (security.yaml, login_link.lifetime), en secondes. */
    private const LINK_LIFETIME = 1800;

    /**
     * Reçoit les dépendances.
     *
     * @param RateLimiterFactoryInterface $loginLinkLimiter limiteur des liens de connexion (clés par IP et par adresse)
     * @param string                      $projectDir       dossier du projet (repère de l'IA simulée)
     */
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly ProfileRepository $profileRepository,
        private readonly LoginLinkHandlerInterface $loginLinkHandler,
        private readonly RequestStack $requestStack,
        #[Autowire(service: 'limiter.recruiter_login_link')]
        private readonly RateLimiterFactoryInterface $loginLinkLimiter,
        #[Autowire('%kernel.project_dir%')]
        private readonly string $projectDir,
    ) {
        parent::__construct();
    }

    /**
     * Déclare l'action et ses options.
     */
    protected function configure(): void
    {
        $this
            ->addArgument('action', InputArgument::REQUIRED, 'start, aged-link, saturate ou cleanup')
            ->addOption('email', null, InputOption::VALUE_REQUIRED, 'Adresse de test (@'.self::EMAIL_DOMAIN.')')
            ->addOption('minutes', null, InputOption::VALUE_REQUIRED, 'aged-link : âge simulé du lien, en minutes', '31')
            ->addOption('days', null, InputOption::VALUE_REQUIRED, 'age-access : jours écoulés depuis la validation', '91')
            ->addOption('hours', null, InputOption::VALUE_REQUIRED, 'age-pending-notice : âge simulé du dernier e-mail « déjà en cours d\'examen », en heures', '25')
            ->addOption('base-url', null, InputOption::VALUE_REQUIRED, 'Adresse du site testé (lien de connexion)', 'https://localhost:8000');
    }

    /**
     * Exécute l'action demandée.
     *
     * Liens :
     * - Appelée par : la console Symfony (Playwright)
     *
     * @return int Command::SUCCESS, Command::FAILURE (adresse hors du domaine de test, recruteur absent) ou Command::INVALID
     */
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $action = (string) $input->getArgument('action');
        if ('start' === $action || 'cleanup' === $action) {
            return 'start' === $action ? $this->start($output) : $this->cleanup($output);
        }

        $email = mb_strtolower(trim((string) $input->getOption('email')));
        if (!str_ends_with($email, '@'.self::EMAIL_DOMAIN)) {
            $output->writeln('Adresse refusée : seules les adresses @'.self::EMAIL_DOMAIN.' sont acceptées.');

            return Command::FAILURE;
        }

        return match ($action) {
            'aged-link' => $this->agedLink($email, (int) $input->getOption('minutes'), rtrim((string) $input->getOption('base-url'), '/'), $output),
            'age-access' => $this->ageAccess($email, (int) $input->getOption('days'), $output),
            'age-pending-notice' => $this->agePendingNotice($email, (int) $input->getOption('hours'), $output),
            'saturate' => $this->saturate($email, $output),
            default => Command::INVALID,
        };
    }

    /**
     * Pose le repère de l'IA simulée et, si besoin, des coordonnées privées fictives ; écrit en JSON les
     * valeurs privées attendues dans l'espace recruteur.
     */
    private function start(OutputInterface $output): int
    {
        $flag = $this->projectDir.E2eFakeAiProvider::FLAG_FILE;
        if (!is_dir(\dirname($flag))) {
            mkdir(\dirname($flag), 0700, true);
        }
        file_put_contents($flag, 'Parcours recruteur de Playwright en cours : IA simulée.');
        // Discord et Telegram coupés le temps de la suite : rien n'atteint les vraies messageries de .env.local
        file_put_contents($this->projectDir.NotificationDispatcher::E2E_SILENT_FLAG, 'Suite Playwright en cours : Discord et Telegram coupés.');

        $profile = $this->profileRepository->getOrCreate();
        if (null === $profile->getId()) {
            $this->entityManager->persist($profile);
        }
        if ('' === (string) $profile->getPrivatePhone() && '' === (string) $profile->getPrivateEmail()) {
            $profile->setPrivatePhone(self::FICTIVE_PHONE)->setPrivateEmail(self::FICTIVE_EMAIL);
            file_put_contents($this->projectDir.self::FICTIVE_CONTACT_FILE, 'Coordonnées fictives posées par app:e2e:recruiter-journey start.');
        }
        $this->entityManager->flush();

        $output->writeln((string) json_encode([
            'phone' => $profile->getPrivatePhone(),
            'email' => $profile->getPrivateEmail(),
            'salary' => $profile->isShowSalaryInRecruiterArea() ? $profile->getPrivateSalaryExpectation() : null,
        ], \JSON_UNESCAPED_UNICODE | \JSON_UNESCAPED_SLASHES));

        return Command::SUCCESS;
    }

    /**
     * Écrit un lien de connexion émis il y a $minutes minutes (date d'expiration = émission + 30 minutes).
     *
     * @param int $minutes âge simulé du lien
     */
    private function agedLink(string $email, int $minutes, string $baseUrl, OutputInterface $output): int
    {
        $recruiter = $this->entityManager->getRepository(Recruiter::class)->findOneBy(['email' => $email]);
        if (null === $recruiter) {
            $output->writeln('Recruteur introuvable.');

            return Command::FAILURE;
        }
        // Durée restante ; 0 ferait prendre la durée par défaut au gestionnaire : -1 (expiré depuis 1 s)
        $lifetime = self::LINK_LIFETIME - $minutes * 60;
        $request = Request::create($baseUrl.'/espace-recruteur');
        $this->requestStack->push($request);
        $link = $this->loginLinkHandler->createLoginLink($recruiter, $request, 0 === $lifetime ? -1 : $lifetime)->getUrl();
        $this->requestStack->pop();
        $output->writeln($link);

        return Command::SUCCESS;
    }

    /**
     * Ramène l'échéance de l'accès à « validé il y a $days jours » (horloge simulée du parcours).
     *
     * @param int $days jours écoulés depuis la validation
     */
    private function ageAccess(string $email, int $days, OutputInterface $output): int
    {
        $recruiter = $this->entityManager->getRepository(Recruiter::class)->findOneBy(['email' => $email]);
        if (null === $recruiter) {
            $output->writeln('Recruteur introuvable.');

            return Command::FAILURE;
        }
        $decidedAt = new \DateTimeImmutable(\sprintf('-%d days', $days));
        $recruiter->setDecidedAt($decidedAt)->setAccessExpiresAt($decidedAt->modify(\sprintf('+%d days', Recruiter::ACCESS_DAYS)));
        $this->entityManager->flush();
        $output->writeln(\sprintf('Accès validé il y a %d jours : échéance le %s.', $days, $recruiter->getAccessExpiresAt()?->format('d/m/Y')));

        return Command::SUCCESS;
    }

    /**
     * Fait dater de $hours heures le dernier e-mail « demande déjà en cours d'examen » (horloge simulée).
     *
     * @param int $hours âge simulé, en heures
     */
    private function agePendingNotice(string $email, int $hours, OutputInterface $output): int
    {
        $recruiter = $this->entityManager->getRepository(Recruiter::class)->findOneBy(['email' => $email]);
        if (null === $recruiter) {
            $output->writeln('Recruteur introuvable.');

            return Command::FAILURE;
        }
        $recruiter->setPendingNoticeSentAt(new \DateTimeImmutable(\sprintf('-%d hours', $hours)));
        $this->entityManager->flush();
        $output->writeln(\sprintf('Dernier e-mail « déjà en cours d\'examen » daté d\'il y a %d heures.', $hours));

        return Command::SUCCESS;
    }

    /**
     * Épuise la limite « lien de connexion » de l'adresse (la clé par IP n'est pas touchée).
     */
    private function saturate(string $email, OutputInterface $output): int
    {
        $limiter = $this->loginLinkLimiter->create('email-'.hash('sha256', $email));
        for ($i = 0; $i < 1000 && $limiter->consume()->isAccepted(); ++$i) {
        }
        $output->writeln('Limite atteinte pour cette adresse.');

        return Command::SUCCESS;
    }

    /**
     * Supprime les recruteurs de test, remet leurs limites à zéro et retire le repère de l'IA simulée.
     */
    private function cleanup(OutputInterface $output): int
    {
        $recruiters = $this->entityManager->getRepository(Recruiter::class)->createQueryBuilder('r')
            ->where('r.email LIKE :domain')->setParameter('domain', '%@'.self::EMAIL_DOMAIN)
            ->getQuery()->getResult();
        foreach ($recruiters as $recruiter) {
            $this->loginLinkLimiter->create('email-'.hash('sha256', $recruiter->getEmail()))->reset();
            $this->entityManager->remove($recruiter);
        }
        $this->entityManager->flush();
        // Adresses saturées sans fiche (limite de débit) : préfixe « limite- » dans le parcours
        foreach (['ordinateur', 'mobile'] as $device) {
            $this->loginLinkLimiter->create('email-'.hash('sha256', 'limite-'.$device.'@'.self::EMAIL_DOMAIN))->reset();
        }
        $fictiveContact = $this->projectDir.self::FICTIVE_CONTACT_FILE;
        if (is_file($fictiveContact)) {
            $profile = $this->profileRepository->getOrCreate();
            if (self::FICTIVE_PHONE === $profile->getPrivatePhone() && self::FICTIVE_EMAIL === $profile->getPrivateEmail()) {
                $profile->setPrivatePhone(null)->setPrivateEmail(null);
                $this->entityManager->flush();
            }
            unlink($fictiveContact);
        }
        foreach ([E2eFakeAiProvider::FLAG_FILE, NotificationDispatcher::E2E_SILENT_FLAG] as $relative) {
            if (is_file($this->projectDir.$relative)) {
                unlink($this->projectDir.$relative);
            }
        }
        $output->writeln(\sprintf('%d recruteur(s) de test supprimé(s), IA simulée désactivée, Discord et Telegram rétablis.', \count($recruiters)));

        return Command::SUCCESS;
    }
}
