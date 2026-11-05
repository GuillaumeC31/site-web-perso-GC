<?php

declare(strict_types=1);

namespace App\Enum;

/**
 * Réseau d'un lien social (Communication › Réseaux sociaux) : nom affiché, domaines permis pour l'adresse (contrôle de
 * SocialLink), icône SVG locale (templates/_partials/_social_icon.html.twig). « Autre » : nom libre, toute adresse
 * https, icône générique. Mastodon (v2.0.2) : toute instance en https, comme « Autre », mais nom et icône fixes ;
 * Bluesky (v2.0.2) : bsky.app seulement.
 *
 * Liens :
 * - Utilisé par : App\Entity\SocialLink, Form\SocialLinkType, templates/_partials/_social_links.html.twig
 */
enum SocialNetwork: string
{
    case LinkedIn = 'linkedin';
    case GitHub = 'github';
    case YouTube = 'youtube';
    case Facebook = 'facebook';
    case Instagram = 'instagram';
    case X = 'x';
    case Discord = 'discord';
    case Telegram = 'telegram';
    case Malt = 'malt';
    case Bluesky = 'bluesky';
    case Mastodon = 'mastodon';
    case Other = 'other';

    /**
     * Renvoie le nom du réseau (« Autre » : le nom libre du lien le remplace).
     *
     * Liens :
     * - Appelée par : SocialLink::getDisplayName(), Form\SocialLinkType (liste des réseaux)
     */
    public function label(): string
    {
        return match ($this) {
            self::LinkedIn => 'LinkedIn',
            self::GitHub => 'GitHub',
            self::YouTube => 'YouTube',
            self::Facebook => 'Facebook',
            self::Instagram => 'Instagram',
            self::X => 'X',
            self::Discord => 'Discord',
            self::Telegram => 'Telegram',
            self::Malt => 'Malt',
            self::Bluesky => 'Bluesky',
            self::Mastodon => 'Mastodon',
            self::Other => 'Autre',
        };
    }

    /**
     * Renvoie les domaines permis pour l'adresse (sous-domaines compris, ex. « www.linkedin.com »).
     *
     * Liens :
     * - Appelée par : SocialLink::validateUrl()
     *
     * @return list<string> domaines ; vide pour « Autre » et Mastodon (toute adresse https : chaque instance a son
     *                      propre domaine)
     */
    public function hosts(): array
    {
        return match ($this) {
            self::LinkedIn => ['linkedin.com'],
            self::GitHub => ['github.com'],
            self::YouTube => ['youtube.com', 'youtu.be'],
            self::Facebook => ['facebook.com', 'fb.com'],
            self::Instagram => ['instagram.com'],
            self::X => ['x.com', 'twitter.com'],
            self::Discord => ['discord.gg', 'discord.com'],
            self::Telegram => ['t.me', 'telegram.me'],
            self::Malt => ['malt.fr', 'malt.com'],
            self::Bluesky => ['bsky.app'],
            self::Mastodon, self::Other => [],
        };
    }

    /**
     * Indique si l'adresse appartient à un domaine permis du réseau.
     *
     * Liens :
     * - Appelée par : SocialLink::validateUrl() ; tests/Entity/SocialLinkTest
     */
    public function acceptsHost(string $host): bool
    {
        $host = strtolower($host);
        if ([] === $this->hosts()) {
            return '' !== $host;
        }
        foreach ($this->hosts() as $allowed) {
            if ($host === $allowed || str_ends_with($host, '.'.$allowed)) {
                return true;
            }
        }

        return false;
    }
}
