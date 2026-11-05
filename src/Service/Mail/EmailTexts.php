<?php

declare(strict_types=1);

namespace App\Service\Mail;

use App\Entity\EmailText;
use App\Entity\EmailTextRevision;
use App\Enum\EmailType;
use App\Repository\EmailTextRepository;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Clock\ClockInterface;

/**
 * Textes modifiables des e-mails (Communication › E-mails › Contenu des e-mails) : objet, phrase
 * d'introduction et signature de chaque type, avec repli sur les textes d'origine (EmailType::default*()).
 *
 * Règles (décision du 2026-10-29) : texte simple seulement (aucune balise, échappé à l'affichage), longueur
 * limitée, variables {nom}, {entreprise}, {delai} seulement (toute autre accolade est refusée). La structure, les
 * liens de connexion, les boutons et les mentions obligatoires restent fixes dans les gabarits.
 *
 * Liens :
 * - Utilisé par : MailFactory::createTyped() (textes de chaque e-mail), Admin\CommunicationController (formulaire,
 *   aperçu, « Rétablir le texte d'origine »)
 * - Utilise : EmailTextRepository, EmailTextRevision (historique)
 * - Testé par : tests/Service/Mail/EmailTextsTest
 */
final readonly class EmailTexts
{
    /** Variables permises dans les textes. */
    public const VARIABLES = ['{nom}', '{entreprise}', '{delai}'];

    /** Longueurs maximales (caractères). */
    public const MAX_SUBJECT = 150;
    public const MAX_INTRO = 1000;
    public const MAX_SIGNATURE = 300;

    /**
     * Reçoit les dépendances.
     */
    public function __construct(
        private EmailTextRepository $repository,
        private EntityManagerInterface $entityManager,
        private ClockInterface $clock,
    ) {
    }

    /**
     * Renvoie les trois textes d'un type, modifiés ou d'origine, SANS remplacer les variables (formulaire).
     *
     * Liens :
     * - Appelée par : resolve(), Admin\CommunicationController::emailText()
     *
     * @return array{subject: string, intro: string, signature: string, modified: bool}
     */
    public function raw(EmailType $type): array
    {
        $text = $this->repository->findOneByType($type);

        return [
            'subject' => $text?->getSubject() ?? $type->defaultSubject(),
            'intro' => $text?->getIntro() ?? $type->defaultIntro(),
            'signature' => $text?->getSignature() ?? $type->defaultSignature(),
            'modified' => null !== $text && (null !== $text->getSubject() || null !== $text->getIntro() || null !== $text->getSignature()),
        ];
    }

    /**
     * Renvoie les trois textes d'un type, variables remplacées (valeur absente : texte vide). Les textes restent
     * du texte brut : les gabarits les échappent.
     *
     * Liens :
     * - Appelée par : MailFactory::createTyped(), Admin\CommunicationController::preview()
     *
     * @param array{nom?: string|null, entreprise?: string|null, delai?: string|null} $values valeurs des variables
     * @param array{subject?: string, intro?: string, signature?: string}|null        $draft  textes à essayer (aperçu
     *                                                                                        avant enregistrement), ou null
     *
     * @return array{subject: string, intro: string, signature: string}
     */
    public function resolve(EmailType $type, array $values = [], ?array $draft = null): array
    {
        $texts = array_merge($this->raw($type), $draft ?? []);
        $replacements = [
            '{nom}' => trim((string) ($values['nom'] ?? '')),
            '{entreprise}' => trim((string) ($values['entreprise'] ?? '')),
            '{delai}' => trim((string) ($values['delai'] ?? '')),
        ];

        return [
            'subject' => trim(strtr($texts['subject'], $replacements)),
            'intro' => trim(strtr($texts['intro'], $replacements)),
            'signature' => trim(strtr($texts['signature'], $replacements)),
        ];
    }

    /**
     * Vérifie des textes saisis : objet obligatoire (sauf rien à dire), longueur, aucune balise, seules les
     * variables permises entre accolades, pas de saut de ligne dans l'objet.
     *
     * Liens :
     * - Appelée par : Admin\CommunicationController::emailText() (enregistrement et aperçu)
     *
     * @return array<string, string> champ (subject, intro, signature) => clé de traduction de l'erreur ; vide si tout est bon
     */
    public function validate(string $subject, string $intro, string $signature): array
    {
        $errors = [];
        $fields = ['subject' => [$subject, self::MAX_SUBJECT], 'intro' => [$intro, self::MAX_INTRO], 'signature' => [$signature, self::MAX_SIGNATURE]];
        foreach ($fields as $field => [$value, $max]) {
            $error = match (true) {
                'subject' === $field && '' === trim($value) => 'admin.communication.text_error.subject_required',
                mb_strlen($value) > $max => 'admin.communication.text_error.too_long',
                1 === preg_match('/<[^>]*>|<\s*[a-z!\/]/i', $value) => 'admin.communication.text_error.html',
                [] !== self::unknownPlaceholders($value) => 'admin.communication.text_error.placeholder',
                'subject' === $field && 1 === preg_match('/[\r\n]/', $value) => 'admin.communication.text_error.subject_line',
                default => null,
            };
            if (null !== $error) {
                $errors[$field] = $error;
            }
        }

        return $errors;
    }

    /**
     * Enregistre les textes d'un type (texte identique à celui d'origine : gardé comme « d'origine ») et note la
     * modification dans l'historique.
     *
     * Liens :
     * - Appelée par : Admin\CommunicationController::emailText() (après validate())
     */
    public function save(EmailType $type, string $subject, string $intro, string $signature): void
    {
        $now = $this->clock->now();
        $subject = self::normalize($subject, $type->defaultSubject());
        $intro = self::normalize($intro, $type->defaultIntro());
        $signature = self::normalize($signature, $type->defaultSignature());
        $text = $this->repository->findOneByType($type) ?? new EmailText($type, $now);
        $text->update($subject, $intro, $signature, $now);
        $this->entityManager->persist($text);
        $this->entityManager->persist(new EmailTextRevision($type, EmailTextRevision::ACTION_SAVED, $subject, $intro, $signature, $now));
        $this->entityManager->flush();
    }

    /**
     * Rétablit les textes d'origine d'un type et le note dans l'historique.
     *
     * Liens :
     * - Appelée par : Admin\CommunicationController::resetEmailText()
     */
    public function reset(EmailType $type): void
    {
        $text = $this->repository->findOneByType($type);
        if (null !== $text) {
            $this->entityManager->remove($text);
        }
        $this->entityManager->persist(new EmailTextRevision($type, EmailTextRevision::ACTION_RESET, null, null, null, $this->clock->now()));
        $this->entityManager->flush();
    }

    /**
     * Renvoie les accolades d'un texte qui ne sont pas des variables permises.
     *
     * @return list<string> ex. ['{prenom}'] ; vide si tout est permis
     */
    public static function unknownPlaceholders(string $value): array
    {
        // Toute accolade isolée compte aussi (« {nom » ou « } »)
        $withoutKnown = str_replace(self::VARIABLES, '', $value);
        preg_match_all('/\{[^{}]*\}?|\}/u', $withoutKnown, $matches);

        return $matches[0];
    }

    /**
     * Nettoie un texte saisi (fins de ligne unifiées, espaces de bord retirés) ; null s'il vaut le texte d'origine.
     */
    private static function normalize(string $value, string $default): ?string
    {
        $value = trim(str_replace(["\r\n", "\r"], "\n", $value));

        return $value === $default ? null : $value;
    }
}
