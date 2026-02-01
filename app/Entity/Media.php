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
class Media
{
    #[Field(type: 'INT', autoIncrement: true, primaryKey: true)]
    public int $id;

    #[Field(type: 'string', nullable: true)]
    public ?string $url = null;
    #[Field(type: 'string', nullable: true)]
    public ?string $type = null;

    #[OneToOne(targetEntity: User::class, mappedBy: 'picture')]
    public ?User $userPicture = null;
    #[OneToOne(targetEntity: User::class, mappedBy: 'banner')]
    public ?User $userBanner = null;
    /** @var Investor[] */
    #[OneToMany(targetEntity: Investor::class, mappedBy: 'tesisDocuments')]
    public array $investorsTesis = [];

    #[OneToOne(targetEntity: Project::class, mappedBy: 'logo')]
    public ?Project $projectLogo = null;
    #[OneToOne(targetEntity: Project::class, mappedBy: 'pitchDeck')]
    public ?Project $projectPitchDeck = null;

    #[OneToOne(targetEntity: Project::class, mappedBy: 'banner')]
    public ?Project $projectBanner = null;
    #[Field(type: 'string', nullable: true)]
    public ?string $hash = null;

    #[ManyToOne(targetEntity: Project::class, inversedBy: 'mediaGallery')]
    public ?Project $projectGallery = null;
    #[OneToOne(targetEntity: User::class, inversedBy: 'mediaUser')]
    public ?User $authorUser = null;

    #[OneToOne(targetEntity: OpenVcInvestor::class, mappedBy: 'logo')]
    public ?OpenVcInvestor $openVcInvestorLogo = null;

    #[Field(type: 'datetime', nullable: true)]
    public ?\DateTimeImmutable $created = null;
    #[ManyToOne(targetEntity: Message::class, inversedBy: 'media')]
    public ?Message $message = null;

    #[ManyToOne(targetEntity: CommentsProject::class, inversedBy: 'media')]
    public ?CommentsProject $commentsProject = null;

    public function __construct()
    {
        $this->investorsTesis = [];
    }

    public function getId(): int
    {
        return $this->id;
    }

    public function getUrl(): ?string
    {
        return $this->url;
    }

    public function setUrl(?string $url): self
    {
        $this->url = $url;
        return $this;
    }

    public function getType(): ?string
    {
        return $this->type;
    }

    public function setType(?string $type): self
    {
        $this->type = $type;
        return $this;
    }

    public function getUserPicture(): ?User
    {
        return $this->userPicture;
    }

    public function setUserPicture(?User $userPicture): self
    {
        $this->userPicture = $userPicture;
        return $this;
    }

    public function removeUserPicture(): self
    {
        $this->userPicture = null;
        return $this;
    }

    public function getUserBanner(): ?User
    {
        return $this->userBanner;
    }

    public function setUserBanner(?User $userBanner): self
    {
        $this->userBanner = $userBanner;
        return $this;
    }

    public function removeUserBanner(): self
    {
        $this->userBanner = null;
        return $this;
    }

    public function addInvestor(Investor $item): self
    {
        $this->investorsTesis[] = $item;
        return $this;
    }

    public function removeInvestor(Investor $item): self
    {
        $this->investorsTesis = array_filter($this->investorsTesis, fn($i) => $i !== $item);
        return $this;
    }

    public function getInvestorsTesis(): array
    {
        return $this->investorsTesis;
    }

    public function getProjectLogo(): ?Project
    {
        return $this->projectLogo;
    }

    public function setProjectLogo(?Project $projectLogo): self
    {
        $this->projectLogo = $projectLogo;
        return $this;
    }

    public function removeProjectLogo(): self
    {
        $this->projectLogo = null;
        return $this;
    }

    public function getProjectPitchDeck(): ?Project
    {
        return $this->projectPitchDeck;
    }

    public function setProjectPitchDeck(?Project $projectPitchDeck): self
    {
        $this->projectPitchDeck = $projectPitchDeck;
        return $this;
    }

    public function removeProjectPitchDeck(): self
    {
        $this->projectPitchDeck = null;
        return $this;
    }

    public function getProjectBanner(): ?Project
    {
        return $this->projectBanner;
    }

    public function setProjectBanner(?Project $projectBanner): self
    {
        $this->projectBanner = $projectBanner;
        return $this;
    }

    public function removeProjectBanner(): self
    {
        $this->projectBanner = null;
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

    public function getProjectGallery(): ?Project
    {
        return $this->projectGallery;
    }

    public function setProjectGallery(?Project $projectGallery): self
    {
        $this->projectGallery = $projectGallery;
        return $this;
    }

    public function removeProjectGallery(): self
    {
        $this->projectGallery = null;
        return $this;
    }

    public function getAuthorUser(): ?User
    {
        return $this->authorUser;
    }

    public function setAuthorUser(?User $authorUser): self
    {
        $this->authorUser = $authorUser;
        return $this;
    }

    public function removeAuthorUser(): self
    {
        $this->authorUser = null;
        return $this;
    }

    public function getOpenVcInvestorLogo(): ?OpenVcInvestor
    {
        return $this->openVcInvestorLogo;
    }

    public function setOpenVcInvestorLogo(?OpenVcInvestor $openVcInvestorLogo): self
    {
        $this->openVcInvestorLogo = $openVcInvestorLogo;
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

    public function getMessage(): ?Message
    {
        return $this->message;
    }

    public function setMessage(?Message $message): self
    {
        $this->message = $message;
        return $this;
    }

    public function removeMessage(): self
    {
        $this->message = null;
        return $this;
    }

    public function getCommentsProject(): ?CommentsProject
    {
        return $this->commentsProject;
    }

    public function setCommentsProject(?CommentsProject $commentsProject): self
    {
        $this->commentsProject = $commentsProject;
        return $this;
    }

    public function removeCommentsProject(): self
    {
        $this->commentsProject = null;
        return $this;
    }
}