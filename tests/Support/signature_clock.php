<?php

declare(strict_types=1);

/*
 * Horloge simulée des signatures (tests seulement) : l'appel time() de SignatureHasher, écrit sans « \ »,
 * cherche d'abord cette fonction dans son espace de noms. Elle lit App\Tests\Support\SignatureClock
 * (heure réelle tant qu'aucun test ne l'avance). Chargé par tests/bootstrap.php.
 */

namespace Symfony\Component\Security\Core\Signature;

use App\Tests\Support\SignatureClock;

if (!\function_exists(__NAMESPACE__.'\time')) {
    /**
     * Heure lue par SignatureHasher (horloge simulée des tests).
     *
     * Liens :
     * - Appelée par : SignatureHasher::verifySignatureHash(), SignatureHasher::acceptSignatureHash()
     * - Utilise : SignatureClock::now()
     */
    function time(): int
    {
        return SignatureClock::now();
    }
}
