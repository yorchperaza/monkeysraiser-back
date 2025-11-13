<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\CommentsProject;
use App\Entity\GroupCommentsProject;
use App\Entity\Media;
use App\Entity\Project;
use App\Entity\User;
use DateTimeImmutable;
use MonkeysLegion\Http\Message\JsonResponse;
use MonkeysLegion\Repository\EntityRepository;
use MonkeysLegion\Repository\RepositoryFactory;
use MonkeysLegion\Router\Attributes\Route;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\UploadedFileInterface;
use Random\RandomException;
use RuntimeException;

final class GroupCommentsProjectController
{
    private EntityRepository $groups;
    private EntityRepository $comments;
    private EntityRepository $projects;
    private EntityRepository $users;
    private EntityRepository $mediaRepo;

    public function __construct(private RepositoryFactory $repos)
    {
        $this->groups   = $this->repos->getRepository(GroupCommentsProject::class);
        $this->comments = $this->repos->getRepository(CommentsProject::class);
        $this->projects = $this->repos->getRepository(Project::class);
        $this->users    = $this->repos->getRepository(User::class);
        $this->mediaRepo= $this->repos->getRepository(Media::class);
    }

    // ------------------------------------------------------------
    // Groups: create, list (per project, per user), detail
    // ------------------------------------------------------------

    /**
     * POST /projects/{hash}/comment-groups
     *
     * Body (JSON or multipart with "data"):
     *  {
     *    recipientIds?: int[],
     *    recipientEmails?: string[]
     *  }
     *
     * Rules:
     *  - Requester must be project author or contributor to create.
     *  - Requester is auto-added as recipient.
     *  - Unknown emails are returned in _warnings.emails_not_found.
     */
    #[Route(methods: 'POST', path: '/projects/{hash}/comment-groups')]
    public function createGroup(ServerRequestInterface $request): JsonResponse
    {
        try {
            // --- AuthN ---
            $uid = (int)$request->getAttribute('user_id', 0);
            /** @var User|null $me */
            $me = $this->users->find($uid);
            if (!$me) throw new RuntimeException('Unauthorized', 401);

            // --- Project ---
            $hash = (string)$request->getAttribute('hash');
            if ($hash === '') throw new RuntimeException('Invalid project identifier', 400);

            /** @var Project|null $project */
            $project = $this->projects->findOneBy(['hash' => $hash]);
            if (!$project) throw new RuntimeException('Project not found', 404);

            // --- AuthZ ---
            $isOwner = (int)($project->getAuthor()?->getId() ?? 0) === (int)$me->getId();
            $isContributor = false;
            foreach ($project->getUsers() as $u) {
                if ($u instanceof User && $u->getId() === $me->getId()) { $isContributor = true; break; }
            }
            if (!$isOwner && !$isContributor) throw new RuntimeException('Forbidden', 403);

            // --- Parse body ---
            $parsedBody  = $request->getParsedBody();
            $isMultipart = is_array($parsedBody) && array_key_exists('data', $parsedBody);
            $raw         = $isMultipart ? (string)($parsedBody['data'] ?? '') : (string)$request->getBody();
            $data        = $raw !== '' ? json_decode($raw, true, 512, JSON_THROW_ON_ERROR) : [];

            $rIds = array_unique(array_filter(array_map('intval', (array)($data['recipientIds'] ?? []))));
            $rEmails = array_values(array_filter(array_map(
                static fn($e) => filter_var((string)$e, FILTER_VALIDATE_EMAIL) ? strtolower((string)$e) : null,
                (array)($data['recipientEmails'] ?? [])
            )));

            // Resolve recipients
            $notFound = [];
            $resolved = [];
            foreach ($rEmails as $em) {
                /** @var User|null $found */
                $found = $this->users->findOneBy(['email' => $em]);
                if ($found) $resolved[$found->getId()] = true; else $notFound[] = $em;
            }
            foreach ($rIds as $id) { if ($id > 0) $resolved[$id] = true; }

            // Always include requester
            $resolved[$me->getId()] = true;

            // --- Create group ---
            $group = new GroupCommentsProject();
            $group->setHash(bin2hex(random_bytes(16)));
            $group->setProject($project);
            $now = new DateTimeImmutable('now', new \DateTimeZone('UTC'));
            $group->setCreatedAt($now)->setUpdateAt($now)->setLastMessageAt(null);

            $this->groups->save($group);

            // Attach recipients via join table
            foreach (array_keys($resolved) as $rid) {
                /** @var User|null $u */
                $u = $this->users->find((int)$rid);
                if ($u instanceof User) {
                    $this->groups->attachRelation($group, 'recipients', $u->getId());
                }
            }

            $this->groups->save($group);

            $payload = $this->serializeGroup($group, includeRecipients: true, includePreview: true);
            if (!empty($notFound)) {
                $payload['_warnings']['emails_not_found'] = array_values(array_unique($notFound));
            }

            return new JsonResponse($payload, 201);
        } catch (\Throwable $e) {
            error_log('[GCP-GROUP][CREATE][FATAL] '.get_class($e).' '.$e->getMessage().' @ '.$e->getFile().':'.$e->getLine());
            throw $e;
        }
    }

