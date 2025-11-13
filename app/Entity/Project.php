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
class Project
{
    #[Field(type: 'INT', autoIncrement: true, primaryKey: true)]
    public int $id;

    #[Field(type: 'string', nullable: true)]
    public ?string $name = null;
    #[Field(type: 'string', nullable: true)]
    public ?string $demoVideo = null;

    #[Field(type: 'string', nullable: true)]
    public ?string $tagline = null;
    #[Field(type: 'string', nullable: true)]
    public ?string $stage = null;

    #[Field(type: 'datetime', nullable: true)]
    public ?\DateTimeImmutable $founded = null;
    #[Field(type: 'json', nullable: true)]
    public ?array $category = null;

    #[Field(type: 'text', nullable: true)]
    public ?string $elevatorPitch = null;
    #[Field(type: 'text', nullable: true)]
    public ?string $problemStatement = null;

    #[Field(type: 'text', nullable: true)]
    public ?string $solution = null;
    #[Field(type: 'text', nullable: true)]
    public ?string $model = null;

    #[Field(type: 'text', nullable: true)]
    public ?string $traction = null;
    #[Field(type: 'json', nullable: true)]
    public ?array $urls = null;

    #[Field(type: 'integer', nullable: true)]
    public ?int $teamSize = null;
    #[Field(type: 'integer', nullable: true)]
    public ?int $capitalSought = null;

    #[Field(type: 'integer', nullable: true)]
    public ?int $foundingTarget = null;
    #[Field(type: 'integer', nullable: true)]
    public ?int $valuation = null;

    #[Field(type: 'integer', nullable: true)]
    public ?int $previuosAmountFounding = null;
    #[Field(type: 'string', nullable: true)]
    public ?string $previuosRound = null;

    #[Field(type: 'datetime', nullable: true)]
    public ?\DateTimeImmutable $previousRoundDate = null;
    #[Field(type: 'json', nullable: true)]
    public ?array $social = null;

    #[Field(type: 'boolean', nullable: true)]
    public ?bool $boost = null;
    #[Field(type: 'boolean', nullable: true)]
    public ?bool $superBoost = null;

    #[Field(type: 'string', nullable: true)]
    public ?string $status = null;
    #[Field(type: 'datetime', nullable: true)]
    public ?\DateTimeImmutable $publishDate = null;

    #[Field(type: 'datetime', nullable: true)]
    public ?\DateTimeImmutable $boostDate = null;
    #[Field(type: 'datetime', nullable: true)]
    public ?\DateTimeImmutable $superBoostDate = null;
    /** @var User[] */
    #[ManyToMany(targetEntity: User::class, inversedBy: 'projects', joinTable: new JoinTable(name: 'project_user', joinColumn: 'project_id', inverseColumn: 'user_id'))]
    public array $users = [];

    #[OneToOne(targetEntity: Media::class, inversedBy: 'projectLogo')]
    public ?Media $logo = null;
    #[OneToOne(targetEntity: Media::class, inversedBy: 'projectPitchDeck')]
    public ?Media $pitchDeck = null;

    #[OneToOne(targetEntity: Media::class, inversedBy: 'projectBanner')]
    public ?Media $banner = null;
    #[OneToOne(targetEntity: User::class, inversedBy: 'projectAuthor')]
    public ?User $author = null;
    /** @var Package[] */
    #[OneToMany(targetEntity: Package::class, mappedBy: 'projectPlan')]
    public array $packageProject = [];
    /** @var Message[] */
    #[OneToMany(targetEntity: Message::class, mappedBy: 'project')]
    public array $messages = [];

    #[Field(type: 'string', nullable: true)]
    public ?string $hash = null;
    /** @var Media[] */
    #[OneToMany(targetEntity: Media::class, mappedBy: 'projectGallery')]
    public array $mediaGallery = [];

    #[Field(type: 'datetime', nullable: true)]
    public ?\DateTimeImmutable $updateDate = null;
    #[Field(type: 'integer', nullable: true)]
    public ?int $currentFoundingAmount = null;
    /** @var User[] */
    #[ManyToMany(targetEntity: User::class, mappedBy: 'favorites')]
    public array $favorite_users = [];

    #[Field(type: 'json', nullable: true)]
    public ?array $location = null;
    /** @var Conversation[] */
    #[OneToMany(targetEntity: Conversation::class, mappedBy: 'project')]
    public array $conversations = [];

    #[ManyToOne(targetEntity: Plan::class, inversedBy: 'projects')]
    public ?Plan $plan = null;
    /** @var GroupCommentsProject[] */
    #[OneToMany(targetEntity: GroupCommentsProject::class, mappedBy: 'project')]
    public array $groupCommentsProjects = [];
    
     /** @var User[] */
    #[ManyToMany(targetEntity: User::class, inversedBy: 'projects_investor', joinTable: new JoinTable(name: 'access_investor_project', joinColumn: 'project_id', inverseColumn: 'user_id'))]
    public array $access_investor = [];

    /** @var ProjectAccessRequest[] */
    #[OneToMany(targetEntity: ProjectAccessRequest::class, mappedBy: 'project')]
    public array $accessRequests = [];

