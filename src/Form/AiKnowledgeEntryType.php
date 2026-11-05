<?php

declare(strict_types=1);

namespace App\Form;

use App\Entity\AiKnowledgeEntry;
use App\Enum\AiKnowledgeCategory;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\EnumType;
use Symfony\Component\Form\Extension\Core\Type\IntegerType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * Fiche de connaissances du « Cerveau de l'IA » : titre, catégorie, contenu, active, ordre.
 *
 * Liens :
 * - Utilisé par : Admin\AiKnowledgeController (new, edit)
 * - Entité : AiKnowledgeEntry
 *
 * @extends AbstractType<AiKnowledgeEntry>
 */
final class AiKnowledgeEntryType extends AbstractType
{
    /**
     * Déclare les champs.
     *
     * Liens :
     * - Appelée par : le composant Form
     *
     * @param array<string, mixed> $options options du formulaire
     */
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('title', TextType::class, ['label' => 'admin.ai_knowledge.field.title', 'attr' => ['maxlength' => 120]])
            ->add('category', EnumType::class, [
                'class' => AiKnowledgeCategory::class,
                'label' => 'admin.ai_knowledge.field.category',
                'choice_label' => static fn (AiKnowledgeCategory $category): string => $category->translationKey(),
            ])
            ->add('content', TextareaType::class, [
                'label' => 'admin.ai_knowledge.field.content',
                'help' => 'admin.ai_knowledge.help.content',
                'attr' => ['rows' => 7, 'maxlength' => 4000],
            ])
            ->add('active', CheckboxType::class, ['label' => 'admin.ai_knowledge.field.active', 'help' => 'admin.ai_knowledge.help.active', 'required' => false])
            ->add('position', IntegerType::class, ['label' => 'admin.ai_knowledge.field.position', 'help' => 'admin.ai_knowledge.help.position', 'attr' => ['min' => 0]]);
    }

    /**
     * Relie le formulaire à l'entité.
     */
    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults(['data_class' => AiKnowledgeEntry::class]);
    }
}
