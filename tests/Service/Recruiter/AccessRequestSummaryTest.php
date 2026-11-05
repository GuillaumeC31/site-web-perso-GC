<?php

declare(strict_types=1);

namespace App\Tests\Service\Recruiter;

use App\Entity\Recruiter;
use App\Enum\RecruiterStatus;
use App\Service\Recruiter\AccessRequestSummary;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * Résumé d'une demande pour les notifications : domaine de l'e-mail seulement, début du message
 * (200 caractères), champs vides omis, jamais l'IP ; boutons de décision seulement pour une demande en attente.
 *
 * Liens :
 * - Couvre : App\Service\Recruiter\AccessRequestSummary
 */
final class AccessRequestSummaryTest extends TestCase
{
    /**
     * Vérifie les lignes d'une demande complète et d'une demande minimale.
     */
    public function testLinesKeepOnlyWhatHelpsToDecide(): void
    {
        $summary = new AccessRequestSummary($this->urlGenerator());
        $recruiter = (new Recruiter(new \DateTimeImmutable()))->setEmail('Camille.Martin@acme.test')->setFullName('Camille Martin')->setCompany('Acme')
            ->setJobTitle('Chargée de recrutement')->setCompanyWebsite('https://www.acme.example')->setIpAddress('203.0.113.9')
            ->setMessage("Bonjour,\n\n".str_repeat('poste ', 60))->setCompanySynthesis('Bureau d\'études en génie climatique.', new \DateTimeImmutable());

        $lines = $summary->lines($recruiter);

        self::assertSame(['Nom', 'Entreprise', 'Fonction', 'Site web', 'E-mail', 'Score', 'Message', 'Synthèse'], array_keys($lines));
        self::assertSame('@acme.example', $lines['E-mail']);
        self::assertSame(AccessRequestSummary::MESSAGE_LENGTH, mb_strlen($lines['Message']));
        self::assertStringStartsWith('Bonjour, poste', $lines['Message']);
        self::assertStringNotContainsString('203.0.113.9', implode(' ', $lines));
        self::assertStringNotContainsString('camille.martin@', mb_strtolower(implode(' ', $lines)));

        $minimal = $summary->lines((new Recruiter(new \DateTimeImmutable()))->setEmail('rh@acme.test')->setFullName('Alex')->setCompany('Acme'));
        self::assertSame(['Nom', 'Entreprise', 'E-mail', 'Score'], array_keys($minimal));
    }

    /**
     * Vérifie que seule une demande en attente porte une décision à distance.
     */
    public function testOnlyPendingRequestCarriesDecision(): void
    {
        $summary = new AccessRequestSummary($this->urlGenerator());
        $recruiter = (new Recruiter(new \DateTimeImmutable()))->setEmail('rh@acme.test')->setFullName('Alex')->setCompany('Acme');

        $pending = $summary->notification($recruiter->setStatus(RecruiterStatus::Pending));
        self::assertSame($recruiter, $pending->decisionRequest);
        self::assertTrue($pending->chatDetails);
        self::assertSame('Nouvelle demande d\'accès recruteur', $pending->title);

        $approved = $summary->notification($recruiter->setStatus(RecruiterStatus::Approved));
        self::assertNull($approved->decisionRequest);
        self::assertSame('Demande d\'accès validée automatiquement', $approved->title);
    }

    /**
     * Générateur d'adresses simulé (fiche du recruteur dans l'admin).
     */
    private function urlGenerator(): UrlGeneratorInterface
    {
        $generator = $this->createStub(UrlGeneratorInterface::class);
        $generator->method('generate')->willReturn('https://site.example/admin/recruteurs/1');

        return $generator;
    }
}
