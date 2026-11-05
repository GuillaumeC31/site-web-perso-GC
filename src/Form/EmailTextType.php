<?php

declare(strict_types=1);

namespace App\Form;

use App\Service\Mail\EmailTexts;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;

/**
 * Communication › E-mails › Contenu d'un e-mail : objet, phrase d'introduction, signature (texte simple ; contrôle
 * complet par EmailTexts::validate() dans le contrôleur). Deux boutons : « Aperçu » et « Enregistrer ».
 *
 * Liens :
 * - Utilisé par : Admin\CommunicationController::emailText()
 * - Données : tableau {subject, intro, signature} (EmailTexts::raw())
 *
 * @extends AbstractType<array{subject: string, intro: string, signature: string}>
 */
final class EmailTextType extends AbstractType
{
    /**
     * Déclare les trois champs.
     *
     * Liens :
     * - Appelée par : le composant Form
     *
     * @param array<string, mixed> $options options du formulaire
     */
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('subject', TextType::class, ['label' => 'admin.communication.field.subject', 'help' => 'admin.communication.help.subject', 'attr' => ['maxlength' => EmailTexts::MAX_SUBJECT], 'empty_data' => ''])
            ->add('intro', TextareaType::class, ['label' => 'admin.communication.field.intro', 'help' => 'admin.communication.help.intro', 'required' => false, 'attr' => ['maxlength' => EmailTexts::MAX_INTRO, 'rows' => 5], 'empty_data' => ''])
            ->add('signature', TextareaType::class, ['label' => 'admin.communication.field.signature', 'help' => 'admin.communication.help.signature', 'required' => false, 'attr' => ['maxlength' => EmailTexts::MAX_SIGNATURE, 'rows' => 3], 'empty_data' => '']);
    }
}
