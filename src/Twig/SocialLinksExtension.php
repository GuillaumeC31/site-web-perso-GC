<?php

declare(strict_types=1);

namespace App\Twig;

use App\Entity\SocialLink;
use App\Repository\SocialLinkRepository;
use Twig\Attribute\AsTwigFunction;

/**
 * Expose aux gabarits les liens sociaux visibles à un endroit (Communication › Réseaux sociaux).
 *
 * Usage : {% for link in social_links('footer') %} … ; endroits : footer, recruiter (la page de maintenance et les
 * données structurées les reçoivent de leur service : MaintenancePage, SeoContext).
 *
 * Liens :
 * - Utilise : SocialLinkRepository::findVisibleFor()
 * - Utilisée par : templates/_partials/_footer.html.twig, templates/recruiter_area/home.html.twig
 */
final readonly class SocialLinksExtension
{
    /**
     * Reçoit le dépôt des liens.
     *
     * Liens :
     * - Appelée par : le conteneur Symfony (autowiring), seulement quand la fonction est utilisée
     */
    public function __construct(private SocialLinkRepository $repository)
    {
    }

    /**
     * Renvoie les liens visibles à un endroit, dans l'ordre d'affichage.
     *
     * Liens :
     * - Appelée par : la fonction Twig social_links()
     *
     * @param string $place SocialLink::PLACE_FOOTER ou SocialLink::PLACE_RECRUITER
     *
     * @return list<SocialLink> liens (vide : rien à afficher)
     */
    #[AsTwigFunction('social_links')]
    public function getLinks(string $place): array
    {
        return $this->repository->findVisibleFor($place);
    }
}