    /**
     * GET /projects/{hash}/comment-groups
     * List groups for a project.
     *
     * - Owner/contributor: see all groups for the project
     * - Otherwise: only groups where requester is recipient
     *
     * Query:
     *  - q: search in preview text (last comment) — simple LIKE on derived field
     *  - page, perPage
     * @throws \ReflectionException
     */
    #[Route(methods: 'GET', path: '/projects/{hash}/comment-groups')]
    public function listProjectGroups(ServerRequestInterface $request): JsonResponse
    {
        $uid = (int)$request->getAttribute('user_id', 0);
        /** @var User|null $me */
        $me = $this->users->find($uid);
        if (!$me) throw new RuntimeException('Unauthorized', 401);

        $hash = (string)$request->getAttribute('hash');
        if ($hash === '') throw new RuntimeException('Invalid project identifier', 400);

        /** @var Project|null $project */
        $project = $this->projects->findOneBy(['hash' => $hash]);
        if (!$project) throw new RuntimeException('Project not found', 404);

        $isOwner = (int)($project->getAuthor()?->getId() ?? 0) === (int)$me->getId();
        $isContributor = false;
        foreach ($project->getUsers() as $u) {
            if ($u instanceof User && $u->getId() === $me->getId()) { $isContributor = true; break; }
        }

        $q = $request->getQueryParams();
        $page    = max(1, (int)($q['page'] ?? 1));
        $perPage = (int)($q['perPage'] ?? 20);
        if ($perPage <= 0)  $perPage = 20;
        if ($perPage > 100) $perPage = 100;
        $offset  = ($page - 1) * $perPage;
        $needle  = trim((string)($q['q'] ?? ''));

        $base = (clone $this->groups->qb)
            ->from('groupcommentsproject', 'g')
            ->where('g.project_id', '=', (int)$project->getId());

        if (!$isOwner && !$isContributor) {
            $base->leftJoin('group_comments_project_recipients', 'gr', 'gr.group_comments_project_id', '=', 'g.id')
                ->andWhere('gr.user_id', '=', (int)$me->getId());
        }

        // naive LIKE on lastMessageAt-driven preview using a join to latest comment
        if ($needle !== '') {
            $like = '%'.mb_strtolower($needle).'%';
            $base->leftJoin('commentsproject', 'cp', 'cp.group_comments_project_id', '=', 'g.id')
                ->andWhereGroup(function($w) use ($like) {
                    $w->whereLike('LOWER(cp.message)', $like)
                        ->orWhereLike('LOWER(cp.subject)', $like);
                });
        }

        $total = $base->duplicate()->count();
        $pages = (int)max(1, (int)ceil($total / max(1, $perPage)));

        $rows = $base->duplicate()
            ->select('g.id AS id')
            ->orderByRaw('COALESCE(g.lastMessageAt, g.updateAt, g.createdAt, g.id) DESC')
            ->limit($perPage)
            ->offset($offset)
            ->fetchAll();

        $items = [];
        foreach ($rows as $r) {
            $gid = isset($r['id']) ? (int)$r['id'] : 0;
            if ($gid <= 0) continue;
            /** @var GroupCommentsProject|null $grp */
            $grp = $this->groups->find($gid);
            if ($grp instanceof GroupCommentsProject) {
                $items[] = $this->serializeGroup($grp, includeRecipients: true, includePreview: true);
            }
        }

        return new JsonResponse([
            'page'    => $page,
            'perPage' => $perPage,
            'total'   => $total,
            'pages'   => $pages,
            'items'   => $items,
        ], 200);
    }

