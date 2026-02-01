<?php

declare(strict_types=1);

namespace App\Entity;

use MonkeysLegion\Entity\Attributes\Entity;
use MonkeysLegion\Entity\Attributes\Field;
use MonkeysLegion\Entity\Attributes\OneToOne;
use MonkeysLegion\Entity\Utils\Uuid;

#[Entity(table: 'openvcinvestor')]
class OpenVcInvestor
{
    #[Field(type: 'uuid', primaryKey: true)]
    public ?string $id;

    #[Field(type: 'string')]
    public string $fundName;

    #[Field(type: 'bool')]
    public bool $verified = false;

    #[Field(type: 'string', nullable: true)]
    public ?string $linkedin = null;

    #[Field(type: 'string', nullable: true)]
    public ?string $website = null;

    #[Field(type: 'text', nullable: true)]
    public ?string $description = null;

    #[Field(type: 'text', nullable: true)]
    public ?string $valueAdd = null;

    #[Field(type: 'json', nullable: true)]
    public ?string $firmType = null;

    #[Field(type: 'string', nullable: true)]
    public ?string $globalHq = null;

    #[Field(type: 'json', nullable: true)]
    public ?string $fundingStages = null;

    #[Field(type: 'int', nullable: true)]
    public ?int $checkSizeMin = null;

    #[Field(type: 'int', nullable: true)]
    public ?int $checkSizeMax = null;

    #[Field(type: 'json', nullable: true)]
    public ?string $targetCountries = null;

    #[Field(type: 'text', nullable: true)]
    public ?string $team = null;

    #[Field(type: 'string', nullable: true)]
    public ?string $sourcePage = null;

    #[OneToOne(targetEntity: Media::class, inversedBy: 'openVcInvestorLogo')]
    public ?Media $logo = null;

    #[Field(type: 'datetime', nullable: true)]
    public ?string $created = null;

    #[Field(type: 'datetime', nullable: true)]
    public ?string $updated = null;

    /** @var int|null FK to Media for logo - persisted directly */
    #[Field(type: 'bigint', nullable: true)]
    public ?int $logo_id = null;

    public function __construct()
    {
        $this->created = (new \DateTimeImmutable())->format('Y-m-d H:i:s');
    }

    public function getId(): ?string
    {
        return $this->id;
    }

    public function getFundName(): string
    {
        return $this->fundName;
    }

    public function setFundName(string $fundName): self
    {
        $this->fundName = $fundName;
        return $this;
    }

    public function isVerified(): bool
    {
        return $this->verified;
    }

    public function setVerified(bool $verified): self
    {
        $this->verified = $verified;
        return $this;
    }

    public function getLinkedin(): ?string
    {
        return $this->linkedin;
    }

    public function setLinkedin(?string $linkedin): self
    {
        $this->linkedin = $linkedin;
        return $this;
    }

    public function getWebsite(): ?string
    {
        return $this->website;
    }

    public function setWebsite(?string $website): self
    {
        $this->website = $website;
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

    public function getValueAdd(): ?string
    {
        return $this->valueAdd;
    }

    public function setValueAdd(?string $valueAdd): self
    {
        $this->valueAdd = $valueAdd;
        return $this;
    }

    public function getFirmType(): ?array
    {
        if (is_string($this->firmType)) {
            $data = json_decode($this->firmType, true);
            return is_array($data) ? $data : null;
        }
        return null;
    }

    public function setFirmType(array|string|null $firmType): self
    {
        if (is_string($firmType)) {
            // Assume it's already a JSON string or a simple string we want to wrap?
            // User requested firmType is JSON. The CSV has "Firm type" as "VC", "Family office", etc.
            // It might be a single string in CSV, but we want to store it as JSON (maybe ["VC"]).
            // Or maybe the user implies it CAN handle multiple values?
            // For safety, let's encode whatever we get if it's an array, 
            // but if it's a string, we might want to decode it first to check if it's valid JSON?
            // Actually, if we pass a raw string "VC", and field is JSON, we should probably encode it as ["VC"] or "VC"?
            // Standard JSON field practice: store as json string.
            // If the input is "VC", storing it as "\"VC\"" is valid JSON.
            // I'll stick to array input for setter to force structure.
            $this->firmType = $firmType; // Dangerous if it's not JSON.
        } elseif (is_array($firmType)) {
            $this->firmType = json_encode($firmType);
        } else {
            $this->firmType = null;
        }
        return $this;
    }

    public function getGlobalHq(): ?string
    {
        return $this->globalHq;
    }

    public function setGlobalHq(?string $globalHq): self
    {
        $this->globalHq = $globalHq;
        return $this;
    }

    public function getFundingStages(): ?array
    {
        if (is_string($this->fundingStages)) {
            $data = json_decode($this->fundingStages, true);
            return is_array($data) ? $data : null;
        }
        return null;
    }

    public function setFundingStages(?array $fundingStages): self
    {
        $this->fundingStages = $fundingStages ? json_encode($fundingStages) : null;
        return $this;
    }

    public function getCheckSizeMin(): ?int
    {
        return $this->checkSizeMin;
    }

    public function setCheckSizeMin(?int $checkSizeMin): self
    {
        $this->checkSizeMin = $checkSizeMin;
        return $this;
    }

    public function getCheckSizeMax(): ?int
    {
        return $this->checkSizeMax;
    }

    public function setCheckSizeMax(?int $checkSizeMax): self
    {
        $this->checkSizeMax = $checkSizeMax;
        return $this;
    }

    public function getTargetCountries(): ?array
    {
        if (is_string($this->targetCountries)) {
            $data = json_decode($this->targetCountries, true);
            return is_array($data) ? $data : null;
        }
        return null;
    }

    public function setTargetCountries(?array $targetCountries): self
    {
        $this->targetCountries = $targetCountries ? json_encode($targetCountries) : null;
        return $this;
    }

    public function getTeam(): ?string
    {
        return $this->team;
    }

    public function setTeam(?string $team): self
    {
        $this->team = $team;
        return $this;
    }

    public function getSourcePage(): ?string
    {
        return $this->sourcePage;
    }

    public function setSourcePage(?string $sourcePage): self
    {
        $this->sourcePage = $sourcePage;
        return $this;
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
}
