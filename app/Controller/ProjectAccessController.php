<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\Project;
use App\Entity\ProjectAccessRequest;
use App\Entity\Role;
use App\Entity\User;
use MonkeysLegion\Http\Message\JsonResponse;
use MonkeysLegion\Repository\EntityRepository;
use MonkeysLegion\Repository\RepositoryFactory;
use MonkeysLegion\Router\Attributes\Route;
use Psr\Http\Message\ServerRequestInterface;
use RuntimeException;

final class ProjectAccessController
{
    private EntityRepository $projects;
    private EntityRepository $users;
    private EntityRepository $roles;
    private EntityRepository $accessRequests;

    public function __construct(
        private RepositoryFactory $repos,
    ) {
        $this->projects       = $this->repos->getRepository(Project::class);
        $this->users          = $this->repos->getRepository(User::class);
        $this->roles          = $this->repos->getRepository(Role::class);
        $this->accessRequests = $this->repos->getRepository(ProjectAccessRequest::class);
    }

    /**
     * GET /me/access/requests
     *
     * Dashboard endpoint.
     *
     * Returns access requests grouped by projects where the current user is:
     *  - the project author, or
     *  - a contributor in Project::$users.
     *
     * Response:
     * {
     *   "items": [
     *     {
     *       "projectId": 1,
     *       "projectHash": "abc...",
     *       "projectName": "ColibriV",
     *       "stage": "Pre-seed",
     *       "isOwner": true,
     *       "isContributor": false,
     *       "location": { ... },   // same shape as Project::location (json)
     *       "requests": [
     *         {
     *           "id": 10,
     *           "status": "requested|approved|rejected|revoked",
     *           "message": "optional message",
     *           "createdAt": "2025-01-01T12:34:56+00:00",
     *           "updatedAt": "2025-01-02T09:00:00+00:00",
     *           "investor": {
     *             "id": 5,
     *             "fullName": "Investor Name",
     *             "email": "investor@example.com"
     *           }
     *         },
     *         ...
     *       ]
     *     },
     *     ...
     *   ]
     * }
     */
    #[Route(methods: 'GET', path: '/me/access/requests')]
    public function listMyManagedAccessRequests(ServerRequestInterface $request): JsonResponse
    {
        error_log('[ACCESS][MY_LIST] start');

        $actor = $this->requireAuthUser($request);
        error_log('[ACCESS][MY_LIST] actor id=' . $actor->getId());

        // --------------------------------------------------
        // Collect projects where user is author or contributor
        // --------------------------------------------------
        /** @var array<int, array{project: Project, isOwner: bool, isContributor: bool}> $map */
        $map = [];

        // 1) Author project (OneToOne)
        $authorProject = $actor->getProjectAuthor();
        if ($authorProject instanceof Project) {
            $map[$authorProject->getId()] = [
                'project'       => $authorProject,
                'isOwner'       => true,
                'isContributor' => false,
            ];
        }

        // 2) Contributor projects (ManyToMany Project::$users)
        foreach ($actor->getProjects() as $p) {
            if (!$p instanceof Project) {
                continue;
            }
            $pid = $p->getId();
            if (!isset($map[$pid])) {
                $map[$pid] = [
                    'project'       => $p,
                    'isOwner'       => ($authorProject instanceof Project && $authorProject->getId() === $pid),
                    'isContributor' => true,
                ];
            } else {
                // If already there (e.g. author AND in users), ensure contributor flag is true
                $map[$pid]['isContributor'] = true;
            }
        }

        error_log('[ACCESS][MY_LIST] candidate projects count=' . count($map));

        // --------------------------------------------------
        // Build response groups per project
        // --------------------------------------------------
        $items = [];

        foreach ($map as $pid => $entry) {
            /** @var Project $project */
            $project       = $entry['project'];
            $isOwner       = (bool) $entry['isOwner'];
            $isContributor = (bool) $entry['isContributor'];

            // Optional guard: if you still want to enforce admin/author-only,
            // you can use ensureCanManageAccess($actor, $project) here.
            // If you want contributors also to manage access, either:
            //  - extend ensureCanManageAccess, or
            //  - skip that check here (as done now, based on relationship).
            //
            // $this->ensureCanManageAccess($actor, $project);

            $reqItems = [];
            foreach ($project->getAccessRequests() as $req) {
                if (!$req instanceof ProjectAccessRequest) {
                    continue;
                }
                $investor = $req->getInvestor();

                $reqItems[] = [
                    'id'        => $req->getId(),
                    'status'    => $req->getStatus(),
                    'message'   => $req->getMessage(),
                    'createdAt' => $req->getCreatedAt()->format(\DateTimeInterface::ATOM),
                    'updatedAt' => $req->getUpdatedAt()?->format(\DateTimeInterface::ATOM),
                    'investor'  => $investor ? [
                        'id'       => $investor->getId(),
                        'fullName' => $investor->getFullName(),
                        'email'    => $investor->getEmail(),
                        'investor'  => $investor->getInvestor() ? $investor->getInvestor()->getHash() : null,
                        'founder'   => $investor->getFounder() ? $investor->getFounder()->getHash() : null,
                    ] : null,
                ];
            }

            // Only push projects that actually have requests
            if (count($reqItems) === 0) {
                continue;
            }

            $items[] = [
                'projectId'      => $project->getId(),
                'projectHash'    => $project->getHash(),
                'projectName'    => $project->getName(),
                'stage'          => $project->getStage(),
                'isOwner'        => $isOwner,
                'isContributor'  => $isContributor,
                'location'       => $project->getLocation(), // json field
                'requests'       => $reqItems,
            ];
        }

        error_log('[ACCESS][MY_LIST] result groups count=' . count($items));

        return new JsonResponse([
            'items' => $items,
        ], 200);
    }

