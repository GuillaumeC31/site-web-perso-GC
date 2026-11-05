<?php

declare(strict_types=1);

namespace App\Twig;

use Symfony\Component\PropertyAccess\PropertyAccess;
use Symfony\Component\PropertyAccess\PropertyAccessorInterface;
use Twig\Attribute\AsTwigFunction;

/**
 * Lit la valeur d'une colonne dans les listes génériques de l'admin.
 *
 * Usage : {{ admin_field_value(entity, 'pillar.name') }}
 *
 * Liens :
 * - Utilisée par : templates/admin/crud/index.html.twig
 * - Voir aussi : AbstractCrudController::getColumns() (chemins des colonnes)
 */
final class AdminCrudExtension
{
    private readonly PropertyAccessorInterface $propertyAccessor;

    /**
     * Prépare le lecteur de propriétés (getters, « is… », méthodes du même nom).
     */
    public function __construct()
    {
        $this->propertyAccessor = PropertyAccess::createPropertyAccessorBuilder()
            ->disableExceptionOnInvalidPropertyPath()
            ->getPropertyAccessor();
    }

    /**
     * Renvoie la valeur d'un chemin de propriété, ou null si un maillon est vide.
     *
     * Liens :
     * - Appelée par : la fonction Twig admin_field_value()
     *
     * @param object $entity ligne affichée
     * @param string $path   chemin, ex. « pillar.name » ou « isPublished »
     *
     * @return mixed la valeur brute (le template la met en forme selon le type de colonne)
     */
    #[AsTwigFunction('admin_field_value')]
    public function getFieldValue(object $entity, string $path): mixed
    {
        return $this->propertyAccessor->getValue($entity, $path);
    }
}
