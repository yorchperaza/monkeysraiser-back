<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\Conversation;
use App\Entity\Founder;
use App\Entity\Investor;
use App\Entity\Media;
use App\Entity\Message;
use App\Entity\Plan;
use App\Entity\Project;
use App\Entity\Role;
use App\Entity\User;
use MonkeysLegion\Http\Message\JsonResponse;
use MonkeysLegion\Repository\EntityRepository;
use MonkeysLegion\Repository\RepositoryFactory;
use MonkeysLegion\Router\Attributes\Route;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use RuntimeException;

final class DashboardController
{
    private EntityRepository $users;
    private EntityRepository $projects;
    private EntityRepository $conversations;
    private EntityRepository $messages;
    private EntityRepository $plans;
    private EntityRepository $founders;
    private EntityRepository $investors;
    private EntityRepository $roles;

    public function __construct(
        private RepositoryFactory $repos,
    ) {
        $this->users         = $this->repos->getRepository(User::class);
        $this->projects      = $this->repos->getRepository(Project::class);
        $this->conversations = $this->repos->getRepository(Conversation::class);
        $this->messages      = $this->repos->getRepository(Message::class);
        $this->plans         = $this->repos->getRepository(Plan::class);
        $this->founders      = $this->repos->getRepository(Founder::class);
        $this->investors     = $this->repos->getRepository(Investor::class);
        $this->roles         = $this->repos->getRepository(Role::class);
    }

    #[Route(methods: 'GET', path: '/dashboard/summary')]
    public function getSummary(ServerRequestInterface $request): JsonResponse
    {
        try {
            // 1. Auth Check
            $userId = (int)$request->getAttribute('user_id', 0);
            if ($userId <= 0) {
                return new JsonResponse(['error' => true, 'message' => 'Unauthorized'], 401);
            }
            /** @var ?User $user */
            $user = $this->users->find($userId);
            if (!$user) {
                return new JsonResponse(['error' => true, 'message' => 'Unauthorized'], 401);
            }
            // 2. Fetch Data

            // A. Unread Messages
            try {
                $unreadCount = (int) (clone $this->messages->qb)
                    ->select('COUNT(m.id) as cnt')
                    ->from('message', 'm')
                    ->innerJoin('conversation_user', 'cu', 'cu.conversation_id', '=', 'm.conversation_id')
                    ->where('cu.user_id', '=', $userId)
                    ->where('m.author_id', '!=', $userId)
                    ->where('m.read', '=', 0)
                    ->fetch()
                    ?->cnt ?? 0;
            } catch (\Throwable $e) {
                $unreadCount = 0;
            }

            // B. My Projects
            $myProjects = $this->fetchMyProjects($userId, 12);

            // C. Favorites (Moved to separate endpoint)
            // $favorites = $this->fetchFavorites($userId, 12);

            // D. Plans
            $myPlans = $this->fetchPlans($user);

            // E. Conversations


            // F. Access Requests
            $accessRequests = []; 
            $favoritesData = $this->fetchFavoritesData($userId, 12);

            // 3. Serialize Response
            return new JsonResponse([
                'user' => $this->serializeUserLite($user),
                'unreadMessagesCount' => $unreadCount,
                'projects'      => ['items' => $myProjects, 'total' => count($myProjects)],
                'favorites'     => $favoritesData,
                'plans'         => ['items' => $myPlans],
                'accessRequests' => $accessRequests,
            ], 200);
            
        } catch (\Throwable $e) {
            return new JsonResponse([
                'error' => true, 
                'message' => $e->getMessage()
            ], 500);
        }
    }

