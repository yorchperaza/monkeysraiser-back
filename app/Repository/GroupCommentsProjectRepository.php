<?php
declare(strict_types=1);

namespace App\Repository;

use MonkeysLegion\Repository\EntityRepository;
use App\Entity\GroupCommentsProject;

/**
 * @extends EntityRepository<GroupCommentsProject>
 */
class GroupCommentsProjectRepository extends EntityRepository
{
    /** @var non-empty-string */
    protected string $table       = 'groupcommentsproject';
    protected string $entityClass = GroupCommentsProject::class;

    // ──────────────────────────────────────────────────────────
    //  Typed convenience wrappers (optional)
    //  Keep them if you like the stricter return types; otherwise
    //  feel free to delete them and rely on the parent methods.
    // ──────────────────────────────────────────────────────────

    /**
     * @param array<string,mixed> $criteria
     * @return GroupCommentsProject[]
     */
    public function findAll(
        array $criteria = [],
        bool  $loadRelations = true
    ): array {
        /** @var GroupCommentsProject[] $rows */
        $rows = parent::findAll($criteria, $loadRelations);
        return $rows;
    }

    /**
     * @param array<string,mixed> $criteria
     */
    public function findOneBy(
        array $criteria,
        bool  $loadRelations = true
    ): ?GroupCommentsProject {
        /** @var ?GroupCommentsProject $row */
        $row = parent::findOneBy($criteria, $loadRelations);
        return $row;
    }
}
