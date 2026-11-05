<?php

declare(strict_types=1);

namespace App\Form;

use App\Entity\SocialLink;
use App\Enum\SocialNetwork;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\EnumType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\Extension\Core\Type\UrlType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * Lien vers un réseau social : réseau, nom libre (« Autre »), adresse https, visible ou non, et cases « Où l'afficher »
 * (pied de page, page de maintenance, espace recruteur, données structurées).
 *
 * Liens :
 * - Utilisé par : Admin\SocialLinkController (new(), edit())
 * - Entité : SocialLink (contrôle de l'adresse : SocialLink::validateUrl())
 *
 * @extends AbstractType<SocialLink>
 */
final class SocialLinkType extends AbstractType
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
            ->add('network', EnumType::class, [
                'label' => 'admin.social_link.field.network', 'class' => SocialNetwork::class,
                'choice_label' => static fn (SocialNetwork $network): string => $network->label(), 'choice_translation_domain' => false,
            ])
            ->add('label', TextType::class, ['label' => 'admin.social_link.field.label', 'help' => 'admin.social_link.help.label', 'required' => false, 'attr' => ['maxlength' => 60]])
            ->add('url', UrlType::class, ['label' => 'admin.social_link.field.url', 'help' => 'admin.social_link.help.url', 'default_protocol' => 'https', 'attr' => ['maxlength' => 255, 'placeholder' => 'https://']])
            ->add('visible', CheckboxType::class, ['label' => 'admin.social_link.field.visible', 'help' => 'admin.social_link.help.visible', 'required' => false])
            ->add('showInFooter', CheckboxType::class, ['label' => 'admin.social_link.place.footer', 'required' => false])
            ->add('showOnMaintenance', CheckboxType::class, ['label' => 'admin.social_link.place.maintenance', 'required' => false])
            ->add('showInRecruiterArea', CheckboxType::class, ['label' => 'admin.social_link.place.recruiter', 'required' => false])
            ->add('showInStructuredData', CheckboxType::class, ['label' => 'admin.social_link.place.structured_data', 'help' => 'admin.social_link.help.structured_data', 'required' => false]);
    }

    /**
     * Associe le formulaire à l'entité SocialLink.
     *
     * Liens :
     * - Appelée par : le composant Form
     */
    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults(['data_class' => SocialLink::class]);
    }
}
