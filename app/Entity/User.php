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
class User
{
    #[Field(type: 'integer')]
    public int $id;

    #[Field(type: 'string', length: 255)]
    public string $email;
    #[Field(type: 'string', length: 255)]
    public string $passwordHash;

    #[Field(type: 'string', nullable: true)]
    public ?string $fullName = null;
    #[Field(type: 'string', nullable: true)]
    public ?string $title = null;

    #[Field(type: 'text', nullable: true)]
    public ?string $shortBio = null;
    #[Field(type: 'text', nullable: true)]
    public ?string $longBio = null;

    #[Field(type: 'json', nullable: true)]
    public ?array $social = null;
    #[Field(type: 'string', nullable: true)]
    public ?string $timeZone = null;

    #[Field(type: 'json', nullable: true)]
    public ?array $location = null;
    #[OneToOne(targetEntity: Media::class, inversedBy: 'userPicture')]
    public ?Media $picture = null;

    #[OneToOne(targetEntity: Media::class, inversedBy: 'userBanner')]
    public ?Media $banner = null;
    #[OneToOne(targetEntity: Founder::class, mappedBy: 'user')]
    public ?Founder $founder = null;

    #[OneToOne(targetEntity: Investor::class, mappedBy: 'investor')]
    public ?Investor $investor = null;
    /** @var Role[] */
    #[ManyToMany(targetEntity: Role::class, inversedBy: 'users', joinTable: new JoinTable(name: 'role_user', joinColumn: 'user_id', inverseColumn: 'role_id'))]
    public array $roles = [];
    /** @var Project[] */
    #[ManyToMany(targetEntity: Project::class, mappedBy: 'users')]
    public array $projects = [];

    #[OneToOne(targetEntity: Project::class, mappedBy: 'author')]
    public ?Project $projectAuthor = null;
    #[OneToOne(targetEntity: Message::class, mappedBy: 'author')]
    public ?Message $authorMessage = null;

    #[Field(type: 'datetime', nullable: true)]
    public ?\DateTimeImmutable $lastLoginAt = null;
    #[OneToOne(targetEntity: Media::class, mappedBy: 'authorUser')]
    public ?Media $mediaUser = null;
    /** @var Project[] */
    #[ManyToMany(targetEntity: Project::class, inversedBy: 'favorite_users', joinTable: new JoinTable(name: 'favorite_project', joinColumn: 'user_id', inverseColumn: 'project_id'))]
    public array $favorites = [];
    /** @var User[] */
    #[ManyToMany(targetEntity: User::class, joinTable: new JoinTable(name: 'favorite_user', joinColumn: 'user_id', inverseColumn: 'follow_user'))]
    public array $favorite_users = [];
    /** @var Conversation[] */
    #[ManyToMany(targetEntity: Conversation::class, mappedBy: 'users')]
    public array $conversations = [];
    /** @var Plan[] */
    #[ManyToMany(targetEntity: Plan::class, mappedBy: 'users')]
    public array $plans = [];

    #[Field(type: 'datetime', nullable: true)]
    public ?\DateTimeImmutable $lastActivityAt = null;
    /** @var CommentsProject[] */
    #[OneToMany(targetEntity: CommentsProject::class, mappedBy: 'author')]
    public array $authorCommentsProject = [];
    /** @var GroupCommentsProject[] */
    #[ManyToMany(targetEntity: GroupCommentsProject::class, mappedBy: 'recipients')]
    public array $groupCommentsProjectsRecipients = [];
    
     /** @var Project[] */
    #[ManyToMany(targetEntity: Project::class, mappedBy: 'access_investor')]
    public array $projects_investor = [];

    /** @var ProjectAccessRequest[] */
    #[OneToMany(targetEntity: ProjectAccessRequest::class, mappedBy: 'investor')]
    public array $projectAccessRequests = [];
    
    /** @var CommentsProject[] */
    public function __construct()
    {
        // any initialization if needed
        $this->roles = [];
        $this->projects = [];
        $this->favorites = [];
        $this->favorite_users = [];
        $this->conversations = [];
        $this->plans = [];
        $this->authorCommentsProject = [];
        $this->groupCommentsProjectsRecipients = [];
        $this->projects_investor = [];
        $this->projectAccessRequests = [];
    }

    public function getId(): int
    {
        return $this->id;
    }

    public function getEmail(): string
    {
        return $this->email;
    }

    public function setEmail(string $email): self
    {
        $this->email = $email;
        return $this;
    }

    public function getPasswordHash(): string
    {
        return $this->passwordHash;
    }

    public function setPasswordHash(string $hash): self
    {
        $this->passwordHash = $hash;
        return $this;
    }

    public function getFullName(): ?string
    {
        return $this->fullName;
    }

    public function setFullName(?string $fullName): self
    {
        $this->fullName = $fullName;
        return $this;
    }

    public function getTitle(): ?string
    {
        return $this->title;
    }

    public function setTitle(?string $title): self
    {
        $this->title = $title;
        return $this;
    }

    public function getShortBio(): ?string
    {
        return $this->shortBio;
    }

    public function setShortBio(?string $shortBio): self
    {
        $this->shortBio = $shortBio;
        return $this;
    }

    public function getLongBio(): ?string
    {
        return $this->longBio;
    }

