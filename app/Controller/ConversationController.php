<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\Conversation;
use App\Entity\Message;
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
use App\Service\MonkeysMailService;
use MonkeysLegion\Template\Renderer;

final class ConversationController
{
    private EntityRepository $conversations;
    private EntityRepository $messages;
    private EntityRepository $projects;
    private EntityRepository $users;
    private EntityRepository $mediaRepo;

    public function __construct(
        private RepositoryFactory $repos,
        private MonkeysMailService $mail,
        private Renderer $renderer,
    ) {
        $this->conversations = $this->repos->getRepository(Conversation::class);
        $this->messages      = $this->repos->getRepository(Message::class);
        $this->projects      = $this->repos->getRepository(Project::class);
        $this->users         = $this->repos->getRepository(User::class);
        $this->mediaRepo     = $this->repos->getRepository(Media::class);
    }

    // ------------------------------------------------------------
    // Conversations: create & list (per project, per user, detail)
    // ------------------------------------------------------------

    /**
     * POST /projects/{hash}/conversations
     *
     * Body (JSON or multipart with "data"):
     *  {
     *    subject?: string,
     *    participantIds?: int[],
     *    participantEmails?: string[]
     *  }
     *
     * Rules:
     *  - Requester must be project author or contributor to create.
     *  - Requester is auto-added as participant.
     *  - Unknown participant emails are returned in _warnings.emails_not_found.
     */
    #[Route(methods: 'POST', path: '/projects/{hash}/conversations')]
    public function createConversation(ServerRequestInterface $request): JsonResponse
    {
        try {
            // --- AuthN ---
            $uid = (int) $request->getAttribute('user_id', 0);
            /** @var User|null $me */
            $me = $this->users->find($uid);
            if (!$me) {
                throw new RuntimeException('Unauthorized', 401);
            }

            // --- Project ---
            $hash = (string) $request->getAttribute('hash');
            if ($hash === '') { throw new RuntimeException('Invalid project identifier', 400); }

            /** @var Project|null $project */
            $project = $this->projects->findOneBy(['hash' => $hash]);
            if (!$project) {
                throw new RuntimeException('Project not found', 404);
            }

            // --- Parse body (JSON or multipart+data) ---
            $parsedBody  = $request->getParsedBody();
            $isMultipart = is_array($parsedBody) && array_key_exists('data', $parsedBody);
            $raw         = $isMultipart ? (string)($parsedBody['data'] ?? '') : (string)$request->getBody();
            $data        = $raw !== '' ? json_decode($raw, true, 512, JSON_THROW_ON_ERROR) : [];

            $subject = isset($data['subject']) ? trim((string)$data['subject']) : '';
            $pIds    = array_unique(array_filter(array_map('intval', (array)($data['participantIds'] ?? []))));
            $pEmails = array_values(array_filter(array_map(
                static fn($e) => filter_var((string)$e, FILTER_VALIDATE_EMAIL) ? strtolower((string)$e) : null,
                (array)($data['participantEmails'] ?? [])
            )));

            // Resolve emails -> user IDs; collect unknowns
            $notFoundEmails = [];
            $resolvedIds = [];
            foreach ($pEmails as $em) {
                /** @var User|null $found */
                $found = $this->users->findOneBy(['email' => $em]);
                if ($found) { $resolvedIds[$found->getId()] = true; }
                else { $notFoundEmails[] = $em; }
            }
            foreach ($pIds as $id) { if ($id > 0) { $resolvedIds[$id] = true; } }
            $participantIds = array_values(array_map('intval', array_keys($resolvedIds)));

            // Always include the requester
            if (!in_array($me->getId(), $participantIds, true)) {
                $participantIds[] = $me->getId();
            }

            // --- Build entity ---
            $conv = new Conversation();
            $conv->setHash(bin2hex(random_bytes(16)));
            $conv->setSubject($subject !== '' ? $subject : null);
            $conv->setProject($project);
            $conv->setCreatedAt(new DateTimeImmutable('now', new \DateTimeZone('UTC')));
            $conv->setUpdatedAt(new DateTimeImmutable('now', new \DateTimeZone('UTC')));

            // Save first to get an ID (if needed by attachRelation)
            $this->conversations->save($conv);

            // Attach participants in join table
            foreach ($participantIds as $pid) {
                /** @var User|null $u */
                $u = $this->users->find($pid);
                if ($u instanceof User) {
                    $this->conversations->attachRelation($conv, 'users', $u->getId());
                }
            }

            // Final save
            $this->conversations->save($conv);

            $payload = $this->serializeConversation($conv, includeParticipants: true, includePreview: true);
            if (!empty($notFoundEmails)) {
                $payload['_warnings']['emails_not_found'] = array_values(array_unique($notFoundEmails));
            }

            $this->sendConversationCreatedNotification($conv, $me);

            return new JsonResponse($payload, 201);
        } catch (\Throwable $e) {
            error_log('[CONV][CREATE][FATAL] '.get_class($e).' '.$e->getMessage().' @ '.$e->getFile().':'.$e->getLine());
            throw $e;
        }
    }

