<?php

namespace App\Entity;

use App\Repository\LiveCodeRepository;
use Doctrine\ORM\Mapping as ORM;

/**
 * Un code d'accès au direct = un billet.
 */
#[ORM\Entity(repositoryClass: LiveCodeRepository::class)]
#[ORM\Table(name: 'live_code')]
#[ORM\UniqueConstraint(name: 'uniq_live_code_code', columns: ['code'])]
class LiveCode
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    /** Code normalisé (majuscules, sans tirets) : PLAB12CD34 */
    #[ORM\Column(length: 16)]
    private string $code;

    /** Libellé libre : nom de l'acheteur, référence Tickafrica… */
    #[ORM\Column(length: 160, nullable: true)]
    private ?string $label = null;

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $firstUsedAt = null;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $lastSeenAt = null;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $revokedAt = null;

    /** Jeton de l'appareil qui a la main sur ce billet (un seul à la fois). */
    #[ORM\Column(length: 64, nullable: true)]
    private ?string $claimToken = null;

    public function __construct(string $code, ?string $label = null)
    {
        $this->code = $code;
        $this->label = $label;
        $this->createdAt = new \DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getCode(): string
    {
        return $this->code;
    }

    public function getLabel(): ?string
    {
        return $this->label;
    }

    public function setLabel(?string $label): void
    {
        $label = null === $label ? null : trim($label);
        $this->label = '' === $label ? null : mb_substr((string) $label, 0, 160);
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getFirstUsedAt(): ?\DateTimeImmutable
    {
        return $this->firstUsedAt;
    }

    public function markUsed(\DateTimeImmutable $at): void
    {
        $this->firstUsedAt ??= $at;
        $this->lastSeenAt = $at;
    }

    public function getLastSeenAt(): ?\DateTimeImmutable
    {
        return $this->lastSeenAt;
    }

    public function setLastSeenAt(\DateTimeImmutable $at): void
    {
        $this->lastSeenAt = $at;
    }

    public function isRevoked(): bool
    {
        return null !== $this->revokedAt;
    }

    public function getRevokedAt(): ?\DateTimeImmutable
    {
        return $this->revokedAt;
    }

    public function revoke(): void
    {
        $this->revokedAt = new \DateTimeImmutable();
        $this->claimToken = null;
    }

    public function restore(): void
    {
        $this->revokedAt = null;
    }

    public function getClaimToken(): ?string
    {
        return $this->claimToken;
    }

    public function setClaimToken(?string $token): void
    {
        $this->claimToken = $token;
    }
}
