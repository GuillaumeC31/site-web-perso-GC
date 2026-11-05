<?php

declare(strict_types=1);

namespace App\Tests\Controller\Admin;

use App\Enum\RecruiterStatus;
use App\Repository\SiteSettingsRepository;
use App\Tests\Support\AdminTestTrait;
use App\Tests\Support\ConfirmationTrait;
use App\Tests\Support\RecruiterTestTrait;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\DomCrawler\Field\ChoiceFormField;

/**
 * Confirmation des actions importantes sans JavaScript : la page de confirmation du serveur s'affiche, rien
 * n'est fait avant de la valider, « Annuler » ramène à la page d'origine ; le formulaire porte aussi les
 * attributs de la fenêtre « notify » (confirmation avec JavaScript). Mode maintenance : confirmation à
 * l'activation seulement.
 *
 * Liens :
 * - Couvre : App\Service\ActionConfirmation, Admin\RecruiterController::decide(), Admin\SiteSettingsController::edit(),
 *   templates/_partials/_confirm.html.twig, templates/confirmation/confirm.html.twig
 */
final class ActionConfirmationTest extends WebTestCase
{
    use AdminTestTrait;
    use ConfirmationTrait;
    use RecruiterTestTrait;

    /**
     * Vérifie le refus d'une demande : formulaire prêt pour la fenêtre, page de confirmation sans
     * JavaScript (champs renvoyés, jeton compris), rien fait avant la confirmation, puis décision appliquée.
     */
    public function testRefuseGoesThroughTheConfirmationPage(): void
    {
        $client = self::createClient();
        $this->loginFullyAsAdmin($client);
        $recruiter = $this->createRecruiter('confirme@acme.test', RecruiterStatus::Pending);

        $crawler = $client->request('GET', $this->adminUrl('/recruteurs/'.$recruiter->getId()));
        $form = $crawler->selectButton('Refuser')->form();
        $formNode = $crawler->selectButton('Refuser')->closest('form');
        self::assertNotNull($formNode);
        self::assertSame('notify', $formNode->attr('data-controller'));
        self::assertSame('submit->notify#confirm', $formNode->attr('data-action'));
        self::assertStringContainsString('Refuser la demande de', (string) $formNode->attr('data-notify-title-value'));
        self::assertSame('true', $formNode->attr('data-notify-danger-value'));
        self::assertSame('0', $form->get('_confirmed')->getValue());

        $client->submit($form);
        self::assertResponseStatusCodeSame(422);
        self::assertSelectorTextContains('[data-testid="confirmation-page"] h1', 'Refuser la demande de');
        self::assertSelectorTextContains('[data-testid="confirmation-page"]', 'Aucun e-mail n\'est envoyé');
        $confirmation = $client->getCrawler()->filter('[data-testid="confirmation-page"] form');
        self::assertSame('refuse', $confirmation->filter('input[name="decision"]')->attr('value'));
        self::assertNotEmpty($confirmation->filter('input[name="_token"]')->attr('value'));
        self::assertSame($this->adminUrl('/recruteurs/'.$recruiter->getId()), $client->getCrawler()->selectLink('Annuler')->attr('href'));
        self::assertSame(RecruiterStatus::Pending, $this->reloadStatus($recruiter->getId()), 'Rien n\'est fait avant la confirmation.');

        $this->confirmAction($client);
        self::assertResponseRedirects($this->adminUrl('/recruteurs/'.$recruiter->getId()));
        self::assertSame(RecruiterStatus::Refused, $this->reloadStatus($recruiter->getId()));
    }

    /**
     * Vérifie le mode maintenance : l'activer passe par la confirmation (rien n'est enregistré avant),
     * le désactiver non.
     */
    public function testEnablingMaintenanceNeedsConfirmation(): void
    {
        $client = self::createClient();
        $this->loginFullyAsAdmin($client);

        $crawler = $client->request('GET', $this->adminUrl('/parametres'));
        $formNode = $crawler->filter('form[name="site_settings"]');
        self::assertSame('#site_settings_maintenanceEnabled', $formNode->attr('data-notify-if-checked-value'));
        $form = $crawler->selectButton('Enregistrer')->form();
        $box = $form['site_settings[maintenanceEnabled]'];
        self::assertInstanceOf(ChoiceFormField::class, $box);
        $box->tick();
        $client->submit($form);
        self::assertSelectorTextContains('[data-testid="confirmation-page"] h1', 'Mettre le site en maintenance ?');
        self::assertFalse($this->settings()->isMaintenanceEnabled(), 'Rien n\'est enregistré avant la confirmation.');

        $this->confirmAction($client);
        self::assertResponseRedirects();
        self::assertTrue($this->settings()->isMaintenanceEnabled());

        // Désactiver : enregistré directement
        $crawler = $client->request('GET', $this->adminUrl('/parametres'));
        $form = $crawler->selectButton('Enregistrer')->form();
        $box = $form['site_settings[maintenanceEnabled]'];
        self::assertInstanceOf(ChoiceFormField::class, $box);
        $box->untick();
        $client->submit($form);
        self::assertResponseRedirects();
        self::assertFalse($this->settings()->isMaintenanceEnabled());
    }

    /**
     * Relit le statut d'un recruteur en base.
     */
    private function reloadStatus(?int $id): ?RecruiterStatus
    {
        $entityManager = $this->getEntityManager();
        $entityManager->clear();

        return $entityManager->find(\App\Entity\Recruiter::class, $id)?->getStatus();
    }

    /**
     * Relit les paramètres du site en base.
     */
    private function settings(): \App\Entity\SiteSettings
    {
        $this->getEntityManager()->clear();

        return static::getContainer()->get(SiteSettingsRepository::class)->getOrCreate();
    }
}
