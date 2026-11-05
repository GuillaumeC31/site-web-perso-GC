<?php

declare(strict_types=1);

namespace App\Service\Ai;

use App\Entity\Recruiter;
use App\Repository\AiUsageRepository;
use App\Repository\ChatMessageRepository;
use App\Repository\SiteSettingsRepository;
use Psr\Clock\ClockInterface;

/**
 * Plafonds de l'assistant IA (réglables dans l'admin, Réglages du site) :
 * - questions libres par recruteur et par jour (20 par défaut) ;
 * - questions libres par jour pour tout le site (100 par défaut) ;
 * - jetons par mois (5 000 000 par défaut, hypothèse à confirmer), comptés à partir des jetons réels
 *   renvoyés par l'API (AiUsage), tous usages confondus (chatbot, import de CV, synthèse).
 *
 * Les journées et les mois commencent à minuit, heure de Paris.
 *
 * Liens :
 * - Utilisé par : ChatAssistant::ask(), CvImportAnalyzer, CompanySynthesizer, AiStatus
 * - Utilise : SiteSettings (plafonds), ChatMessageRepository, AiUsageRepository
 */
final readonly class AiQuota
{
    /** Plafond par recruteur atteint. */
    public const RECRUITER_DAILY = 'recruiter_daily';

    /** Plafond du site pour la journée atteint. */
    public const SITE_DAILY = 'site_daily';

    /** Plafond mensuel de jetons atteint. */
    public const MONTHLY_TOKENS = 'monthly_tokens';

    private const TIMEZONE = 'Europe/Paris';

    /**
     * Reçoit les dépendances.
     */
    public function __construct(
        private SiteSettingsRepository $settingsRepository,
        private ChatMessageRepository $chatMessageRepository,
        private AiUsageRepository $aiUsageRepository,
        private ClockInterface $clock,
    ) {
    }

    /**
     * Renvoie le plafond atteint pour une question libre, ou null si la question peut partir.
     *
     * Liens :
     * - Appelée par : ChatAssistant::ask()
     *
     * @param Recruiter|null $recruiter recruteur qui pose la question ; null : usage de l'admin (seul le plafond mensuel compte)
     *
     * @return string|null RECRUITER_DAILY, SITE_DAILY, MONTHLY_TOKENS ou null
     */
    public function getReachedLimit(?Recruiter $recruiter): ?string
    {
        $settings = $this->settingsRepository->getOrCreate();
        if ($this->getMonthTokens() >= $settings->getAiMonthlyTokenLimit()) {
            return self::MONTHLY_TOKENS;
        }
        if (null === $recruiter) {
            return null;
        }
        $today = $this->startOfDay();
        if ($this->chatMessageRepository->countFreeQuestionsSince($today) >= $settings->getAiSiteDailyLimit()) {
            return self::SITE_DAILY;
        }
        if ($this->chatMessageRepository->countFreeQuestionsSince($today, $recruiter) >= $settings->getAiRecruiterDailyLimit()) {
            return self::RECRUITER_DAILY;
        }

        return null;
    }

    /**
     * Renvoie le nombre de questions libres qu'il reste au recruteur aujourd'hui (plafond du site compris).
     *
     * Liens :
     * - Appelée par : RecruiterAreaController::home() (affiché sous le champ de question)
     */
    public function getRemainingToday(Recruiter $recruiter): int
    {
        $settings = $this->settingsRepository->getOrCreate();
        $today = $this->startOfDay();

        return max(0, min(
            $settings->getAiRecruiterDailyLimit() - $this->chatMessageRepository->countFreeQuestionsSince($today, $recruiter),
            $settings->getAiSiteDailyLimit() - $this->chatMessageRepository->countFreeQuestionsSince($today),
        ));
    }

    /**
     * Renvoie les jetons consommés depuis le début du mois (heure de Paris).
     *
     * Liens :
     * - Appelée par : getReachedLimit(), AiStatus::getSummary()
     */
    public function getMonthTokens(): int
    {
        return $this->aiUsageRepository->sumTokensSince($this->startOfMonth());
    }

    /**
     * Renvoie minuit (heure de Paris) du jour en cours, dans le fuseau de l'application.
     *
     * Liens :
     * - Appelée par : getReachedLimit(), AiStatus::getSummary()
     */
    public function startOfDay(): \DateTimeImmutable
    {
        $now = $this->clock->now();

        return $now->setTimezone(new \DateTimeZone(self::TIMEZONE))->setTime(0, 0)->setTimezone($now->getTimezone());
    }

    /**
     * Renvoie le premier jour du mois à minuit (heure de Paris), dans le fuseau de l'application.
     *
     * Liens :
     * - Appelée par : getMonthTokens(), AiStatus::getSummary()
     */
    public function startOfMonth(): \DateTimeImmutable
    {
        $now = $this->clock->now();

        return $now->setTimezone(new \DateTimeZone(self::TIMEZONE))->modify('first day of this month')->setTime(0, 0)->setTimezone($now->getTimezone());
    }
}