    /**
     * GET /projects/{hash}/access/me
     *
     * Called by current user to know:
     *  - Do I have access?
     *  - Is there a request and its status?
     *
     * Response:
     * {
     *   "projectId": 1,
     *   "projectHash": "abc",
     *   "status": "none|requested|approved|rejected|revoked",
     *   "hasAccess": bool
     * }
     */
    #[Route(methods: 'GET', path: '/projects/{hash}/access/me')]
    public function myAccess(ServerRequestInterface $request): JsonResponse
    {
        error_log('[ACCESS][MY] start');

        $user    = $this->requireAuthUser($request);
        error_log('[ACCESS][MY] user id=' . $user->getId());

        $project = $this->requireProjectByHash($request);
        error_log('[ACCESS][MY] project id=' . $project->getId() . ' hash=' . $project->getHash());

        $hasAccess = $this->userHasAccess($project, $user);
        $latest    = $this->findLatestRequest($project, $user);

        if ($hasAccess) {
            $status = 'approved';
        } elseif (!$latest) {
            $status = 'none';
        } else {
            $status = $latest->getStatus();
        }

        error_log('[ACCESS][MY] status=' . $status . ' hasAccess=' . ($hasAccess ? '1' : '0'));

        return new JsonResponse([
            'projectId'   => $project->getId(),
            'projectHash' => $project->getHash(),
            'status'      => $status,
            'hasAccess'   => $hasAccess,
        ], 200);
    }

    /**
     * POST /projects/{hash}/access/request
     *
     * Current user (investor) requests access to a project.
     *
     * Optional JSON body:
     * {
     *   "message": "optional message for the founder"
     * }
     *
     * Response:
     * {
     *   "projectId": 1,
     *   "projectHash": "abc",
     *   "requestId": 10,
     *   "status": "requested|approved|rejected|revoked",
     *   "hasAccess": bool
     * }
     *
     * @throws \JsonException
     */
    #[Route(methods: 'POST', path: '/projects/{hash}/access/request')]
    public function requestAccess(ServerRequestInterface $request): JsonResponse
    {
        error_log('[ACCESS][REQ] start');

        $investor = $this->requireAuthUser($request);
        error_log('[ACCESS][REQ] investor id=' . $investor->getId());

        $project = $this->requireProjectByHash($request);
        error_log('[ACCESS][REQ] project id=' . $project->getId() . ' hash=' . $project->getHash());

        // If user already has access, just return that
        if ($this->userHasAccess($project, $investor)) {
            error_log('[ACCESS][REQ] user already has access, returning approved');

            $latest = $this->findLatestRequest($project, $investor);

            return new JsonResponse([
                'projectId'   => $project->getId(),
                'projectHash' => $project->getHash(),
                'requestId'   => $latest?->getId(),
                'status'      => 'approved',
                'hasAccess'   => true,
            ], 200);
        }

        // Parse optional message from JSON/form body
        $data    = $this->parseBody($request);
        $message = isset($data['message']) ? (string) $data['message'] : null;

        // Check if there is already a latest request
        $latest = $this->findLatestRequest($project, $investor);

        // If latest exists and is still "requested", just update message and return
        if ($latest instanceof ProjectAccessRequest && $latest->getStatus() === 'requested') {
            error_log('[ACCESS][REQ] existing pending request id=' . $latest->getId() . ' -> updating message only');

            if ($message !== null && $message !== '') {
                $latest->setMessage($message);
                $this->accessRequests->save($latest);
            }

            return new JsonResponse([
                'projectId'   => $project->getId(),
                'projectHash' => $project->getHash(),
                'requestId'   => $latest->getId(),
                'status'      => $latest->getStatus(),
                'hasAccess'   => false,
            ], 200);
        }

        // Otherwise create a brand-new request
        error_log('[ACCESS][REQ] creating new access request');

        $req = new ProjectAccessRequest();
        $req->setProject($project);
        $req->setInvestor($investor);
        $req->setStatus('requested');
        $req->setMessage($message);

        $this->accessRequests->save($req);

        error_log('[ACCESS][REQ] created request id=' . $req->getId());

        return new JsonResponse([
            'projectId'   => $project->getId(),
            'projectHash' => $project->getHash(),
            'requestId'   => $req->getId(),
            'status'      => $req->getStatus(),
            'hasAccess'   => false,
        ], 201);
    }

