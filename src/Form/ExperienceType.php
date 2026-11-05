<?php

declare(strict_types=1);

namespace App\Form;

use App\Entity\Experience;
use App\Entity\Skill;
use App\Enum\CareerSection;
use Doctrine\ORM\EntityRepository;
use Doctrine\ORM\QueryBuilder;
use Symfony\Bridge\Doctrine\Form\Type\EntityType;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\DateType;
use Symfony\Component\Form\Extension\Core\Type\EnumType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Form\FormEvent;
use Symfony\Component\Form\FormEvents;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * Formulaire d'une expérience professionnelle.
 *
 * « Visible en public » : une case par puce déjà enregistrée (réalisations, puis missions) ; une puce ajoutée ou
 * modifiée apparaît après l'enregistrement. Les puces non cochées restent dans l'espace recruteur.
 *
 * Liens :
 * - Utilisé par : Admin\ExperienceController (via AbstractCrudController)
 * - Entité : Experience
 *
 * @extends AbstractType<Experience>
 */
final class ExperienceType extends AbstractType
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
        $lines = ['help' => 'admin.form.one_per_line', 'required' => false, 'attr' => ['rows' => 5]];

        $builder
            ->add('jobTitle', TextType::class, ['label' => 'admin.experience.field.job_title'])
            ->add('companyName', TextType::class, ['label' => 'admin.experience.field.company_name', 'help' => 'admin.experience.help.company_name', 'required' => false])
            ->add('isCompanyAnonymized', CheckboxType::class, ['label' => 'admin.experience.field.is_company_anonymized', 'required' => false])
            ->add('publicCompanyLabel', TextType::class, ['label' => 'admin.experience.field.public_company_label', 'help' => 'admin.experience.help.public_company_label', 'required' => false])
            ->add('companyDetails', TextType::class, ['label' => 'admin.experience.field.company_details', 'required' => false])
            ->add('sector', TextType::class, ['label' => 'admin.experience.field.sector', 'required' => false])
            ->add('companySize', TextType::class, ['label' => 'admin.experience.field.company_size', 'required' => false])
            ->add('location', TextType::class, ['label' => 'admin.experience.field.location', 'required' => false])
            ->add('startDate', DateType::class, ['label' => 'admin.experience.field.start_date', 'widget' => 'single_text', 'input' => 'datetime_immutable', 'help' => 'admin.experience.help.dates'])
            ->add('endDate', DateType::class, ['label' => 'admin.experience.field.end_date', 'widget' => 'single_text', 'input' => 'datetime_immutable', 'required' => false, 'help' => 'admin.experience.help.end_date'])
            ->add('datesInYears', CheckboxType::class, ['label' => 'admin.experience.field.dates_in_years', 'help' => 'admin.experience.help.dates_in_years', 'required' => false])
            ->add('summary', TextareaType::class, ['label' => 'admin.experience.field.summary', 'required' => false, 'attr' => ['rows' => 3]])
            ->add('missions', TextareaType::class, ['label' => 'admin.experience.field.missions'] + $lines)
            ->add('achievements', TextareaType::class, ['label' => 'admin.experience.field.achievements', 'help' => 'admin.experience.help.achievements'] + $lines)
            ->add('tools', TextareaType::class, ['label' => 'admin.experience.field.tools'] + $lines)
            ->add('skills', EntityType::class, [
                'label' => 'admin.experience.field.skills',
                'class' => Skill::class,
                'multiple' => true,
                'expanded' => true,
                'required' => false,
                'group_by' => static fn (Skill $skill): string => (string) $skill->getPillar()?->getName(),
                'query_builder' => static fn (EntityRepository $repository): QueryBuilder => $repository->createQueryBuilder('s')
                    ->join('s.pillar', 'p')
                    ->addSelect('p')
                    ->orderBy('p.position', 'ASC')
                    ->addOrderBy('s.position', 'ASC'),
            ])
            ->add('careerSection', EnumType::class, [
                'label' => 'admin.experience.field.career_section',
                'help' => 'admin.experience.help.career_section',
                'class' => CareerSection::class,
                'choice_label' => static fn (CareerSection $section): string => $section->translationKey(),
            ])
            ->add('privateNote', TextType::class, ['label' => 'admin.experience.field.private_note', 'help' => 'admin.experience.help.private_note', 'required' => false])
            ->add('isPublished', CheckboxType::class, ['label' => 'admin.experience.field.is_published', 'required' => false]);

        // Une case « Visible en public » par puce enregistrée (CV public allégé, 2026-10-30)
        $builder->addEventListener(FormEvents::PRE_SET_DATA, static function (FormEvent $event): void {
            $experience = $event->getData();
            $items = $experience instanceof Experience ? $experience->getItemList() : [];
            $event->getForm()->add('publicItems', ChoiceType::class, [
                'label' => 'admin.experience.field.public_items',
                'help' => [] === $items ? 'admin.experience.help.public_items_empty' : 'admin.experience.help.public_items',
                'choices' => array_combine($items, $items),
                'choice_label' => static fn (string $item): string => str_replace('**', '', $item),
                'choice_translation_domain' => false,
                'multiple' => true,
                'expanded' => true,
                'required' => false,
            ]);
        });
    }

    /**
     * Associe le formulaire à l'entité Experience.
     *
     * Liens :
     * - Appelée par : le composant Form
     */
    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults(['data_class' => Experience::class]);
    }
}
