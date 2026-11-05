<?php

declare(strict_types=1);

namespace App\Form;

use App\Entity\CvEntry;
use App\Enum\CvEntrySection;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\EnumType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * Formulaire d'un élément d'une rubrique « liste » du CV.
 *
 * Liens :
 * - Utilisé par : Admin\CvEntryController (via AbstractCrudController)
 * - Entité : CvEntry
 *
 * @extends AbstractType<CvEntry>
 */
final class CvEntryType extends AbstractType
{
    /**
     * Déclare les champs du formulaire.
     *
     * Liens :
     * - Appelée par : le composant Form
     *
     * @param array<string, mixed> $options options du formulaire
     */
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('section', EnumType::class, [
                'label' => 'admin.cv_entry.field.section',
                'class' => CvEntrySection::class,
                'choice_label' => static fn (CvEntrySection $section): string => $section->translationKey(),
            ])
            ->add('title', TextType::class, ['label' => 'admin.cv_entry.field.title', 'help' => 'admin.cv_entry.help.title', 'required' => false])
            ->add('content', TextareaType::class, ['label' => 'admin.cv_entry.field.content', 'attr' => ['rows' => 3]])
            // Résumé du CV public (outils techniques, expertises, logiciels), 2026-10-30
            ->add('publicSummary', TextType::class, ['label' => 'admin.cv_entry.field.public_summary', 'help' => 'admin.cv_entry.help.public_summary', 'required' => false, 'attr' => ['maxlength' => 255]]);
    }

    /**
     * Associe le formulaire à l'entité CvEntry.
     *
     * Liens :
     * - Appelée par : le composant Form
     */
    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults(['data_class' => CvEntry::class]);
    }
}
