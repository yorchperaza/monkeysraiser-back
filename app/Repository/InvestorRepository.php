<?php
declare(strict_types=1);

namespace App\Repository;

use MonkeysLegion\Repository\EntityRepository;
use App\Entity\Investor;

/**
 * @extends EntityRepository<Investor>
 */
class InvestorRepository extends EntityRepository
{
    /** @var non-empty-string */
    protected string $table       = 'investor';
    protected string $entityClass = Investor::class;

    // ──────────────────────────────────────────────────────────
    //  Typed convenience wrappers (optional)
    //  Keep them if you like the stricter return types; otherwise
    //  feel free to delete them and rely on the parent methods.
    // ──────────────────────────────────────────────────────────

    /**
     * @param array<string,mixed> $criteria
     * @return Investor[]
     */
    public function findAll(
        array $criteria = [],
        bool  $loadRelations = true
    ): array {
        /** @var Investor[] $rows */
        $rows = parent::findAll($criteria, $loadRelations);
        return $rows;
    }

    /**
     * @param array<string,mixed> $criteria
     */
    public function findOneBy(
        array $criteria,
        bool  $loadRelations = true
    ): ?Investor {
        /** @var ?Investor $row */
        $row = parent::findOneBy($criteria, $loadRelations);
        return $row;
    }
}
