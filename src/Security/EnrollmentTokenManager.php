<?php

declare(strict_types=1);

namespace App\Security;

use App\Entity\User;
use Psr\Clock\ClockInterface;

/**
 * Crée et vérifie les jetons des liens d'enrôlement (enregistrement d'une clé YubiKey).
 *
 * - Jeton aléatoire de 256 bits, transmis une seule fois dans le lien affiché en console ;
 * - seule son empreinte SHA-256 est stockée en base (User::enrollmentTokenHash) ;
 * - valable 30 minutes, à usage unique (effacé dès qu'il est accepté) ;
 * - utilisable seulement par le compte connecté avec son mot de passe.
 *
 * Liens :
 * - Utilisée par : CreateAdminCommand, ResetAdminWebauthnCommand (création),
 *   SecondFactorController::startEnrollment() (vérification)
 */
final readonly class EnrollmentTokenManager
{
    /** Durée de validité d'un lien d'enrôlement. */
    public const LIFETIME = '+30 minutes';

    /**
     * Reçoit l'horloge.
     *
     * @param ClockInterface $clock horloge (remplaçable en test)
     */
    public function __construct(private ClockInterface $clock)
    {
    }

    /**
     * Crée un nouveau jeton pour le compte (l'ancien, s'il existe, n'est plus valable).
     *
     * L'appelant doit enregistrer le compte (flush) puis afficher le lien une seule fois.
     *
     * Liens :
     * - Appelée par : CreateAdminCommand::execute(), ResetAdminWebauthnCommand::execute()
     *
     * @param User $user compte à qui le lien est destiné
     *
     * @return string le jeton en clair (base64url, 43 caractères) ; il n'est conservé nulle part
     */
    public function issue(User $user): string
    {
        $token = rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
        $user->setEnrollmentToken(self::hash($token), $this->clock->now()->modify(self::LIFETIME));

        return $token;
    }

    /**
     * Vérifie un jeton et, s'il est valable, l'efface aussitôt (usage unique).
     *
     * La comparaison se fait en temps constant (hash_equals). L'appelant doit
     * enregistrer le compte (flush) pour que l'effacement soit définitif.
     *
     * Liens :
     * - Appelée par : SecondFactorController::startEnrollment()
     *
     * @param User   $user  compte connecté par mot de passe
     * @param string $token jeton lu dans le lien
     *
     * @return \DateTimeImmutable|null la date limite du jeton accepté, ou null s'il est
     *                                 invalide, expiré, déjà utilisé ou destiné à un autre compte
     */
    public function consume(User $user, string $token): ?\DateTimeImmutable
    {
        $expectedHash = $user->getEnrollmentTokenHash();
        $expiresAt = $user->getEnrollmentTokenExpiresAt();

        if (null === $expectedHash || null === $expiresAt || '' === $token) {
            return null;
        }

        if ($expiresAt <= $this->clock->now()) {
            // Jeton expiré : on l'efface pour qu'il ne traîne pas en base.
            $user->clearEnrollmentToken();

            return null;
        }

        if (!hash_equals($expectedHash, self::hash($token))) {
            return null;
        }

        $user->clearEnrollmentToken();

        return $expiresAt;
    }

    /**
     * Calcule l'empreinte stockée en base d'un jeton.
     *
     * @param string $token jeton en clair
     *
     * @return string empreinte SHA-256 en hexadécimal (64 caractères)
     */
    private static function hash(string $token): string
    {
        return hash('sha256', $token);
    }
}
