<?php

declare(strict_types=1);

namespace App\Tests\Controller\Admin;

use App\Entity\SkillPillar;
use App\Tests\Support\AdminTestTrait;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * Icône d'un pilier de compétences choisie dans l'admin (bug du 05/11/2026 : « Contenu manquant » après
 * « Enregistrer », icône revenue à « Horloge »).
 *
 * Couvre : l'enregistrement de chaque icône (relue en base), le retour à la liste avec le message de confirmation,
 * l'envoi du formulaire hors de la Turbo Frame de la liste (data-turbo-frame="_top"), les autres champs du pilier,
 * la conservation de l'icône par app:skill-pillar:create-defaults et app:calculator:load-reference-data (lancées à
 * chaque déploiement), et l'icône affichée sur l'accueil.
 *
 * Liens :
 * - Couvre : Admin\SkillPillarController (AbstractCrudController::edit()), SkillPillarType,
 *   templates/admin/crud/form.html.twig, assets/lib/turbo_frame_missing.js (voir tests Vitest), home/index.html.twig
 */
final class SkillPillarIconTest extends WebTestCase
{
    use AdminTestTrait;

    /**
     * Chaque icône choisie dans le formulaire est enregistrée sur un pilier existant ; retour à la liste et message.
     *
     * @param string $icon icône de SkillPillar::ICONS
     */
    #[DataProvider('provideIcons')]
    public function testEachIconIsSaved(string $icon): void
    {
        $client = self::createClient();
        $this->loginFullyAsAdmin($client);
        $pillar = $this->createPillar('climate' === $icon ? 'generic' : 'climate');

        $crawler = $client->request('GET', $this->adminUrl('/piliers/'.$pillar->getId().'/modifier'));
        self::assertResponseIsSuccessful();
        $form = $crawler->selectButton('Enregistrer')->form();
        $form['skill_pillar[icon]'] = $icon;
        $client->submit($form, [], ['HTTP_ORIGIN' => 'http://localhost']);

        self::assertResponseRedirects($this->adminUrl('/piliers'), 303);
        $client->followRedirect();
        self::assertSelectorTextContains('body', 'Les modifications ont été enregistrées.');

        $this->getEntityManager()->clear();
        self::assertSame($icon, $this->getEntityManager()->find(SkillPillar::class, $pillar->getId())?->getIcon());
    }

    /**
     * Les six icônes du catalogue.
     *
     * @return iterable<string, array{string}>
     */
    public static function provideIcons(): iterable
    {
        foreach (SkillPillar::ICONS as $icon) {
            yield $icon => [$icon];
        }
    }

    /**
     * Les autres champs du pilier (nom, résumé, étiquette) sont enregistrés avec l'icône.
     */
    public function testOtherFieldsAreSaved(): void
    {
        $client = self::createClient();
        $this->loginFullyAsAdmin($client);
        $pillar = $this->createPillar('generic');

        $crawler = $client->request('GET', $this->adminUrl('/piliers/'.$pillar->getId().'/modifier'));
        $form = $crawler->selectButton('Enregistrer')->form();
        $form['skill_pillar[name]'] = 'Projets et pilotage';
        $form['skill_pillar[summary]'] = 'Pilotage de projets industriels.';
        $form['skill_pillar[badge]'] = 'mon plus';
        $form['skill_pillar[icon]'] = 'project';
        $client->submit($form, [], ['HTTP_ORIGIN' => 'http://localhost']);
        self::assertResponseRedirects($this->adminUrl('/piliers'), 303);

        $this->getEntityManager()->clear();
        $saved = $this->getEntityManager()->find(SkillPillar::class, $pillar->getId());
        self::assertSame(['Projets et pilotage', 'Pilotage de projets industriels.', 'mon plus', 'project'], [$saved?->getName(), $saved?->getSummary(), $saved?->getBadge(), $saved?->getIcon()]);
    }