    /**
     * GET /me/comment-groups
     * Groups where requester is a recipient, across all projects.
     *
     * Query: q, page, perPage
     * @throws \Throwable
     */
    #[Route(methods: 'GET', path: '/me/comment-groups')]
    public function listMyGroups(ServerRequestInterface $request): JsonResponse
    {
        $uid = (int)$request->getAttribute('user_id', 0);
        /** @var User|null $me */
        $me = $this->users->find($uid);
        if (!$me) throw new RuntimeException('Unauthorized', 401);

        $q = $request->getQueryParams();
        $page    = max(1, (int)($q['page'] ?? 1));
        $perPage = (int)($q['perPage'] ?? 20);
        if ($perPage <= 0)  $perPage = 20;
        if ($perPage > 100) $perPage = 100;
        $offset  = ($page - 1) * $perPage;
        $needle  = trim((string)($q['q'] ?? ''));

        $base = (clone $this->groups->qb)
            ->from('groupcommentsproject', 'g')
            ->leftJoin('group_comments_project_recipients', 'gr', 'gr.group_comments_project_id', '=', 'g.id')
            ->where('gr.user_id', '=', (int)$me->getId());

        if ($needle !== '') {
            $like = '%'.mb_strtolower($needle).'%';
            $base->leftJoin('commentsproject', 'cp', 'cp.group_comments_project_id', '=', 'g.id')
                ->andWhereGroup(function($w) use ($like) {
                    $w->whereLike('LOWER(cp.message)', $like)
                        ->orWhereLike('LOWER(cp.subject)', $like);
                });
        }

        $total = $base->duplicate()->count();
        $pages = (int)max(1, (int)ceil($total / max(1, $perPage)));

        $rows = $base->duplicate()
            ->select('g.id AS id')
            ->orderByRaw('COALESCE(g.lastMessageAt, g.updateAt, g.createdAt, g.id) DESC')
            ->limit($perPage)
            ->offset($offset)
            ->fetchAll();

        $items = [];
        foreach ($rows as $r) {
            $gid = isset($r['id']) ? (int)$r['id'] : 0;
            if ($gid <= 0) continue;
            /** @var GroupCommentsProject|null $grp */
            $grp = $this->groups->find($gid);
            if ($grp instanceof GroupCommentsProject) {
                $items[] = $this->serializeGroup($grp, includeRecipients: true, includePreview: true);
            }
        }

        return new JsonResponse([
            'page'    => $page,
            'perPage' => $perPage,
            'total'   => $total,
            'pages'   => $pages,
            'items'   => $items,
        ], 200);
    }

