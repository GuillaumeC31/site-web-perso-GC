<?php

declare(strict_types=1);

namespace App\Command;

use App\Entity\User;
use App\Security\EnrollmentTokenManager;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Question\Question;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Validator\Constraints\Length;
use Symfony\Component\Validator\Constraints\NotBlank;
use Symfony\Component\Validator\Constraints\PasswordStrength;
use Symfony\Component\Validator\Validator\ValidatorInterface;

/**
 * Outils communs aux commandes de gestion du compte admin (lancées par SSH uniquement).
 *
 * - saisie masquée et confirmée d'un nouveau mot de passe, avec contrôle de robustesse ;
 * - création d'un lien d'enrôlement à usage unique (30 minutes) et affichage unique.
 *
 * Liens :
 * - Étendue par : CreateAdminCommand, ResetAdminPasswordCommand, ResetAdminWebauthnCommand
 * - Utilise : EnrollmentTokenManager, ValidatorInterface, UrlGeneratorInterface (DEFAULT_URI)
 */
abstract class AbstractAdminCommand extends Command
{
    /** Longueur minimale d'un mot de passe admin. */
    public const PASSWORD_MIN_LENGTH = 12;

    /**
     * Reçoit les dépendances communes.
     *
     * @param ValidatorInterface     $validator    contrôle du mot de passe
     * @param EnrollmentTokenManager $tokenManager création des liens d'enrôlement
     * @param UrlGeneratorInterface  $urlGenerator URL absolue du lien (base : DEFAULT_URI)
     */
    public function __construct(
        protected readonly ValidatorInterface $validator,
        protected readonly EnrollmentTokenManager $tokenManager,
        protected readonly UrlGeneratorInterface $urlGenerator,
    ) {
        parent::__construct();
    }

    /**
     * Demande un nouveau mot de passe, masqué, deux fois, jusqu'à ce qu'il soit valable.
     *
     * Le mot de passe n'est jamais affiché ni journalisé.
     *
     * Liens :
     * - Appelée par : CreateAdminCommand::execute(), ResetAdminPasswordCommand::execute()
     *
     * @param SymfonyStyle $io sortie console
     *
     * @return string le mot de passe en clair, à hacher immédiatement par l'appelant
     *
     * @throws \RuntimeException après 3 essais infructueux
     */
    protected function askNewPassword(SymfonyStyle $io): string
    {
        for ($attempt = 1; $attempt <= 3; ++$attempt) {
            $password = (string) $io->askQuestion((new Question('Nouveau mot de passe (12 caractères minimum, masqué)'))->setHidden(true)->setHiddenFallback(false));
            $confirmation = (string) $io->askQuestion((new Question('Confirmez le mot de passe'))->setHidden(true)->setHiddenFallback(false));

            if ($password !== $confirmation) {
                $io->error('Les deux saisies ne correspondent pas.');
                continue;
            }

            $violations = $this->validator->validate($password, [
                new NotBlank(),
                new Length(min: self::PASSWORD_MIN_LENGTH, max: 4096),
                new PasswordStrength(minScore: PasswordStrength::STRENGTH_MEDIUM),
            ]);

            if (0 === \count($violations)) {
                return $password;
            }

            foreach ($violations as $violation) {
                $io->error((string) $violation->getMessage());
            }
        }

        throw new \RuntimeException('Mot de passe non défini après 3 essais.');
    }

    /**
     * Crée un lien d'enrôlement pour le compte et renvoie son URL absolue.
     *
     * L'appelant doit enregistrer le compte (flush) avant d'afficher le lien.
     *
     * Liens :
     * - Appelée par : CreateAdminCommand::execute(), ResetAdminWebauthnCommand::execute()
     * - Utilise : EnrollmentTokenManager::issue(), route admin_second_factor_enroll_start
     *
     * @param User $user compte destinataire
     *
     * @return string URL à ouvrir dans le navigateur, après connexion par mot de passe
     */
    protected function createEnrollmentUrl(User $user): string
    {
        $token = $this->tokenManager->issue($user);

        return $this->urlGenerator->generate(
            'admin_second_factor_enroll_start',
            ['token' => $token],
            UrlGeneratorInterface::ABSOLUTE_URL,
        );
    }

    /**
     * Affiche le lien d'enrôlement et la marche à suivre.
     *
     * Liens :
     * - Appelée par : CreateAdminCommand::execute(), ResetAdminWebauthnCommand::execute()
     *
     * @param SymfonyStyle $io  sortie console
     * @param string       $url lien d'enrôlement
     */
    protected function displayEnrollmentUrl(SymfonyStyle $io, string $url): void
    {
        $io->section('Lien d\'enrôlement de la clé YubiKey (usage unique, valable 30 minutes)');
        $io->writeln($url);
        $io->newLine();
        $io->listing([
            'Ouvrez ce lien dans votre navigateur ;',
            'connectez-vous avec votre identifiant et votre mot de passe ;',
            'touchez la YubiKey quand le navigateur le demande.',
        ]);
        $io->note('Ce lien n\'est affiché qu\'une fois et n\'est stocké nulle part. S\'il expire : bin/console app:admin:reset-webauthn <identifiant>.');
    }
}
