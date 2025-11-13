<?php
declare(strict_types=1);

namespace App\Repository;

use MonkeysLegion\Repository\EntityRepository;
use App\Entity\Role;

/**
 * @extends EntityRepository<Role>
 */
class RoleRepository extends EntityRepository
{
    /** @var non-empty-string */
    protected string $table       = 'role';
    protected string $entityClass = Role::class;

    // ──────────────────────────────────────────────────────────
    //  Typed convenience wrappers (optional)
    //  Keep them if you like the stricter return types; otherwise
    //  feel free to delete them and rely on the parent methods.
    // ──────────────────────────────────────────────────────────

    /**
     * @param array<string,mixed> $criteria
     * @return Role[]
     */
    public function findAll(
        array $criteria = [],
        bool  $loadRelations = true
    ): array {
        /** @var Role[] $rows */
        $rows = parent::findAll($criteria, $loadRelations);
        return $rows;
    }

    /**
     * @param array<string,mixed> $criteria
     */
    public function findOneBy(
        array $criteria,
        bool  $loadRelations = true
    ): ?Role {
        /** @var ?Role $row */
        $row = parent::findOneBy($criteria, $loadRelations);
        return $row;
    }
}
