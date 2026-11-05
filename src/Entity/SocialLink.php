<?php

declare(strict_types=1);

namespace App\Entity;

use App\Entity\Trait\SortableTrait;
use App\Enum\SocialNetwork;
use App\Repository\SocialLinkRepository;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;
use Symfony\Component\Validator\Context\ExecutionContextInterface;

/**
 * Lien vers un réseau social (Communication › Réseaux sociaux, décision du 2026-10-29 ; remplace le champ unique
 * « LinkedIn » des paramètres). Simple lien : jamais de badge, widget ou bouton officiel (traceurs).
 *
 * Un nouveau lien est masqué jusqu'à son activation ; une fois visible, il s'affiche aux endroits cochés : pied de
 * page, page de maintenance, espace recruteur, données structurées (« sameAs »). Adresse https seulement, du domaine du
 * réseau (linkedin.com pour LinkedIn…) ; « Autre » : nom libre et toute adresse https.
 *
 * Liens :
 * - Utilisée par : Admin\SocialLinkController, Form\SocialLinkType, SocialLinkRepository::findVisibleFor(),
 *   Twig\SocialLinksExtension (pied de page, espace recruteur), MaintenancePage, Seo\SeoContext (sameAs),
 *   Legacy\LegacyMigrator (reprise de LinkedIn)
 */
#[ORM\Entity(repositoryClass: SocialLinkRepository::class)]
#[ORM\Table(name: 'social_link')]
class SocialLink implements SortableInterface, \Stringable
{
    use SortableTrait;

    /** Endroits où un lien peut s'afficher. */
    public const PLACE_FOOTER = 'footer';
    public const PLACE_MAINTENANCE = 'maintenance';
    public const PLACE_RECRUITER = 'recruiter';
    public const PLACE_STRUCTURED_DATA = 'structured_data';

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 20, enumType: SocialNetwork::class)]
    private SocialNetwork $network = SocialNetwork::LinkedIn;

    /** Nom affiché pour « Autre » (obligatoire), ou nom personnalisé facultatif. */
    #[ORM\Column(length: 60, nullable: true)]
    #[Assert\Length(max: 60)]
    private ?string $label = null;

    #[ORM\Column(length: 255)]
    #[Assert\NotBlank]
    #[Assert\Length(max: 255)]
    #[Assert\Url(requireTld: true)]
    private string $url = '';

    /** Visible sur le site (faux à la création : masqué jusqu'à activation). */
    #[ORM\Column(options: ['default' => false])]
    private bool $visible = false;

    #[ORM\Column(options: ['default' => true])]
    private bool $showInFooter = true;

    #[ORM\Column(options: ['default' => true])]
    private bool $showOnMaintenance = true;

    #[ORM\Column(options: ['default' => true])]
    private bool $showInRecruiterArea = true;

    #[ORM\Column(options: ['default' => true])]
    private bool $showInStructuredData = true;

    /** Renvoie le nom affiché (liste de l'admin, confirmation de suppression). */
    public function __toString(): string
    {
        return $this->getDisplayName();
    }

    /** Renvoie l'identifiant technique. */
    public function getId(): ?int
    {
        return $this->id;
    }

    /** Renvoie le réseau. */
    public function getNetwork(): SocialNetwork
    {
        return $this->network;
    }

    /** Définit le réseau. */
    public function setNetwork(SocialNetwork $network): static
    {
        $this->network = $network;

        return $this;
    }

    /** Renvoie le nom libre (null : nom du réseau). */
    public function getLabel(): ?string
    {
        return $this->label;
    }

    /** Définit le nom libre (vide : null). */
    public function setLabel(?string $label): static
    {
        $label = trim((string) $label);
        $this->label = '' === $label ? null : $label;

        return $this;
    }

    /**
     * Renvoie le nom affiché : nom libre s'il existe, sinon nom du réseau.
     *
     * Liens :
     * - Appelée par : templates/_partials/_social_links.html.twig, templates/maintenance.html.twig
     */
    public function getDisplayName(): string
    {
        return $this->label ?? $this->network->label();
    }

    /** Renvoie l'adresse. */
    public function getUrl(): string
    {
        return $this->url;
    }

    /** Définit l'adresse. */
    public function setUrl(?string $url): static
    {
        $this->url = trim((string) $url);

        return $this;
    }

    /** Indique si le lien est visible sur le site. */
    public function isVisible(): bool
    {
        return $this->visible;
    }

    /** Affiche ou masque le lien. */
    public function setVisible(bool $visible): static
    {
        $this->visible = $visible;

        return $this;
    }

    /** Indique si le lien s'affiche dans le pied de page. */
    public function isShowInFooter(): bool
    {
        return $this->showInFooter;
    }

    /** Affiche ou non le lien dans le pied de page. */
    public function setShowInFooter(bool $show): static
    {
        $this->showInFooter = $show;

        return $this;
    }

    /** Indique si le lien s'affiche sur la page de maintenance. */
    public function isShowOnMaintenance(): bool
    {
        return $this->showOnMaintenance;
    }

    /** Affiche ou non le lien sur la page de maintenance. */
    public function setShowOnMaintenance(bool $show): static
    {
        $this->showOnMaintenance = $show;

        return $this;
    }

    /** Indique si le lien s'affiche dans l'espace recruteur. */
    public function isShowInRecruiterArea(): bool
    {
        return $this->showInRecruiterArea;
    }

    /** Affiche ou non le lien dans l'espace recruteur. */
    public function setShowInRecruiterArea(bool $show): static
    {
        $this->showInRecruiterArea = $show;

        return $this;
    }

    /** Indique si le lien figure dans les données structurées (sameAs). */
    public function isShowInStructuredData(): bool
    {
        return $this->showInStructuredData;
    }

    /** Ajoute ou non le lien aux données structurées (sameAs). */
    public function setShowInStructuredData(bool $show): static
    {
        $this->showInStructuredData = $show;

        return $this;
    }

    /**
     * Indique si le lien s'affiche à un endroit (visible ET coché pour cet endroit).
     *
     * Liens :
     * - Appelée par : SocialLinkRepository::findVisibleFor()
     *
     * @param string $place un des PLACE_*
     */
    public function isShownAt(string $place): bool
    {
        return $this->visible && match ($place) {
            self::PLACE_FOOTER => $this->showInFooter,
            self::PLACE_MAINTENANCE => $this->showOnMaintenance,
            self::PLACE_RECRUITER => $this->showInRecruiterArea,
            self::PLACE_STRUCTURED_DATA => $this->showInStructuredData,
            default => false,
        };
    }

    /**
     * Vérifie l'adresse : https seulement, domaine du réseau (sauf « Autre ») ; nom libre obligatoire pour « Autre ».
     *
     * Liens :
     * - Appelée par : le validateur (Form\SocialLinkType)
     */
    #[Assert\Callback]
    public function validateUrl(ExecutionContextInterface $context): void
    {
        $parts = parse_url($this->url);
        if (!\is_array($parts) || 'https' !== strtolower((string) ($parts['scheme'] ?? ''))) {
            $context->buildViolation('admin.social_link.https_only')->atPath('url')->addViolation();
        } elseif (!$this->network->acceptsHost((string) ($parts['host'] ?? ''))) {
            $context->buildViolation('admin.social_link.wrong_domain')
                ->setParameter('%network%', $this->network->label())
                ->setParameter('%domains%', implode(', ', $this->network->hosts()))
                ->atPath('url')->addViolation();
        }
        if (SocialNetwork::Other === $this->network && null === $this->label) {
            $context->buildViolation('admin.social_link.label_required')->atPath('label')->addViolation();
        }
    }
}
