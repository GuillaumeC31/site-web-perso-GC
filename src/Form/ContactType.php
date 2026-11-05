<?php

declare(strict_types=1);

namespace App\Form;

use App\Entity\ContactMessage;
use App\Enum\ContactSubject;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\EmailType;
use Symfony\Component\Form\Extension\Core\Type\EnumType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints\IsTrue;

/**
 * Formulaire de contact (TEXTES_SITE § 10) : nom, e-mail, entreprise (facultative), objet, message,
 * consentement ; plus un champ piège invisible (« website ») que seuls les robots remplissent.
 *
 * Liens :
 * - Utilisé par : ContactController::show() (route contact_show, /contact)
 * - Entité : ContactMessage
 *
 * @extends AbstractType<ContactMessage>
 */
final class ContactType extends AbstractType
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
            ->add('name', TextType::class, ['label' => 'contact.field.name', 'attr' => ['autocomplete' => 'name']])
            ->add('email', EmailType::class, ['label' => 'contact.field.email', 'attr' => ['autocomplete' => 'email']])
            ->add('company', TextType::class, ['label' => 'contact.field.company', 'required' => false, 'attr' => ['autocomplete' => 'organization']])
            ->add('subject', EnumType::class, ['label' => 'contact.field.subject', 'class' => ContactSubject::class, 'placeholder' => 'contact.field.subject_placeholder', 'required' => true, 'choice_label' => static fn (ContactSubject $s): string => $s->translationKey()])
            ->add('message', TextareaType::class, ['label' => 'contact.field.message', 'attr' => ['rows' => 6]])
            ->add('consent', CheckboxType::class, [
                'label' => 'contact.field.consent', 'mapped' => false, 'constraints' => [new IsTrue(message: 'contact.consent_required')],
            ])
            ->add('website', TextType::class, HoneypotOptions::OPTIONS);
    }

    /**
     * Associe le formulaire à l'entité ContactMessage.
     *
     * Liens :
     * - Appelée par : le composant Form
     */
    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults(['data_class' => ContactMessage::class]);
    }
}