    /**
     * GET /comment-groups/{hash}
     * Detail (must be project owner/contributor or recipient).
     * @throws \JsonException
     */
    #[Route(methods: 'GET', path: '/comment-groups/{hash}')]
    public function showGroup(ServerRequestInterface $request): JsonResponse
    {
        $uid = (int)$request->getAttribute('user_id', 0);
        /** @var User|null $me */
        $me = $this->users->find($uid);
        if (!$me) throw new RuntimeException('Unauthorized', 401);

        $hash = (string)$request->getAttribute('hash');
        if ($hash === '') throw new RuntimeException('Invalid group', 400);

        /** @var GroupCommentsProject|null $grp */
        $grp = $this->groups->findOneBy(['hash' => $hash]);
        if (!$grp) throw new RuntimeException('Group not found', 404);

        if (!$this->canViewGroup($grp, $me)) {
            throw new RuntimeException('Forbidden', 403);
        }

        return new JsonResponse(
            $this->serializeGroup($grp, includeRecipients: true, includePreview: true),
            200
        );
    }

    // ------------------------------------------------------------
    // Comments: list (cursor) & post (multipart)
    // ------------------------------------------------------------

    /**
     * GET /comment-groups/{hash}/comments
     *
     * Cursor:
     *  - beforeId: return comments with id < beforeId (older), newest-first
     *  - limit: default 20, max 100
     * @throws \ReflectionException
     * @throws \JsonException
     */
    #[Route(methods: 'GET', path: '/comment-groups/{hash}/comments')]
    public function listComments(ServerRequestInterface $request): JsonResponse
    {
        $uid = (int)$request->getAttribute('user_id', 0);
        /** @var User|null $me */
        $me = $this->users->find($uid);
        if (!$me) throw new RuntimeException('Unauthorized', 401);

        $hash = (string)$request->getAttribute('hash');
        if ($hash === '') throw new RuntimeException('Invalid group', 400);

        /** @var GroupCommentsProject|null $grp */
        $grp = $this->groups->findOneBy(['hash' => $hash]);
        if (!$grp) throw new RuntimeException('Group not found', 404);

        if (!$this->canViewGroup($grp, $me)) {
            throw new RuntimeException('Forbidden', 403);
        }

        $q = $request->getQueryParams();
        $beforeId = isset($q['beforeId']) ? (int)$q['beforeId'] : null;
        $limit    = (int)($q['limit'] ?? 20);
        if ($limit <= 0)  $limit = 20;
        if ($limit > 100) $limit = 100;

        $qb = (clone $this->comments->qb)
            ->from('commentsproject', 'cp')
            ->where('cp.group_comments_project_id', '=', (int)$grp->getId());

        if ($beforeId && $beforeId > 0) {
            $qb->andWhere('cp.id', '<', $beforeId);
        }

        $rows = $qb->select('cp.id AS id')
            ->orderBy('cp.id', 'DESC')
            ->limit($limit)
            ->fetchAll();

        $items = [];
        $minId = null;
        foreach ($rows as $r) {
            $cid = isset($r['id']) ? (int)$r['id'] : 0;
            if ($cid <= 0) continue;
            /** @var CommentsProject|null $c */
            $c = $this->comments->find($cid);
            if ($c instanceof CommentsProject) {
                $items[] = $this->serializeComment($c);
                if ($minId === null || $cid < $minId) $minId = $cid;
            }
        }

        return new JsonResponse([
            'items'      => $items,
            'nextCursor' => ['beforeId' => $minId],
        ], 200);
    }

