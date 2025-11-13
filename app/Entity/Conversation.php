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
class Conversation
{
    #[Field(type: 'INT', autoIncrement: true, primaryKey: true)]
    public int $id;

    #[Field(type: 'string', nullable: true)]
    public ?string $hash = null;
    
    #[Field(type: 'string', nullable: true)]
    public ?string $subject = null;

    #[Field(type: 'datetime', nullable: true)]
    public ?\DateTimeImmutable $createdAt = null;
    
    #[Field(type: 'datetime', nullable: true)]
    public ?\DateTimeImmutable $updatedAt = null;

    #[Field(type: 'datetime', nullable: true)]
    public ?\DateTimeImmutable $lastMessageAt = null;
    
    #[ManyToOne(targetEntity: Project::class, inversedBy: 'conversations')]
    public ?Project $project = null;

    #[ManyToOne(targetEntity: User::class, inversedBy: 'conversations')]
    public ?User $createdBy = null;
    
     /** @var User[] */
    #[ManyToMany(targetEntity: User::class, inversedBy: 'conversations', joinTable: new JoinTable(name: 'conversation_user', joinColumn: 'conversation_id', inverseColumn: 'user_id'))]
    public array $users = [];
    
     /** @var Message[] */
    #[OneToMany(targetEntity: Message::class, mappedBy: 'conversation')]
    public array $messages = [];

    public function __construct()
    {
        $this->users = [];
        $this->messages = [];
    }

    public function getId(): int
    {
        return $this->id;
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

    public function getSubject(): ?string
    {
        return $this->subject;
    }

    public function setSubject(?string $subject): self
    {
        $this->subject = $subject;
        return $this;
    }

    public function getCreatedAt(): ?\DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function setCreatedAt(?\DateTimeImmutable $createdAt): self
    {
        $this->createdAt = $createdAt;
        return $this;
    }

    public function getUpdatedAt(): ?\DateTimeImmutable
    {
        return $this->updatedAt;
    }

    public function setUpdatedAt(?\DateTimeImmutable $updatedAt): self
    {
        $this->updatedAt = $updatedAt;
        return $this;
    }

    public function getLastMessageAt(): ?\DateTimeImmutable
    {
        return $this->lastMessageAt;
    }

    public function setLastMessageAt(?\DateTimeImmutable $lastMessageAt): self
    {
        $this->lastMessageAt = $lastMessageAt;
        return $this;
    }

    public function getProject(): ?Project
    {
        return $this->project;
    }

    public function setProject(?Project $project): self
    {
        $this->project = $project;
        return $this;
    }

    public function removeProject(): self
    {
        $this->project = null;
        return $this;
    }

    public function getCreatedBy(): ?User
    {
        return $this->createdBy;
    }

    public function setCreatedBy(?User $createdBy): self
    {
        $this->createdBy = $createdBy;
        return $this;
    }

    public function removeCreatedBy(): self
    {
        $this->createdBy = null;
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

    public function addMessage(Message $item): self
    {
        $this->messages[] = $item;
        return $this;
    }

    public function removeMessage(Message $item): self
    {
        $this->messages = array_filter($this->messages, fn($i) => $i !== $item);
        return $this;
    }

    public function getMessages(): array
    {
        return $this->messages;
    }
}