<?php

declare(strict_types=1);

namespace App\Command;

use App\Repository\UserRepository;
use App\Repository\WebauthnCredentialRepository;
use App\Security\EnrollmentTokenManager;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Validator\Validator\ValidatorInterface;

/**
 * Supprime toutes les clés YubiKey d'un compte admin et affiche un nouveau lien
 * d'enrôlement (procédure de secours en cas de clé perdue, SSH uniquement).
 *
 * Sert aussi à obtenir un nouveau lien si celui d'app:create-admin a expiré.
 * Toutes les sessions ouvertes sont fermées (version de sécurité augmentée).
 *
 * Usage : php bin/console app:admin:reset-webauthn <identifiant>
 *
 * Liens :
 * - Utilise : WebauthnCredentialRepository::deleteAllForUser(), AbstractAdminCommand::createEnrollmentUrl()
 * - Voir aussi : ResetAdminPasswordCommand
 */
#[AsCommand(name: 'app:admin:reset-webauthn', description: 'Supprime les clés YubiKey du compte admin et affiche un nouveau lien d\'enrôlement')]
final class ResetAdminWebauthnCommand extends AbstractAdminCommand
{
    /**
     * Reçoit les dépendances.
     *
     * @param UserRepository               $userRepository       dépôt des comptes
     * @param WebauthnCredentialRepository $credentialRepository dépôt des clés
     * @param EntityManagerInterface       $entityManager        gestionnaire Doctrine
     */
    public function __construct(
        private readonly UserRepository $userRepository,
        private readonly WebauthnCredentialRepository $credentialRepository,
        private readonly EntityManagerInterface $entityManager,
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
     * Demande confirmation, supprime les clés, crée et affiche le lien d'enrôlement.
     *
     * Liens :
     * - Appelée par : la console Symfony
     *
     * @return int Command::SUCCESS, ou Command::FAILURE (compte inconnu, annulation)
     */
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $io->title('Réinitialisation des clés YubiKey');

        $user = $this->userRepository->findOneByUsername((string) $input->getArgument('username'));
        if (null === $user) {
            $io->error('Aucun compte avec cet identifiant.');

            return Command::FAILURE;
        }

        $count = $this->credentialRepository->countByUser($user);
        $question = 0 === $count
            ? \sprintf('Aucune clé enregistrée pour « %s ». Créer un nouveau lien d\'enrôlement ?', $user->getUsername())
            : \sprintf('Supprimer les %d clé(s) de « %s », fermer ses sessions et créer un lien d\'enrôlement ?', $count, $user->getUsername());

        if (!$io->confirm($question, false)) {
            $io->warning('Annulé : rien n\'a été modifié.');

            return Command::FAILURE;
        }

        $deleted = $this->credentialRepository->deleteAllForUser($user);
        $user->incrementSecurityVersion();
        $url = $this->createEnrollmentUrl($user);
        $this->entityManager->flush();

        $io->success(\sprintf('%d clé(s) supprimée(s). Les sessions ouvertes sont fermées.', $deleted));
        $this->displayEnrollmentUrl($io, $url);

        return Command::SUCCESS;
    }
}
