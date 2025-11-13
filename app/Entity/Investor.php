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
class Investor
{
    #[Field(type: 'INT', autoIncrement: true, primaryKey: true)]
    public int $id;

    #[Field(type: 'string', nullable: true)]
    public ?string $foundName = null;
    #[Field(type: 'string', nullable: true)]
    public ?string $fundWebsite = null;

    #[Field(type: 'json', nullable: true)]
    public ?array $stageFocus = null;
    #[Field(type: 'json', nullable: true)]
    public ?array $sector = null;

    #[Field(type: 'integer', nullable: true)]
    public ?int $ticketSizeStart = null;
    #[Field(type: 'integer', nullable: true)]
    public ?int $ticketSizeRangeEnd = null;

    #[Field(type: 'json', nullable: true)]
    public ?array $geographicFocus = null;
    #[Field(type: 'integer', nullable: true)]
    public ?int $avgCheckSize = null;

    #[Field(type: 'integer', nullable: true)]
    public ?int $assetsManagement = null;
    #[Field(type: 'text', nullable: true)]
    public ?string $previousInvestments = null;

    #[Field(type: 'integer', nullable: true)]
    public ?int $leadInvestments = null;
    #[Field(type: 'string', nullable: true)]
    public ?string $accreditation = null;

    #[Field(type: 'string', nullable: true)]
    public ?string $personalWebsite = null;
    #[Field(type: 'text', nullable: true)]
    public ?string $preferredPartner = null;

    #[Field(type: 'json', nullable: true)]
    public ?array $pressLinks = null;
    #[ManyToOne(targetEntity: Media::class, inversedBy: 'investorsTesis')]
    public ?Media $tesisDocuments = null;

    #[OneToOne(targetEntity: User::class, inversedBy: 'investor')]
    public ?User $investor = null;
    
    #[Field(type: 'string', nullable: true)]
    public ?string $hash = null;

    public function __construct()
    {
    }

    public function getId(): int
    {
        return $this->id;
    }

    public function getFoundName(): ?string
    {
        return $this->foundName;
    }

    public function setFoundName(?string $foundName): self
    {
        $this->foundName = $foundName;
        return $this;
    }

    public function getFundWebsite(): ?string
    {
        return $this->fundWebsite;
    }

    public function setFundWebsite(?string $fundWebsite): self
    {
        $this->fundWebsite = $fundWebsite;
        return $this;
    }

    public function getStageFocus(): ?array
    {
        return $this->stageFocus;
    }

    public function setStageFocus(?array $stageFocus): self
    {
        $this->stageFocus = $stageFocus;
        return $this;
    }

    public function getSector(): ?array
    {
        return $this->sector;
    }

    public function setSector(?array $sector): self
    {
        $this->sector = $sector;
        return $this;
    }

    public function getTicketSizeStart(): ?int
    {
        return $this->ticketSizeStart;
    }

    public function setTicketSizeStart(?int $ticketSizeStart): self
    {
        $this->ticketSizeStart = $ticketSizeStart;
        return $this;
    }

    public function getTicketSizeRangeEnd(): ?int
    {
        return $this->ticketSizeRangeEnd;
    }

    public function setTicketSizeRangeEnd(?int $ticketSizeRangeEnd): self
    {
        $this->ticketSizeRangeEnd = $ticketSizeRangeEnd;
        return $this;
    }

    public function getGeographicFocus(): ?array
    {
        return $this->geographicFocus;
    }

    public function setGeographicFocus(?array $geographicFocus): self
    {
        $this->geographicFocus = $geographicFocus;
        return $this;
    }

    public function getAvgCheckSize(): ?int
    {
        return $this->avgCheckSize;
    }

    public function setAvgCheckSize(?int $avgCheckSize): self
    {
        $this->avgCheckSize = $avgCheckSize;
        return $this;
    }

    public function getAssetsManagement(): ?int
    {
        return $this->assetsManagement;
    }

    public function setAssetsManagement(?int $assetsManagement): self
    {
        $this->assetsManagement = $assetsManagement;
        return $this;
    }

    public function getPreviousInvestments(): ?string
    {
        return $this->previousInvestments;
    }

    public function setPreviousInvestments(?string $previousInvestments): self
    {
        $this->previousInvestments = $previousInvestments;
        return $this;
    }

    public function getLeadInvestments(): ?int
    {
        return $this->leadInvestments;
    }

    public function setLeadInvestments(?int $leadInvestments): self
    {
        $this->leadInvestments = $leadInvestments;
        return $this;
    }

    public function getAccreditation(): ?string
    {
        return $this->accreditation;
    }

    public function setAccreditation(?string $accreditation): self
    {
        $this->accreditation = $accreditation;
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

    public function getPreferredPartner(): ?string
    {
        return $this->preferredPartner;
    }

    public function setPreferredPartner(?string $preferredPartner): self
    {
        $this->preferredPartner = $preferredPartner;
        return $this;
    }

    public function getPressLinks(): ?array
    {
        return $this->pressLinks;
    }

    public function setPressLinks(?array $pressLinks): self
    {
        $this->pressLinks = $pressLinks;
        return $this;
    }

    public function getTesisDocuments(): ?Media
    {
        return $this->tesisDocuments;
    }

    public function setTesisDocuments(?Media $tesisDocuments): self
    {
        $this->tesisDocuments = $tesisDocuments;
        return $this;
    }

    public function removeTesisDocuments(): self
    {
        $this->tesisDocuments = null;
        return $this;
    }

    public function getInvestor(): ?User
    {
        return $this->investor;
    }

    public function setInvestor(?User $investor): self
    {
        $this->investor = $investor;
        return $this;
    }

    public function removeInvestor(): self
    {
        $this->investor = null;
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