    /**
     * POST /comment-groups/{hash}/comments
     *
     * Accepts:
     *  - JSON: { subject?: string, message?: string }
     *  - multipart/form-data: data=<json>, attachments[] files
     */
    #[Route(methods: 'POST', path: '/comment-groups/{hash}/comments')]
    public function postComment(ServerRequestInterface $request): JsonResponse
    {
        try {
            $uid = (int)$request->getAttribute('user_id', 0);
            /** @var User|null $me */
            $me = $this->users->find($uid);
            if (!$me) throw new RuntimeException('Unauthorized', 401);

            $hash = (string)$request->getAttribute('hash');
            if ($hash === '') throw new RuntimeException('Invalid group', 400);

            /** @var GroupCommentsProject|null $grp */
            $grp = $this->groups->findOneBy(['hash' => $hash]);
            if (!$grp) throw new RuntimeException('Group not found', 404);

            if (!$this->canViewGroup($grp, $me)) {
                throw new RuntimeException('Forbidden', 403);
            }

            // Parse payload
            $parsedBody  = $request->getParsedBody();
            $isMultipart = is_array($parsedBody) && array_key_exists('data', $parsedBody);
            $raw         = $isMultipart ? (string)($parsedBody['data'] ?? '') : (string)$request->getBody();
            $data        = $raw !== '' ? json_decode($raw, true, 512, JSON_THROW_ON_ERROR) : [];

            $subject = isset($data['subject']) ? trim((string)$data['subject']) : null;
            $body    = isset($data['message']) ? trim((string)$data['message']) : null;

            if (($subject === null || $subject === '') && ($body === null || $body === '')) {
                throw new RuntimeException('Empty message', 400);
            }

            // Create comment
            $comment = new CommentsProject();
            $comment->setSlug(bin2hex(random_bytes(8)));
            $comment->setSubject($subject ?: null);
            $comment->setMessage($body ?: null);
            $comment->setAuthor($me);
            $comment->setGroupCommentsProject($grp);
            $comment->setDate(new DateTimeImmutable('now', new \DateTimeZone('UTC')));
            $comment->setRead(null)->setReadDate(null);

            $this->comments->save($comment);

            // Attachments
            $uploaded = $request->getUploadedFiles();
            $filesRaw = $uploaded['attachments'] ?? ($uploaded['attachments[]'] ?? null);
            $files = $this->expandFileArray($filesRaw);

            foreach ($files as $i => $file) {
                $norm = $this->normalizeUploadedFile($file);
                if ((int)$norm['error'] !== UPLOAD_ERR_OK) {
                    error_log('[GCP-COMMENT][ATTACH]['.$i.'] upload error='.$norm['error']);
                    continue;
                }
                $media = $this->createMediaFromNormalizedFile($norm, $me);
                if (method_exists($media, 'setCommentsProject')) {
                    $media->setCommentsProject($comment);
                }
                $this->mediaRepo->save($media);
                if (method_exists($comment, 'addMedia')) {
                    $comment->addMedia($media);
                }
            }

            // Update group timestamps
            $now = new DateTimeImmutable('now', new \DateTimeZone('UTC'));
            $grp->setUpdateAt($now)->setLastMessageAt($now);
            $this->groups->save($grp);

            return new JsonResponse($this->serializeComment($comment), 201);
        } catch (\Throwable $e) {
            error_log('[GCP-COMMENT][CREATE][FATAL] '.get_class($e).' '.$e->getMessage().' @ '.$e->getFile().':'.$e->getLine());
            throw $e;
        }
    }

    // ------------------------------------------------------------
    // Helpers
    // ------------------------------------------------------------

    private function canViewGroup(GroupCommentsProject $grp, User $me): bool
    {
        // project owner or contributor?
        $project = $grp->getProject();
        if ($project instanceof Project) {
            $isOwner = (int)($project->getAuthor()?->getId() ?? 0) === (int)$me->getId();
            if ($isOwner) return true;
            foreach ($project->getUsers() as $u) {
                if ($u instanceof User && $u->getId() === $me->getId()) return true;
            }
        }

        // recipient?
        return $this->isRecipient($grp, (int)$me->getId());
    }

    /**
     * @throws \Throwable
     */
    private function isRecipient(GroupCommentsProject $grp, int $userId): bool
    {
        if ($userId <= 0) return false;

        if (method_exists($grp, 'getRecipients')) {
            foreach ($grp->getRecipients() as $u) {
                if ($u instanceof User && (int)$u->getId() === $userId) return true;
            }
        }

        // join table fallback
        $cnt = (clone $this->groups->qb)
            ->from('group_comments_project_recipients', 'gr')
            ->where('gr.group_comments_project_id', '=', (int)$grp->getId())
            ->andWhere('gr.user_id', '=', $userId)
            ->count();

        return $cnt > 0;
    }