    public function setLongBio(?string $longBio): self
    {
        $this->longBio = $longBio;
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

    public function getTimeZone(): ?string
    {
        return $this->timeZone;
    }

    public function setTimeZone(?string $timeZone): self
    {
        $this->timeZone = $timeZone;
        return $this;
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

    public function getPicture(): ?Media
    {
        return $this->picture;
    }

    public function setPicture(?Media $picture): self
    {
        $this->picture = $picture;
        return $this;
    }

    public function removePicture(): self
    {
        $this->picture = null;
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

    public function getFounder(): ?Founder
    {
        return $this->founder;
    }

    public function setFounder(?Founder $founder): self
    {
        $this->founder = $founder;
        return $this;
    }

    public function removeFounder(): self
    {
        $this->founder = null;
        return $this;
    }

    public function getInvestor(): ?Investor
    {
        return $this->investor;
    }

    public function setInvestor(?Investor $investor): self
    {
        $this->investor = $investor;
        return $this;
    }

    public function removeInvestor(): self
    {
        $this->investor = null;
        return $this;
    }

    public function addRole(Role $item): self
    {
        $this->roles[] = $item;
        return $this;
    }

    public function removeRole(Role $item): self
    {
        $this->roles = array_filter($this->roles, fn($i) => $i !== $item);
        return $this;
    }

    public function getRoles(): array
    {
        return $this->roles;
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

    public function getProjectAuthor(): ?Project
    {
        return $this->projectAuthor;
    }

    public function setProjectAuthor(?Project $projectAuthor): self
    {
        $this->projectAuthor = $projectAuthor;
        return $this;
    }

    public function removeProjectAuthor(): self
    {
        $this->projectAuthor = null;
        return $this;
    }

    public function getAuthorMessage(): ?Message
    {
        return $this->authorMessage;
    }

    public function setAuthorMessage(?Message $authorMessage): self
    {
        $this->authorMessage = $authorMessage;
        return $this;
    }

    public function removeAuthorMessage(): self
    {
        $this->authorMessage = null;
        return $this;
    }

    public function getLastLoginAt(): ?\DateTimeImmutable
    {
        return $this->lastLoginAt;
    }

    public function setLastLoginAt(?\DateTimeImmutable $lastLoginAt): self
    {
        $this->lastLoginAt = $lastLoginAt;
        return $this;
    }

    public function getMediaUser(): ?Media
    {
        return $this->mediaUser;
    }

    public function setMediaUser(?Media $mediaUser): self
    {
        $this->mediaUser = $mediaUser;
        return $this;
    }

    public function removeMediaUser(): self
    {
        $this->mediaUser = null;
        return $this;
    }

    public function addProjectFavorite(Project $item): self
    {
        $this->favorites[] = $item;
        return $this;
    }

    public function removeProjectFavorite(Project $item): self
    {
        $this->favorites = array_filter($this->favorites, fn($i) => $i !== $item);
        return $this;
    }

    public function getFavorites(): array
    {
        return $this->favorites;
    }

    public function addUser(User $item): self
    {
        $this->favorite_users[] = $item;
        return $this;
    }

    public function removeUser(User $item): self
    {
        $this->favorite_users = array_filter($this->favorite_users, fn($i) => $i !== $item);
        return $this;
    }

    public function getFavorite_users(): array
    {
        return $this->favorite_users;
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

    public function addPlan(Plan $item): self
    {
        $this->plans[] = $item;
        return $this;
    }

    public function removePlan(Plan $item): self
    {
        $this->plans = array_filter($this->plans, fn($i) => $i !== $item);
        return $this;
    }

    public function getPlans(): array
    {
        return $this->plans;
    }

    public function getLastActivityAt(): ?\DateTimeImmutable
    {
        return $this->lastActivityAt;
    }

    public function setLastActivityAt(?\DateTimeImmutable $lastActivityAt): self
    {
        $this->lastActivityAt = $lastActivityAt;
        return $this;
    }

    public function addCommentsProject(CommentsProject $item): self
    {
        $this->authorCommentsProject[] = $item;
        return $this;
    }

    public function removeCommentsProject(CommentsProject $item): self
    {
        $this->authorCommentsProject = array_filter($this->authorCommentsProject, fn($i) => $i !== $item);
        return $this;
    }

    public function getAuthorCommentsProject(): array
    {
        return $this->authorCommentsProject;
    }

    public function addGroupCommentsProject(GroupCommentsProject $item): self
    {
        $this->groupCommentsProjectsRecipients[] = $item;
        return $this;
    }

    public function removeGroupCommentsProject(GroupCommentsProject $item): self
    {
        $this->groupCommentsProjectsRecipients = array_filter($this->groupCommentsProjectsRecipients, fn($i) => $i !== $item);
        return $this;
    }

    public function getGroupCommentsProjectsRecipients(): array
    {
        return $this->groupCommentsProjectsRecipients;
    }

    public function addProjectInvestorAccess(Project $item): self
    {
        $this->projects_investor[] = $item;
        return $this;
    }

    public function removeProjectInvestorAccess(Project $item): self
    {
        $this->projects_investor = array_filter($this->projects_investor, fn($i) => $i !== $item);
        return $this;
    }

    public function getProjects_investor(): array
    {
        return $this->projects_investor;
    }

    public function addProjectAccessRequest(ProjectAccessRequest $req): self
    {
        $this->projectAccessRequests[] = $req;
        return $this;
    }

    public function removeProjectAccessRequest(ProjectAccessRequest $req): self
    {
        $this->projectAccessRequests = array_filter(
            $this->projectAccessRequests,
            fn($i) => $i !== $req
        );
        return $this;
    }

    public function getProjectAccessRequests(): array
    {
        return $this->projectAccessRequests;
    }
}