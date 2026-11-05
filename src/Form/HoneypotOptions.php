<?php

declare(strict_types=1);

namespace App\Form;

/**
 * Options du champ piège des formulaires publics (« website ») : non lié à l'entité, masqué aux
 * visiteurs (classe hp-field, hors du flux de tabulation, sans saisie automatique). Un robot qui le
 * remplit reçoit la réponse normale, mais rien n'est enregistré ni notifié.
 *
 * Liens :
 * - Utilisé par : ContactType, RecruiterRequestType, LoginLinkRequestType
 * - Voir aussi : assets/styles/app.css (.hp-field)
 */
final class HoneypotOptions
{
    /** Options du champ piège. */
    public const OPTIONS = [
        'label' => 'form.honeypot', 'mapped' => false, 'required' => false,
        'row_attr' => ['class' => 'hp-field', 'aria-hidden' => 'true'],
        'attr' => ['tabindex' => '-1', 'autocomplete' => 'off'],
    ];
}
