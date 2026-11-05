<?php

declare(strict_types=1);

namespace App\Tests\Controller\Admin;

use App\Entity\Experience;
use App\Entity\Profile;
use App\Entity\Skill;
use App\Entity\SkillPillar;
use App\Entity\WebauthnCredential;
use App\Service\Admin\ProfilePhotoStorage;
use App\Tests\Support\AdminTestTrait;
use App\Tests\Support\ConfirmationTrait;
use App\Tests\Support\VisibleTextAssertionsTrait;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\DomCrawler\Field\FileFormField;

/**
 * Tests fonctionnels des écrans de contenu de l'admin (profil, expériences, compétences,
 * piliers, clés de sécurité), avec un admin entièrement connecté (mot de passe + clé).
 *
 * Liens :
 * - Couvre : AbstractCrudController et ses modules, ProfileController, SecurityKeyController
 */
final class ContentAdminTest extends WebTestCase
{
    use ConfirmationTrait;
    use VisibleTextAssertionsTrait;
    use AdminTestTrait;

    /**
     * Vérifie l'ajout d'une expérience par le formulaire, puis son affichage dans la liste.
     */
    public function testCreateExperience(): void
    {
        $client = self::createClient();
        $this->loginFullyAsAdmin($client);

        $crawler = $client->request('GET', $this->adminUrl('/experiences/nouveau'));
        self::assertResponseIsSuccessful();
        $form = $crawler->selectButton('Enregistrer')->form([
            'experience[jobTitle]' => 'Responsable Bureau d\'Études',
            'experience[companyName]' => 'Entreprise de test',
            'experience[startDate]' => '2021-05-01',
            'experience[achievements]' => "Première réalisation\n\n- Deuxième réalisation",
            'experience[isPublished]' => true,
        ]);
        $client->submit($form, [], ['HTTP_ORIGIN' => 'http://localhost']);

        self::assertResponseRedirects($this->adminUrl('/experiences'));
        $client->followRedirect();
        self::assertSelectorTextContains('table', 'Entreprise de test');

        $experience = $this->getEntityManager()->getRepository(Experience::class)->findOneBy(['companyName' => 'Entreprise de test']);
        self::assertNotNull($experience);
        self::assertSame(['Première réalisation', 'Deuxième réalisation'], $experience->getAchievementList());
        self::assertSame(1, $experience->getPosition());
    }

    /**
     * Vérifie qu'une expérience anonymisée exige un libellé public (formulaire refusé, code 422).
     */
    public function testAnonymizedExperienceRequiresPublicLabel(): void
    {
        $client = self::createClient();
        $this->loginFullyAsAdmin($client);

        $crawler = $client->request('GET', $this->adminUrl('/experiences/nouveau'));
        $form = $crawler->selectButton('Enregistrer')->form([
            'experience[jobTitle]' => 'Technicien',
            'experience[companyName]' => 'Vrai nom confidentiel',
            'experience[isCompanyAnonymized]' => true,
            'experience[startDate]' => '2010-01-01',
            'experience[endDate]' => '2009-01-01',
        ]);
        $client->submit($form, [], ['HTTP_ORIGIN' => 'http://localhost']);

        self::assertResponseStatusCodeSame(422);
        self::assertSelectorTextContains('body', 'Indiquez le libellé public');
        self::assertSelectorTextContains('body', 'La date de fin doit être postérieure');
        self::assertSame(0, $this->getEntityManager()->getRepository(Experience::class)->count([]));
    }

    /**
     * Vérifie qu'un pilier qui contient des compétences ne peut pas être supprimé, et qu'un pilier vide peut l'être.
     */
    public function testPillarWithSkillsCannotBeDeleted(): void
    {
        $client = self::createClient();
        $this->loginFullyAsAdmin($client);
        $entityManager = $this->getEntityManager();

        $usedPillar = (new SkillPillar())->setName('Pilier utilisé')->setPosition(1);
        $emptyPillar = (new SkillPillar())->setName('Pilier vide')->setPosition(2);
        $entityManager->persist($usedPillar);
        $entityManager->persist($emptyPillar);
        $entityManager->persist((new Skill())->setName('Compétence')->setPillar($usedPillar)->setPosition(1));
        $entityManager->flush();
        $usedId = $usedPillar->getId();
        $emptyId = $emptyPillar->getId();

        $crawler = $client->request('GET', $this->adminUrl('/piliers'));
        $client->submit($crawler->filter(\sprintf('form[action$="/piliers/%d/suppression"]', $usedId))->form());
        $client->followRedirect();
        self::assertSelectorTextContains('[role="alert"]', 'contient encore des compétences');

        $crawler = $client->request('GET', $this->adminUrl('/piliers'));
        $client->submit($crawler->filter(\sprintf('form[action$="/piliers/%d/suppression"]', $emptyId))->form());
        $this->confirmAction($client, 'Supprimer');

        $entityManager->clear();
        self::assertNotNull($entityManager->find(SkillPillar::class, $usedId));
        self::assertNull($entityManager->find(SkillPillar::class, $emptyId));
    }

