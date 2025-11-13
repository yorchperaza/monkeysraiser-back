<?php
declare(strict_types=1);

namespace App\Repository;

use MonkeysLegion\Repository\EntityRepository;
use App\Entity\Conversation;

/**
 * @extends EntityRepository<Conversation>
 */
class ConversationRepository extends EntityRepository
{
    /** @var non-empty-string */
    protected string $table       = 'conversation';
    protected string $entityClass = Conversation::class;

    // ──────────────────────────────────────────────────────────
    //  Typed convenience wrappers (optional)
    //  Keep them if you like the stricter return types; otherwise
    //  feel free to delete them and rely on the parent methods.
    // ──────────────────────────────────────────────────────────

    /**
     * @param array<string,mixed> $criteria
     * @return Conversation[]
     */
    public function findAll(
        array $criteria = [],
        bool  $loadRelations = true
    ): array {
        /** @var Conversation[] $rows */
        $rows = parent::findAll($criteria, $loadRelations);
        return $rows;
    }

    /**
     * @param array<string,mixed> $criteria
     */
    public function findOneBy(
        array $criteria,
        bool  $loadRelations = true
    ): ?Conversation {
        /** @var ?Conversation $row */
        $row = parent::findOneBy($criteria, $loadRelations);
        return $row;
    }
}