    /**
     * GET /projects/{hash}/access/requests
     *
     * List access requests for a project (founder/admin only).
     */
    #[Route(methods: 'GET', path: '/projects/{hash}/access/requests')]
    public function listRequests(ServerRequestInterface $request): JsonResponse
    {
        error_log('[ACCESS][LIST] start');

        $actor   = $this->requireAuthUser($request);
        error_log('[ACCESS][LIST] actor id=' . $actor->getId());

        $project = $this->requireProjectByHash($request);
        error_log('[ACCESS][LIST] project id=' . $project->getId() . ' hash=' . $project->getHash());

        $this->ensureCanManageAccess($actor, $project);

        $items = [];
        foreach ($project->getAccessRequests() as $req) {
            if (!$req instanceof ProjectAccessRequest) {
                continue;
            }
            $investor = $req->getInvestor();
            $items[] = [
                'id'          => $req->getId(),
                'status'      => $req->getStatus(),
                'message'     => $req->getMessage(),
                'createdAt'   => $req->getCreatedAt()->format(\DateTimeInterface::ATOM),
                'updatedAt'   => $req->getUpdatedAt()?->format(\DateTimeInterface::ATOM),
                'investor'    => $investor ? [
                    'id'       => $investor->getId(),
                    'fullName' => $investor->getFullName(),
                    'email'    => $investor->getEmail(),
                ] : null,
            ];
        }

        error_log('[ACCESS][LIST] items count=' . count($items));

        return new JsonResponse([
            'projectId'   => $project->getId(),
            'projectHash' => $project->getHash(),
            'items'       => $items,
        ], 200);
    }

