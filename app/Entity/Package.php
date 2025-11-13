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
class Package
{
    #[Field(type: 'INT', autoIncrement: true, primaryKey: true)]
    public int $id;

    #[Field(type: 'string', nullable: true)]
    public ?string $name = null;
    
    #[Field(type: 'string', nullable: true)]
    public ?string $slug = null;

    #[Field(type: 'text', nullable: true)]
    public ?string $description = null;
    
    #[Field(type: 'boolean', nullable: true)]
    public ?bool $status = null;

    #[Field(type: 'datetime', nullable: true)]
    public ?\DateTimeImmutable $created = null;
    
    #[Field(type: 'datetime', nullable: true)]
    public ?\DateTimeImmutable $updated = null;

    #[Field(type: 'integer', nullable: true)]
    public ?int $price = null;
    
    #[Field(type: 'string', nullable: true)]
    public ?string $stripePriceId = null;

    #[Field(type: 'string', nullable: true)]
    public ?string $stripeProductId = null;
    
    #[ManyToOne(targetEntity: Project::class, inversedBy: 'packageProject')]
    public ?Project $projectPlan = null;

    public function __construct()
    {
    }

    public function getId(): int
    {
        return $this->id;
    }

    public function getName(): ?string
    {
        return $this->name;
    }

    public function setName(?string $name): self
    {
        $this->name = $name;
        return $this;
    }

    public function getSlug(): ?string
    {
        return $this->slug;
    }

    public function setSlug(?string $slug): self
    {
        $this->slug = $slug;
        return $this;
    }

    public function getDescription(): ?string
    {
        return $this->description;
    }

    public function setDescription(?string $description): self
    {
        $this->description = $description;
        return $this;
    }

    public function getStatus(): ?bool
    {
        return $this->status;
    }

    public function setStatus(?bool $status): self
    {
        $this->status = $status;
        return $this;
    }

    public function getCreated(): ?\DateTimeImmutable
    {
        return $this->created;
    }

    public function setCreated(?\DateTimeImmutable $created): self
    {
        $this->created = $created;
        return $this;
    }

    public function getUpdated(): ?\DateTimeImmutable
    {
        return $this->updated;
    }

    public function setUpdated(?\DateTimeImmutable $updated): self
    {
        $this->updated = $updated;
        return $this;
    }

    public function getPrice(): ?int
    {
        return $this->price;
    }

    public function setPrice(?int $price): self
    {
        $this->price = $price;
        return $this;
    }

    public function getStripePriceId(): ?string
    {
        return $this->stripePriceId;
    }

    public function setStripePriceId(?string $stripePriceId): self
    {
        $this->stripePriceId = $stripePriceId;
        return $this;
    }

    public function getStripeProductId(): ?string
    {
        return $this->stripeProductId;
    }

    public function setStripeProductId(?string $stripeProductId): self
    {
        $this->stripeProductId = $stripeProductId;
        return $this;
    }

    public function getProjectPlan(): ?Project
    {
        return $this->projectPlan;
    }

    public function setProjectPlan(?Project $projectPlan): self
    {
        $this->projectPlan = $projectPlan;
        return $this;
    }

    public function removeProjectPlan(): self
    {
        $this->projectPlan = null;
        return $this;
    }
}