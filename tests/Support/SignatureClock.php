<?php

declare(strict_types=1);

namespace App\Tests\Support;

/**
 * Horloge simulée de la vérification des liens signés (liens de connexion des recruteurs) : décale
 * l'heure lue par Symfony\Component\Security\Core\Signature\SignatureHasher, qui appelle time() sans
 * horloge injectable. La fonction time() de cet espace de noms est déclarée dans
 * tests/Support/signature_clock.php (chargé par tests/bootstrap.php, avant tout appel).
 *
 * Seule la vérification est décalée : le lien est créé à l'heure réelle, puis « vérifié plus tard ».
 *
 * Liens :
 * - Utilisée par : tests/Controller/RecruiterAreaControllerTest (lien expiré après 30 minutes)
 */
final class SignatureClock
{
    /** Décalage appliqué à l'heure réelle (secondes). */
    private static int $offset = 0;

    /**
     * Renvoie l'heure simulée (horodatage Unix).
     *
     * Liens :
     * - Appelée par : Symfony\Component\Security\Core\Signature\time() (tests/Support/signature_clock.php)
     */
    public static function now(): int
    {
        return \time() + self::$offset;
    }

    /**
     * Avance l'horloge simulée.
     *
     * @param int $seconds durée à ajouter (secondes)
     */
    public static function advance(int $seconds): void
    {
        self::$offset += $seconds;
    }

    /**
     * Remet l'horloge à l'heure réelle (à appeler en fin de test).
     */
    public static function reset(): void
    {
        self::$offset = 0;
    }
}
