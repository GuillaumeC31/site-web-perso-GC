<?php

declare(strict_types=1);

namespace App\Command;

use App\Repository\UserRepository;
use App\Security\EnrollmentTokenManager;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Validator\Validator\ValidatorInterface;

/**
 * Réinitialise le mot de passe d'un compte admin (procédure de secours, SSH uniquement).
 *
 * Il n'existe pas de « mot de passe oublié » par e-mail. Les clés YubiKey ne sont pas
 * touchées ; toutes les sessions ouvertes sont fermées (version de sécurité augmentée).
 *
 * Usage : php bin/console app:admin:reset-password <identifiant>
 *
 * Liens :
 * - Utilise : UserRepository, UserPasswordHasherInterface, AbstractAdminCommand::askNewPassword()
 * - Voir aussi : ResetAdminWebauthnCommand
 */
#[AsCommand(name: 'app:admin:reset-password', description: 'Réinitialise le mot de passe admin (saisie masquée) et ferme les sessions ouvertes')]
final class ResetAdminPasswordCommand extends AbstractAdminCommand
{
    /**
     * Reçoit les dépendances.
     *
     * @param UserRepository              $userRepository dépôt des comptes
     * @param EntityManagerInterface      $entityManager  gestionnaire Doctrine
     * @param UserPasswordHasherInterface $passwordHasher calcul de l'empreinte du mot de passe
     */
    public function __construct(
        private readonly UserRepository $userRepository,
        private readonly EntityManagerInterface $entityManager,
        private readonly UserPasswordHasherInterface $passwordHasher,
        ValidatorInterface $validator,
        EnrollmentTokenManager $tokenManager,
        UrlGeneratorInterface $urlGenerator,
    ) {
        parent::__construct($validator, $tokenManager, $urlGenerator);
    }

    /**
     * Déclare l'argument « identifiant ».
     *
     * Liens :
     * - Appelée par : la console Symfony
     */
    protected function configure(): void
    {
        $this->addArgument('username', InputArgument::REQUIRED, 'Identifiant de connexion du compte admin');
    }

    /**
     * Demande confirmation puis le nouveau mot de passe, et l'enregistre.
     *
     * Liens :
     * - Appelée par : la console Symfony
     *
     * @return int Command::SUCCESS, ou Command::FAILURE (compte inconnu, annulation, mode non interactif)
     */
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $io->title('Réinitialisation du mot de passe admin');

        if (!$input->isInteractive()) {
            $io->error('Cette commande est interactive (le mot de passe est saisi de façon masquée).');

            return Command::FAILURE;
        }

        $user = $this->userRepository->findOneByUsername((string) $input->getArgument('username'));
        if (null === $user) {
            $io->error('Aucun compte avec cet identifiant.');

            return Command::FAILURE;
        }

        if (!$io->confirm(\sprintf('Remplacer le mot de passe de « %s » et fermer ses sessions ouvertes ?', $user->getUsername()), false)) {
            $io->warning('Annulé : rien n\'a été modifié.');

            return Command::FAILURE;
        }

        $plainPassword = $this->askNewPassword($io);
        $user->setPassword($this->passwordHasher->hashPassword($user, $plainPassword));
        unset($plainPassword);
        $user->incrementSecurityVersion();
        $this->entityManager->flush();

        $io->success('Mot de passe remplacé. Les sessions ouvertes sont fermées ; la clé YubiKey reste exigée à la connexion.');

        return Command::SUCCESS;
    }
}
