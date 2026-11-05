<?php

declare(strict_types=1);

namespace App\Service\Ai;

use App\Repository\AiUsageRepository;
use App\Repository\ChatMessageRepository;
use App\Repository\SiteSettingsRepository;

/**
 * État et consommation de l'assistant IA, pour le tableau de bord et la page « Assistant IA » de l'admin.
 *
 * États :
 * - active        : questions libres envoyées au modèle principal ;
 * - fallback      : modèle principal ménagé (reste faible, 429) ou retiré : le modèle de secours répond ;
 * - guided        : IA indisponible (clé absente, modèles retirés) ou désactivée : assistant guidé seul ;
 * - limit_reached : plafond du site pour la journée ou plafond mensuel atteint : assistant guidé seul.
 *
 * Liens :
 * - Utilisé par : Admin\DashboardController, Admin\AssistantController
 * - Utilise : AiQuota, AiModelMonitor, AiProviderInterface, ChatMessageRepository, AiUsageRepository, SiteSettings
 */
final readonly class AiStatus
{
    /**
     * Reçoit les dépendances.
     */
    public function __construct(
        private AiQuota $quota,
        private AiModelMonitor $modelMonitor,
        private AiProviderInterface $provider,
        private ChatMessageRepository $chatMessageRepository,
        private AiUsageRepository $aiUsageRepository,
        private SiteSettingsRepository $settingsRepository,
    ) {
    }

    /**
     * Renvoie l'état, la consommation du jour et du mois, et les alertes.
     *
     * Liens :
     * - Appelée par : Admin\DashboardController::index(), Admin\AssistantController::index()
     *
     * @param bool $refreshModels vrai : relit la liste des modèles chez Mistral AI
     *
     * @return array{
     *     state: 'active'|'fallback'|'guided'|'limit_reached',
     *     enabled: bool,
     *     configured: bool,
     *     provider: string,
     *     primary_model: string,
     *     fallback_model: string,
     *     model_alerts: list<array{role: 'primary'|'fallback', model: string, problem: string}>,
     *     today: array{questions: int, site_limit: int, tokens: int},
     *     month: array{tokens: int, limit: int, percent: float, alert: bool, alert_percent: int},
     *     purposes: list<array{purpose: \App\Enum\AiPurpose, calls: int, errors: int, tokens: int}>
     * }
     */
    public function getSummary(bool $refreshModels = false): array
    {
        $settings = $this->settingsRepository->getOrCreate();
        $today = $this->quota->startOfDay();
        $monthTokens = $this->quota->getMonthTokens();
        $monthLimit = max(1, $settings->getAiMonthlyTokenLimit());
        $percent = round(100 * $monthTokens / $monthLimit, 1);
        $todayQuestions = $this->chatMessageRepository->countFreeQuestionsSince($today);
        $alerts = $this->modelMonitor->getModelAlerts($refreshModels);
        $broken = array_column(array_filter($alerts, static fn (array $alert): bool => \in_array($alert['problem'], ['missing', 'archived', 'not_configured'], true)), 'role');

        $state = match (true) {
            !$settings->isAiAssistantEnabled(), !$this->provider->isConfigured(), \in_array('primary', $broken, true) && \in_array('fallback', $broken, true) => 'guided',
            $monthTokens >= $monthLimit, $todayQuestions >= $settings->getAiSiteDailyLimit() => 'limit_reached',
            \in_array('primary', $broken, true), $this->modelMonitor->isPrimaryResting() => 'fallback',
            default => 'active',
        };

        return [
            'state' => $state,
            'enabled' => $settings->isAiAssistantEnabled(),
            'configured' => $this->provider->isConfigured(),
            'provider' => $this->provider->getName(),
            'primary_model' => $this->modelMonitor->getPrimaryModel(),
            'fallback_model' => $this->modelMonitor->getFallbackModel(),
            'model_alerts' => $alerts,
            'today' => [
                'questions' => $todayQuestions,
                'site_limit' => $settings->getAiSiteDailyLimit(),
                'tokens' => $this->aiUsageRepository->sumTokensSince($today),
            ],
            'month' => [
                'tokens' => $monthTokens,
                'limit' => $monthLimit,
                'percent' => $percent,
                'alert' => $percent >= $settings->getAiAlertPercent(),
                'alert_percent' => $settings->getAiAlertPercent(),
            ],
            'purposes' => $this->aiUsageRepository->summarizeSince($this->quota->startOfMonth()),
        ];
    }
}