    /**
     * GET /me/access/projects
     *
     * Investor dashboard endpoint.
     *
     * Returns projects where the current user (investor) has:
     *  - at least one access request (any status), OR
     *  - direct access via Project::$access_investor (approved).
     * @throws \ReflectionException
     */
    #[Route(methods: 'GET', path: '/me/access/projects')]
    public function listMyInvestorProjects(ServerRequestInterface $request): JsonResponse
    {
        error_log('[ACCESS][INV_LIST] start');

        $investor = $this->requireAuthUser($request);
        $investorId = $investor->getId();
        error_log('[ACCESS][INV_LIST] investor id=' . $investorId);

        /** @var array<int, array{project: Project, latestReq: ?ProjectAccessRequest}> $map */
        $map = [];

        // --------------------------------------------------
        // 1) Collect all requests made by this user
        // --------------------------------------------------

        // If your repository doesn't support findAll(), you can use findBy([]) instead.
        /** @var ProjectAccessRequest[] $allRequests */
        $allRequests = $this->accessRequests->findAll();
        error_log('[ACCESS][INV_LIST] allRequests count=' . count($allRequests));

        foreach ($allRequests as $req) {
            if (!$req instanceof ProjectAccessRequest) {
                continue;
            }

            $reqInvestor = $req->getInvestor();
            if (!$reqInvestor instanceof User) {
                continue;
            }
            if ($reqInvestor->getId() !== $investorId) {
                continue;
            }

            $project = $req->getProject();
            if (!$project instanceof Project) {
                continue;
            }

            $pid = $project->getId();
            if (!isset($map[$pid])) {
                $map[$pid] = [
                    'project'   => $project,
                    'latestReq' => $req,
                ];
            } else {
                // keep the latest by createdAt
                $currentLatest = $map[$pid]['latestReq'];
                if ($currentLatest === null || $req->getCreatedAt() > $currentLatest->getCreatedAt()) {
                    $map[$pid]['latestReq'] = $req;
                }
            }
        }

        // --------------------------------------------------
        // 2) Add all projects where this user currently has access
        //    via ManyToMany Project::$access_investor
        // --------------------------------------------------

        $projectsWithAccess = $investor->getProjects_investor();
        error_log('[ACCESS][INV_LIST] projects_investor count=' . count($projectsWithAccess));

        foreach ($projectsWithAccess as $project) {
            if (!$project instanceof Project) {
                continue;
            }
            $pid = $project->getId();
            if (!isset($map[$pid])) {
                $map[$pid] = [
                    'project'   => $project,
                    'latestReq' => null,
                ];
            }
        }

        error_log('[ACCESS][INV_LIST] candidate projects count=' . count($map));

        // --------------------------------------------------
        // 3) Build response items
        // --------------------------------------------------

        $items = [];

        foreach ($map as $pid => $entry) {
            /** @var Project $project */
            $project   = $entry['project'];
            $latestReq = $entry['latestReq'];

            $hasAccess = $this->userHasAccess($project, $investor);

            // Derive status similar to /projects/{hash}/access/me
            if ($hasAccess) {
                $status = 'approved';
            } elseif (!$latestReq) {
                $status = 'none';
            } else {
                $status = $latestReq->getStatus();
            }

            $latestPayload = null;
            if ($latestReq instanceof ProjectAccessRequest) {
                $latestPayload = [
                    'id'        => $latestReq->getId(),
                    'status'    => $latestReq->getStatus(),
                    'message'   => $latestReq->getMessage(),
                    'createdAt' => $latestReq->getCreatedAt()->format(\DateTimeInterface::ATOM),
                    'updatedAt' => $latestReq->getUpdatedAt()?->format(\DateTimeInterface::ATOM),
                ];
            }

            $items[] = [
                'projectId'     => $project->getId(),
                'projectHash'   => $project->getHash(),
                'projectName'   => $project->getName(),
                'stage'         => $project->getStage(),
                'location'      => $project->getLocation(),
                'hasAccess'     => $hasAccess,
                'status'        => $status,
                'latestRequest' => $latestPayload,
            ];
        }

        error_log('[ACCESS][INV_LIST] result items count=' . count($items));

        return new JsonResponse([
            'items' => $items,
        ], 200);
    }


    /**
     * POST /projects/{hash}/access/requests/{id}/approve
     *
     * Founder/admin approves a request and grants access.
     */
    #[Route(methods: 'POST', path: '/projects/{hash}/access/requests/{id}/approve')]
    public function approve(ServerRequestInterface $request): JsonResponse
    {
        error_log('[ACCESS][APPROVE] start');

        $actor   = $this->requireAuthUser($request);
        error_log('[ACCESS][APPROVE] actor id=' . $actor->getId());

        $project = $this->requireProjectByHash($request);
        error_log('[ACCESS][APPROVE] project id=' . $project->getId() . ' hash=' . $project->getHash());

        $this->ensureCanManageAccess($actor, $project);

        $reqId = (int) $request->getAttribute('id');
        error_log('[ACCESS][APPROVE] reqId=' . $reqId);

        if ($reqId <= 0) {
            error_log('[ACCESS][APPROVE][ERR] invalid request id');
            throw new RuntimeException('Invalid request id', 400);
        }

        /** @var ProjectAccessRequest|null $req */
        $req = $this->accessRequests->find($reqId);
        if (!$req instanceof ProjectAccessRequest || $req->getProject()?->getId() !== $project->getId()) {
            error_log('[ACCESS][APPROVE][ERR] request not found or not for this project');
            throw new RuntimeException('Request not found for this project', 404);
        }

        $investor = $req->getInvestor();
        if (!$investor instanceof User) {
            error_log('[ACCESS][APPROVE][ERR] request has no investor associated');
            throw new RuntimeException('Request has no investor associated', 500);
        }

        error_log('[ACCESS][APPROVE] approving request id=' . $req->getId() . ' investor id=' . $investor->getId());

        // Update status
        $req->setStatus('approved');
        $this->accessRequests->save($req);

        // Grant access (ManyToMany)
        if (!$this->userHasAccess($project, $investor)) {
            error_log('[ACCESS][APPROVE] granting ManyToMany access');
            $project->addUserInvestorAccess($investor);
            $investor->addProjectInvestorAccess($project);
            $this->projects->save($project);
            $this->users->save($investor);
        } else {
            error_log('[ACCESS][APPROVE] user already had access, skipping grant');
        }

        return new JsonResponse([
            'projectId'   => $project->getId(),
            'projectHash' => $project->getHash(),
            'requestId'   => $req->getId(),
            'status'      => $req->getStatus(),
            'hasAccess'   => true,
        ], 200);
    }

