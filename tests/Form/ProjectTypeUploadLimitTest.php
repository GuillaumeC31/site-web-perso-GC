<?php

declare(strict_types=1);

namespace App\Tests\Form;

use App\Form\ProjectType;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Form\FormFactoryInterface;
use Symfony\Component\Validator\Constraints\Count;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Vérifie la limite d'images de la galerie par envoi, alignée sur public/.user.ini
 * (post_max_size 55M, max_file_uploads 6 : 5 images de 10 Mo et la couverture).
 *
 * Liens :
 * - Couvre : ProjectType (champ newImages), translations/validators.fr.yaml (project.new_images_too_many)
 */
final class ProjectTypeUploadLimitTest extends KernelTestCase
{
    /**
     * Vérifie la contrainte : 5 images au plus, avec un message en français qui explique quoi faire.
     */
    public function testGalleryAcceptsAtMostFiveImagesPerUpload(): void
    {
        self::bootKernel();
        $form = static::getContainer()->get(FormFactoryInterface::class)->create(ProjectType::class);
        $counts = array_values(array_filter(
            $form->get('newImages')->getConfig()->getOption('constraints'),
            static fn (object $constraint): bool => $constraint instanceof Count,
        ));

        self::assertCount(1, $counts);
        self::assertSame(5, $counts[0]->max);
        $message = static::getContainer()->get(TranslatorInterface::class)->trans($counts[0]->maxMessage, ['{{ limit }}' => 5], 'validators');
        self::assertSame('5 images au plus par envoi : enregistrez, puis ajoutez les suivantes.', $message);
    }

    /**
     * Vérifie que la limite de PHP (max_file_uploads) laisse passer 5 images et la couverture.
     */
    public function testUserIniAllowsFiveImagesAndCover(): void
    {
        $ini = parse_ini_file(\dirname(__DIR__, 2).'/public/.user.ini');
        self::assertIsArray($ini);
        self::assertSame('6', $ini['max_file_uploads']);
        self::assertSame('55M', $ini['post_max_size']);
        self::assertSame('50M', $ini['upload_max_filesize']);
        self::assertSame('0', (string) $ini['session.upload_progress.enabled']);
    }
}
