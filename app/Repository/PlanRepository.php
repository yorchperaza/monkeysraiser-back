<?php
declare(strict_types=1);

namespace App\Repository;

use MonkeysLegion\Repository\EntityRepository;
use App\Entity\Plan;

/**
 * @extends EntityRepository<Plan>
 */
class PlanRepository extends EntityRepository
{
    /** @var non-empty-string */
    protected string $table       = 'plan';
    protected string $entityClass = Plan::class;

    // ──────────────────────────────────────────────────────────
    //  Typed convenience wrappers (optional)
    //  Keep them if you like the stricter return types; otherwise
    //  feel free to delete them and rely on the parent methods.
    // ──────────────────────────────────────────────────────────

    /**
     * @param array<string,mixed> $criteria
     * @return Plan[]
     */
    public function findAll(
        array $criteria = [],
        bool  $loadRelations = true
    ): array {
        /** @var Plan[] $rows */
        $rows = parent::findAll($criteria, $loadRelations);
        return $rows;
    }

    /**
     * @param array<string,mixed> $criteria
     */
    public function findOneBy(
        array $criteria,
        bool  $loadRelations = true
    ): ?Plan {
        /** @var ?Plan $row */
        $row = parent::findOneBy($criteria, $loadRelations);
        return $row;
    }
}