    private function serializeGroup(GroupCommentsProject $g, bool $includeRecipients = true, bool $includePreview = true): array
    {
        $project = $g->getProject();

        $out = [
            'id'            => $g->getId(),
            'hash'          => $g->getHash(),
            'project'       => $project ? [
                'id'   => $project->getId(),
                'hash' => $project->getHash(),
                'name' => $project->getName(),
            ] : null,
            'createdAt'     => $g->getCreatedAt()?->format(\DateTimeInterface::ATOM),
            'updatedAt'     => $g->getUpdateAt()?->format(\DateTimeInterface::ATOM),
            'lastMessageAt' => $g->getLastMessageAt()?->format(\DateTimeInterface::ATOM),
        ];

        if ($includePreview) {
            // fetch newest comment id (DESC, limit 1) using fetchAll() then pick first row
            $rows = (clone $this->comments->qb)
                ->from('commentsproject', 'cp')
                ->select('cp.id AS id')
                ->where('cp.group_comments_project_id', '=', (int)$g->getId())
                ->orderBy('cp.id', 'DESC')
                ->limit(1)
                ->fetchAll();

            $latestId = (is_array($rows) && isset($rows[0]['id'])) ? (int)$rows[0]['id'] : null;

            if ($latestId) {
                /** @var CommentsProject|null $latest */
                $latest = $this->comments->find($latestId);
                $out['lastComment'] = $latest ? $this->serializeComment($latest) : null;
            } else {
                $out['lastComment'] = null;
            }
        }

        if ($includeRecipients && method_exists($g, 'getRecipients')) {
            $out['recipients'] = array_values(array_map(
                fn($u) => $u instanceof User ? $this->serializeUserLite($u) : null,
                $g->getRecipients()
            ));
        }

        return $out;
    }

    private function serializeComment(CommentsProject $c): array
    {
        $author = $c->getAuthor();
        $atts = method_exists($c, 'getMedia') ? $c->getMedia() : [];

        return [
            'id'        => $c->getId(),
            'slug'      => $c->getSlug(),
            'subject'   => $c->getSubject(),
            'message'   => $c->getMessage(),
            'date'      => $c->getDate()?->format(\DateTimeInterface::ATOM),
            'read'      => $c->getRead(),
            'readDate'  => $c->getReadDate()?->format(\DateTimeInterface::ATOM),
            'author'    => $author ? $this->serializeUserLite($author) : null,
            'attachments' => array_values(array_map(
                fn($media) => is_object($media) ? $this->serializeMedia($media) : null,
                is_array($atts) ? $atts : []
            )),
        ];
    }

    private function serializeUserLite(User $u): array
    {
        $pic = $u->getPicture();
        return [
            'id'       => $u->getId(),
            'fullName' => $u->getFullName(),
            'email'    => $u->getEmail(),
            'picture'  => $pic ? $this->serializeMedia($pic) : null,
        ];
    }

    private function serializeMedia(object $media): array
    {
        return [
            'id'   => method_exists($media, 'getId')   ? $media->getId()   : null,
            'url'  => method_exists($media, 'getUrl')  ? $media->getUrl()  : null,
            'type' => method_exists($media, 'getType') ? $media->getType() : (method_exists($media, 'getMimeType') ? $media->getMimeType() : null),
            'hash' => method_exists($media, 'getHash') ? $media->getHash() : null,
        ];
    }

