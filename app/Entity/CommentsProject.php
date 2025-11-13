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
class CommentsProject
{
    #[Field(type: 'INT', autoIncrement: true, primaryKey: true)]
    public int $id;

    #[Field(type: 'string', nullable: true)]
    public ?string $slug = null;
    #[Field(type: 'string', nullable: true)]
    public ?string $subject = null;

    #[Field(type: 'text', nullable: true)]
    public ?string $message = null;
    #[Field(type: 'datetime', nullable: true)]
    public ?\DateTimeImmutable $date = null;

    #[Field(type: 'datetime', nullable: true)]
    public ?\DateTimeImmutable $readDate = null;
    #[Field(type: 'boolean', nullable: true)]
    public ?bool $read = null;
    /** @var Media[] */
    #[OneToMany(targetEntity: Media::class, mappedBy: 'commentsProject')]
    public array $media = [];

    #[ManyToOne(targetEntity: User::class, inversedBy: 'authorCommentsProject')]
    public ?User $author = null;

    #[ManyToOne(targetEntity: GroupCommentsProject::class, inversedBy: 'commentsProjects')]
    public ?GroupCommentsProject $groupCommentsProject = null;

    public function __construct()
    {
        $this->media = [];
    }

    public function getId(): int
    {
        return $this->id;
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

    public function getSubject(): ?string
    {
        return $this->subject;
    }

    public function setSubject(?string $subject): self
    {
        $this->subject = $subject;
        return $this;
    }

    public function getMessage(): ?string
    {
        return $this->message;
    }

    public function setMessage(?string $message): self
    {
        $this->message = $message;
        return $this;
    }

    public function getDate(): ?\DateTimeImmutable
    {
        return $this->date;
    }

    public function setDate(?\DateTimeImmutable $date): self
    {
        $this->date = $date;
        return $this;
    }

    public function getReadDate(): ?\DateTimeImmutable
    {
        return $this->readDate;
    }

    public function setReadDate(?\DateTimeImmutable $readDate): self
    {
        $this->readDate = $readDate;
        return $this;
    }

    public function getRead(): ?bool
    {
        return $this->read;
    }

    public function setRead(?bool $read): self
    {
        $this->read = $read;
        return $this;
    }

    public function addMedia(Media $item): self
    {
        $this->media[] = $item;
        return $this;
    }

    public function removeMedia(Media $item): self
    {
        $this->media = array_filter($this->media, fn($i) => $i !== $item);
        return $this;
    }

    public function getMedia(): array
    {
        return $this->media;
    }

    public function getAuthor(): ?User
    {
        return $this->author;
    }

    public function setAuthor(?User $author): self
    {
        $this->author = $author;
        return $this;
    }

    public function removeAuthor(): self
    {
        $this->author = null;
        return $this;
    }

    public function getGroupCommentsProject(): ?GroupCommentsProject
    {
        return $this->groupCommentsProject;
    }

    public function setGroupCommentsProject(?GroupCommentsProject $groupCommentsProject): self
    {
        $this->groupCommentsProject = $groupCommentsProject;
        return $this;
    }

    public function removeGroupCommentsProject(): self
    {
        $this->groupCommentsProject = null;
        return $this;
    }
}