    /**
     * Vérifie le changement d'ordre (descendre la première ligne l'échange avec la deuxième).
     */
    public function testMovePillarDown(): void
    {
        $client = self::createClient();
        $this->loginFullyAsAdmin($client);
        $entityManager = $this->getEntityManager();
        $first = (new SkillPillar())->setName('Premier')->setPosition(1);
        $second = (new SkillPillar())->setName('Deuxième')->setPosition(2);
        $entityManager->persist($first);
        $entityManager->persist($second);
        $entityManager->flush();

        $crawler = $client->request('GET', $this->adminUrl('/piliers'));
        $client->submit($crawler->filter(\sprintf('form[action$="/piliers/%d/deplacer/bas"]', $first->getId()))->form());
        self::assertResponseRedirects($this->adminUrl('/piliers'));

        $entityManager->clear();
        self::assertSame(2, $entityManager->find(SkillPillar::class, $first->getId())?->getPosition());
        self::assertSame(1, $entityManager->find(SkillPillar::class, $second->getId())?->getPosition());
    }

    /**
     * Vérifie l'enregistrement du profil, avec coordonnées privées et photo : nom aléatoire,
     * conversion en WebP 800 × 1000 et miniature 400 × 400, chacune de moins de 200 Ko.
     */
    public function testSaveProfileWithPhoto(): void
    {
        $client = self::createClient();
        $this->loginFullyAsAdmin($client);

        $crawler = $client->request('GET', $this->adminUrl('/profil'));
        self::assertResponseIsSuccessful();
        self::assertInputValueSame('profile[jobTitle]', 'Responsable Bureau d\'Études — Génie climatique & électrique');

        $form = $crawler->selectButton('Enregistrer')->form([
            'profile[searchStatus]' => 'En recherche active',
            'profile[privatePhone]' => '0600000000',
        ]);
        $photoField = $form['profile[photo]'];
        self::assertInstanceOf(FileFormField::class, $photoField);
        $photoField->upload($this->createTestImage());
        $client->submit($form, [], ['HTTP_ORIGIN' => 'http://localhost']);
        self::assertResponseRedirects($this->adminUrl('/profil'));

        $profile = $this->getEntityManager()->getRepository(Profile::class)->findOneBy([]);
        self::assertNotNull($profile);
        self::assertSame('En recherche active', $profile->getSearchStatus());
        self::assertMatchesRegularExpression('/^[0-9a-f]{32}\.webp$/', (string) $profile->getPhotoFilename());

        $directory = static::getContainer()->getParameter('kernel.project_dir').'/public/uploads/profile/';
        $photoPath = $directory.$profile->getPhotoFilename();
        $miniaturePath = $directory.ProfilePhotoStorage::miniatureFilename((string) $profile->getPhotoFilename());
        try {
            foreach ([$photoPath => [800, 1000], $miniaturePath => [400, 400]] as $path => [$width, $height]) {
                $info = getimagesize($path);
                self::assertNotFalse($info);
                self::assertSame('image/webp', $info['mime']);
                self::assertSame([$width, $height], [$info[0], $info[1]]);
                self::assertLessThanOrEqual(200 * 1024, filesize($path));
            }

            // La page d'accueil affiche la photo envoyée à la place de la photo par défaut.
            $client->request('GET', '/');
            self::assertSelectorExists('img[src$="uploads/profile/'.$profile->getPhotoFilename().'"]');
        } finally {
            // Nettoyage des fichiers déposés par le test.
            @unlink($photoPath);
            @unlink($miniaturePath);
        }
    }

    /**
     * Vérifie qu'une photo trop petite pour le format 800 × 1000 est refusée.
     */
    public function testTooSmallPhotoIsRejected(): void
    {
        $client = self::createClient();
        $this->loginFullyAsAdmin($client);

        $crawler = $client->request('GET', $this->adminUrl('/profil'));
        $form = $crawler->selectButton('Enregistrer')->form();
        $photoField = $form['profile[photo]'];
        self::assertInstanceOf(FileFormField::class, $photoField);
        $photoField->upload($this->createTestImage(400, 400));
        $client->submit($form, [], ['HTTP_ORIGIN' => 'http://localhost']);

        self::assertResponseStatusCodeSame(422);
        self::assertNull($this->getEntityManager()->getRepository(Profile::class)->findOneBy([])?->getPhotoFilename());
    }

