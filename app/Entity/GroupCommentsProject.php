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
class GroupCommentsProject
{
    #[Field(type: 'INT', autoIncrement: true, primaryKey: true)]
    public int $id;

    #[Field(type: 'string', nullable: true)]
    public ?string $hash = null;
    #[Field(type: 'datetime', nullable: true)]
    public ?\DateTimeImmutable $createdAt = null;

    #[Field(type: 'datetime', nullable: true)]
    public ?\DateTimeImmutable $updateAt = null;
    #[Field(type: 'datetime', nullable: true)]
    public ?\DateTimeImmutable $lastMessageAt = null;

    #[ManyToOne(targetEntity: Project::class, inversedBy: 'groupCommentsProjects')]
    public ?Project $project = null;
    /** @var CommentsProject[] */
    #[OneToMany(targetEntity: CommentsProject::class, mappedBy: 'groupCommentsProject')]
    public array $commentsProjects = [];
    
     /** @var User[] */
    #[ManyToMany(targetEntity: User::class, inversedBy: 'groupCommentsProjectsRecipients', joinTable: new JoinTable(name: 'group_comments_project_recipients', joinColumn: 'group_comments_project_id', inverseColumn: 'user_id'))]
    public array $recipients = [];

    public function __construct()
    {
        $this->commentsProjects = [];
        $this->recipients = [];
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

    public function getCreatedAt(): ?\DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function setCreatedAt(?\DateTimeImmutable $createdAt): self
    {
        $this->createdAt = $createdAt;
        return $this;
    }

    public function getUpdateAt(): ?\DateTimeImmutable
    {
        return $this->updateAt;
    }

    public function setUpdateAt(?\DateTimeImmutable $updateAt): self
    {
        $this->updateAt = $updateAt;
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

    public function addCommentsProject(CommentsProject $item): self
    {
        $this->commentsProjects[] = $item;
        return $this;
    }

    public function removeCommentsProject(CommentsProject $item): self
    {
        $this->commentsProjects = array_filter($this->commentsProjects, fn($i) => $i !== $item);
        return $this;
    }

    public function getCommentsProjects(): array
    {
        return $this->commentsProjects;
    }

    public function addUser(User $item): self
    {
        $this->recipients[] = $item;
        return $this;
    }

    public function removeUser(User $item): self
    {
        $this->recipients = array_filter($this->recipients, fn($i) => $i !== $item);
        return $this;
    }

    public function getRecipients(): array
    {
        return $this->recipients;
    }
}