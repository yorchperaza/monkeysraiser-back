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
     * @throws \JsonException
     */
    #[Route(methods: 'GET', path: '/me/access/requests')]
    public function listMyManagedAccessRequests(ServerRequestInterface $request): JsonResponse
    {
        $actor = $this->requireAuthUser($request);

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
        $user    = $this->requireAuthUser($request);
        $project = $this->requireProjectByHash($request);
        $hasAccess = $this->userHasAccess($project, $user);
        $latest    = $this->findLatestRequest($project, $user);

        if ($hasAccess) {
            $status = 'approved';
        } elseif (!$latest) {
            $status = 'none';
        } else {
            $status = $latest->getStatus();
        }

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
        $investor = $this->requireAuthUser($request);
        $project = $this->requireProjectByHash($request);

        // If user already has access, just return that
        if ($this->userHasAccess($project, $investor)) {
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
        $req = new ProjectAccessRequest();
        $req->setProject($project);
        $req->setInvestor($investor);
        $req->setStatus('requested');
        $req->setMessage($message);

        $this->accessRequests->save($req);

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
     * @throws \JsonException
     */
    #[Route(methods: 'GET', path: '/projects/{hash}/access/requests')]
    public function listRequests(ServerRequestInterface $request): JsonResponse
    {
        $actor   = $this->requireAuthUser($request);
        $project = $this->requireProjectByHash($request);
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
     * @throws \JsonException
     */
    #[Route(methods: 'GET', path: '/me/access/projects')]
    public function listMyInvestorProjects(ServerRequestInterface $request): JsonResponse
    {
        $investor = $this->requireAuthUser($request);
        $investorId = $investor->getId();

        /** @var array<int, array{project: Project, latestReq: ?ProjectAccessRequest}> $map */
        $map = [];

        // --------------------------------------------------
        // 1) Collect all requests made by this user
        // --------------------------------------------------

        // If your repository doesn't support findAll(), you can use findBy([]) instead.
        /** @var ProjectAccessRequest[] $allRequests */
        $allRequests = $this->accessRequests->findAll();

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

        return new JsonResponse([
            'items' => $items,
        ], 200);
    }


    /**
     * POST /projects/{hash}/access/requests/{id}/approve
     *
     * Founder/admin approves a request and grants access.
     * @throws \JsonException
     */
    #[Route(methods: 'POST', path: '/projects/{hash}/access/requests/{id}/approve')]
    public function approve(ServerRequestInterface $request): JsonResponse
    {
        $actor   = $this->requireAuthUser($request);
        $project = $this->requireProjectByHash($request);

        $this->ensureCanManageAccess($actor, $project);

        $reqId = (int) $request->getAttribute('id');
        if ($reqId <= 0) {
            throw new RuntimeException('Invalid request id', 400);
        }

        /** @var ProjectAccessRequest|null $req */
        $req = $this->accessRequests->find($reqId);
        if (!$req instanceof ProjectAccessRequest || $req->getProject()?->getId() !== $project->getId()) {
            throw new RuntimeException('Request not found for this project', 404);
        }

        $investor = $req->getInvestor();
        if (!$investor instanceof User) {
            throw new RuntimeException('Request has no investor associated', 500);
        }

        // Update status
        $req->setStatus('approved');
        $this->accessRequests->save($req);

        // Grant access (ManyToMany)
        if (!$this->userHasAccess($project, $investor)) {
            $project->addUserInvestorAccess($investor);
            $investor->addProjectInvestorAccess($project);
            $this->projects->save($project);
            $this->users->save($investor);
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
     * @throws \ReflectionException
     * @throws \JsonException
     */
    #[Route(methods: 'POST', path: '/projects/{hash}/access/requests/{id}/reject')]
    public function reject(ServerRequestInterface $request): JsonResponse
    {
        $actor   = $this->requireAuthUser($request);
        $project = $this->requireProjectByHash($request);

        $this->ensureCanManageAccess($actor, $project);

        $reqId = (int) $request->getAttribute('id');

        if ($reqId <= 0) {
            throw new RuntimeException('Invalid request id', 400);
        }

        /** @var ProjectAccessRequest|null $req */
        $req = $this->accessRequests->find($reqId);
        if (!$req instanceof ProjectAccessRequest || $req->getProject()?->getId() !== $project->getId()) {
            throw new RuntimeException('Request not found for this project', 404);
        }

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

    /**
     * @throws \ReflectionException
     */
    private function requireAuthUser(ServerRequestInterface $request): User
    {
        $userId = (int) $request->getAttribute('user_id', 0);
        if ($userId <= 0) {
            throw new RuntimeException('Unauthorized', 401);
        }

        /** @var User|null $user */
        $user = $this->users->find($userId);
        if (!$user instanceof User) {
            throw new RuntimeException('Unauthorized', 401);
        }

        return $user;
    }

    private function requireProjectByHash(ServerRequestInterface $request): Project
    {
        $hash = (string) $request->getAttribute('hash');
        $hash = trim($hash);

        // 1) Basic non-empty check
        if ($hash === '') {
            throw new RuntimeException('Invalid project hash', 400);
        }

        // 2) Optional: still enforce hex, but DO NOT enforce 32 chars
        if (!ctype_xdigit($hash)) {
            throw new RuntimeException('Invalid project hash', 400);
        }

        /** @var Project|null $project */
        $project = $this->projects->findOneBy(['hash' => $hash]);

        if (!$project instanceof Project) {
            throw new RuntimeException('Project not found', 404);
        }

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
        $author = $project->getAuthor();

        if ($author instanceof User && $author->getId() === $actor->getId()) {
            return;
        }

        if ($this->isAdmin($actor)) {
            return;
        }

        throw new RuntimeException('Forbidden', 403);
    }

    private function userHasAccess(Project $project, User $user): bool
    {
        foreach ($project->getAccess_investor() as $u) {
            if ($u instanceof User && $u->getId() === $user->getId()) {
                return true;
            }
        }
        return false;
    }

    private function findLatestRequest(Project $project, User $investor): ?ProjectAccessRequest
    {
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

        if (is_array($parsed) && !empty($parsed)) {
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
                // ignore
            }

            if ($rawBody === '') {
                $rawBody = @file_get_contents('php://input') ?: '';
            }

            if ($rawBody === '') {
                return [];
            }

            $data = json_decode($rawBody, true, 512, JSON_THROW_ON_ERROR);

            return is_array($data) ? $data : [];
        }

        if (is_array($parsed)) {
            return $parsed;
        }

        return [];
    }
}
