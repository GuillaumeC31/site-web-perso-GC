<?php

declare(strict_types=1);

namespace App\Form;

use App\Entity\RecruitmentArgument;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * Formulaire d'un argument « Pourquoi me recruter » : titre, texte d'une à deux phrases, interrupteur « Afficher ».
 *
 * Liens :
 * - Utilisé par : Admin\RecruitmentArgumentController (formulaire générique admin/crud/form.html.twig)
 * - Entité : RecruitmentArgument
 *
 * @extends AbstractType<RecruitmentArgument>
 */
final class RecruitmentArgumentType extends AbstractType
{
    /**
     * Déclare les champs du formulaire.
     *
     * @param array<string, mixed> $options options du formulaire
     */
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('title', TextType::class, ['label' => 'admin.recruitment_argument.field.title'])
            ->add('text', TextareaType::class, ['label' => 'admin.recruitment_argument.field.text', 'help' => 'admin.recruitment_argument.help.text', 'attr' => ['rows' => 3]])
            ->add('visible', CheckboxType::class, ['label' => 'admin.recruitment_argument.field.visible', 'help' => 'admin.recruitment_argument.help.visible', 'required' => false]);
    }

    /**
     * Associe le formulaire à l'entité RecruitmentArgument.
     */
    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults(['data_class' => RecruitmentArgument::class]);
    }
}
