<?php

declare(strict_types=1);

namespace App\Service;

use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Twig\Environment;

/**
 * Confirmation des actions importantes ou irréversibles, même sans JavaScript.
 *
 * Avec JavaScript, le contrôleur Stimulus « notify » ouvre une fenêtre « Confirmer / Annuler » et envoie
 * le formulaire avec le champ caché _confirmed=1. Sans JavaScript (ou si la fenêtre n'a pas pu s'ouvrir),
 * le formulaire arrive sans ce champ : le contrôleur affiche alors une page de confirmation qui renvoie les
 * mêmes champs (jeton CSRF compris) avec _confirmed=1, ou permet d'annuler.
 *
 * Exemple dans un contrôleur :
 *   if (!$confirmation->isConfirmed($request)) {
 *       return $confirmation->render($request, 'Supprimer ce recruteur ?', 'Action définitive.', 'Supprimer', $this->generateUrl('admin_recruiter_show', ['id' => $id]));
 *   }
 *
 * Liens :
 * - Utilisée par : Admin\RecruiterController (decide, delete), Admin\MailController::resend(),
 *   Admin\SiteSettingsController::edit() (mode maintenance), RecruiterAreaController::deleteData()
 * - Rend : templates/confirmation/confirm.html.twig
 * - Voir aussi : assets/controllers/lazy/notify_controller.js (confirm), templates/_partials/_confirm.html.twig
 */
final class ActionConfirmation
{
    /** Champ caché qui porte la confirmation. */
    public const FIELD = '_confirmed';

    /**
     * Reçoit le moteur de gabarits.
     */
    public function __construct(private readonly Environment $twig)
    {
    }

    /**
     * Indique si l'action a été confirmée (fenêtre ou page de confirmation).
     *
     * Liens :
     * - Appelée par : les actions listées dans le docblock de la classe
     *
     * @param Request $request requête POST de l'action
     *
     * @return bool vrai si le champ _confirmed vaut « 1 »
     */
    public function isConfirmed(Request $request): bool
    {
        return '1' === $request->request->getString(self::FIELD);
    }

    /**
     * Affiche la page de confirmation : les champs reçus sont renvoyés tels quels, avec _confirmed=1.
     *
     * Liens :
     * - Rend : templates/confirmation/confirm.html.twig
     *
     * @param Request $request      requête POST de l'action (adresse et champs à renvoyer)
     * @param string  $title        question posée (ex. « Supprimer ce recruteur ? »)
     * @param string  $text         précision (conséquences), vide si aucune
     * @param string  $confirmLabel libellé du bouton de confirmation
     * @param string  $cancelUrl    adresse du lien « Annuler »
     * @param bool    $danger       vrai : bouton rouge (action irréversible)
     * @param string  $layout       gabarit parent (admin/base.html.twig ou base.html.twig)
     *
     * @return Response page de confirmation, statut 422 : Turbo n'affiche pas une réponse 200 à un envoi de
     *                  formulaire (il attend une redirection), mais affiche une réponse 422 (comme un formulaire à
     *                  reprendre) ; sans JavaScript, le navigateur l'affiche normalement
     */
    public function render(Request $request, string $title, string $text, string $confirmLabel, string $cancelUrl, bool $danger = true, string $layout = 'admin/base.html.twig'): Response
    {
        $fields = $request->request->all();
        unset($fields[self::FIELD]);

        return new Response($this->twig->render('confirmation/confirm.html.twig', [
            'layout' => $layout,
            'title' => $title,
            'text' => $text,
            'confirm_label' => $confirmLabel,
            'cancel_url' => $cancelUrl,
            'danger' => $danger,
            'action' => $request->getRequestUri(),
            'fields' => $this->flatten($fields),
        ]), Response::HTTP_UNPROCESSABLE_ENTITY);
    }

    /**
     * Met à plat les champs reçus (tableaux des formulaires Symfony compris) en paires nom / valeur.
     *
     * @param array<array-key, mixed> $fields champs reçus
     * @param string                  $prefix nom du parent (« site_settings »), vide au premier niveau
     *
     * @return list<array{name: string, value: string}> champs à renvoyer, dans l'ordre reçu
     */
    private function flatten(array $fields, string $prefix = ''): array
    {
        $flat = [];
        foreach ($fields as $key => $value) {
            $name = '' === $prefix ? (string) $key : $prefix.'['.$key.']';
            if (\is_array($value)) {
                array_push($flat, ...$this->flatten($value, $name));
            } elseif (\is_scalar($value)) {
                $flat[] = ['name' => $name, 'value' => (string) $value];
            }
        }

        return $flat;
    }
}
