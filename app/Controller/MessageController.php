<?php

declare(strict_types=1);

namespace App\Controller;

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
use RuntimeException;
use App\Service\MonkeysMailService;
use MonkeysLegion\Template\Renderer;

final class MessageController
{
    private EntityRepository $messages;
    private EntityRepository $projects;
    private EntityRepository $users;
    private EntityRepository $mediaRepo;

    public function __construct(
        private RepositoryFactory $repos,
        private MonkeysMailService $mail,
        private Renderer $renderer,
    )
    {
        $this->messages  = $this->repos->getRepository(Message::class);
        $this->projects  = $this->repos->getRepository(Project::class);
        $this->users     = $this->repos->getRepository(User::class);
        $this->mediaRepo = $this->repos->getRepository(Media::class);
    }

    /**
     * POST /projects/{hash}/messages
     * Create a message under a project.
     *
     * Body: JSON or multipart with "data" JSON field.
     *  - subject: string
     *  - message: string
     *
     * File uploads (optional):
     *  - attachments OR attachments[]  (one or many files)
     */
    #[Route(methods: 'POST', path: '/projects/{hash}/messages')]
    public function create(ServerRequestInterface $request): JsonResponse
    {
        // --- Auth ---
        $uid = (int) $request->getAttribute('user_id', 0);
        if ($uid <= 0) {
            throw new RuntimeException('Unauthorized', 401);
        }
        /** @var User|null $author */
        $author = $this->users->find($uid);
        if (!$author) {
            throw new RuntimeException('Unauthorized', 401);
        }

        // --- Project ---
        $hash = (string) $request->getAttribute('hash');
        if ($hash === '') {
            throw new RuntimeException('Invalid project identifier', 400);
        }
        /** @var Project|null $project */
        $project = $this->projects->findOneBy(['hash' => $hash]);
        if (!$project) {
            throw new RuntimeException('Project not found', 404);
        }

        // --- Parse body (JSON or multipart with "data") ---
        $parsed = $request->getParsedBody();
        $isMultipart = is_array($parsed) && array_key_exists('data', $parsed);
        $raw = $isMultipart ? (string)($parsed['data'] ?? '') : (string)$request->getBody();
        $data = $raw !== '' ? json_decode($raw, true, 512, JSON_THROW_ON_ERROR) : [];
        if (!is_array($data)) { $data = []; }

        $subject = isset($data['subject']) ? trim((string)$data['subject']) : null;
        $body    = isset($data['message']) ? trim((string)$data['message']) : null;

        if (($subject === null || $subject === '') && ($body === null || $body === '')) {
            throw new RuntimeException('Message subject or message text is required', 400);
        }

        // --- Build entity ---
        $msg = new Message();
        $msg->setAuthor($author)
            ->setProject($project)
            ->setSubject($subject ?: null)
            ->setMessage($body ?: null);

        $this->messages->save($msg);

        // --- Attachments (multipart only) ---
        if ($isMultipart) {
            $this->processAttachments($request, $msg, $author);
            $this->messages->save($msg);
        }

        $this->sendNewMessageNotification($msg);

        return new JsonResponse($this->serializeMessage($msg), 201);
    }

