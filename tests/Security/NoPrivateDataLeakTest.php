<?php

declare(strict_types=1);

namespace App\Tests\Security;

use App\Service\Cv\CvPdfRenderer;
use App\Tests\Support\RecruiterTestTrait;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Vérifie qu'aucune adresse e-mail ni aucun numéro de téléphone n'apparaît dans
 * le HTML public (critère de réception du CDC §12).
 *
 * Le test est volontairement large : il cherche tout motif d'e-mail ou de
 * numéro français, pas seulement les coordonnées connues. Le profil contient des coordonnées et
 * des prétentions privées (affichables dans l'espace recruteur) : elles ne doivent sortir nulle part.
 */
final class NoPrivateDataLeakTest extends WebTestCase
{
    use RecruiterTestTrait;

    /** Motif d'adresse e-mail. */
    private const EMAIL_PATTERN = '/[A-Z0-9._%+-]+@[A-Z0-9.-]+\.[A-Z]{2,}/i';

    /** Motif de numéro de téléphone français (06 12 34 56 78, +33 6 12 34 56 78, 0612345678…). */
    private const PHONE_PATTERN = '/(?:\+33\s?|0)[1-9](?:[\s.-]?\d{2}){4}/';

    /**
     * Seul numéro permis en public : celui de l'hébergeur l’hébergeur, exigé dans les mentions légales (LCEN,
     * article 6, III). Jamais celui de Guillaume Couget.
     */
    private const HOST_PHONE = '01 23 45 67 89';

    /**
     * Vérifie qu'une page publique ne contient ni e-mail ni téléphone.
     */
    #[DataProvider('providePublicUrls')]
    public function testPublicPageContainsNoContactData(string $url): void
    {
        $client = self::createClient();
        $this->createPrivateProfile(true);
        $client->request('GET', $url);
        $html = (string) $client->getResponse()->getContent();
        self::assertStringNotContainsString('XX à YY k€', $html, 'Prétentions trouvées sur '.$url);

        self::assertDoesNotMatchRegularExpression(self::EMAIL_PATTERN, $html, 'Adresse e-mail trouvée sur '.$url);
        $text = $this->stripAttributes($html);
        if ('/mentions-legales' === $url) {
            self::assertStringContainsString(self::HOST_PHONE, $text, 'Téléphone de l\'hébergeur exigé dans les mentions légales.');
            $text = str_replace(self::HOST_PHONE, '', $text);
        }
        self::assertDoesNotMatchRegularExpression(self::PHONE_PATTERN, $text, 'Numéro de téléphone trouvé sur '.$url);
    }

    /**
     * Vérifie le contenu des CV PDF : coordonnées privées seulement dans le CV complet de l'espace
     * recruteur, jamais de date de naissance ni d'adresse postale.
     */
    #[DataProvider('provideCvVariants')]
    public function testCvPdfContactData(string $variant): void
    {
        self::bootKernel();
        $this->createPrivateProfile(true);
        $renderer = static::getContainer()->get(CvPdfRenderer::class);

        $public = $renderer->renderHtml($variant);
        self::assertStringNotContainsString(self::PRIVATE_PHONE, $public);
        self::assertStringNotContainsString(self::PRIVATE_EMAIL, $public);
        self::assertStringNotContainsString('XX à YY k€', $public);

        $private = $renderer->renderHtml($variant, true);
        self::assertStringContainsString(self::PRIVATE_PHONE, $private);
        self::assertStringContainsString(self::PRIVATE_EMAIL, $private);
        self::assertStringContainsString('XX à YY k€', $private);
        self::assertDoesNotMatchRegularExpression('/naissance|né le|née le/iu', $private);
    }

    /**
     * Variantes du CV PDF.
     *
     * @return iterable<string, array{0: string}>
     */
    public static function provideCvVariants(): iterable
    {
        yield 'ATS' => ['ats'];
        yield 'design' => ['design'];
    }

    /**
     * URL publiques à vérifier.
     *
     * @return iterable<string, array{0: string}>
     */
    public static function providePublicUrls(): iterable
    {
        yield 'accueil' => ['/'];
        yield 'cv' => ['/cv'];
        yield 'projets' => ['/projets'];
        yield 'calculatrice' => ['/calculatrice'];
        yield 'outil de la calculatrice' => ['/calculatrice/electricite/calculs-cables'];
        yield 'contact' => ['/contact'];
        yield 'espace recruteur' => ['/espace-recruteur'];
        yield 'lien de connexion invalide' => ['/espace-recruteur/lien-invalide'];
        yield 'espace privé sans connexion' => ['/espace-recruteur/accueil'];
        yield 'mentions légales' => ['/mentions-legales'];
        yield 'confidentialité' => ['/confidentialite'];
    }

    /**
     * Retire les attributs des balises (tracés SVG, nonces) pour éviter les faux positifs
     * sur les suites de chiffres qui ne sont pas du texte visible.
     *
     * @param string $html le HTML de la page
     *
     * @return string le HTML sans les attributs
     */
    private function stripAttributes(string $html): string
    {
        return (string) preg_replace('/<([a-z0-9-]+)\s[^>]*>/i', '<$1>', $html);
    }
}
