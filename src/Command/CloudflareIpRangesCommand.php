<?php

declare(strict_types=1);

namespace App\Command;

use App\Enum\NotificationEvent;
use App\Security\CloudflareIpRanges;
use App\Service\Notification\Notification;
use App\Service\Notification\NotificationDispatcher;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Compare les plages de config/cloudflare_ip_ranges.php à la liste officielle publiée par Cloudflare
 * (https://www.cloudflare.com/ips-v4 et ips-v6).
 *
 * Aucune mise à jour automatique : en cas d'écart, la commande affiche les plages ajoutées et retirées, échoue
 * (code 1) et, avec --notify, envoie une alerte « Général du site » (évènement SecurityAlert). La liste est alors à
 * recopier à la main dans le fichier versionné, puis à déployer.
 *
 * Usage : php bin/console app:security:cloudflare-ips [--notify]   (tâche cron hebdomadaire conseillée)
 *
 * Liens :
 * - Utilise : Security\CloudflareIpRanges, NotificationDispatcher
 * - Testé par : tests/Command/CloudflareIpRangesCommandTest (réponses simulées)
 */
#[AsCommand(name: 'app:security:cloudflare-ips', description: 'Compare les plages Cloudflare de confiance à la liste officielle (alerte si elle a changé)')]
final class CloudflareIpRangesCommand extends Command
{
    /** Listes officielles de Cloudflare. */
    public const SOURCES = ['ipv4' => 'https://www.cloudflare.com/ips-v4', 'ipv6' => 'https://www.cloudflare.com/ips-v6'];

    /**
     * Reçoit les dépendances.
     *
     * @param HttpClientInterface    $httpClient   client HTTP (listes officielles)
     * @param NotificationDispatcher $notifier     alerte « Général du site »
     * @param UrlGeneratorInterface  $urlGenerator lien vers le tableau de bord
     * @param string                 $projectDir   racine du projet (fichier versionné)
     */
    public function __construct(
        private readonly HttpClientInterface $httpClient,
        private readonly NotificationDispatcher $notifier,
        private readonly UrlGeneratorInterface $urlGenerator,
        #[Autowire('%kernel.project_dir%')]
        private readonly string $projectDir,
    ) {
        parent::__construct();
    }

    /**
     * Déclare l'option --notify.
     */
    protected function configure(): void
    {
        $this->addOption('notify', null, InputOption::VALUE_NONE, 'Envoie une alerte « Général du site » si la liste a changé');
    }

    /**
     * Télécharge les listes officielles et les compare au fichier versionné.
     *
     * Liens :
     * - Appelée par : la console Symfony (tâche cron hebdomadaire)
     *
     * @return int Command::SUCCESS si identiques ; Command::FAILURE si elles diffèrent ou si la liste est injoignable
     */
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $local = CloudflareIpRanges::read($this->projectDir);

        $added = [];
        $removed = [];
        foreach (self::SOURCES as $family => $url) {
            try {
                $response = $this->httpClient->request('GET', $url, ['timeout' => 10]);
                $official = $this->parse($response->getContent());
            } catch (\Throwable $exception) {
                $io->error(\sprintf('Liste officielle injoignable (%s) : %s', $url, $exception->getMessage()));

                return Command::FAILURE;
            }
            if ([] === $official) {
                $io->error(\sprintf('Liste officielle vide ou illisible (%s) : rien n\'est modifié.', $url));

                return Command::FAILURE;
            }
            $added = [...$added, ...array_values(array_diff($official, $local[$family]))];
            $removed = [...$removed, ...array_values(array_diff($local[$family], $official))];
        }

        if ([] === $added && [] === $removed) {
            $io->success(\sprintf('Plages Cloudflare à jour (fichier relevé le %s).', $local['checked_at']));

            return Command::SUCCESS;
        }

        $io->warning('La liste officielle de Cloudflare a changé : config/cloudflare_ip_ranges.php est à mettre à jour à la main, puis à déployer.');
        if ([] !== $added) {
            $io->listing(array_map(static fn (string $range): string => 'à ajouter : '.$range, $added));
        }
        if ([] !== $removed) {
            $io->listing(array_map(static fn (string $range): string => 'à retirer : '.$range, $removed));
        }
        if ((bool) $input->getOption('notify')) {
            $this->notifier->notify(new Notification(
                NotificationEvent::SecurityAlert,
                'Plages d\'adresses Cloudflare modifiées',
                ['Plages à ajouter' => (string) \count($added), 'Plages à retirer' => (string) \count($removed), 'À faire' => 'mettre à jour config/cloudflare_ip_ranges.php puis déployer'],
                $this->urlGenerator->generate('admin_dashboard', [], UrlGeneratorInterface::ABSOLUTE_URL),
            ));
        }

        return Command::FAILURE;
    }

    /**
     * Lit une liste publiée (une plage CIDR par ligne).
     *
     * @param string $content texte reçu
     *
     * @return list<string> plages valides
     */
    private function parse(string $content): array
    {
        $ranges = [];
        foreach (preg_split('/\R/', trim($content)) ?: [] as $line) {
            $line = trim($line);
            if (1 === preg_match('#^[0-9a-f:.]+/\d{1,3}$#i', $line)) {
                $ranges[] = strtolower($line);
            }
        }

        return $ranges;
    }
}
