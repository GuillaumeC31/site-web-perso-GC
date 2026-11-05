<?php

declare(strict_types=1);

namespace App\Entity;

use App\Entity\Trait\TimestampTrait;
use App\Repository\UserRepository;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Validator\Constraints\UniqueEntity;
use Symfony\Component\Security\Core\User\PasswordAuthenticatedUserInterface;
use Symfony\Component\Security\Core\User\UserInterface;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Compte d'administration du site (un seul compte en pratique).
 *
 * Connexion en deux étapes : identifiant + mot de passe (form_login), puis clé YubiKey
 * (WebAuthn). L'identifiant est libre et distinct de l'e-mail, qui sert seulement aux
 * notifications. Le compte est créé et réparé uniquement en console (SSH).
 *
 * Liens :
 * - Utilisée par : security.yaml (fournisseur app_user_provider), SecondFactorVoter,
 *   SecondFactorController, CreateAdminCommand, ResetAdminPasswordCommand, ResetAdminWebauthnCommand
 * - Voir aussi : WebauthnCredential (clés enregistrées, reliées par webauthnUserHandle)
 */
#[ORM\Entity(repositoryClass: UserRepository::class)]
#[ORM\Table(name: 'app_user')]
#[ORM\HasLifecycleCallbacks]
#[UniqueEntity(fields: ['username'], message: 'user.username.taken')]
class User implements UserInterface, PasswordAuthenticatedUserInterface
{
    use TimestampTrait;

    /** Format de l'identifiant : minuscules, chiffres, point, tiret, souligné. */
    public const USERNAME_PATTERN = '/^[a-z0-9._-]{3,64}$/';

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    /** Identifiant de connexion, libre (jamais l'adresse e-mail). */
    #[ORM\Column(length: 64, unique: true)]
    #[Assert\NotBlank]
    #[Assert\Regex(pattern: self::USERNAME_PATTERN, message: 'user.username.invalid')]
    private string $username;

    /** Adresse de notification (alertes, demandes recruteurs). N'est pas un identifiant de connexion. */
    #[ORM\Column(length: 180, nullable: true)]
    #[Assert\Email]
    private ?string $notificationEmail = null;

    /**
     * État du tableau de bord déjà vu par l'admin (tuile => statut), pour n'afficher le récapitulatif à
     * l'ouverture que si quelque chose a changé depuis (DashboardMetrics::getDigest()).
     *
     * @var array<string, string>|null
     */
    #[ORM\Column(nullable: true)]
    private ?array $dashboardSeen = null;

    /**
     * Filtres choisis sur la page Messages, repris à la visite suivante (filtre, type, tri ; jamais la recherche).
     *
     * @var array<string, string>|null
     */
    #[ORM\Column(nullable: true)]
    private ?array $messageFilters = null;

    /** @var list<string> rôles du compte (ROLE_ADMIN pour l'administrateur) */
    #[ORM\Column]
    private array $roles = [];

    /** Empreinte du mot de passe (jamais le mot de passe en clair). */
    #[ORM\Column]
    private string $password = '';

    /**
     * Identifiant WebAuthn du compte (« user handle »), aléatoire et sans donnée personnelle.
     * Il relie le compte à ses clés (WebauthnCredential::userHandle).
     */
    #[ORM\Column(length: 64, unique: true)]
    private string $webauthnUserHandle;

    /**
     * Numéro de version de sécurité. Il augmente à chaque réinitialisation (mot de passe
     * ou clés) : un marqueur de session « clé vérifiée » d'une version plus ancienne
     * n'est plus accepté, ce qui déconnecte les sessions ouvertes.
     */
    #[ORM\Column(options: ['default' => 1])]
    private int $securityVersion = 1;

    /** Empreinte SHA-256 du jeton d'enrôlement en cours (le jeton lui-même n'est jamais stocké). */
    #[ORM\Column(length: 64, nullable: true)]
    private ?string $enrollmentTokenHash = null;

    /** Date limite d'utilisation du jeton d'enrôlement (30 minutes après sa création). */
    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $enrollmentTokenExpiresAt = null;

    /**
     * Crée un compte avec un identifiant WebAuthn aléatoire.
     *
     * Liens :
     * - Appelée par : CreateAdminCommand::execute(), tests (AdminTestTrait)
     *
     * @param string $username identifiant de connexion
     */
    public function __construct(string $username)
    {
        $this->username = $username;
        // 32 octets aléatoires encodés en base64url (43 caractères)
        $this->webauthnUserHandle = rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
    }

    /** Renvoie l'identifiant technique (null avant enregistrement). */
    public function getId(): ?int
    {
        return $this->id;
    }

    /** Renvoie l'identifiant de connexion. */
    public function getUsername(): string
    {
        return $this->username;
    }

    /**
     * Renvoie l'identifiant utilisé par le composant Security (ici l'identifiant de connexion).
     *
     * Liens :
     * - Appelée par : le composant Security (session, journal, throttling)
     *
     * @return non-empty-string
     */
    public function getUserIdentifier(): string
    {
        \assert('' !== $this->username);

        return $this->username;
    }

    /** Renvoie l'adresse de notification, ou null. */
    public function getNotificationEmail(): ?string
    {
        return $this->notificationEmail;
    }

    /** Définit l'adresse de notification (null pour aucune). */
    public function setNotificationEmail(?string $notificationEmail): static
    {
        $this->notificationEmail = $notificationEmail;

        return $this;
    }

    /**
     * Renvoie les rôles du compte, avec ROLE_USER toujours présent.
     *
     * @return list<string>
     */
    public function getRoles(): array
    {
        $roles = $this->roles;
        $roles[] = 'ROLE_USER';

        return array_values(array_unique($roles));
    }