    /**
     * POST /projects/{hash}/access/requests/{id}/reject
     *
     * Founder/admin rejects a request (no access).
     */
    #[Route(methods: 'POST', path: '/projects/{hash}/access/requests/{id}/reject')]
    public function reject(ServerRequestInterface $request): JsonResponse
    {
        error_log('[ACCESS][REJECT] start');

        $actor   = $this->requireAuthUser($request);
        error_log('[ACCESS][REJECT] actor id=' . $actor->getId());

        $project = $this->requireProjectByHash($request);
        error_log('[ACCESS][REJECT] project id=' . $project->getId() . ' hash=' . $project->getHash());

        $this->ensureCanManageAccess($actor, $project);

        $reqId = (int) $request->getAttribute('id');
        error_log('[ACCESS][REJECT] reqId=' . $reqId);

        if ($reqId <= 0) {
            error_log('[ACCESS][REJECT][ERR] invalid request id');
            throw new RuntimeException('Invalid request id', 400);
        }

        /** @var ProjectAccessRequest|null $req */
        $req = $this->accessRequests->find($reqId);
        if (!$req instanceof ProjectAccessRequest || $req->getProject()?->getId() !== $project->getId()) {
            error_log('[ACCESS][REJECT][ERR] request not found for this project');
            throw new RuntimeException('Request not found for this project', 404);
        }

        error_log('[ACCESS][REJECT] rejecting request id=' . $req->getId());

        $req->setStatus('rejected');
        $this->accessRequests->save($req);

        return new JsonResponse([
            'projectId'   => $project->getId(),
            'projectHash' => $project->getHash(),
            'requestId'   => $req->getId(),
            'status'      => $req->getStatus(),
            'hasAccess'   => false,
        ], 200);
    }

    // --------------------------------------------------
    // Helpers
    // --------------------------------------------------

    private function requireAuthUser(ServerRequestInterface $request): User
    {
        $userId = (int) $request->getAttribute('user_id', 0);
        error_log('[ACCESS][AUTH] user_id attribute=' . $userId);

        if ($userId <= 0) {
            error_log('[ACCESS][AUTH][ERR] missing/invalid user_id');
            throw new RuntimeException('Unauthorized', 401);
        }

        /** @var User|null $user */
        $user = $this->users->find($userId);
        if (!$user instanceof User) {
            error_log('[ACCESS][AUTH][ERR] user not found in repository id=' . $userId);
            throw new RuntimeException('Unauthorized', 401);
        }

        return $user;
    }

    private function requireProjectByHash(ServerRequestInterface $request): Project
    {
        $hash = (string) $request->getAttribute('hash');
        $hash = trim($hash);

        $len = strlen($hash);
        error_log('[ACCESS][PROJECT] incoming hash="' . $hash . '" len=' . $len);

        // 1) Basic non-empty check
        if ($hash === '') {
            error_log('[ACCESS][PROJECT][ERR] empty project hash');
            throw new RuntimeException('Invalid project hash', 400);
        }

        // 2) Optional: still enforce hex, but DO NOT enforce 32 chars
        if (!ctype_xdigit($hash)) {
            error_log('[ACCESS][PROJECT][ERR] hash is not hex');
            throw new RuntimeException('Invalid project hash', 400);
        }

        /** @var Project|null $project */
        $project = $this->projects->findOneBy(['hash' => $hash]);

        if (!$project instanceof Project) {
            error_log('[ACCESS][PROJECT][ERR] project not found for hash');
            throw new RuntimeException('Project not found', 404);
        }

        error_log('[ACCESS][PROJECT] resolved project id=' . $project->getId());
        return $project;
    }