    #[Route(methods: 'GET', path: '/dashboard/favorites')]
    public function getFavorites(ServerRequestInterface $request): ResponseInterface
    {
        try {
            $userId = (int)$request->getAttribute('user_id', 0);
            if ($userId <= 0) {
                 return new JsonResponse(['error' => true, 'message' => 'Unauthorized'], 401);
            }

            // --- pagination + flags ---
            $q = $request->getQueryParams();
            $page    = max(1, (int)($q['page'] ?? 1));
            $perPage = (int)($q['perPage'] ?? 12);
            if ($perPage <= 0)  { $perPage = 12; }
            if ($perPage > 100) { $perPage = 100; }
            $offset  = ($page - 1) * $perPage;

            // --- Base query over favorites join table ---
            $base = (clone $this->projects->qb)
                ->from('favorite_project', 'uf')
                ->leftJoin('project', 'p', 'p.id', '=', 'uf.project_id')
                ->where('uf.user_id', '=', $userId)
                ->andWhere('p.status', '=', 'published'); // Default to published only

            // Totals
            $total = 0;
            try {
                $total = $base->duplicate()->count();
            } catch (\Throwable $e) {
                $total = 0;
            }
            $pages = (int) max(1, (int) ceil($total / max(1, $perPage)));

            // Page rows
            $qPage = $base->duplicate()
                ->select('p.id AS id');
            
            // Apply limit/offset
            $qPage->limit($perPage)->offset($offset);

            $idRows = [];
            try {
                $idRows = $qPage->fetchAll();
            } catch (\Throwable $e) {
                $idRows = [];
            }

            // Hydrate & summarize
            $items = [];
            foreach ($idRows as $r) {
                $pid = isset($r['id']) ? (int)$r['id'] : 0;
                if ($pid <= 0) continue;

                $p = $this->projects->find($pid);
                if (!$p instanceof Project) continue;

                $row = $this->summarizeProjectForList($p);
                $row['favorited'] = true;
                $items[] = $row;
            }
            
            return new JsonResponse([
                'page'    => $page,
                'perPage' => $perPage,
                'total'   => $total,
                'pages'   => $pages,
                'items'   => $items,
            ], 200);

        } catch (\Throwable $e) {
             return new JsonResponse([
                'error' => true, 
                'message' => $e->getMessage()
            ], 500);
        }
    }

    // --- Fetch Helpers ---

    private function fetchFavoritesData(int $userId, int $limit): array
    {
        try {
            // --- Base query over favorites join table ---
            $base = (clone $this->projects->qb)
                ->from('favorite_project', 'uf')
                ->leftJoin('project', 'p', 'p.id', '=', 'uf.project_id')
                ->where('uf.user_id', '=', $userId)
                ->andWhere('p.status', '=', 'published'); // Default to published only

            // Totals
            $total = 0;
            try {
                $total = $base->duplicate()->count();
            } catch (\Throwable $e) {
                $total = 0;
            }

            // Page rows (limit 12 default for summary)
            $qPage = $base->duplicate()
                ->select('p.id AS id');
            
            $qPage->limit($limit);

            $idRows = [];
            try {
                $idRows = $qPage->fetchAll();
            } catch (\Throwable $e) {
                $idRows = [];
            }

            // Hydrate & summarize
            $items = [];
            foreach ($idRows as $r) {
                $pid = isset($r['id']) ? (int)$r['id'] : 0;
                if ($pid <= 0) continue;

                $p = $this->projects->find($pid);
                if (!$p instanceof Project) continue;

                $row = $this->summarizeProjectForList($p);
                $row['favorited'] = true;
                $items[] = $row;
            }

            return ['items' => $items, 'total' => $total];

        } catch (\Throwable $e) {
            return ['items' => [], 'total' => 0];
        }
    }

