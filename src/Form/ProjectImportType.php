<?php

declare(strict_types=1);

namespace App\Form;

use App\Service\ProjectPackage\ProjectPackageFormat;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\FileType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Validator\Constraints\File;
use Symfony\Component\Validator\Constraints\NotNull;

/**
 * Formulaire d'envoi d'une archive ZIP de projet (ce site ou l'ancien site).
 *
 * Liens :
 * - Utilisé par : Admin\ProjectController::import()
 * - Traité par : ProjectImporter
 *
 * @extends AbstractType<null>
 */
final class ProjectImportType extends AbstractType
{
    /**
     * Déclare le champ d'envoi.
     *
     * Liens :
     * - Appelée par : le composant Form
     *
     * @param array<string, mixed> $options options du formulaire
     */
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder->add('archive', FileType::class, [
            'label' => 'admin.project.import.archive',
            'help' => 'admin.project.import.help',
            'attr' => ['accept' => '.zip,application/zip'],
            'constraints' => [
                new NotNull(message: 'admin.project.import.missing'),
                new File(
                    maxSize: ProjectPackageFormat::MAX_ARCHIVE_BYTES,
                    // Extension ET type réel du fichier vérifiés
                    extensions: ['zip' => ['application/zip', 'application/x-zip-compressed']],
                ),
            ],
        ]);
    }
}