    private function isAdmin(User $user): bool
    {
        foreach ($user->getRoles() as $role) {
            $slug = method_exists($role, 'getSlug') ? strtolower((string) $role->getSlug()) : '';
            if ($slug === 'admin') {
                return true;
            }
        }
        return false;
    }

    private function hasRoleSlug(User $user, string $slug): bool
    {
        $slug = strtolower($slug);
        foreach ($user->getRoles() as $role) {
            $roleSlug = method_exists($role, 'getSlug') ? strtolower((string) $role->getSlug()) : '';
            if ($roleSlug === $slug) {
                return true;
            }
        }
        return false;
    }

    private function ensureCanManageAccess(User $actor, Project $project): void
    {
        error_log('[ACCESS][GUARD] ensureCanManageAccess actor=' . $actor->getId() . ' project=' . $project->getId());

        $author = $project->getAuthor();

        if ($author instanceof User && $author->getId() === $actor->getId()) {
            error_log('[ACCESS][GUARD] actor is project author');
            return;
        }

        if ($this->isAdmin($actor)) {
            error_log('[ACCESS][GUARD] actor is admin');
            return;
        }

        error_log('[ACCESS][GUARD][ERR] forbidden actor=' . $actor->getId());
        throw new RuntimeException('Forbidden', 403);
    }

    private function userHasAccess(Project $project, User $user): bool
    {
        foreach ($project->getAccess_investor() as $u) {
            if ($u instanceof User && $u->getId() === $user->getId()) {
                error_log('[ACCESS][HAS] user ' . $user->getId() . ' has project access');
                return true;
            }
        }
        error_log('[ACCESS][HAS] user ' . $user->getId() . ' has NO project access');
        return false;
    }

    private function findLatestRequest(Project $project, User $investor): ?ProjectAccessRequest
    {
        error_log('[ACCESS][FIND] findLatestRequest investor=' . $investor->getId() . ' project=' . $project->getId());
        $latest = null;
        foreach ($project->getAccessRequests() as $req) {
            if (!$req instanceof ProjectAccessRequest) {
                continue;
            }
            if (!$req->getInvestor() instanceof User) {
                continue;
            }
            if ($req->getInvestor()->getId() !== $investor->getId()) {
                continue;
            }

            if ($latest === null || $req->getCreatedAt() > $latest->getCreatedAt()) {
                $latest = $req;
            }
        }

        if ($latest) {
            error_log('[ACCESS][FIND] latest request id=' . $latest->getId() . ' status=' . $latest->getStatus());
        } else {
            error_log('[ACCESS][FIND] no previous request found');
        }

        return $latest;
    }

    /**
     * Same parseBody helper style as your other controllers.
     *
     * @throws \JsonException
     */
    private function parseBody(ServerRequestInterface $request): array
    {
        $ct     = strtolower($request->getHeaderLine('Content-Type') ?: '');
        $parsed = $request->getParsedBody();

        error_log('[ACCESS][BODY] Content-Type="' . $ct . '" parsedType=' . gettype($parsed));

        if (is_array($parsed) && !empty($parsed)) {
            error_log('[ACCESS][BODY] using parsedBody array with ' . count($parsed) . ' keys');
            return $parsed;
        }

        if (str_starts_with($ct, 'application/json')) {
            $bodyStream = $request->getBody();
            $rawBody    = '';

            try {
                $rawBody = (string) $bodyStream;
                if ($rawBody === '') {
                    $rawBody = $bodyStream->getContents();
                }
            } catch (\Throwable $e) {
                error_log('[ACCESS][BODY][ERR] reading body stream: ' . $e->getMessage());
            }

            if ($rawBody === '') {
                $rawBody = @file_get_contents('php://input') ?: '';
            }

            error_log('[ACCESS][BODY] raw JSON length=' . strlen($rawBody));

            if ($rawBody === '') {
                return [];
            }

            $data = json_decode($rawBody, true, 512, JSON_THROW_ON_ERROR);
            $count = is_array($data) ? count($data) : 0;
            error_log('[ACCESS][BODY] decoded JSON keys=' . $count);

            return is_array($data) ? $data : [];
        }

        if (is_array($parsed)) {
            error_log('[ACCESS][BODY] non-JSON, parsed array size=' . count($parsed));
            return $parsed;
        }

        error_log('[ACCESS][BODY] no body data found, returning empty array');
        return [];
    }
}