    private function fetchMyProjects(int $userId, int $limit): array
    {
        // 1. Authored
        // Use findBy for safety and hydration
        $authored = $this->projects->findBy(
            ['author' => $userId],
            ['updateDate' => 'DESC'],
            $limit
        );

        // 2. Contributed (via join)
        $contributedRows = (clone $this->projects->qb)
            ->select('p.id')
            ->from('project', 'p')
            ->innerJoin('project_user', 'pu', 'pu.project_id', '=', 'p.id')
            ->where('pu.user_id', '=', $userId)
            ->where('p.author_id', '!=', $userId)
            ->orderBy('p.updateDate', 'DESC')
            ->limit($limit)
            ->fetchAll();
        
        $contributedIds = array_column($contributedRows, 'id');
        $contributed = [];
        if (!empty($contributedIds)) {
            $contributed = $this->projects->findBy(['id' => $contributedIds]);
        }

        // Merge & Sort
        $all = array_merge($authored, $contributed);
        // Deduplicate
        $unique = [];
        foreach ($all as $p) {
            $unique[$p->getId()] = $p;
        }
        $all = array_values($unique);

        usort($all, fn(Project $a, Project $b) => 
            ($b->getUpdateDate() <=> $a->getUpdateDate())
        );
        $all = array_slice($all, 0, $limit);

        return array_map(fn($p) => $this->serializeProjectLite($p), $all);
    }



    private function fetchPlans(User $user): array
    {
        try {
            // Bypass ORM entirely - use raw query to avoid hydration issues
            $rows = (clone $this->plans->qb)
                ->select('p.id, p.name, p.price, p.slug')
                ->from('plan', 'p')
                ->innerJoin('plan_user', 'pu', 'pu.plan_id', '=', 'p.id')
                ->where('pu.user_id', '=', $user->getId())
                ->fetchAll();
            
            if (empty($rows)) {
                return [];
            }
            
            // Map directly from query result (no ORM hydration)
            return array_map(fn($row) => [
                'id' => (int)$row['id'],
                'name' => $row['name'] ?? null,
                'price' => isset($row['price']) ? (int)$row['price'] : null,
                'slug' => $row['slug'] ?? null,
            ], $rows);
        } catch (\Throwable $e) {
            return [];
        }
    }

    private function fetchConversations(int $userId, int $limit): array
    {
        try {
            // Bypass ORM - raw query to avoid hydration issues with ManyToMany
            $rows = (clone $this->conversations->qb)
                ->select('c.id, c.hash, c.subject, c.createdAt')
                ->from('conversation', 'c')
                ->innerJoin('conversation_user', 'cu', 'cu.conversation_id', '=', 'c.id')
                ->where('cu.user_id', '=', $userId)
                ->groupBy('c.id')
                ->limit($limit)
                ->fetchAll();

            if (empty($rows)) return [];
            
            // Map directly from query result
            return array_map(fn($row) => [
                'id' => (int)$row['id'],
                'hash' => $row['hash'] ?? null,
                'subject' => $row['subject'] ?? null,
                'updatedAt' => $row['createdAt'] ?? null,
                'otherParticipant' => null, // Skip for now to avoid more ORM issues
            ], $rows);
        } catch (\Throwable $e) {
            return [];
        }
    }

    // --- Serializers ---

    private function serializeUserLite(User $user): array
    {
        try {
            // Avoid loading relations that cause ORM crashes
            $roles = [];
            try {
                $roles = array_map(fn(Role $r) => [
                    'id'   => method_exists($r, 'getId') ? $r->getId() : null,
                    'name' => method_exists($r, 'getName') ? $r->getName() : null,
                    'slug' => $r->getSlug(),
                ], $user->getRoles());
            } catch (\Throwable $e) {
                $roles = [];
            }
            
            $founderHash  = null;
            $investorHash = null;
            try {
                // Founder
                $f = $this->founders->findOneBy(['user' => $user]);
                if ($f) $founderHash = $f->getHash();
            } catch (\Throwable $e) {}

            try {
                // Investor
                $i = $this->investors->findOneBy(['investor' => $user]); // investor relation uses 'investor' field
                if (!$i) $i = $this->investors->findOneBy(['investor' => $user->getId()]); 
                if ($i) $investorHash = $i->getHash();
            } catch (\Throwable $e) {}

            return [
                'id'          => $user->getId(),
                'fullName'    => $user->getFullName(),
                'title'       => $user->getTitle(),
                'email'       => $user->getEmail(),
                'shortBio'    => $user->getShortBio(),
                'longBio'     => $user->getLongBio(),
                'social'      => $user->getSocial(),
                'location'    => $user->getLocation(),
                'timeZone'    => $user->getTimeZone(),
                'lastLoginAt' => method_exists($user, 'getLastLoginAt') ? $user->getLastLoginAt()?->format('c') : null,
                
                'picture'     => $this->safeGetMedia($user, 'getPicture'),
                'banner'      => $this->safeGetMedia($user, 'getBanner'),
                
                'roles'       => $roles,
                'founder'     => ['hash' => $founderHash],
                'investor'    => ['hash' => $investorHash],
            ];
        } catch (\Throwable $e) {
            error_log("DashboardController: serializeUserLite CRASH: " . $e->getMessage());
            return [
                'id' => $user->getId(),
                'fullName' => 'Unknown', 
                'email' => null,
                'picture' => null
            ];
        }
    }

