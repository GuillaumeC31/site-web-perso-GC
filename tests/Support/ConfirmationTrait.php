<?php

declare(strict_types=1);

namespace App\Tests\Support;

use Symfony\Bundle\FrameworkBundle\KernelBrowser;

/**
 * Confirmation d'une action importante sans JavaScript, comme dans un navigateur sans script : le
 * formulaire arrive sans _confirmed, le serveur affiche sa page de confirmation, on la valide.
 *
 * Liens :
 * - Utilisé par : tests des actions confirmées (recruteurs, e-mails, suppressions, mode maintenance)
 * - Couvre : App\Service\ActionConfirmation, templates/confirmation/confirm.html.twig
 */
trait ConfirmationTrait
{
    /**
     * Vérifie que la page de confirmation est affichée (rien n'est encore fait), puis la valide.
     *
     * @param KernelBrowser         $client navigateur de test, sur la réponse à l'envoi du formulaire
     * @param string|null           $title  question attendue (facultatif)
     * @param array<string, string> $server en-têtes de l'envoi (ex. HTTP_ORIGIN)
     */
    private function confirmAction(KernelBrowser $client, ?string $title = null, array $server = []): void
    {
        // 422 : affichée aussi par Turbo (qui ignore une réponse 200 à un envoi de formulaire)
        self::assertResponseStatusCodeSame(422, 'Page de confirmation attendue.');
        self::assertSelectorExists('[data-testid="confirmation-page"][role="alertdialog"]');
        if (null !== $title) {
            self::assertSelectorTextContains('[data-testid="confirmation-page"] h1', $title);
        }
        $form = $client->getCrawler()->filter('[data-testid="confirmation-page"] form')->form();
        self::assertSame('1', $form->get('_confirmed')->getValue());
        $client->submit($form, [], $server);
    }
}