    /**
     * Vérifie que les coordonnées privées du profil n'apparaissent pas sur les pages publiques.
     */
    public function testPrivateProfileDataIsNotPublic(): void
    {
        $client = self::createClient();
        $profile = (new Profile())->setFullName('Guillaume Couget')->setJobTitle('Titre')
            ->setPrivatePhone('06 12 34 56 78')->setPrivateEmail('prive@example.test')->setPrivatePostalAddress('12 rue Secrète');
        $this->getEntityManager()->persist($profile);
        $this->getEntityManager()->flush();

        foreach (['/', '/cv', '/contact'] as $url) {
            $client->request('GET', $url);
            $html = (string) $client->getResponse()->getContent();
            self::assertStringNotContainsString('06 12 34 56 78', $html, $url);
            self::assertStringNotContainsString('prive@example.test', $html, $url);
            self::assertStringNotContainsString('12 rue Secrète', $html, $url);
        }
    }

    /**
     * Vérifie que la dernière clé ne peut pas être retirée, mais qu'une clé de secours peut l'être.
     */
    public function testLastSecurityKeyCannotBeRemoved(): void
    {
        $client = self::createClient();
        $user = $this->loginFullyAsAdmin($client);

        $crawler = $client->request('GET', $this->adminUrl('/securite/mes-cles'));
        self::assertResponseIsSuccessful();
        self::assertCount(0, $crawler->filter('form[action*="/suppression"]'), 'Pas de bouton de suppression pour une clé unique.');

        $backup = $this->createFakeCredential($user, 'Clé de secours');
        $this->getEntityManager()->persist($backup);
        $this->getEntityManager()->flush();

        $crawler = $client->request('GET', $this->adminUrl('/securite/mes-cles'));
        $client->submit($crawler->filter(\sprintf('form[action$="/%s/suppression"]', $backup->getId()))->form());
        $this->confirmAction($client, 'Clé de secours');
        self::assertResponseRedirects($this->adminUrl('/securite/mes-cles'));
        self::assertSame(1, $this->getEntityManager()->getRepository(WebauthnCredential::class)->count([]));

        // Une suppression forcée de la dernière clé (requête fabriquée, jeton CSRF valable)
        // est refusée côté serveur.
        $last = $this->getEntityManager()->getRepository(WebauthnCredential::class)->findOneBy([]);
        self::assertNotNull($last);
        $token = $this->generateCsrfToken($client, 'delete_security_key_'.$last->getId());
        $client->request('POST', $this->adminUrl('/securite/mes-cles/'.$last->getId().'/suppression'), ['_token' => $token]);
        self::assertResponseRedirects($this->adminUrl('/securite/mes-cles'));
        $client->followRedirect();
        self::assertSelectorTextContains('[role="alert"]', 'La dernière clé ne peut pas être retirée');
        self::assertSame(1, $this->getEntityManager()->getRepository(WebauthnCredential::class)->count([]));
    }

    /**
     * Vérifie qu'une suppression sans jeton CSRF valable est refusée.
     */
    public function testDeleteWithoutValidCsrfTokenIsRefused(): void
    {
        $client = self::createClient();
        $this->loginFullyAsAdmin($client);
        $pillar = (new SkillPillar())->setName('À garder')->setPosition(1);
        $this->getEntityManager()->persist($pillar);
        $this->getEntityManager()->flush();

        $client->request('POST', $this->adminUrl('/piliers/'.$pillar->getId().'/suppression'), ['_token' => 'invalide']);
        self::assertResponseRedirects($this->adminUrl('/piliers'));

        $this->getEntityManager()->clear();
        self::assertNotNull($this->getEntityManager()->find(SkillPillar::class, $pillar->getId()));
    }

    /**
     * Vérifie que la liste ouvre ses formulaires dans la Turbo Frame dédiée.
     */
    public function testListUsesTurboFrameForForms(): void
    {
        $client = self::createClient();
        $this->loginFullyAsAdmin($client);
        $client->request('GET', $this->adminUrl('/langues'));

        self::assertSelectorExists('turbo-frame#crud_form');
        self::assertSelectorExists('a[data-turbo-frame="crud_form"]');
    }

    /**
     * Crée une image PNG (dégradé, pour une compression réaliste) pour tester l'envoi de photo.
     *
     * @param int $width  largeur en pixels
     * @param int $height hauteur en pixels
     *
     * @return string chemin du fichier temporaire
     */
    private function createTestImage(int $width = 1200, int $height = 1600): string
    {
        $path = tempnam(sys_get_temp_dir(), 'photo').'.png';
        $image = imagecreatetruecolor($width, $height);
        self::assertNotFalse($image);
        for ($y = 0; $y < $height; ++$y) {
            $color = imagecolorallocate($image, (int) (255 * $y / $height), 120, 160);
            self::assertNotFalse($color);
            imageline($image, 0, $y, $width - 1, $y, $color);
        }
        imagepng($image, $path);

        return $path;
    }
}