    public function __construct()
    {
        $this->users = [];
        $this->packageProject = [];
        $this->messages = [];
        $this->mediaGallery = [];
        $this->favorite_users = [];
        $this->conversations = [];
        $this->groupCommentsProjects = [];
        $this->access_investor = [];
        $this->accessRequests = [];
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

    public function getDemoVideo(): ?string
    {
        return $this->demoVideo;
    }

    public function setDemoVideo(?string $demoVideo): self
    {
        $this->demoVideo = $demoVideo;
        return $this;
    }

    public function getTagline(): ?string
    {
        return $this->tagline;
    }

    public function setTagline(?string $tagline): self
    {
        $this->tagline = $tagline;
        return $this;
    }

    public function getStage(): ?string
    {
        return $this->stage;
    }

    public function setStage(?string $stage): self
    {
        $this->stage = $stage;
        return $this;
    }

    public function getFounded(): ?\DateTimeImmutable
    {
        return $this->founded;
    }

    public function setFounded(?\DateTimeImmutable $founded): self
    {
        $this->founded = $founded;
        return $this;
    }

    public function getCategory(): ?array
    {
        return $this->category;
    }

    public function setCategory(?array $category): self
    {
        $this->category = $category;
        return $this;
    }

    public function getElevatorPitch(): ?string
    {
        return $this->elevatorPitch;
    }

    public function setElevatorPitch(?string $elevatorPitch): self
    {
        $this->elevatorPitch = $elevatorPitch;
        return $this;
    }

    public function getProblemStatement(): ?string
    {
        return $this->problemStatement;
    }

    public function setProblemStatement(?string $problemStatement): self
    {
        $this->problemStatement = $problemStatement;
        return $this;
    }

    public function getSolution(): ?string
    {
        return $this->solution;
    }

    public function setSolution(?string $solution): self
    {
        $this->solution = $solution;
        return $this;
    }

    public function getModel(): ?string
    {
        return $this->model;
    }

    public function setModel(?string $model): self
    {
        $this->model = $model;
        return $this;
    }

    public function getTraction(): ?string
    {
        return $this->traction;
    }

    public function setTraction(?string $traction): self
    {
        $this->traction = $traction;
        return $this;
    }

    public function getUrls(): ?array
    {
        return $this->urls;
    }

    public function setUrls(?array $urls): self
    {
        $this->urls = $urls;
        return $this;
    }

    public function getTeamSize(): ?int
    {
        return $this->teamSize;
    }

    public function setTeamSize(?int $teamSize): self
    {
        $this->teamSize = $teamSize;
        return $this;
    }

    public function getCapitalSought(): ?int
    {
        return $this->capitalSought;
    }

    public function setCapitalSought(?int $capitalSought): self
    {
        $this->capitalSought = $capitalSought;
        return $this;
    }

    public function getFoundingTarget(): ?int
    {
        return $this->foundingTarget;
    }

    public function setFoundingTarget(?int $foundingTarget): self
    {
        $this->foundingTarget = $foundingTarget;
        return $this;
    }

    public function getValuation(): ?int
    {
        return $this->valuation;
    }

    public function setValuation(?int $valuation): self
    {
        $this->valuation = $valuation;
        return $this;
    }

    public function getPreviuosAmountFounding(): ?int
    {
        return $this->previuosAmountFounding;
    }

    public function setPreviuosAmountFounding(?int $previuosAmountFounding): self
    {
        $this->previuosAmountFounding = $previuosAmountFounding;
        return $this;
    }

    public function getPreviuosRound(): ?string
    {
        return $this->previuosRound;
    }

    public function setPreviuosRound(?string $previuosRound): self
    {
        $this->previuosRound = $previuosRound;
        return $this;
    }

    public function getPreviousRoundDate(): ?\DateTimeImmutable
    {
        return $this->previousRoundDate;
    }

    public function setPreviousRoundDate(?\DateTimeImmutable $previousRoundDate): self
    {
        $this->previousRoundDate = $previousRoundDate;
        return $this;
    }

    public function getSocial(): ?array
    {
        return $this->social;
    }

    public function setSocial(?array $social): self
    {
        $this->social = $social;
        return $this;
    }

    public function getBoost(): ?bool
    {
        return $this->boost;
    }

    public function setBoost(?bool $boost): self
    {
        $this->boost = $boost;
        return $this;
    }

    public function getSuperBoost(): ?bool
    {
        return $this->superBoost;
    }

    public function setSuperBoost(?bool $superBoost): self
    {
        $this->superBoost = $superBoost;
        return $this;
    }

    public function getStatus(): ?string
    {
        return $this->status;
    }

    public function setStatus(?string $status): self
    {
        $this->status = $status;
        return $this;
    }

    public function getPublishDate(): ?\DateTimeImmutable
    {
        return $this->publishDate;
    }

    public function setPublishDate(?\DateTimeImmutable $publishDate): self
    {
        $this->publishDate = $publishDate;
        return $this;
    }

    public function getBoostDate(): ?\DateTimeImmutable
    {
        return $this->boostDate;
    }

    public function setBoostDate(?\DateTimeImmutable $boostDate): self
    {
        $this->boostDate = $boostDate;
        return $this;
    }

    public function getSuperBoostDate(): ?\DateTimeImmutable
    {
        return $this->superBoostDate;
    }

    public function setSuperBoostDate(?\DateTimeImmutable $superBoostDate): self
    {
        $this->superBoostDate = $superBoostDate;
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

    public function getLogo(): ?Media
    {
        return $this->logo;
    }

    public function setLogo(?Media $logo): self
    {
        $this->logo = $logo;
        return $this;
    }

    public function removeLogo(): self
    {
        $this->logo = null;
        return $this;
    }

    public function getPitchDeck(): ?Media
    {
        return $this->pitchDeck;
    }

    public function setPitchDeck(?Media $pitchDeck): self
    {
        $this->pitchDeck = $pitchDeck;
        return $this;
    }

    public function removePitchDeck(): self
    {
        $this->pitchDeck = null;
        return $this;
    }

    public function getBanner(): ?Media
    {
        return $this->banner;
    }

    public function setBanner(?Media $banner): self
    {
        $this->banner = $banner;
        return $this;
    }

    public function removeBanner(): self
    {
        $this->banner = null;
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

    public function addPackage(Package $item): self
    {
        $this->packageProject[] = $item;
        return $this;
    }

    public function removePackage(Package $item): self
    {
        $this->packageProject = array_filter($this->packageProject, fn($i) => $i !== $item);
        return $this;
    }

    public function getPackageProject(): array
    {
        return $this->packageProject;
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

    public function getHash(): ?string
    {
        return $this->hash;
    }

    public function setHash(?string $hash): self
    {
        $this->hash = $hash;
        return $this;
    }

    public function addMedia(Media $item): self
    {
        $this->mediaGallery[] = $item;
        return $this;
    }

    public function removeMedia(Media $item): self
    {
        $this->mediaGallery = array_filter($this->mediaGallery, fn($i) => $i !== $item);
        return $this;
    }

    public function getMediaGallery(): array
    {
        return $this->mediaGallery;
    }

    public function getUpdateDate(): ?\DateTimeImmutable
    {
        return $this->updateDate;
    }

    public function setUpdateDate(?\DateTimeImmutable $updateDate): self
    {
        $this->updateDate = $updateDate;
        return $this;
    }

    public function getCurrentFoundingAmount(): ?int
    {
        return $this->currentFoundingAmount;
    }

    public function setCurrentFoundingAmount(?int $currentFoundingAmount): self
    {
        $this->currentFoundingAmount = $currentFoundingAmount;
        return $this;
    }

    public function addUserFavorite(User $item): self
    {
        $this->favorite_users[] = $item;
        return $this;
    }

    public function removeUserFavorite(User $item): self
    {
        $this->favorite_users = array_filter($this->favorite_users, fn($i) => $i !== $item);
        return $this;
    }

    public function getFavorite_users(): array
    {
        return $this->favorite_users;
    }

    public function getLocation(): ?array
    {
        return $this->location;
    }

    public function setLocation(?array $location): self
    {
        $this->location = $location;
        return $this;
    }

    public function addConversation(Conversation $item): self
    {
        $this->conversations[] = $item;
        return $this;
    }

    public function removeConversation(Conversation $item): self
    {
        $this->conversations = array_filter($this->conversations, fn($i) => $i !== $item);
        return $this;
    }

    public function getConversations(): array
    {
        return $this->conversations;
    }

    public function getPlan(): ?Plan
    {
        return $this->plan;
    }

    public function setPlan(?Plan $plan): self
    {
        $this->plan = $plan;
        return $this;
    }

    public function removePlan(): self
    {
        $this->plan = null;
        return $this;
    }

    public function addGroupCommentsProject(GroupCommentsProject $item): self
    {
        $this->groupCommentsProjects[] = $item;
        return $this;
    }

    public function removeGroupCommentsProject(GroupCommentsProject $item): self
    {
        $this->groupCommentsProjects = array_filter($this->groupCommentsProjects, fn($i) => $i !== $item);
        return $this;
    }

    public function getGroupCommentsProjects(): array
    {
        return $this->groupCommentsProjects;
    }

    public function addUserInvestorAccess(User $item): self
    {
        $this->access_investor[] = $item;
        return $this;
    }

    public function removeUserInvestorAccess(User $item): self
    {
        $this->access_investor = array_filter($this->access_investor, fn($i) => $i !== $item);
        return $this;
    }

    public function getAccess_investor(): array
    {
        return $this->access_investor;
    }


    public function addAccessRequest(ProjectAccessRequest $req): self
    {
        $this->accessRequests[] = $req;
        return $this;
    }

    public function removeAccessRequest(ProjectAccessRequest $req): self
    {
        $this->accessRequests = array_filter(
            $this->accessRequests,
            fn($i) => $i !== $req
        );
        return $this;
    }

    public function getAccessRequests(): array
    {
        return $this->accessRequests;
    }
}