    /**
     * GET /projects/{hash}/conversations
     * List conversations for a project (requester must be project owner or contributor or conversation participant).
     *
     * Query:
     *  - q: search in subject and last message text
     *  - page, perPage (default 1, 20)
     */
    #[Route(methods: 'GET', path: '/projects/{hash}/conversations')]
    public function listProjectConversations(ServerRequestInterface $request): JsonResponse
    {
        // --- AuthN ---
        $uid = (int) $request->getAttribute('user_id', 0);
        /** @var User|null $me */
        $me = $this->users->find($uid);
        if (!$me) { throw new RuntimeException('Unauthorized', 401); }

        $hash = (string) $request->getAttribute('hash');
        if ($hash === '') { throw new RuntimeException('Invalid project identifier', 400); }

        /** @var Project|null $project */
        $project = $this->projects->findOneBy(['hash' => $hash]);
        if (!$project) { throw new RuntimeException('Project not found', 404); }

        // --- AuthZ (owner or contributor OR participant of any conv of this project) ---
        $isOwner = (int)($project->getAuthor()?->getId() ?? 0) === (int)$me->getId();
        $isContributor = false;
        foreach ($project->getUsers() as $u) {
            if ($u instanceof User && $u->getId() === $me->getId()) { $isContributor = true; break; }
        }

        // Base query: convs by project
        $q = $request->getQueryParams();
        $page    = max(1, (int)($q['page'] ?? 1));
        $perPage = (int)($q['perPage'] ?? 20);
        if ($perPage <= 0)  { $perPage = 20; }
        if ($perPage > 100) { $perPage = 100; }
        $offset  = ($page - 1) * $perPage;

        $needle = trim((string)($q['q'] ?? ''));

        $base = (clone $this->conversations->qb)
            ->from('conversation', 'c')
            ->where('c.project_id', '=', (int)$project->getId());

        if (!$isOwner && !$isContributor) {
            // restrict to convs where requester is a participant
            $base->leftJoin('conversation_user', 'cu', 'cu.conversation_id', '=', 'c.id')
                ->andWhere('cu.user_id', '=', (int)$me->getId());
        }

        if ($needle !== '') {
            $like = '%'.mb_strtolower($needle).'%';
            $base->andWhereGroup(function($g) use ($like) {
                $g->whereLike('LOWER(c.subject)', $like)
                    ->orWhereLike('LOWER(c.lastMessagePreview)', $like);
            });
        }

        // Totals
        $total = $base->duplicate()->count();
        $pages = (int)max(1, (int)ceil($total / max(1, $perPage)));

        // Page
        $rows = $base->duplicate()
            ->select('c.id AS id')
            ->orderByRaw('COALESCE(c.updatedAt, c.createdAt, c.id) DESC')
            ->limit($perPage)
            ->offset($offset)
            ->fetchAll();

        $items = [];
        foreach ($rows as $r) {
            $cid = isset($r['id']) ? (int)$r['id'] : 0;
            if ($cid <= 0) continue;
            /** @var Conversation|null $conv */
            $conv = $this->conversations->find($cid);
            if ($conv instanceof Conversation) {
                $items[] = $this->serializeConversation($conv, includeParticipants: true, includePreview: true);
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
     * GET /me/conversations
     * Conversations where the requester is a participant (across all projects).
     *
     * Query: q, page, perPage
     */
    #[Route(methods: 'GET', path: '/me/conversations')]
    public function listMyConversations(ServerRequestInterface $request): JsonResponse
    {
        $uid = (int) $request->getAttribute('user_id', 0);
        /** @var User|null $me */
        $me = $this->users->find($uid);
        if (!$me) { throw new RuntimeException('Unauthorized', 401); }

        $q = $request->getQueryParams();
        $page    = max(1, (int)($q['page'] ?? 1));
        $perPage = (int)($q['perPage'] ?? 20);
        if ($perPage <= 0)  { $perPage = 20; }
        if ($perPage > 100) { $perPage = 100; }
        $offset  = ($page - 1) * $perPage;
        $needle  = trim((string)($q['q'] ?? ''));

        $base = (clone $this->conversations->qb)
            ->from('conversation', 'c')
            ->leftJoin('conversation_user', 'cu', 'cu.conversation_id', '=', 'c.id')
            ->where('cu.user_id', '=', (int)$me->getId());

        if ($needle !== '') {
            $like = '%'.mb_strtolower($needle).'%';
            $base->andWhereGroup(function($g) use ($like) {
                $g->whereLike('LOWER(c.subject)', $like)
                    ->orWhereLike('LOWER(c.lastMessagePreview)', $like);
            });
        }

        $total = $base->duplicate()->count();
        $pages = (int)max(1, (int)ceil($total / max(1, $perPage)));

        $rows = $base->duplicate()
            ->select('c.id AS id')
            ->orderByRaw('COALESCE(c.updatedAt, c.createdAt, c.id) DESC')
            ->limit($perPage)
            ->offset($offset)
            ->fetchAll();

        $items = [];
        foreach ($rows as $r) {
            $cid = isset($r['id']) ? (int)$r['id'] : 0;
            if ($cid <= 0) continue;
            /** @var Conversation|null $conv */
            $conv = $this->conversations->find($cid);
            if ($conv instanceof Conversation) {
                $items[] = $this->serializeConversation($conv, includeParticipants: true, includePreview: true);
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
     * GET /conversations/{hash}
     * Detail with participants and last message preview.
     */
    #[Route(methods: 'GET', path: '/conversations/{hash}')]
    public function showConversation(ServerRequestInterface $request): JsonResponse
    {
        $uid = (int) $request->getAttribute('user_id', 0);
        /** @var User|null $me */
        $me = $this->users->find($uid);
        if (!$me) { throw new RuntimeException('Unauthorized', 401); }

        $hash = (string) $request->getAttribute('hash');
        if ($hash === '') { throw new RuntimeException('Invalid conversation', 400); }

        /** @var Conversation|null $conv */
        $conv = $this->conversations->findOneBy(['hash' => $hash]);
        if (!$conv) { throw new RuntimeException('Conversation not found', 404); }

        // must be participant
        if (!$this->isParticipant($conv, $me->getId())) {
            throw new RuntimeException('Forbidden', 403);
        }

        return new JsonResponse(
            $this->serializeConversation($conv, includeParticipants: true, includePreview: true),
            200
        );
    }

    // ------------------------------------------------------------
    // Messages: list (with cursor), create (multipart), delete (optional)
    // ------------------------------------------------------------

    /**
     * GET /conversations/{hash}/messages
     *
     * Supports page & perPage, but also **cursor** for lazy/infinite scroll:
     *  - beforeId: return messages with id < beforeId (older), newest-first in response
     *  - limit: number of messages to return (default 20, max 100)
     *
     * Returned:
     *  {
     *    items: MessageLite[],
     *    nextCursor: { beforeId: int|null } // pass this to fetch older
     *  }
     */
    #[Route(methods: 'GET', path: '/conversations/{hash}/messages')]
    public function listMessages(ServerRequestInterface $request): JsonResponse
    {
        $uid = (int) $request->getAttribute('user_id', 0);
        /** @var User|null $me */
        $me = $this->users->find($uid);
        if (!$me) { throw new RuntimeException('Unauthorized', 401); }

        $hash = (string) $request->getAttribute('hash');
        if ($hash === '') { throw new RuntimeException('Invalid conversation', 400); }

        /** @var Conversation|null $conv */
        $conv = $this->conversations->findOneBy(['hash' => $hash]);
        if (!$conv) { throw new RuntimeException('Conversation not found', 404); }

        if (!$this->isParticipant($conv, $me->getId())) {
            throw new RuntimeException('Forbidden', 403);
        }

        $q = $request->getQueryParams();
        // Cursor params (preferred for lazy loading)
        $beforeId = isset($q['beforeId']) ? (int)$q['beforeId'] : null;
        $limit    = (int)($q['limit'] ?? 20);
        if ($limit <= 0)  { $limit = 20; }
        if ($limit > 100) { $limit = 100; }

        $qb = (clone $this->messages->qb)
            ->from('message', 'm')
            ->where('m.conversation_id', '=', (int)$conv->getId());

        if ($beforeId && $beforeId > 0) {
            // fetch older than cursor
            $qb->andWhere('m.id', '<', $beforeId);
        }

        // Newest-first so UI can append older at the bottom if desired
        $rows = $qb->select('m.id AS id')
            ->orderBy('m.id', 'DESC')
            ->limit($limit)
            ->fetchAll();

        $items = [];
        $minId = null;
        foreach ($rows as $r) {
            $mid = isset($r['id']) ? (int)$r['id'] : 0;
            if ($mid <= 0) continue;
            /** @var Message|null $msg */
            $msg = $this->messages->find($mid);
            if ($msg instanceof Message) {
                $items[] = $this->serializeMessage($msg);
                if ($minId === null || $mid < $minId) { $minId = $mid; }
            }
        }

        // nextCursor -> beforeId=minId for subsequent older fetch
        $nextCursor = ['beforeId' => $minId];

        return new JsonResponse([
            'items'      => $items,
            'nextCursor' => $nextCursor,
        ], 200);
    }

    /**
     * POST /conversations/{hash}/messages
     *
     * Accepts:
     *  - JSON: { subject?: string, message?: string }
     *  - multipart/form-data: data=<json>, attachments[] files
     */
    #[Route(methods: 'POST', path: '/conversations/{hash}/messages')]
    public function postMessage(ServerRequestInterface $request): JsonResponse
    {
        try {
            $uid = (int) $request->getAttribute('user_id', 0);
            /** @var User|null $me */
            $me = $this->users->find($uid);
            if (!$me) { throw new RuntimeException('Unauthorized', 401); }

            $hash = (string) $request->getAttribute('hash');
            if ($hash === '') { throw new RuntimeException('Invalid conversation', 400); }

            /** @var Conversation|null $conv */
            $conv = $this->conversations->findOneBy(['hash' => $hash]);
            if (!$conv) { throw new RuntimeException('Conversation not found', 404); }

            if (!$this->isParticipant($conv, $me->getId())) {
                throw new RuntimeException('Forbidden', 403);
            }

            // Parse JSON or multipart
            $parsedBody  = $request->getParsedBody();
            $isMultipart = is_array($parsedBody) && array_key_exists('data', $parsedBody);
            $raw         = $isMultipart ? (string)($parsedBody['data'] ?? '') : (string)$request->getBody();
            $data        = $raw !== '' ? json_decode($raw, true, 512, JSON_THROW_ON_ERROR) : [];

            $subject = isset($data['subject']) ? trim((string)$data['subject']) : null;
            $body    = isset($data['message']) ? trim((string)$data['message']) : null;

            if (($subject === null || $subject === '') && ($body === null || $body === '')) {
                throw new RuntimeException('Empty message', 400);
            }

            // Build message
            $msg = new Message();
            $msg->setSubject($subject ?: null);
            $msg->setMessage($body ?: null);
            $msg->setAuthor($me);
            // Requires your Message entity to have setConversation(Conversation $c)
            if (method_exists($msg, 'setConversation')) {
                $msg->setConversation($conv);
            } else {
                // Fallback: if your current model ties Message->Project only (older), keep it:
                $msg->setProject($conv->getProject());
            }

            // Save early to get ID (for FK on media if needed)
            $this->messages->save($msg);

            // Attachments
            $uploaded = $request->getUploadedFiles();
            // Support attachments or attachments[]
            $filesRaw = $uploaded['attachments'] ?? ($uploaded['attachments[]'] ?? null);
            $files = $this->expandFileArray($filesRaw);

            foreach ($files as $i => $file) {
                $norm = $this->normalizeUploadedFile($file);
                if ((int)$norm['error'] !== UPLOAD_ERR_OK) {
                    error_log('[CONV][MSG][ATTACH]['.$i.'] upload error='.$norm['error']);
                    continue;
                }
                $media = $this->createMediaFromNormalizedFile($norm, $me);
                // Link FK (Media -> Message), method must exist in your Media entity
                if (method_exists($media, 'setMessage')) {
                    $media->setMessage($msg);
                }
                $this->mediaRepo->save($media);
                // Link Message -> Media list (if your Message entity keeps array)
                if (method_exists($msg, 'addMedia')) {
                    $msg->addMedia($media);
                }
            }

            // Update conversation last message preview & timestamps
            if (method_exists($conv, 'setLastMessage')) {
                $conv->setLastMessage($msg);
            }
            if (method_exists($conv, 'setLastMessagePreview')) {
                $preview = $subject ?: ($body ? mb_substr($body, 0, 180) : null);
                $conv->setLastMessagePreview($preview ?: null);
            }
            if (method_exists($conv, 'setUpdatedAt')) {
                $conv->setUpdatedAt(new DateTimeImmutable('now', new \DateTimeZone('UTC')));
            }
            $this->conversations->save($conv);

            $this->sendNewConversationMessageNotification($conv, $msg);

            return new JsonResponse($this->serializeMessage($msg), 201);
        } catch (\Throwable $e) {
            error_log('[CONV][MSG][CREATE][FATAL] '.get_class($e).' '.$e->getMessage().' @ '.$e->getFile().':'.$e->getLine());
            throw $e;
        }
    }

    // ------------------------------------------------------------
    // Helpers
    // ------------------------------------------------------------

    /**
     * @throws \Throwable
     */
    private function isParticipant(Conversation $conv, int $userId): bool
    {
        if ($userId <= 0) return false;

        // Try hydrated relation
        if (method_exists($conv, 'getUsers')) {
            foreach ($conv->getUsers() as $u) {
                if ($u instanceof User && (int)$u->getId() === $userId) return true;
            }
        }

        // Fallback: join table lookup
        $cnt = (clone $this->conversations->qb)
            ->from('conversation_user', 'cu')
            ->where('cu.conversation_id', '=', (int)$conv->getId())
            ->andWhere('cu.user_id', '=', $userId)
            ->count();

        return $cnt > 0;
    }

    private function serializeConversation(Conversation $c, bool $includeParticipants = true, bool $includePreview = true): array
    {
        $project = $c->getProject();
        $lastMsg = method_exists($c, 'getLastMessage') ? $c->getLastMessage() : null;

        $out = [
            'id'        => $c->getId(),
            'hash'      => $c->getHash(),
            'subject'   => method_exists($c, 'getSubject') ? $c->getSubject() : null,
            'project'   => $project ? [
                'id'   => $project->getId(),
                'hash' => $project->getHash(),
                'name' => $project->getName(),
            ] : null,
            'createdAt' => method_exists($c, 'getCreatedAt') && $c->getCreatedAt()
                ? $c->getCreatedAt()->format(\DateTimeInterface::ATOM) : null,
            'updatedAt' => method_exists($c, 'getUpdatedAt') && $c->getUpdatedAt()
                ? $c->getUpdatedAt()->format(\DateTimeInterface::ATOM) : null,
        ];

        if ($includePreview) {
            $out['lastMessage'] = $lastMsg ? $this->serializeMessage($lastMsg) : null;
            if (method_exists($c, 'getLastMessagePreview')) {
                $out['lastMessagePreview'] = $c->getLastMessagePreview();
            }
        }

        if ($includeParticipants && method_exists($c, 'getUsers')) {
            $out['participants'] = array_values(array_map(
                fn($u) => $u instanceof User ? $this->serializeUserLite($u) : null,
                $c->getUsers()
            ));
        }

        return $out;
    }

    /**
     * Serialize a Message entity to array.
     *
     * @param Message $m
     * @return array
     */
    private function serializeMessage(Message $m): array
    {
        $author = $m->getAuthor();
        $atts = method_exists($m, 'getMedia') ? $m->getMedia() : [];

        return [
            'id'        => $m->getId(),
            'subject'   => $m->getSubject(),
            'message'   => $m->getMessage(),
            'author'    => $author ? $this->serializeUserLite($author) : null,
            'attachments' => array_values(array_map(
                fn($media) => is_object($media) ? $this->serializeMedia($media) : null,
                is_array($atts) ? $atts : []
            )),
            'read'      => method_exists($m, 'getRead') ? (bool)$m->getRead() : null,
            'readDate'  => (method_exists($m, 'getReadDate') && $m->getReadDate())
                ? $m->getReadDate()->format(\DateTimeInterface::ATOM)
                : null,
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
                'tmpPath'    => method_exists($file, 'getStream')
                    ? $file->getStream()->getMetadata('uri')
                    : null,
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

    /**
     * POST /conversations/{hash}/messages/{id}/read
     * Marks one message as read by the current user.
     * @throws \JsonException
     * @throws \Throwable
     */
    #[Route(methods: 'POST', path: '/conversations/{hash}/messages/{id}/read')]
    public function markMessageRead(ServerRequestInterface $request): JsonResponse
    {
        $uid = (int) $request->getAttribute('user_id', 0);
        /** @var User|null $me */
        $me = $this->users->find($uid);
        if (!$me) { throw new RuntimeException('Unauthorized', 401); }

        $hash = (string) $request->getAttribute('hash');
        $id   = (int) $request->getAttribute('id');
        if ($hash === '' || $id <= 0) { throw new RuntimeException('Invalid request', 400); }

        /** @var Conversation|null $conv */
        $conv = $this->conversations->findOneBy(['hash' => $hash]);
        if (!$conv) { throw new RuntimeException('Conversation not found', 404); }
        if (!$this->isParticipant($conv, $me->getId())) { throw new RuntimeException('Forbidden', 403); }

        /** @var Message|null $msg */
        $msg = $this->messages->find($id);
        if (!$msg) { throw new RuntimeException('Message not found', 404); }

        // Optional: ensure the message belongs to this conversation
        if (method_exists($msg, 'getConversation') && $msg->getConversation()?->getId() !== $conv->getId()) {
            throw new RuntimeException('Message does not belong to this conversation', 400);
        }

        // Optional: don’t require marking your own message as read
        if ($msg->getAuthor()?->getId() === $me->getId()) {
            return new JsonResponse($this->serializeMessage($msg), 200);
        }

        // Idempotent: only update if not already read
        if (method_exists($msg, 'getRead') && $msg->getRead()) {
            return new JsonResponse($this->serializeMessage($msg), 200);
        }

        if (method_exists($msg, 'setRead'))     { $msg->setRead(true); }
        if (method_exists($msg, 'setReadDate')) { $msg->setReadDate(new DateTimeImmutable('now', new \DateTimeZone('UTC'))); }
        $this->messages->save($msg);

        return new JsonResponse($this->serializeMessage($msg), 200);
    }

    /**
     * POST /conversations/{hash}/read
     * Marks all unread messages (not authored by the requester) in the conversation as read.
     * Returns how many were updated.
     * @throws \JsonException
     * @throws \DateMalformedStringException
     * @throws \Throwable
     */
    #[Route(methods: 'POST', path: '/conversations/{hash}/read')]
    public function markConversationRead(ServerRequestInterface $request): JsonResponse
    {
        $uid = (int) $request->getAttribute('user_id', 0);
        /** @var User|null $me */
        $me = $this->users->find($uid);
        if (!$me) { throw new RuntimeException('Unauthorized', 401); }

        $hash = (string) $request->getAttribute('hash');
        if ($hash === '') { throw new RuntimeException('Invalid conversation', 400); }

        /** @var Conversation|null $conv */
        $conv = $this->conversations->findOneBy(['hash' => $hash]);
        if (!$conv) { throw new RuntimeException('Conversation not found', 404); }
        if (!$this->isParticipant($conv, $me->getId())) { throw new RuntimeException('Forbidden', 403); }

        // Find candidate message IDs (unread + not authored by me) for this conversation
        $rows = (clone $this->messages->qb)
            ->from('message', 'm')
            ->select('m.id AS id')
            ->where('m.conversation_id', '=', (int)$conv->getId())
            ->andWhereGroup(function($g) {
                // read is NULL or false
                $g->whereNull('m.read')->orWhere('m.read', '=', 0);
            })
            ->andWhere('m.author_id', '!=', (int)$me->getId())
            ->fetchAll();

        $count = 0;
        $now = new DateTimeImmutable('now', new \DateTimeZone('UTC'));

        foreach ($rows as $r) {
            $mid = isset($r['id']) ? (int)$r['id'] : 0;
            if ($mid <= 0) continue;
            /** @var Message|null $msg */
            $msg = $this->messages->find($mid);
            if (!$msg) continue;

            if (method_exists($msg, 'getRead') && $msg->getRead()) continue; // idempotent guard

            if (method_exists($msg, 'setRead'))     { $msg->setRead(true); }
            if (method_exists($msg, 'setReadDate')) { $msg->setReadDate($now); }

            // if your EntityRepository::save accepts a flush flag, pass true:
            $this->messages->save($msg/* , true */);
            $count++;
        }

        return new JsonResponse(['updated' => $count], 200);
    }

    /**
     * GET /me/messages/unread-count
     * Returns total unread messages (not authored by me) across conversations
     * where the requester is a participant.
     * @throws \ReflectionException
     * @throws \Throwable
     */
    #[Route(methods: 'GET', path: '/me/messages/unread-count')]
    public function unreadCountAll(ServerRequestInterface $request): JsonResponse
    {
        $uid = (int) $request->getAttribute('user_id', 0);
        /** @var User|null $me */
        $me = $this->users->find($uid);
        if (!$me) {
            throw new RuntimeException('Unauthorized', 401);
        }

        // Simpler and guaranteed logic:
        $qb = (clone $this->messages->qb)
            ->from('message', 'm')
            ->innerJoin('conversation_user', 'cu', 'cu.conversation_id', '=', 'm.conversation_id')
            ->where('cu.user_id', '=', (int)$me->getId())
            ->andWhere('m.author_id', '!=', (int)$me->getId())
            ->andWhereGroup(function ($g) {
                $g->whereNull('m.read')->orWhere('m.read', '=', 0);
            });

        $count = $qb->count();

        return new JsonResponse(['unread' => (int)$count], 200);
    }

    /**
     * GET /conversations/{hash}/unread-count
     * Returns unread messages count for a single conversation.
     * @throws \Throwable
     */
    #[Route(methods: 'GET', path: '/conversations/{hash}/unread-count')]
    public function unreadCountByConversation(ServerRequestInterface $request): JsonResponse
    {
        $uid = (int) $request->getAttribute('user_id', 0);
        /** @var User|null $me */
        $me = $this->users->find($uid);
        if (!$me) { throw new RuntimeException('Unauthorized', 401); }

        $hash = (string) $request->getAttribute('hash');
        if ($hash === '') { throw new RuntimeException('Invalid conversation', 400); }

        /** @var Conversation|null $conv */
        $conv = $this->conversations->findOneBy(['hash' => $hash]);
        if (!$conv) { throw new RuntimeException('Conversation not found', 404); }

        // Must be a participant
        if (!$this->isParticipant($conv, $me->getId())) {
            throw new RuntimeException('Forbidden', 403);
        }

        $count = (clone $this->messages->qb)
            ->from('message', 'm')
            ->where('m.conversation_id', '=', (int)$conv->getId())
            ->andWhereGroup(function($g) {
                $g->whereNull('m.read')->orWhere('m.read', '=', 0);
            })
            ->andWhere('m.author_id', '!=', (int)$me->getId())
            ->count();

        return new JsonResponse(['unread' => (int)$count], 200);
    }

    /**
     * Notify conversation participants by email when a new message is posted.
     * Uses ML template: emails/new_message_notification.ml.php
     */
    private function sendNewConversationMessageNotification(Conversation $conv, Message $msg): void
    {
        $author = $msg->getAuthor();
        $project = $conv->getProject();

        if (!$author) {
            return;
        }

        // ---- Collect participants (from conversation users) ----
        $participants = [];
        if (method_exists($conv, 'getUsers')) {
            foreach ($conv->getUsers() as $u) {
                if ($u instanceof User) {
                    $participants[] = $u;
                }
            }
        }

        if (empty($participants)) {
            return;
        }

        $authorId = (int)($author->getId() ?? 0);
        $emails = [];

        foreach ($participants as $u) {
            if (!$u instanceof User) {
                continue;
            }
            $uid   = (int)($u->getId() ?? 0);
            $email = trim((string)($u->getEmail() ?? ''));

            // exclude sender
            if ($uid === $authorId) {
                continue;
            }
            if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
                continue;
            }
            $emails[$email] = true; // dedupe by key
        }

        if (empty($emails)) {
            return;
        }

        // ---- Context for template ----
        $frontendBase = rtrim(
            (string)(getenv('FRONTEND_BASE_URL') ?: 'https://monkeysraiser.com'),
            '/'
        );

        $projectName = $project ? (string)($project->getName() ?? 'a project') : 'a project';
        $projectHash = $project ? (string)($project->getHash() ?? '') : '';
        $conversationHash = (string)($conv->getHash() ?? '');

        // Adjust this to your actual front-end route
        // example: /dashboard/messages?c=<convHash>
        $conversationUrl = $frontendBase . '/dashboard/messages?c=' . rawurlencode($conversationHash);

        $authorName  = trim((string)($author->getFullName() ?? '')) ?: (string)($author->getEmail() ?? 'A MonkeysRaiser user');
        $authorEmail = (string)($author->getEmail() ?? '');

        $subjectText = $msg->getSubject() ?: '(no subject)';
        $bodyText    = $msg->getMessage() ?: '';

        // Snippet
        $snippet = trim($bodyText);
        if ($snippet === '') {
            $snippet = '(No body text – only subject)';
        } elseif (function_exists('mb_strlen') && mb_strlen($snippet) > 260) {
            $snippet = mb_substr($snippet, 0, 260) . '…';
        } elseif (!function_exists('mb_strlen') && strlen($snippet) > 260) {
            $snippet = substr($snippet, 0, 260) . '…';
        }

        $emailSubject = sprintf(
            'New message in %s conversation: %s',
            $projectName,
            $subjectText
        );

        // ---- Render & send ----
        foreach (array_keys($emails) as $toEmail) {
            try {
                $html = $this->renderer->render('emails/new_message_notification', [
                    'projectName'      => $projectName,
                    'projectUrl'       => $conversationUrl,   // in this context: link to the conversation
                    'authorName'       => $authorName,
                    'authorEmail'      => $authorEmail,
                    'subject'          => $subjectText,
                    'snippet'          => $snippet,
                    'recipientEmail'   => $toEmail,
                ]);

                $this->mail->sendSimple(
                    $toEmail,
                    $emailSubject,
                    $html,
                    null,
                    null,
                    null,
                    false,
                    [
                        'tags' => ['conversation_message', 'projects'],
                        'metadata' => [
                            'conversationId'   => $conv->getId(),
                            'conversationHash' => $conversationHash,
                            'projectId'        => $project?->getId(),
                            'projectHash'      => $projectHash,
                            'messageId'        => $msg->getId(),
                            'authorId'         => $authorId,
                            'recipient'        => $toEmail,
                        ],
                    ]
                );
            } catch (\Throwable $e) {
                error_log('[CONV][MSG][NOTIFY][ERR] ' . $e->getMessage());
                // don't break loop; best-effort only
            }
        }
    }

    private function sendConversationCreatedNotification(Conversation $conv, User $creator): void
    {
        $project = $conv->getProject();
        $creatorId = (int)($creator->getId() ?? 0);

        // Participants
        $participants = [];
        if (method_exists($conv, 'getUsers')) {
            foreach ($conv->getUsers() as $u) {
                if ($u instanceof User) {
                    $participants[] = $u;
                }
            }
        }

        if (empty($participants)) {
            return;
        }

        $emails = [];
        foreach ($participants as $u) {
            if (!$u instanceof User) {
                continue;
            }
            $uid   = (int)($u->getId() ?? 0);
            $email = trim((string)($u->getEmail() ?? ''));

            // Exclude creator
            if ($uid === $creatorId) {
                continue;
            }
            if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
                continue;
            }
            $emails[$email] = true;
        }

        if (empty($emails)) {
            return;
        }

        $frontendBase = rtrim(
            (string)(getenv('FRONTEND_BASE_URL') ?: 'https://monkeysraiser.com'),
            '/'
        );

        $projectName = $project ? (string)($project->getName() ?? 'a project') : 'a project';
        $conversationHash = (string)($conv->getHash() ?? '');
        $conversationUrl  = $frontendBase . '/dashboard/messages?c=' . rawurlencode($conversationHash);

        $subject      = method_exists($conv, 'getSubject') ? ($conv->getSubject() ?? 'New conversation') : 'New conversation';
        $creatorName  = trim((string)($creator->getFullName() ?? '')) ?: (string)($creator->getEmail() ?? 'A MonkeysRaiser user');
        $creatorEmail = (string)($creator->getEmail() ?? '');

        $emailSubject = sprintf(
            '%s started a new conversation about %s',
            $creatorName,
            $projectName
        );

        foreach (array_keys($emails) as $toEmail) {
            try {
                $html = $this->renderer->render('emails/new_conversation_created', [
                    'projectName'      => $projectName,
                    'conversationUrl'  => $conversationUrl,
                    'creatorName'      => $creatorName,
                    'creatorEmail'     => $creatorEmail,
                    'subject'          => $subject,
                    'recipientEmail'   => $toEmail,
                ]);

                $this->mail->sendSimple(
                    $toEmail,
                    $emailSubject,
                    $html,
                    null,
                    null,
                    null,
                    false,
                    [
                        'tags' => ['conversation_created', 'projects'],
                        'metadata' => [
                            'conversationId'   => $conv->getId(),
                            'conversationHash' => $conversationHash,
                            'projectId'        => $project?->getId(),
                            'projectHash'      => $project?->getHash(),
                            'creatorId'        => $creatorId,
                            'recipient'        => $toEmail,
                        ],
                    ]
                );
            } catch (\Throwable $e) {
                error_log('[CONV][CREATE][NOTIFY][ERR] ' . $e->getMessage());
            }
        }
    }

}