    /**
     * Définit les rôles du compte.
     *
     * @param list<string> $roles ex. ['ROLE_ADMIN']
     */
    public function setRoles(array $roles): static
    {
        $this->roles = $roles;

        return $this;
    }

    /** Indique si le compte est administrateur. */
    public function isAdmin(): bool
    {
        return \in_array('ROLE_ADMIN', $this->roles, true);
    }

    /** Renvoie l'empreinte du mot de passe. */
    public function getPassword(): string
    {
        return $this->password;
    }

    /**
     * Enregistre une nouvelle empreinte de mot de passe et invalide les sessions existantes.
     *
     * Liens :
     * - Appelée par : CreateAdminCommand, ResetAdminPasswordCommand,
     *   le composant Security (mise à niveau automatique de l'empreinte)
     *
     * @param string $hashedPassword empreinte calculée par UserPasswordHasherInterface
     */
    public function setPassword(string $hashedPassword): static
    {
        $this->password = $hashedPassword;

        return $this;
    }

    /** Renvoie l'identifiant WebAuthn (user handle) du compte. */
    public function getWebauthnUserHandle(): string
    {
        return $this->webauthnUserHandle;
    }

    /** Renvoie la version de sécurité actuelle. */
    public function getSecurityVersion(): int
    {
        return $this->securityVersion;
    }

    /**
     * Augmente la version de sécurité : tous les marqueurs « clé vérifiée » existants deviennent invalides.
     *
     * Liens :
     * - Appelée par : ResetAdminPasswordCommand, ResetAdminWebauthnCommand
     */
    public function incrementSecurityVersion(): static
    {
        ++$this->securityVersion;

        return $this;
    }

    /**
     * Enregistre l'empreinte d'un nouveau jeton d'enrôlement et sa date limite.
     *
     * Liens :
     * - Appelée par : EnrollmentTokenManager::issue()
     *
     * @param string             $tokenHash empreinte SHA-256 (hexadécimale) du jeton
     * @param \DateTimeImmutable $expiresAt date limite d'utilisation
     */
    public function setEnrollmentToken(string $tokenHash, \DateTimeImmutable $expiresAt): static
    {
        $this->enrollmentTokenHash = $tokenHash;
        $this->enrollmentTokenExpiresAt = $expiresAt;

        return $this;
    }

    /**
     * Efface le jeton d'enrôlement (après usage ou à l'expiration).
     *
     * Liens :
     * - Appelée par : EnrollmentTokenManager::consume()
     */
    public function clearEnrollmentToken(): static
    {
        $this->enrollmentTokenHash = null;
        $this->enrollmentTokenExpiresAt = null;

        return $this;
    }

    /** Renvoie l'empreinte du jeton d'enrôlement en cours, ou null. */
    public function getEnrollmentTokenHash(): ?string
    {
        return $this->enrollmentTokenHash;
    }

    /** Renvoie la date limite du jeton d'enrôlement, ou null. */
    public function getEnrollmentTokenExpiresAt(): ?\DateTimeImmutable
    {
        return $this->enrollmentTokenExpiresAt;
    }

    /**
     * Ne fait rien : aucune donnée sensible temporaire n'est conservée sur l'objet.
     *
     * Liens :
     * - Appelée par : le composant Security (après authentification)
     */
    public function eraseCredentials(): void
    {
    }

    /**
     * Limite les données mises en session : l'empreinte du jeton d'enrôlement n'y figure pas.
     *
     * Liens :
     * - Appelée par : PHP (sérialisation du jeton de sécurité en session)
     *
     * @return array<string, mixed>
     */
    public function __serialize(): array
    {
        return [
            'id' => $this->id,
            'username' => $this->username,
            'password' => $this->password,
            'roles' => $this->roles,
            'webauthnUserHandle' => $this->webauthnUserHandle,
            'securityVersion' => $this->securityVersion,
        ];
    }

    /**
     * Reconstruit l'objet depuis la session.
     *
     * @param array<string, mixed> $data données produites par __serialize()
     */
    public function __unserialize(array $data): void
    {
        $this->id = \is_int($data['id'] ?? null) ? $data['id'] : null;
        $this->username = (string) ($data['username'] ?? '');
        $this->password = (string) ($data['password'] ?? '');
        $this->roles = array_values(array_map('strval', (array) ($data['roles'] ?? [])));
        $this->webauthnUserHandle = (string) ($data['webauthnUserHandle'] ?? '');
        $this->securityVersion = (int) ($data['securityVersion'] ?? 1);
    }

    /**
     * Renvoie l'état du tableau de bord déjà vu (tuile => statut), ou null avant la première visite.
     *
     * @return array<string, string>|null
     */
    public function getDashboardSeen(): ?array
    {
        return $this->dashboardSeen;
    }

    /**
     * Note l'état du tableau de bord vu par l'admin.
     *
     * @param array<string, string> $dashboardSeen tuile => statut
     */
    public function setDashboardSeen(array $dashboardSeen): static
    {
        $this->dashboardSeen = $dashboardSeen;

        return $this;
    }

    /**
     * Renvoie les filtres mémorisés de la page Messages.
     *
     * Liens :
     * - Appelée par : Admin\ContactMessageController::index()
     *
     * @return array<string, string>|null filtre, type, tri ; null si jamais choisis
     */
    public function getMessageFilters(): ?array
    {
        return $this->messageFilters;
    }

    /**
     * Mémorise les filtres de la page Messages.
     *
     * Liens :
     * - Appelée par : Admin\ContactMessageController::index()
     *
     * @param array<string, string> $messageFilters filtre, type, tri
     */
    public function setMessageFilters(array $messageFilters): static
    {
        $this->messageFilters = $messageFilters;

        return $this;
    }
}
