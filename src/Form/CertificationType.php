<?php

declare(strict_types=1);

namespace App\Form;

use App\Entity\Certification;
use App\Enum\CertificationType as CertificationKind;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\DateType;
use Symfony\Component\Form\Extension\Core\Type\EnumType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * Formulaire d'une certification ou d'une habilitation (avec dates de validité).
 *
 * Liens :
 * - Utilisé par : Admin\CertificationController (via AbstractCrudController)
 * - Entité : Certification ; enum App\Enum\CertificationType (importé sous le nom CertificationKind)
 *
 * @extends AbstractType<Certification>
 */
final class CertificationType extends AbstractType
{
    /**
     * Déclare les champs du formulaire.
     *
     * @param array<string, mixed> $options options du formulaire
     */
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $date = ['widget' => 'single_text', 'input' => 'datetime_immutable', 'required' => false];

        $builder
            ->add('name', TextType::class, ['label' => 'admin.certification.field.name'])
            ->add('type', EnumType::class, [
                'label' => 'admin.certification.field.type',
                'class' => CertificationKind::class,
                'choice_label' => static fn (CertificationKind $kind): string => $kind->translationKey(),
            ])
            ->add('issuer', TextType::class, ['label' => 'admin.certification.field.issuer', 'required' => false])
            ->add('obtainedOn', DateType::class, ['label' => 'admin.certification.field.obtained_on'] + $date)
            ->add('expiresOn', DateType::class, ['label' => 'admin.certification.field.expires_on', 'help' => 'admin.certification.help.expires_on'] + $date);
    }

    /**
     * Associe le formulaire à l'entité Certification.
     */
    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults(['data_class' => Certification::class]);
    }
}
