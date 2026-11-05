<?php

declare(strict_types=1);

namespace App\Command;

use App\Enum\EmailType;
use App\Enum\MailCategory;
use App\Service\Dashboard\DashboardMetrics;
use App\Service\Mail\MailFactory;
use App\Service\Mail\MailRecipients;
use App\Service\Mail\ResilientMailer;
use Psr\Clock\ClockInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Envoie à l'admin le résumé hebdomadaire du tableau de bord : mêmes indicateurs que la page, chiffres
 * seulement (aucune donnée personnelle de recruteur), vers l'adresse « Général du site » (Communication ›
 * E-mails). Objet et introduction se règlent dans Contenu des e-mails ; la date suit l'objet.
 *
 * À lancer chaque lundi à 7 h par la tâche planifiée de l’hébergeur :
 *   php bin/console app:dashboard:weekly-summary --env=prod
 *
 * Liens :
 * - Utilise : DashboardMetrics::getTiles(true), MailFactory::createTyped(), ResilientMailer (gardé pour un renvoi si
 *   le serveur d'envoi est injoignable), MailRecipients::addressFor(General)
 * - Rend : templates/email/admin/dashboard_summary.html.twig et .txt.twig
 * - Testée par : tests/Command/DashboardWeeklySummaryCommandTest
 */
#[AsCommand(name: 'app:dashboard:weekly-summary', description: 'Envoie le résumé hebdomadaire du tableau de bord à l\'admin (chiffres seulement)')]
final class DashboardWeeklySummaryCommand extends Command
{
    /**
     * Reçoit les dépendances.
     *
     * @param DashboardMetrics $metrics     indicateurs (relus, sans cache)
     * @param MailRecipients   $recipients  adresse « Général du site »
     * @param MailFactory      $mailFactory construction de l'e-mail
     * @param ResilientMailer  $mailer      envoi (gardé pour un renvoi en cas d'échec)
     * @param ClockInterface   $clock       date de l'objet
     */
    public function __construct(
        private readonly DashboardMetrics $metrics,
        private readonly MailRecipients $recipients,
        private readonly MailFactory $mailFactory,
        private readonly ResilientMailer $mailer,
        private readonly ClockInterface $clock,
    ) {
        parent::__construct();
    }

    /**
     * Calcule les indicateurs et envoie le résumé.
     *
     * Liens :
     * - Appelée par : la console Symfony (tâche planifiée du lundi)
     *
     * @return int Command::SUCCESS (e-mail parti, ou gardé pour un renvoi), Command::FAILURE sans adresse « Général du site »
     */
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $address = $this->recipients->addressFor(MailCategory::General);
        if (null === $address) {
            $io->error('Aucune adresse « Général du site » (Communication › E-mails) : résumé non envoyé.');

            return Command::FAILURE;
        }

        $dashboard = $this->metrics->getTiles(true);
        $date = $this->clock->now()->setTimezone(new \DateTimeZone('Europe/Paris'))->format('d/m/Y');
        $email = $this->mailFactory->createTyped(EmailType::DashboardSummary, $address, [
            'tiles' => $dashboard['tiles'],
            'computed_at' => $dashboard['computed_at'],
        ], subjectSuffix: ' — '.$date);
        $sent = $this->mailer->send($email, ResilientMailer::KIND_DASHBOARD_SUMMARY, category: MailCategory::General);
        $io->success($sent ? 'Résumé hebdomadaire envoyé.' : 'Serveur d\'envoi injoignable : résumé gardé pour un renvoi depuis le tableau de bord.');

        return Command::SUCCESS;
    }
}