    private function safeGetMedia(User $user, string $method): ?array
    {
        try {
            $media = $user->$method();
            return $this->safeSerializeMedia($media);
        } catch (\Throwable $e) {
            return null;
        }
    }

    private function safeSerializeMedia($media): ?array
    {
        if (!$media) return null;
        try {
            return $this->serializeMedia($media);
        } catch (\Throwable $e) {
            return null;
        }
    }

    
    private function serializeProjectLite(Project $p): array
    {
        return [
            'id' => $p->getId(),
            'hash' => $p->getHash(),
            'title' => $p->getName(),  // Frontend expects 'title', not 'name'
            'tagline' => $p->getTagline(),
            'stage' => $p->getStage(),
            'image' => $this->pickFirstProjectImage($p), // Frontend expects 'image'
            'status' => $p->getStatus(),
            'location' => $p->getLocation(),
            'foundingTarget' => $p->getFoundingTarget(),
            'capitalSought' => $p->getCapitalSought(),
        ];
    }

    private function fetchFounder(User $user): ?Founder
    {
        $f = $this->founders->findOneBy(['user' => $user->getId()]);
        if (!$f) $f = $this->founders->findOneBy(['user' => $user]);
        return $f instanceof Founder ? $f : null;
    }

    private function fetchInvestor(User $user): ?Investor
    {
        $i = $this->investors->findOneBy(['investor' => $user->getId()]);
        if (!$i) $i = $this->investors->findOneBy(['investor' => $user]);
        return $i instanceof Investor ? $i : null;
    }

    private function summarizeProjectForList(Project $project): array
    {
        return [
            'hash'              => $project->getHash(),
            'title'             => $project->getName(),
            'tagline'           => $project->getTagline(),
            'categories'        => $project->getCategory(),
            'founded'           => $project->getFounded()?->format('Y-m-d'),
            'stage'             => $project->getStage(),
            'foundingTarget'    => $project->getFoundingTarget(),
            'capitalSought'     => $project->getCapitalSought(),
            'location'          => $project->getLocation(),
            'image'             => $this->pickFirstProjectImage($project),
            'boost'             => $project->getBoost() ?? false,
            'superBoost'        => $project->getSuperBoost() ?? false
        ];
    }

    private function pickFirstProjectImage(Project $project): ?array
    {
        if ($project->getBanner()) {
            return $this->safeSerializeMedia($project->getBanner());
        }
        if ($project->getLogo()) {
            return $this->safeSerializeMedia($project->getLogo());
        }
        $gallery = $project->getMediaGallery();
        if (!empty($gallery)) {
            // ensure we take the first gallery item deterministically
            $first = reset($gallery);
            if ($first) {
                return $this->safeSerializeMedia($first);
            }
        }
        return null;
    }

    private function serializeMedia(object $media): array
    {
        return [
            'id'   => method_exists($media, 'getId')   ? $media->getId()   : null,
            'url'  => method_exists($media, 'getUrl')  ? $media->getUrl()  : null,
            'type' => method_exists($media, 'getType') ? $media->getType() : null,
            'hash' => method_exists($media, 'getHash') ? $media->getHash() : null,
        ];
    }
}
