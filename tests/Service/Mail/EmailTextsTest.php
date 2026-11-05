<?php

declare(strict_types=1);

namespace App\Tests\Service\Mail;

use App\Entity\Recruiter;
use App\Enum\EmailType;
use App\Repository\EmailTextRevisionRepository;
use App\Service\Mail\EmailTexts;
use App\Service\Mail\MailFactory;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Mime\BodyRendererInterface;

/**
 * Textes modifiables des e-mails : variables {nom}, {entreprise}, {delai} (toute autre accolade refusée), texte
 * simple seulement (aucune balise, échappé à l'affichage), longueur limitée, enregistrement, retour au texte
 * d'origine et historique.
 *
 * Liens :
 * - Couvre : App\Service\Mail\EmailTexts, EmailText, EmailTextRevision, MailFactory::createTyped()
 */
final class EmailTextsTest extends KernelTestCase
{
    /**
     * Vérifie le remplacement des variables et le texte d'origine (délai réglé dans Paramètres).
     */
    public function testVariablesAreReplaced(): void
    {
        self::bootKernel();
        $texts = static::getContainer()->get(EmailTexts::class);
        $resolved = $texts->resolve(EmailType::RequestReceived, ['nom' => 'Camille', 'entreprise' => 'Acme', 'delai' => 'sous 48 h']);
        self::assertSame(EmailType::RequestReceived->defaultSubject(), $resolved['subject']);
        self::assertStringContainsString('Je la valide sous 48 h ;', $resolved['intro']);

        $resolved = $texts->resolve(EmailType::Approval, ['nom' => 'Camille', 'entreprise' => 'Acme'], ['subject' => 'Bienvenue {nom} ({entreprise})', 'intro' => 'Réponse {delai}.', 'signature' => 'G.']);
        self::assertSame('Bienvenue Camille (Acme)', $resolved['subject']);
        self::assertSame('Réponse .', $resolved['intro']);
    }

    /**
     * Vérifie les refus : accolade inconnue, HTML, objet vide ou sur deux lignes, texte trop long.
     */
    public function testValidation(): void
    {
        self::bootKernel();
        $texts = static::getContainer()->get(EmailTexts::class);
        self::assertSame([], $texts->validate('Bonjour {nom}', 'Merci, {entreprise} ; réponse {delai}.', "Cordialement,\nGuillaume"));
        self::assertSame(['subject' => 'admin.communication.text_error.placeholder'], $texts->validate('Bonjour {prenom}', '', ''));
        self::assertSame(['intro' => 'admin.communication.text_error.placeholder'], $texts->validate('Objet', 'Accolade seule } ici', ''));
        self::assertSame(['intro' => 'admin.communication.text_error.html'], $texts->validate('Objet', '<b>gras</b>', ''));
        self::assertSame(['signature' => 'admin.communication.text_error.html'], $texts->validate('Objet', '', '<script>alert(1)</script>'));
        self::assertSame(['subject' => 'admin.communication.text_error.subject_required'], $texts->validate('  ', '', ''));
        self::assertSame(['subject' => 'admin.communication.text_error.subject_line'], $texts->validate("Deux\nlignes", '', ''));
        self::assertSame(['intro' => 'admin.communication.text_error.too_long'], $texts->validate('Objet', str_repeat('a', EmailTexts::MAX_INTRO + 1), ''));
    }

    /**
     * Vérifie l'enregistrement, l'historique et le retour au texte d'origine ; un texte égal à l'origine reste « d'origine ».
     */
    public function testSaveResetAndHistory(): void
    {
        self::bootKernel();
        $texts = static::getContainer()->get(EmailTexts::class);
        self::assertFalse($texts->raw(EmailType::Refusal)['modified']);

        $texts->save(EmailType::Refusal, 'Votre demande, {nom}', 'Merci beaucoup.', EmailType::Refusal->defaultSignature());
        $raw = $texts->raw(EmailType::Refusal);
        self::assertTrue($raw['modified']);
        self::assertSame('Votre demande, {nom}', $raw['subject']);
        self::assertSame(EmailType::Refusal->defaultSignature(), $raw['signature']);

        $texts->reset(EmailType::Refusal);
        self::assertFalse($texts->raw(EmailType::Refusal)['modified']);
        $history = static::getContainer()->get(EmailTextRevisionRepository::class)->findLatest(EmailType::Refusal);
        self::assertSame(['reset', 'saved'], array_map(static fn ($r): string => $r->getAction(), $history));
        // Signature égale à l'origine : gardée comme « d'origine » (null)
        self::assertNull($history[1]->getSignature());
    }

    /**
     * Vérifie que le texte saisi est échappé dans l'e-mail HTML (aucune balise interprétée) et que les liens et
     * mentions fixes restent en place.
     */
    public function testTextsAreEscapedAndStructureStays(): void
    {
        self::bootKernel();
        $texts = static::getContainer()->get(EmailTexts::class);
        // Saisie directe en base (le formulaire refuse déjà les balises) : l'affichage doit quand même échapper
        $texts->save(EmailType::LoginLink, 'Connexion', 'Bonjour & bienvenue "{nom}"', 'G. & C.');
        $recruiter = (new Recruiter(new \DateTimeImmutable()))->setEmail('rh@acme.test')->setFullName('Camille <Martin>')->setCompany('Acme');
        $email = static::getContainer()->get(MailFactory::class)->createTyped(EmailType::LoginLink, 'rh@acme.test', ['recruiter' => $recruiter, 'url' => 'https://localhost/lien-unique', 'minutes' => 30], ['nom' => '<b>Camille</b>']);
        static::getContainer()->get(BodyRendererInterface::class)->render($email);
        $html = (string) $email->getHtmlBody();

        self::assertSame('Connexion', $email->getSubject());
        self::assertStringContainsString('Bonjour &amp; bienvenue &quot;&lt;b&gt;Camille&lt;/b&gt;&quot;', $html);
        self::assertStringNotContainsString('<b>Camille</b>', $html);
        self::assertStringContainsString('https://localhost/lien-unique', $html);
        self::assertStringContainsString('ne sert <strong>qu\'une seule fois</strong>', $html);
    }
}
