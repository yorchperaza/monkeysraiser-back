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
class Plan
{
    #[Field(type: 'INT', autoIncrement: true, primaryKey: true)]
    public int $id;

    #[Field(type: 'string', nullable: true)]
    public ?string $name = null;
    #[Field(type: 'string', nullable: true)]
    public ?string $slug = null;

    #[Field(type: 'string', nullable: true)]
    public ?string $stripe_price_id = null;
    #[Field(type: 'string', nullable: true)]
    public ?string $stripe_product_id = null;
    /** @var Project[] */
    #[OneToMany(targetEntity: Project::class, mappedBy: 'plan')]
    public array $projects = [];

    #[Field(type: 'integer', nullable: true)]
    public ?int $price = null;
    
     /** @var User[] */
    #[ManyToMany(targetEntity: User::class, inversedBy: 'plans', joinTable: new JoinTable(name: 'plan_user', joinColumn: 'plan_id', inverseColumn: 'user_id'))]
    public array $users = [];

    public function __construct()
    {
        $this->projects = [];
        $this->users = [];
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

    public function getStripe_price_id(): ?string
    {
        return $this->stripe_price_id;
    }

    public function setStripe_price_id(?string $stripe_price_id): self
    {
        $this->stripe_price_id = $stripe_price_id;
        return $this;
    }

    public function getStripe_product_id(): ?string
    {
        return $this->stripe_product_id;
    }

    public function setStripe_product_id(?string $stripe_product_id): self
    {
        $this->stripe_product_id = $stripe_product_id;
        return $this;
    }

    public function addProject(Project $item): self
    {
        $this->projects[] = $item;
        return $this;
    }

    public function removeProject(Project $item): self
    {
        $this->projects = array_filter($this->projects, fn($i) => $i !== $item);
        return $this;
    }

    public function getProjects(): array
    {
        return $this->projects;
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

    public function addUser(User $item): self
    {
        $this->users[] = $item;
        return $this;
    }

    public function removeUser(User $item): self
    {
        $this->users = array_filter($this->users, fn($i) => $i !== $item);
        return $this;
    }

    public function getUsers(): array
    {
        return $this->users;
    }
}