<?php

declare(strict_types=1);

namespace App\Enum;

/**
 * État physique d'un fluide de référence de la calculatrice.
 *
 * Valeurs reprises de l'ancien site (« liquide », « gaz »).
 *
 * Liens :
 * - Utilisé par : Fluid (colonne state), FluidType (formulaire)
 */
enum FluidState: string
{
    case Liquid = 'liquide';
    case Gas = 'gaz';

    /**
     * Renvoie la clé de traduction du libellé.
     *
     * Liens :
     * - Appelée par : App\Form\FluidType (choix), templates de l'admin
     */
    public function translationKey(): string
    {
        return 'fluid.state.'.$this->value;
    }
}
