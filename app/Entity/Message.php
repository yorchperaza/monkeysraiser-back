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
class Message
{
    #[Field(type: 'INT', autoIncrement: true, primaryKey: true)]
    public int $id;

    #[Field(type: 'longText', nullable: true)]
    public ?string $message = null;
    #[Field(type: 'string', nullable: true)]
    public ?string $subject = null;

    #[OneToOne(targetEntity: User::class, inversedBy: 'authorMessage')]
    public ?User $author = null;
    #[ManyToOne(targetEntity: Project::class, inversedBy: 'messages')]
    public ?Project $project = null;
    /** @var Media[] */
    #[OneToMany(targetEntity: Media::class, mappedBy: 'message')]
    public array $media = [];

    #[ManyToOne(targetEntity: Conversation::class, inversedBy: 'messages')]
    public ?Conversation $conversation = null;
    
    #[Field(type: 'boolean', nullable: true)]
    public ?bool $read = null;

    #[Field(type: 'datetime', nullable: true)]
    public ?\DateTimeImmutable $readDate = null;

    public function __construct()
    {
        $this->media = [];
    }

    public function getId(): int
    {
        return $this->id;
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

    public function getSubject(): ?string
    {
        return $this->subject;
    }

    public function setSubject(?string $subject): self
    {
        $this->subject = $subject;
        return $this;
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

    public function getConversation(): ?Conversation
    {
        return $this->conversation;
    }

    public function setConversation(?Conversation $conversation): self
    {
        $this->conversation = $conversation;
        return $this;
    }

    public function removeConversation(): self
    {
        $this->conversation = null;
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

    public function getReadDate(): ?\DateTimeImmutable
    {
        return $this->readDate;
    }

    public function setReadDate(?\DateTimeImmutable $readDate): self
    {
        $this->readDate = $readDate;
        return $this;
    }
}