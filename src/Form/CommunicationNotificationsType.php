<?php

declare(strict_types=1);

namespace App\Form;

use App\Entity\SiteSettings;
use App\Enum\NotificationChannel;
use App\Enum\NotificationEvent;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\EnumType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Form\FormEvent;
use Symfony\Component\Form\FormEvents;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * Communication › Notifications : tableau « événement → canaux » (e-mail, Discord, Telegram), une ligne de cases
 * par événement (déplacé de Paramètres le 2026-10-29).
 *
 * Liens :
 * - Utilisé par : Admin\CommunicationController::notifications()
 * - Entité : SiteSettings (canaux lus et écrits par getChannels() et setChannels())
 *
 * @extends AbstractType<SiteSettings>
 */
final class CommunicationNotificationsType extends AbstractType
{
    /**
     * Déclare un groupe de cases par événement (champ channels_<événement>).
     *
     * Liens :
     * - Appelée par : le composant Form
     *
     * @param array<string, mixed> $options options du formulaire
     */
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        foreach (NotificationEvent::cases() as $event) {
            $builder->add('channels_'.$event->value, EnumType::class, [
                'label' => $event->translationKey(), 'class' => NotificationChannel::class, 'multiple' => true, 'expanded' => true, 'mapped' => false, 'required' => false,
                'choice_label' => static fn (NotificationChannel $c): string => $c->translationKey(),
            ]);
        }

        // Canaux : lus depuis l'entité à l'affichage, recopiés dans l'entité à l'envoi
        $builder->addEventListener(FormEvents::POST_SET_DATA, static function (FormEvent $formEvent): void {
            $settings = $formEvent->getData();
            if ($settings instanceof SiteSettings) {
                foreach (NotificationEvent::cases() as $event) {
                    $formEvent->getForm()->get('channels_'.$event->value)->setData($settings->getChannels($event));
                }
            }
        });
        $builder->addEventListener(FormEvents::SUBMIT, static function (FormEvent $formEvent): void {
            $settings = $formEvent->getData();
            if ($settings instanceof SiteSettings) {
                foreach (NotificationEvent::cases() as $event) {
                    /** @var list<NotificationChannel> $channels */
                    $channels = $formEvent->getForm()->get('channels_'.$event->value)->getData() ?? [];
                    $settings->setChannels($event, $channels);
                }
            }
        });
    }

    /**
     * Associe le formulaire à l'entité SiteSettings.
     *
     * Liens :
     * - Appelée par : le composant Form
     */
    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults(['data_class' => SiteSettings::class]);
    }
}