    /**
     * @param array|UploadedFileInterface $file
     * @return array{clientName:string,mime:string,tmpPath:?string,error:int,psr:?UploadedFileInterface,size:?int}
     */
    private function normalizeUploadedFile(array|UploadedFileInterface $file): array
    {
        if ($file instanceof UploadedFileInterface) {
            return [
                'clientName' => $file->getClientFilename() ?? 'upload.bin',
                'mime'       => $file->getClientMediaType() ?? 'application/octet-stream',
                'tmpPath'    => method_exists($file, 'getStream') ? $file->getStream()->getMetadata('uri') : null,
                'error'      => $file->getError(),
                'psr'        => $file,
                'size'       => method_exists($file, 'getSize') ? $file->getSize() : null,
            ];
        }

        return [
            'clientName' => $file['name']     ?? 'upload.bin',
            'mime'       => $file['type']     ?? 'application/octet-stream',
            'tmpPath'    => $file['tmp_name'] ?? null,
            'error'      => $file['error']    ?? UPLOAD_ERR_NO_FILE,
            'psr'        => null,
            'size'       => isset($file['size']) ? (int)$file['size'] : null,
        ];
    }

    /**
     * Expand a PHP multi-file shape into a flat list.
     *
     * @param mixed $raw
     * @return array<int, UploadedFileInterface|array>
     */
    private function expandFileArray($raw): array
    {
        if ($raw instanceof UploadedFileInterface) return [$raw];

        if (is_array($raw) && isset($raw['name']) && !is_array($raw['name'])) {
            return [$raw];
        }

        if (is_array($raw)
            && isset($raw['name'], $raw['type'], $raw['tmp_name'], $raw['error'])
            && is_array($raw['name']) && is_array($raw['type']) && is_array($raw['tmp_name']) && is_array($raw['error'])
        ) {
            $out = [];
            $count = count($raw['name']);
            for ($i = 0; $i < $count; $i++) {
                $out[] = [
                    'name'     => $raw['name'][$i]      ?? null,
                    'type'     => $raw['type'][$i]      ?? 'application/octet-stream',
                    'tmp_name' => $raw['tmp_name'][$i]  ?? null,
                    'error'    => $raw['error'][$i]     ?? UPLOAD_ERR_NO_FILE,
                    'size'     => $raw['size'][$i]      ?? null,
                ];
            }
            return $out;
        }

        if (is_array($raw) && !empty($raw) && $raw[0] instanceof UploadedFileInterface) {
            return $raw;
        }

        return [];
    }

    /**
     * @throws RandomException
     * @throws \DateMalformedStringException
     */
    private function createMediaFromNormalizedFile(array $norm, User $user): Media
    {
        $clientFilename = $norm['clientName'];
        $ext      = pathinfo($clientFilename, PATHINFO_EXTENSION);
        $mimeType = $norm['mime'];
        $randomName = bin2hex(random_bytes(8));
        $safeExt    = $ext !== '' ? ('.' . preg_replace('/[^a-zA-Z0-9]/', '', $ext)) : '';
        $finalName  = $randomName . $safeExt;

        $uploadDir = __DIR__ . '/../../public/uploads';
        if (!is_dir($uploadDir)) {
            @mkdir($uploadDir, 0775, true);
        }

        $absolutePath = $uploadDir . '/' . $finalName;
        $publicUrl    = '/uploads/' . $finalName;

        if ($norm['psr'] instanceof UploadedFileInterface) {
            $norm['psr']->moveTo($absolutePath);
        } else {
            if (!isset($norm['tmpPath']) || !is_readable($norm['tmpPath'])) {
                throw new RuntimeException('Upload tmp file missing', 500);
            }
            if (!@move_uploaded_file($norm['tmpPath'], $absolutePath)) {
                if (!@rename($norm['tmpPath'], $absolutePath)) {
                    if (!@copy($norm['tmpPath'], $absolutePath)) {
                        throw new RuntimeException('Failed to write uploaded file', 500);
                    }
                }
            }
        }

        $mediaHash = bin2hex(random_bytes(16));

        $media = new Media();
        $media
            ->setUrl($publicUrl)
            ->setType($mimeType)
            ->setCreated(new DateTimeImmutable('now', new \DateTimeZone('UTC')))
            ->setAuthorUser($user)
            ->setHash($mediaHash);

        return $media;
    }
}
