<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Entity\Certification;
use App\Entity\SortableInterface;
use App\Form\CertificationType;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Gestion des certifications et habilitations dans l'admin (liste, ajout, modification, suppression, ordre).
 *
 * Liens :
 * - Routes : admin_certification_index, _new, _edit, _delete, _move (/{ADMIN_PATH}/certifications)
 * - Hérite de : AbstractCrudController (actions et templates communs)
 * - Utilise : Certification, CertificationType
 *
 * @extends AbstractCrudController<Certification>
 */
#[IsGranted('ADMIN_KEY_VERIFIED')]
#[Route('/certifications', name: 'certification_')]
final class CertificationController extends AbstractCrudController
{
    /** Renvoie la classe de l'entité gérée. */
    protected function getEntityClass(): string
    {
        return Certification::class;
    }

    /** Crée une entité vide pour le formulaire d'ajout. */
    protected function createEntity(): SortableInterface
    {
        return new Certification();
    }

    /** Renvoie la classe du formulaire. */
    protected function getFormType(): string
    {
        return CertificationType::class;
    }

    /** Renvoie le préfixe des noms de routes. */
    protected function getRoutePrefix(): string
    {
        return 'admin_certification';
    }

    /** Renvoie le préfixe des clés de traduction. */
    protected function getTranslationPrefix(): string
    {
        return 'admin.certification';
    }

    /** Décrit les colonnes de la liste. */
    protected function getColumns(): array
    {
        return [
            ['label' => 'admin.certification.field.name', 'field' => 'name'],
            ['label' => 'admin.certification.field.type', 'field' => 'type', 'type' => 'enum'],
            ['label' => 'admin.certification.field.expires_on', 'field' => 'expiresOn', 'type' => 'date'],
        ];
    }
}
