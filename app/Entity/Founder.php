<?php

declare(strict_types=1);

namespace App\Entity;

use MonkeysLegion\Entity\Attributes\Entity;
use MonkeysLegion\Entity\Attributes\Field;
use MonkeysLegion\Entity\Attributes\OneToOne;
use MonkeysLegion\Entity\Attributes\OneToMany;
use MonkeysLegion\Entity\Attributes\ManyToOne;
use MonkeysLegion\Entity\Attributes\ManyToMany;
use MonkeysLegion\Entity\Attributes\JoinTable;

#[Entity]
class Founder
{
    #[Field(type: 'INT', autoIncrement: true, primaryKey: true)]
    public int $id;

    #[Field(type: 'integer', nullable: true)]
    public ?int $yearsExpertise = null;
    #[Field(type: 'json', nullable: true)]
    public ?array $expertise = null;

    #[Field(type: 'text', nullable: true)]
    public ?string $notable = null;
    #[Field(type: 'string', nullable: true)]
    public ?string $personalWebsite = null;

    #[Field(type: 'json', nullable: true)]
    public ?array $fundingPreferences = null;
    #[Field(type: 'string', nullable: true)]
    public ?string $calendly = null;

    #[OneToOne(targetEntity: User::class, inversedBy: 'founder')]
    public ?User $user = null;
    
    #[Field(type: 'string', nullable: true)]
    public ?string $hash = null;

    public function __construct()
    {
    }

    public function getId(): int
    {
        return $this->id;
    }

    public function getYearsExpertise(): ?int
    {
        return $this->yearsExpertise;
    }

    public function setYearsExpertise(?int $yearsExpertise): self
    {
        $this->yearsExpertise = $yearsExpertise;
        return $this;
    }

    public function getExpertise(): ?array
    {
        return $this->expertise;
    }

    public function setExpertise(?array $expertise): self
    {
        $this->expertise = $expertise;
        return $this;
    }

    public function getNotable(): ?string
    {
        return $this->notable;
    }

    public function setNotable(?string $notable): self
    {
        $this->notable = $notable;
        return $this;
    }

    public function getPersonalWebsite(): ?string
    {
        return $this->personalWebsite;
    }

    public function setPersonalWebsite(?string $personalWebsite): self
    {
        $this->personalWebsite = $personalWebsite;
        return $this;
    }

    public function getFundingPreferences(): ?array
    {
        return $this->fundingPreferences;
    }

    public function setFundingPreferences(?array $fundingPreferences): self
    {
        $this->fundingPreferences = $fundingPreferences;
        return $this;
    }

    public function getCalendly(): ?string
    {
        return $this->calendly;
    }

    public function setCalendly(?string $calendly): self
    {
        $this->calendly = $calendly;
        return $this;
    }

    public function getUser(): ?User
    {
        return $this->user;
    }

    public function setUser(?User $user): self
    {
        $this->user = $user;
        return $this;
    }

    public function removeUser(): self
    {
        $this->user = null;
        return $this;
    }

    public function getHash(): ?string
    {
        return $this->hash;
    }

    public function setHash(?string $hash): self
    {
        $this->hash = $hash;
        return $this;
    }
}