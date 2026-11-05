<?php

declare(strict_types=1);

namespace App\Tests\Security;

use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Webauthn\Bundle\Service\PublicKeyCredentialCreationOptionsFactory;
use Webauthn\Bundle\Service\PublicKeyCredentialRequestOptionsFactory;
use Webauthn\PublicKeyCredentialUserEntity;

/**
 * Identifiant de la partie de confiance WebAuthn (rp_id) : lu dans RELYING_PARTY_ID, « localhost » en local et
 * « guillaume-couget.fr » sur le serveur (domaine enregistrable, jamais un sous-domaine).
 *
 * Liens :
 * - Couvre : config/packages/webauthn.yaml (profils default d'inscription et de vérification)
 */
final class WebauthnRelyingPartyTest extends KernelTestCase
{
    protected function tearDown(): void
    {
        unset($_SERVER['RELYING_PARTY_ID'], $_ENV['RELYING_PARTY_ID']);
        parent::tearDown();
    }

    /**
     * Vérifie que les options d'inscription et de vérification reprennent RELYING_PARTY_ID.
     */
    #[DataProvider('relyingPartyProvider')]
    public function testOptionsUseTheConfiguredRelyingParty(?string $configured, string $expected): void
    {
        if (null !== $configured) {
            $_SERVER['RELYING_PARTY_ID'] = $_ENV['RELYING_PARTY_ID'] = $configured;
        }
        self::bootKernel();
        $container = static::getContainer();

        $request = $container->get(PublicKeyCredentialRequestOptionsFactory::class)->create('default', []);
        $creation = $container->get(PublicKeyCredentialCreationOptionsFactory::class)->create(
            'default',
            PublicKeyCredentialUserEntity::create('admin_test', 'identifiant-de-test', 'Admin de test'),
            [],
        );

        self::assertSame($expected, $request->rpId);
        self::assertSame($expected, $creation->rp->id);
    }

    /**
     * @return iterable<string, array{?string, string}>
     */
    public static function relyingPartyProvider(): iterable
    {
        yield 'local (valeur de .env)' => [null, 'localhost'];
        yield 'serveur (domaine enregistrable)' => ['guillaume-couget.fr', 'guillaume-couget.fr'];
    }
}