    /**
     * Le formulaire du CRUD s'envoie hors de la Turbo Frame de la liste : la page d'arrivée (liste et message, ou
     * formulaire avec ses erreurs, ou page de connexion) s'affiche en entier, jamais « Content missing ».
     */
    public function testFormIsSubmittedOutsideTheListFrame(): void
    {
        $client = self::createClient();
        $this->loginFullyAsAdmin($client);
        $pillar = $this->createPillar('generic');

        $crawler = $client->request('GET', $this->adminUrl('/piliers/'.$pillar->getId().'/modifier'), [], [], ['HTTP_TURBO_FRAME' => 'crud_form']);

        $form = $crawler->filter('turbo-frame#crud_form form');
        self::assertSame('_top', $form->attr('data-turbo-frame'));
        // Cause du bug : sans action, le navigateur envoyait le formulaire à l'adresse de la PAGE (la liste, 405)
        self::assertSame($this->adminUrl('/piliers/'.$pillar->getId().'/modifier'), $form->attr('action'));
    }

    /**
     * Le formulaire d'ajout ouvert dans la frame porte lui aussi son adresse d'envoi.
     */
    public function testNewFormHasExplicitAction(): void
    {
        $client = self::createClient();
        $this->loginFullyAsAdmin($client);

        $crawler = $client->request('GET', $this->adminUrl('/piliers/nouveau'), [], [], ['HTTP_TURBO_FRAME' => 'crud_form']);

        self::assertSame($this->adminUrl('/piliers/nouveau'), $crawler->filter('turbo-frame#crud_form form')->attr('action'));
    }

    /**
     * Une erreur de validation reste sur le formulaire (422), avec le message sous le champ ; rien n'est enregistré.
     */
    public function testValidationErrorStaysOnTheForm(): void
    {
        $client = self::createClient();
        $this->loginFullyAsAdmin($client);
        $pillar = $this->createPillar('generic');

        $crawler = $client->request('GET', $this->adminUrl('/piliers/'.$pillar->getId().'/modifier'));
        $form = $crawler->selectButton('Enregistrer')->form();
        $form['skill_pillar[name]'] = '';
        $form['skill_pillar[icon]'] = 'project';
        $client->submit($form, [], ['HTTP_ORIGIN' => 'http://localhost']);

        self::assertResponseStatusCodeSame(422);
        self::assertSelectorExists('form [id^="skill_pillar_name"]');
        $this->getEntityManager()->clear();
        self::assertSame('generic', $this->getEntityManager()->find(SkillPillar::class, $pillar->getId())?->getIcon());
    }

    /**
     * Les commandes lancées à chaque déploiement ne remettent jamais une icône choisie à zéro.
     */
    public function testDeployCommandsKeepTheChosenIcon(): void
    {
        self::bootKernel();
        $pillar = $this->createPillar('project');
        $application = new Application(self::$kernel);

        foreach (['app:skill-pillar:create-defaults', 'app:calculator:load-reference-data'] as $command) {
            $tester = new CommandTester($application->find($command));
            self::assertSame(0, $tester->execute([]), $command);
        }

        $this->getEntityManager()->clear();
        self::assertSame('project', $this->getEntityManager()->find(SkillPillar::class, $pillar->getId())?->getIcon());
    }

    /**
     * L'accueil dessine l'icône enregistrée (presse-papiers de « project »).
     */
    public function testHomeShowsTheSavedIcon(): void
    {
        $client = self::createClient();
        $this->createPillar('project');

        $client->request('GET', '/');

        self::assertResponseIsSuccessful();
        self::assertStringContainsString('M9 3h6v3H9z', (string) $client->getResponse()->getContent());
    }

    /**
     * Crée un pilier existant (avec résumé, sans compétence), placé en tête.
     *
     * @param string $icon icône de départ
     */
    private function createPillar(string $icon): SkillPillar
    {
        $pillar = (new SkillPillar())->setName('Projets')->setSummary('Pilotage et coordination.')->setIcon($icon)->setPosition(1);
        $this->getEntityManager()->persist($pillar);
        $this->getEntityManager()->flush();

        return $pillar;
    }
}
