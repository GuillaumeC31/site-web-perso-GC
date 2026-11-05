<?php

declare(strict_types=1);

namespace App\Tests\Support;

/**
 * Signe les requêtes d'interaction comme Discord, avec une paire de clés Ed25519 de TEST tirée d'une graine fixe
 * (« t » × 32) : sa clé publique est DISCORD_PUBLIC_KEY de .env.test ; jamais une vraie clé.
 *
 * Liens :
 * - Utilisé par : tests/Controller/DiscordInteractionsControllerTest, tests/Service/NotificationDispatcherTest
 */
final class DiscordSigner
{
    /**
     * Renvoie la clé publique de test (hexadécimale), identique à DISCORD_PUBLIC_KEY de .env.test.
     */
    public static function publicKey(): string
    {
        return bin2hex(sodium_crypto_sign_publickey(self::keyPair()));
    }

    /**
     * Signe l'horodatage suivi du corps, comme Discord.
     *
     * @return string signature hexadécimale (en-tête X-Signature-Ed25519)
     */
    public static function sign(string $timestamp, string $body): string
    {
        return bin2hex(sodium_crypto_sign_detached($timestamp.$body, sodium_crypto_sign_secretkey(self::keyPair())));
    }

    /**
     * Paire de clés de test, tirée d'une graine fixe.
     */
    private static function keyPair(): string
    {
        return sodium_crypto_sign_seed_keypair(str_repeat('t', 32));
    }
}
