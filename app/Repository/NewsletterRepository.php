<?php
declare(strict_types=1);

namespace App\Repository;

use MonkeysLegion\Repository\EntityRepository;
use App\Entity\Newsletter;

/**
 * @extends EntityRepository<Newsletter>
 */
class NewsletterRepository extends EntityRepository
{
    /** @var non-empty-string */
    protected string $table       = 'newsletter';
    protected string $entityClass = Newsletter::class;

    // ──────────────────────────────────────────────────────────
    //  Typed convenience wrappers (optional)
    //  Keep them if you like the stricter return types; otherwise
    //  feel free to delete them and rely on the parent methods.
    // ──────────────────────────────────────────────────────────

    /**
     * @param array<string,mixed> $criteria
     * @return Newsletter[]
     */
    public function findAll(
        array $criteria = [],
        bool  $loadRelations = true
    ): array {
        /** @var Newsletter[] $rows */
        $rows = parent::findAll($criteria, $loadRelations);
        return $rows;
    }

    /**
     * @param array<string,mixed> $criteria
     */
    public function findOneBy(
        array $criteria,
        bool  $loadRelations = true
    ): ?Newsletter {
        /** @var ?Newsletter $row */
        $row = parent::findOneBy($criteria, $loadRelations);
        return $row;
    }
}
