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

    #[Field(type: 'int', nullable: true)]
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
            $firmType = trim($firmType);
            if ($firmType === '') {
                $this->firmType = null;
            } else {
                // If it looks like JSON array, store as-is
                $decoded = json_decode($firmType, true);
                if (is_array($decoded)) {
                    $this->firmType = $firmType;
                } elseif (strpos($firmType, ';') !== false) {
                    // Semicolon-delimited: convert to JSON array
                    $this->firmType = json_encode(array_map('trim', explode(';', $firmType)));
                } else {
                    // Single value: wrap in array
                    $this->firmType = json_encode([$firmType]);
                }
            }
        } elseif (is_array($firmType)) {
            $this->firmType = !empty($firmType) ? json_encode($firmType) : null;
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

    public function setFundingStages(array|string|null $fundingStages): self
    {
        if (is_string($fundingStages)) {
            $fundingStages = trim($fundingStages);
            if ($fundingStages === '') {
                $this->fundingStages = null;
            } else {
                // If it looks like JSON array, store as-is
                $decoded = json_decode($fundingStages, true);
                if (is_array($decoded)) {
                    $this->fundingStages = $fundingStages;
                } elseif (strpos($fundingStages, ';') !== false) {
                    // Semicolon-delimited: convert to JSON array
                    $this->fundingStages = json_encode(array_map('trim', explode(';', $fundingStages)));
                } else {
                    // Single value: wrap in array
                    $this->fundingStages = json_encode([$fundingStages]);
                }
            }
        } elseif (is_array($fundingStages)) {
            $this->fundingStages = !empty($fundingStages) ? json_encode($fundingStages) : null;
        } else {
            $this->fundingStages = null;
        }
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

    public function setTargetCountries(array|string|null $targetCountries): self
    {
        if (is_string($targetCountries)) {
            $targetCountries = trim($targetCountries);
            if ($targetCountries === '') {
                $this->targetCountries = null;
            } else {
                // If it looks like JSON array, store as-is
                $decoded = json_decode($targetCountries, true);
                if (is_array($decoded)) {
                    $this->targetCountries = $targetCountries;
                } elseif (strpos($targetCountries, ';') !== false) {
                    // Semicolon-delimited: convert to JSON array
                    $this->targetCountries = json_encode(array_map('trim', explode(';', $targetCountries)));
                } else {
                    // Single value: wrap in array
                    $this->targetCountries = json_encode([$targetCountries]);
                }
            }
        } elseif (is_array($targetCountries)) {
            $this->targetCountries = !empty($targetCountries) ? json_encode($targetCountries) : null;
        } else {
            $this->targetCountries = null;
        }
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
        $this->logo_id = $logo?->getId();
        return $this;
    }
}
