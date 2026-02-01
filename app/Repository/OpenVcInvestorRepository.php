<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\OpenVcInvestor;
use MonkeysLegion\Repository\EntityRepository;
use MonkeysLegion\Entity\Hydrator;

class OpenVcInvestorRepository extends EntityRepository
{
    protected string $table = 'openvcinvestor';
    protected string $entityClass = OpenVcInvestor::class;

    /**
     * @param int $page
     * @param int $limit
     * @param array{
     *     name?: string,
     *     targetCountries?: string,
     *     firmType?: string,
     *     fundingStages?: string
     * } $filters
     * @return array{items: OpenVcInvestor[], total: int}
     */
    public function search(int $page = 1, int $limit = 20, array $filters = []): array
    {
        $qb = $this->qb->select('*')->from($this->table);

        // Full-text search across multiple fields (fundName, description, valueAdd, globalHq, team)
        if (!empty($filters['name'])) {
            $searchTerm = '%' . $filters['name'] . '%';
            // Use raw SQL for OR logic across multiple columns
            $qb->whereRaw(
                "(fundName LIKE ? OR description LIKE ? OR valueAdd LIKE ? OR globalHq LIKE ? OR team LIKE ?)",
                [$searchTerm, $searchTerm, $searchTerm, $searchTerm, $searchTerm]
            );
        }

        // JSON fields - targetCountries with AND logic (must match ALL selected)
        if (!empty($filters['targetCountries'])) {
            // Decode JSON array of countries
            $countries = json_decode($filters['targetCountries'], true);
            if (is_array($countries)) {
                foreach ($countries as $country) {
                    // Each country must be present in the targetCountries JSON
                    $qb->andWhere('targetCountries', 'LIKE', '%"' . $country . '"%');
                }
            } else {
                // Fallback to simple LIKE if not valid JSON array
                $qb->andWhere('targetCountries', 'LIKE', '%' . $filters['targetCountries'] . '%');
            }
        }

        if (!empty($filters['firmType'])) {
            $qb->where('firmType', 'LIKE', '%' . $filters['firmType'] . '%');
        }

        if (!empty($filters['fundingStages'])) {
            $qb->where('fundingStages', 'LIKE', '%' . $filters['fundingStages'] . '%');
        }

        // Count total
        $countQb = clone $qb;
        $total = (int) $countQb->count();

        // Pagination
        $offset = ($page - 1) * $limit;
        $qb->limit($limit)->offset($offset);
        
        // Order by id (guaranteed to exist)
        $qb->orderBy('id', 'ASC');

        $rows = $qb->fetchAll();
        error_log("OpenVcInvestorRepository::search - Found " . count($rows) . " rows.");
        
        /** @var OpenVcInvestor[] $items */
        $items = [];
        foreach ($rows as $row) {
            $entity = Hydrator::hydrate($this->entityClass, $row);
            // Attach logo_id from raw row for controller access
            $entity->logo_id = $row['logo_id'] ?? null;
            $items[] = $entity;
        }

        // Skip loadRelations to avoid nested query issues with related entities
        // The logo can be loaded lazily or via a separate query if needed
        foreach ($items as $entity) {
            $this->storeOriginalValues($entity);
            // $this->loadRelations($entity); // Disabled due to column name issues in related tables
        }

        return [
            'items' => $items,
            'total' => $total,
        ];
    }}