    /**
     * GET /projects/{hash}/messages
     *
     * Query:
     *  - page, perPage
     *  - q (search in subject/message, AND across words)
     */
    #[Route(methods: 'GET', path: '/projects/{hash}/messages')]
    public function listByProject(ServerRequestInterface $request): JsonResponse
    {
        $hash = (string) $request->getAttribute('hash');
        if ($hash === '') {
            throw new RuntimeException('Invalid project identifier', 400);
        }
        /** @var Project|null $project */
        $project = $this->projects->findOneBy(['hash' => $hash]);
        if (!$project) {
            throw new RuntimeException('Project not found', 404);
        }

        $q = $request->getQueryParams();
        $page    = max(1, (int)($q['page'] ?? 1));
        $perPage = (int)($q['perPage'] ?? 24);
        if ($perPage <= 0)  { $perPage = 24; }
        if ($perPage > 100) { $perPage = 100; }
        $offset  = ($page - 1) * $perPage;

        $base = (clone $this->messages->qb)
            ->from('message', 'm')
            ->where('m.project_id', '=', (int)$project->getId());

        // Search
        if (!empty($q['q'])) {
            $needle = trim((string)$q['q']);
            $words = array_values(array_filter(preg_split('/\s+/', mb_strtolower($needle)) ?: []));
            if ($words) {
                $fields = [
                    'LOWER(m.subject)',
                    'LOWER(m.message)',
                ];
                $andClauses = [];
                $params     = [];
                foreach ($words as $w) {
                    $like = '%' . $w . '%';
                    $orParts = [];
                    foreach ($fields as $f) {
                        $orParts[] = "$f LIKE ?";
                        $params[]  = $like;
                    }
                    $andClauses[] = '(' . implode(' OR ', $orParts) . ')';
                }
                $base->whereRaw('(' . implode(' AND ', $andClauses) . ')', $params);
            }
        }

        // Totals
        $total = $base->duplicate()->count();
        $pages = (int)max(1, (int)ceil($total / max(1, $perPage)));

        // Page rows (newest first)
        $rows = $base->duplicate()
            ->select('m.id AS id')
            ->orderBy('m.id', 'DESC')
            ->limit($perPage)
            ->offset($offset)
            ->fetchAll();

        $items = [];
        foreach ($rows as $r) {
            $mid = (int)($r['id'] ?? 0);
            if ($mid <= 0) { continue; }
            /** @var Message|null $m */
            $m = $this->messages->find($mid);
            if ($m instanceof Message) {
                $items[] = $this->serializeMessage($m);
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
     * GET /messages/{id}
     */
    #[Route(methods: 'GET', path: '/messages/{id}')]
    public function show(ServerRequestInterface $request): JsonResponse
    {
        $id = (int) $request->getAttribute('id');
        if ($id <= 0) {
            throw new RuntimeException('Invalid message id', 400);
        }
        /** @var Message|null $msg */
        $msg = $this->messages->find($id);
        if (!$msg) {
            throw new RuntimeException('Message not found', 404);
        }
        return new JsonResponse($this->serializeMessage($msg), 200);
    }

    /**
     * POST /messages/{id}
     * Update subject/message; manage attachments.
     *
     * JSON or multipart with "data".
     *  - subject?: string|null
     *  - message?: string|null
     *  - removeAttachmentIds?: int[]   // remove these Media ids (only those linked to this message)
     *
     * File uploads:
     *  - attachments OR attachments[]
     */
    #[Route(methods: 'POST', path: '/messages/{id}')]
    public function update(ServerRequestInterface $request): JsonResponse
    {
        // --- Auth ---
        $uid = (int) $request->getAttribute('user_id', 0);
        if ($uid <= 0) {
            throw new RuntimeException('Unauthorized', 401);
        }
        /** @var User|null $actor */
        $actor = $this->users->find($uid);
        if (!$actor) {
            throw new RuntimeException('Unauthorized', 401);
        }

        $id = (int) $request->getAttribute('id');
        if ($id <= 0) {
            throw new RuntimeException('Invalid message id', 400);
        }
        /** @var Message|null $msg */
        $msg = $this->messages->find($id);
        if (!$msg) {
            throw new RuntimeException('Message not found', 404);
        }

        // --- AuthZ: only author can edit (tweak if you want contributors/owner rule) ---
        $isAuthor = ((int)($msg->getAuthor()?->getId() ?? 0)) === (int)$actor->getId();
        if (!$isAuthor) {
            throw new RuntimeException('Forbidden', 403);
        }

        // --- Parse body ---
        $parsed = $request->getParsedBody();
        $isMultipart = is_array($parsed) && array_key_exists('data', $parsed);
        $raw = $isMultipart ? (string)($parsed['data'] ?? '') : (string)$request->getBody();
        $data = $raw !== '' ? json_decode($raw, true, 512, JSON_THROW_ON_ERROR) : [];
        if (!is_array($data)) { $data = []; }

        if (array_key_exists('subject', $data)) {
            $sub = trim((string)$data['subject']);
            $msg->setSubject($sub !== '' ? $sub : null);
        }
        if (array_key_exists('message', $data)) {
            $txt = trim((string)$data['message']);
            $msg->setMessage($txt !== '' ? $txt : null);
        }

        // --- Remove attachments by id (only those belonging to this message) ---
        if (!empty($data['removeAttachmentIds']) && is_array($data['removeAttachmentIds'])) {
            $ids = array_values(array_unique(array_map('intval', $data['removeAttachmentIds'])));
            foreach ($ids as $mid) {
                if ($mid <= 0) continue;
                /** @var Media|null $m */
                $m = $this->mediaRepo->find($mid);
                if ($m instanceof Media) {
                    // must belong to this message
                    $ownerId = method_exists($m, 'getMessage') && $m->getMessage()
                        ? (int)$m->getMessage()->getId()
                        : 0;
                    if ($ownerId === (int)$msg->getId()) {
                        // detach: Media->message = null and Message->media remove
                        if (method_exists($m, 'setMessage')) {
                            $m->setMessage(null);
                        }
                        $this->mediaRepo->save($m);

                        // remove from message collection if present
                        foreach ($msg->getMedia() as $mm) {
                            if ($mm instanceof Media && (int)$mm->getId() === (int)$m->getId()) {
                                $msg->removeMedia($mm);
                                break;
                            }
                        }
                    }
                }
            }
        }

        $this->messages->save($msg);

        // --- New attachments (multipart only) ---
        if ($isMultipart) {
            $this->processAttachments($request, $msg, $actor);
            $this->messages->save($msg);
        }

        return new JsonResponse($this->serializeMessage($msg), 200);
    }

    /**
     * DELETE /messages/{id}
     */
    #[Route(methods: 'DELETE', path: '/messages/{id}')]
    public function delete(ServerRequestInterface $request): JsonResponse
    {
        // --- Auth ---
        $uid = (int) $request->getAttribute('user_id', 0);
        if ($uid <= 0) {
            throw new RuntimeException('Unauthorized', 401);
        }
        /** @var User|null $actor */
        $actor = $this->users->find($uid);
        if (!$actor) {
            throw new RuntimeException('Unauthorized', 401);
        }

        $id = (int) $request->getAttribute('id');
        if ($id <= 0) {
            throw new RuntimeException('Invalid message id', 400);
        }
        /** @var Message|null $msg */
        $msg = $this->messages->find($id);
        if (!$msg) {
            throw new RuntimeException('Message not found', 404);
        }

        // AuthZ
        $isAuthor = ((int)($msg->getAuthor()?->getId() ?? 0)) === (int)$actor->getId();
        if (!$isAuthor) {
            throw new RuntimeException('Forbidden', 403);
        }

        // Optional: detach attachments first (keep files on disk or add file deletion here)
        foreach ($msg->getMedia() as $m) {
            if ($m instanceof Media && method_exists($m, 'setMessage')) {
                $m->setMessage(null);
                $this->mediaRepo->save($m);
            }
        }

        $this->messages->delete($msg);

        return new JsonResponse(['ok' => true], 200);
    }

    // -------------------------
    // Helpers
    // -------------------------

    private function serializeMessage(Message $m): array
    {
        $author = $m->getAuthor();
        $project = $m->getProject();

        return [
            'id'      => $m->getId(),
            'subject' => $m->getSubject(),
            'message' => $m->getMessage(),
            'author'  => $author ? $this->serializeUserLite($author) : null,
            'project' => $project ? [
                'id'   => $project->getId(),
                'hash' => $project->getHash(),
                'name' => $project->getName(),
            ] : null,
            'attachments' => array_values(array_map(
                fn ($med) => $this->serializeMedia($med),
                $m->getMedia() ?? []
            )),
        ];
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

    /**
     * Handle "attachments" uploads and link to Message.
     */
    private function processAttachments(ServerRequestInterface $request, Message $msg, User $u): void
    {
        $uploaded = $request->getUploadedFiles();
        if (empty($uploaded)) {
            return;
        }

        $raw = $uploaded['attachments'] ?? ($uploaded['attachments[]'] ?? null);
        $files = $this->expandFileArray($raw);

        if (empty($files)) {
            return;
        }

        foreach ($files as $i => $f) {
            $norm = $this->normalizeUploadedFile($f);

            if ((int)$norm['error'] !== UPLOAD_ERR_OK) { continue; }

            // Move to public/uploads
            $media = $this->createMediaFromNormalizedFile($norm, $u);

            // Link Media -> Message (requires Media::setMessage)
            if (method_exists($media, 'setMessage')) {
                $media->setMessage($msg);
            }

            $this->mediaRepo->save($media);

            // Link Message -> Media collection
            $msg->addMedia($media);
        }
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
     * Expand a PHP multi-file array to a flat list.
     *
     * @param mixed $raw
     * @return array<int, UploadedFileInterface|array>
     */
    private function expandFileArray($raw): array
    {
        if ($raw instanceof UploadedFileInterface) {
            return [$raw];
        }
        if (is_array($raw) && isset($raw['name']) && !is_array($raw['name'])) {
            return [$raw];
        }
        if (is_array($raw)
            && isset($raw['name'], $raw['type'], $raw['tmp_name'], $raw['error'])
            && is_array($raw['name']) && is_array($raw['type']) && is_array($raw['tmp_name']) && is_array($raw['error'])) {
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

    private function createMediaFromNormalizedFile(array $norm, User $user): Media
    {
        $clientFilename = $norm['clientName'];
        $ext      = pathinfo($clientFilename, PATHINFO_EXTENSION);
        $mimeType = $norm['mime'];

        $randomName = bin2hex(random_bytes(8));
        $safeExt    = $ext !== '' ? ('.' . preg_replace('/[^a-zA-Z0-9]/', '', (string)$ext)) : '';
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

        $media = new Media();
        $media
            ->setUrl($publicUrl)
            ->setType($mimeType)
            ->setCreated(new DateTimeImmutable('now', new \DateTimeZone('UTC')))
            ->setAuthorUser($user)
            ->setHash(bin2hex(random_bytes(16)));

        return $media;
    }

    /**
     * Notify project members by email when a new message is created.
     * Uses ML template: emails/new_message_notification.ml.php
     */
    private function sendNewMessageNotification(Message $msg): void
    {
        $project = $msg->getProject();
        $author  = $msg->getAuthor();

        if (!$project || !$author) {
            return;
        }

        // ----- Collect recipients (project owner + contributors, excluding author) -----
        $recipients = [];

        // Try common "owner" accessors – adjust to your actual Project entity
        if (method_exists($project, 'getOwner') && $project->getOwner() instanceof User) {
            $recipients[] = $project->getOwner();
        } elseif (method_exists($project, 'getAuthor') && $project->getAuthor() instanceof User) {
            $recipients[] = $project->getAuthor();
        } elseif (method_exists($project, 'getUser') && $project->getUser() instanceof User) {
            $recipients[] = $project->getUser();
        }

        // Optional: contributors list
        if (method_exists($project, 'getContributors')) {
            foreach ($project->getContributors() as $u) {
                if ($u instanceof User) {
                    $recipients[] = $u;
                }
            }
        }

        // Deduplicate, exclude author, and extract emails
        $emails = [];
        $authorId = (int)($author->getId() ?? 0);

        foreach ($recipients as $u) {
            if (!$u instanceof User) {
                continue;
            }
            $uid   = (int)($u->getId() ?? 0);
            $email = trim((string)($u->getEmail() ?? ''));

            if ($uid === $authorId) {
                continue; // don't email the sender
            }
            if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
                continue;
            }
            $emails[$email] = true; // use key for dedupe
        }

        if (empty($emails)) {
            return;
        }

        // ----- Build template context -----
        $frontendBase = rtrim(
            (string)(getenv('FRONTEND_BASE_URL') ?: 'https://monkeysraiser.com'),
            '/'
        );

        $projectName = (string)($project->getName() ?? 'a project');
        $projectHash = (string)($project->getHash() ?? '');
        $projectUrl  = $frontendBase . '/projects/' . rawurlencode($projectHash) . '?tab=messages';

        $authorName  = trim((string)($author->getFullName() ?? '')) ?: (string)($author->getEmail() ?? 'A MonkeysRaiser user');
        $authorEmail = (string)($author->getEmail() ?? '');

        $subjectText = $msg->getSubject() ?: '(no subject)';
        $bodyText    = $msg->getMessage() ?: '';

        // Short snippet for email
        $snippet = trim($bodyText);
        if ($snippet === '') {
            $snippet = '(No body text – only subject)';
        } elseif (function_exists('mb_strlen') && mb_strlen($snippet) > 260) {
            $snippet = mb_substr($snippet, 0, 260) . '…';
        } elseif (!function_exists('mb_strlen') && strlen($snippet) > 260) {
            $snippet = substr($snippet, 0, 260) . '…';
        }

        $subject = sprintf('New message on %s: %s', $projectName, $subjectText);

        // ----- Render template & send -----
        foreach (array_keys($emails) as $toEmail) {
            try {
                $html = $this->renderer->render('emails/new_message_notification', [
                    'projectName'  => $projectName,
                    'projectUrl'   => $projectUrl,
                    'authorName'   => $authorName,
                    'authorEmail'  => $authorEmail,
                    'subject'      => $subjectText,
                    'snippet'      => $snippet,
                    'recipientEmail' => $toEmail,
                ]);

                $this->mail->sendSimple(
                    $toEmail,
                    $subject,
                    $html,
                    null,
                    null,
                    null,
                    false,
                    [
                        'tags' => ['message_notification', 'projects'],
                        'metadata' => [
                            'projectId'   => $project->getId(),
                            'projectHash' => $projectHash,
                            'messageId'   => $msg->getId(),
                            'authorId'    => $authorId,
                            'recipient'   => $toEmail,
                        ],
                    ]
                );
            } catch (\Throwable $e) {
                error_log('[MESSAGE][NOTIFY][ERR] ' . $e->getMessage());
                // keep looping; don't break on one failure
            }
        }
    }

}
