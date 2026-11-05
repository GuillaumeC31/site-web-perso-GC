<?php

declare(strict_types=1);

namespace App\Command;

use App\Entity\User;
use App\Repository\UserRepository;
use App\Security\EnrollmentTokenManager;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Validator\Constraints\Email;
use Symfony\Component\Validator\Validator\ValidatorInterface;

/**
 * Crée le compte d'administration (une seule fois, par SSH).
 *
 * Demande l'identifiant, l'e-mail de notification et le mot de passe (saisi masqué,
 * deux fois), puis affiche le lien d'enrôlement de la première YubiKey.
 * Refuse de créer un deuxième compte admin.
 *
 * Usage : php bin/console app:create-admin
 *
 * Liens :
 * - Utilise : User, UserRepository, UserPasswordHasherInterface, AbstractAdminCommand
 */
#[AsCommand(name: 'app:create-admin', description: 'Crée le compte admin et affiche le lien d\'enrôlement de la YubiKey')]
final class CreateAdminCommand extends AbstractAdminCommand
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
     * Crée le compte après saisie interactive.
     *
     * Liens :
     * - Appelée par : la console Symfony
     * - Utilise : askNewPassword(), createEnrollmentUrl(), displayEnrollmentUrl()
     *
     * @return int Command::SUCCESS, ou Command::FAILURE (admin existant, saisie invalide, mode non interactif)
     */
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $io->title('Création du compte admin');

        if (!$input->isInteractive()) {
            $io->error('Cette commande est interactive (le mot de passe est saisi de façon masquée).');

            return Command::FAILURE;
        }

        if ($this->userRepository->hasAdmin()) {
            $io->error('Un compte admin existe déjà. Utilisez app:admin:reset-password ou app:admin:reset-webauthn.');

            return Command::FAILURE;
        }

        $username = (string) $io->ask('Identifiant de connexion (minuscules, chiffres, . _ -, différent de votre e-mail)', null, function (?string $value): string {
            $value = strtolower(trim((string) $value));
            if (1 !== preg_match(User::USERNAME_PATTERN, $value)) {
                throw new \RuntimeException('Identifiant invalide : 3 à 64 caractères parmi a-z, 0-9, point, tiret, souligné.');
            }
            if (null !== $this->userRepository->findOneByUsername($value)) {
                throw new \RuntimeException('Cet identifiant est déjà utilisé.');
            }

            return $value;
        });

        $email = (string) $io->ask('E-mail de notification (ne sert pas à se connecter)', null, function (?string $value) use ($username): string {
            $value = trim((string) $value);
            if (0 !== \count($this->validator->validate($value, new Email())) || '' === $value) {
                throw new \RuntimeException('Adresse e-mail invalide.');
            }
            if (strtolower($value) === $username || strtolower(strstr($value, '@', true) ?: '') === $username) {
                throw new \RuntimeException('L\'identifiant doit être différent de l\'adresse e-mail.');
            }

            return $value;
        });

        $plainPassword = $this->askNewPassword($io);

        $user = (new User($username))
            ->setNotificationEmail($email)
            ->setRoles(['ROLE_ADMIN']);
        $user->setPassword($this->passwordHasher->hashPassword($user, $plainPassword));
        unset($plainPassword);

        $url = $this->createEnrollmentUrl($user);
        $this->entityManager->persist($user);
        $this->entityManager->flush();

        $io->success(\sprintf('Compte « %s » créé.', $username));
        $this->displayEnrollmentUrl($io, $url);

        return Command::SUCCESS;
    }
}
