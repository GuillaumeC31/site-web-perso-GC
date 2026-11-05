<?php

declare(strict_types=1);

namespace App\Service\Ai;

use App\Entity\AiKnowledgeEntry;
use App\Enum\AiKnowledgeCategory;
use App\Repository\AiKnowledgeEntryRepository;
use App\Service\Legacy\LegacyDatabaseReader;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Reprend les directives du chatbot de l'ancien site (table chatbot_directive de ../guillaume_c, lue sans
 * jamais l'écrire) en fiches du « Cerveau de l'IA », toujours en BROUILLON inactif (l'admin relit et active).
 *
 * Correspondance des types : « Info Personnelle » → Infos personnelles, « FAQ » → FAQ recruteurs,
 * « Ton & Personnalité » → Ton et style. Les « Règles strictes » ne sont PAS importées : elles sont listées à
 * part (les règles restent dans le socle de sécurité). Une directive qui contient une donnée bloquée
 * (AiKnowledgeValidator) ou déjà reprise (même contenu) est écartée, avec son motif.
 *
 * Liens :
 * - Utilisé par : Command\AiImportDirectivesCommand (aperçu, puis --apply)
 * - Utilise : LegacyDatabaseReader::getChatbotDirectives(), AiKnowledgeValidator, AiKnowledgeEntryRepository
 * - Testé par : tests/Service/Ai/AiDirectiveImporterTest
 */
final readonly class AiDirectiveImporter
{
    /** Types de l'ancien site repris en fiches (type => catégorie). */
    public const TYPE_MAP = [
        'Info Personnelle' => AiKnowledgeCategory::Personal,
        'FAQ' => AiKnowledgeCategory::Faq,
        'Ton & Personnalité' => AiKnowledgeCategory::Tone,
    ];

    /** Type de l'ancien site jamais repris en fiche (listé à part). */
    public const STRICT_TYPE = 'Règle Stricte';

    /** Longueur maximale du titre généré à partir du contenu. */
    private const TITLE_LENGTH = 70;

    /**
     * Reçoit les dépendances.
     */
    public function __construct(
        private AiKnowledgeValidator $validator,
        private AiKnowledgeEntryRepository $entryRepository,
        private EntityManagerInterface $entityManager,
    ) {
    }

    /**
     * Prépare l'import et l'applique si demandé.
     *
     * Liens :
     * - Appelée par : AiImportDirectivesCommand::execute()
     *
     * @param LegacyDatabaseReader $reader ancienne base (lecture seule)
     * @param bool                 $apply  vrai : crée les fiches (brouillons inactifs) ; faux : aperçu seulement
     *
     * @return array{
     *     imports: list<array{id: int, category: AiKnowledgeCategory, title: string, content: string}>,
     *     strict: list<array{id: int, content: string, active: bool}>,
     *     skipped: list<array{id: int, type: string, reason: string}>
     * } fiches créées (ou prévues), règles strictes à décider, directives écartées et motif (clé de traduction ou texte)
     */
    public function import(LegacyDatabaseReader $reader, bool $apply): array
    {
        $result = ['imports' => [], 'strict' => [], 'skipped' => []];
        $position = 100;
        foreach ($reader->getChatbotDirectives() as $directive) {
            if (self::STRICT_TYPE === $directive['type']) {
                $result['strict'][] = ['id' => $directive['id'], 'content' => $directive['content'], 'active' => $directive['active']];
                continue;
            }
            $category = self::TYPE_MAP[$directive['type']] ?? null;
            if (null === $category) {
                $result['skipped'][] = ['id' => $directive['id'], 'type' => $directive['type'], 'reason' => 'type inconnu'];
                continue;
            }
            if ('' === $directive['content']) {
                $result['skipped'][] = ['id' => $directive['id'], 'type' => $directive['type'], 'reason' => 'contenu vide'];
                continue;
            }
            if ($this->entryRepository->existsWithContent($directive['content'])) {
                $result['skipped'][] = ['id' => $directive['id'], 'type' => $directive['type'], 'reason' => 'déjà reprise'];
                continue;
            }
            $title = $this->makeTitle($directive['content']);
            $blocked = $this->validator->validate($title."\n".$directive['content']);
            if ([] !== $blocked) {
                $result['skipped'][] = ['id' => $directive['id'], 'type' => $directive['type'], 'reason' => implode(', ', $blocked)];
                continue;
            }
            $result['imports'][] = ['id' => $directive['id'], 'category' => $category, 'title' => $title, 'content' => $directive['content']];
            if ($apply) {
                $this->entityManager->persist((new AiKnowledgeEntry())
                    ->setTitle($title)->setContent($directive['content'])->setCategory($category)
                    ->setActive(false)->setPosition($position));
                $position += 10;
            }
        }
        if ($apply) {
            $this->entityManager->flush();
        }

        return $result;
    }

    /**
     * Construit un titre à partir du début du contenu (l'ancien site n'avait pas de titre).
     *
     * Exemple : « Guillaume est mobile sur toute la France et… » → « Guillaume est mobile sur toute la France… ».
     */
    private function makeTitle(string $content): string
    {
        $firstLine = trim((string) preg_replace('/\s+/u', ' ', strtok($content, "\n") ?: $content));
        if (mb_strlen($firstLine) <= self::TITLE_LENGTH) {
            return rtrim($firstLine, ' .');
        }
        $cut = mb_substr($firstLine, 0, self::TITLE_LENGTH);

        return rtrim((string) preg_replace('/\s+\S*$/u', '', $cut), ' ,;:.').'…';
    }
}
