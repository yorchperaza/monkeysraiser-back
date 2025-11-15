<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\User;
use MonkeysLegion\Http\Message\JsonResponse;
use MonkeysLegion\Repository\EntityRepository;
use MonkeysLegion\Repository\RepositoryFactory;
use MonkeysLegion\Router\Attributes\Route;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\UploadedFileInterface;
use App\Service\MonkeysMailService;
use MonkeysLegion\Template\Renderer;
use RuntimeException;

final class SupportController
{
    private EntityRepository $users;

    public function __construct(
        private RepositoryFactory $repos,
        private MonkeysMailService $mail,
        private Renderer $renderer,
    ) {
        $this->users = $this->repos->getRepository(User::class);
    }

    /**
     * POST /support
     *
     * Form-data:
     *  - subject (string, required)
     *  - description (string, required)
     *  - email (string, optional if user has token)
     *  - attachments[] (files, optional)
     *
     * Always returns 202 on success (even if user is guest).
     *
     * @throws \JsonException
     * @throws \ReflectionException
     */
    #[Route(methods: 'POST', path: '/support')]
    public function create(ServerRequestInterface $request): JsonResponse
    {
        error_log('[SUPPORT][CREATE] Hit /support endpoint');

        $uri    = (string) $request->getUri();
        $method = $request->getMethod();
        error_log("[SUPPORT][CREATE] Method={$method} URI={$uri}");

        $body = $request->getParsedBody() ?? [];
        error_log('[SUPPORT][CREATE] Parsed body=' . json_encode([
                'keys'    => array_keys((array) $body),
                'subject' => $body['subject'] ?? null,
                'email'   => $body['email'] ?? null,
            ]));

        $subject     = trim((string)($body['subject'] ?? ''));
        $description = trim((string)($body['description'] ?? ''));
        $guestEmail  = trim((string)($body['email'] ?? ''));

        if ($subject === '' || $description === '') {
            error_log("[SUPPORT][CREATE][ERR] Missing subject/description. subject='{$subject}', desc_length=" . strlen($description));
            throw new RuntimeException('subject and description are required', 400);
        }

        // Resolve reporter from auth token (if any)
        $userId        = (int) $request->getAttribute('user_id', 0);
        error_log("[SUPPORT][CREATE] user_id attribute={$userId}");

        $reporterName  = null;
        $reporterEmail = $guestEmail !== '' ? $guestEmail : null;
        $user          = null;

        if ($userId > 0) {
            /** @var User|null $user */
            $user = $this->users->find($userId);
            if ($user) {
                error_log("[SUPPORT][CREATE] Authenticated user found. id={$user->getId()}, email=" . $user->getEmail());
                $reporterName  = $user->getFullName() ?: null;
                $reporterEmail = $user->getEmail() ?: $reporterEmail;
            } else {
                error_log("[SUPPORT][CREATE][WARN] user_id={$userId} but user not found in DB");
            }
        } else {
            error_log('[SUPPORT][CREATE] No user_id (guest or token missing)');
        }

        if (!$reporterEmail) {
            error_log('[SUPPORT][CREATE][ERR] reporterEmail missing after resolving guestEmail + user.email');
            throw new RuntimeException('email is required', 400);
        }

        // ---------- Collect uploaded files (attachments[]) ----------
        $uploaded           = $request->getUploadedFiles();
        $attachmentsPayload = [];   // for MonkeysMail (filename/content/contentType)
        $attachmentsForUi   = [];   // for template listing

        error_log('[SUPPORT][CREATE] Uploaded files keys=' . json_encode(array_keys($uploaded)));

        try {
            if (!isset($uploaded['attachments'])) {
                error_log('[SUPPORT][CREATE] No attachments key present in uploaded files.');
            } else {
                $files = $uploaded['attachments'];
                error_log('[SUPPORT][CREATE] attachments raw type=' . get_debug_type($files));

                $totalBytes = 0;

                // CASE 1: Native PHP multiple-files structure:
                // $_FILES['attachments'] = [
                //   'name'     => [...],
                //   'type'     => [...],
                //   'tmp_name' => [...],
                //   'error'    => [...],
                //   'size'     => [...],
                // ]
                if (is_array($files)
                    && isset($files['name'], $files['type'], $files['tmp_name'], $files['error'], $files['size'])
                ) {
                    $names    = (array) $files['name'];
                    $types    = (array) $files['type'];
                    $tmpNames = (array) $files['tmp_name'];
                    $errors   = (array) $files['error'];
                    $sizes    = (array) $files['size'];

                    $count = count($names);
                    error_log("[SUPPORT][CREATE] attachments PHP-FILES style count={$count}");

                    for ($i = 0; $i < $count; $i++) {
                        $err = (int)($errors[$i] ?? \UPLOAD_ERR_NO_FILE);
                        if ($err === \UPLOAD_ERR_NO_FILE) {
                            error_log("[SUPPORT][CREATE][ATT][SKIP] index={$i} no file (UPLOAD_ERR_NO_FILE)");
                            continue;
                        }
                        if ($err !== \UPLOAD_ERR_OK) {
                            error_log("[SUPPORT][CREATE][ATT][SKIP] index={$i} upload error={$err}");
                            continue;
                        }

                        $size = (int)($sizes[$i] ?? 0);
                        $name = (string)($names[$i] ?? ('attachment-' . ($i + 1)));
                        $type = (string)($types[$i] ?? 'application/octet-stream');
                        $tmp  = (string)($tmpNames[$i] ?? '');

                        if ($size > 10 * 1024 * 1024) {
                            throw new RuntimeException('Each attachment must be ≤ 10 MB', 400);
                        }
                        $totalBytes += $size;
                        if ($totalBytes > 20 * 1024 * 1024) {
                            throw new RuntimeException('Total attachments size must be ≤ 20 MB', 400);
                        }

                        if ($tmp === '' || !is_file($tmp)) {
                            error_log("[SUPPORT][CREATE][ATT][SKIP] index={$i} tmp file '{$tmp}' not found");
                            continue;
                        }

                        $binary = (string) file_get_contents($tmp);
                        error_log("[SUPPORT][CREATE][ATT] index={$i} '{$name}' ({$type}, {$size} bytes)");

                        // For MonkeysMail: base64, per docs (contentType camelCase)
                        $attachmentsPayload[] = [
                            'filename'    => $name,
                            'content'     => base64_encode($binary),
                            'contentType' => $type,
                        ];

                        // For HTML template
                        $attachmentsForUi[] = [
                            'name'        => $name,
                            'contentType' => $type,
                            'sizeBytes'   => $size,
                        ];
                    }
                }
                // CASE 2: (future-proof) PSR-7 UploadedFileInterface or array of them
                elseif ($files instanceof UploadedFileInterface || is_array($files)) {
                    $fileList   = $files instanceof UploadedFileInterface ? [$files] : $files;
                    $totalBytes = 0;

                    foreach ($fileList as $idx => $file) {
                        if (!$file instanceof UploadedFileInterface) {
                            error_log('[SUPPORT][CREATE][ATT][WARN] Non-file element in uploaded list: ' . get_debug_type($file));
                            continue;
                        }

                        if ($file->getError() === \UPLOAD_ERR_NO_FILE) {
                            error_log("[SUPPORT][CREATE][ATT][SKIP] single index={$idx} no file");
                            continue;
                        }
                        if ($file->getError() !== \UPLOAD_ERR_OK) {
                            error_log("[SUPPORT][CREATE][ATT][SKIP] single index={$idx} upload error={$file->getError()}");
                            continue;
                        }

                        $size = (int)($file->getSize() ?? 0);
                        if ($size > 10 * 1024 * 1024) {
                            throw new RuntimeException('Each attachment must be ≤ 10 MB', 400);
                        }
                        $totalBytes += $size;
                        if ($totalBytes > 20 * 1024 * 1024) {
                            throw new RuntimeException('Total attachments size must be ≤ 20 MB', 400);
                        }

                        $name = $file->getClientFilename() ?: ('attachment-' . ($idx + 1));
                        $type = $file->getClientMediaType() ?: 'application/octet-stream';

                        $stream = $file->getStream();
                        if (method_exists($stream, 'rewind')) {
                            $stream->rewind();
                        }
                        $binary = (string) $stream->getContents();

                        $attachmentsPayload[] = [
                            'filename'    => $name,
                            'content'     => base64_encode($binary),
                            'contentType' => $type,
                        ];

                        $attachmentsForUi[] = [
                            'name'        => $name,
                            'contentType' => $type,
                            'sizeBytes'   => $size,
                        ];
                    }
                } else {
                    error_log('[SUPPORT][CREATE][ATT][WARN] attachments has unexpected shape: ' . get_debug_type($files));
                }

                error_log('[SUPPORT][CREATE] Final processed attachments=' . count($attachmentsForUi));
            }
        } catch (\Throwable $e) {
            error_log('[SUPPORT][ATT][ERR] ' . $e->getMessage());
            error_log('[SUPPORT][ATT][TRACE] ' . $e->getTraceAsString());
            throw new RuntimeException('Unable to process attachments', 500);
        }

        $toAddress = 'yorch.peraza@gmail.com'; // <— your final destination
        error_log("[SUPPORT][CREATE] Preparing email to {$toAddress}");

        try {
            error_log('[SUPPORT][CREATE] Rendering template emails/support-request');
            $html = $this->renderer->render('emails/support-request', [
                'subjectLine'    => $subject,
                'description'    => $description,
                'reporterEmail'  => $reporterEmail,
                'reporterName'   => $reporterName,
                'userId'         => $user?->getId(),
                'hasAttachments' => count($attachmentsForUi) > 0,
                'attachments'    => $attachmentsForUi,
            ]);
            error_log('[SUPPORT][CREATE] Template rendered OK (len=' . strlen($html) . ')');

            // ---- Build extra payload for MonkeysMail ----
            $extra = [
                'tags'     => ['support', 'contact'],
                'metadata' => [
                    'reporterEmail' => $reporterEmail,
                    'reporterName'  => $reporterName,
                    'userId'        => $user?->getId(),
                ],
            ];

            // ✅ Attachments for MonkeysMail /messages/send
            if (!empty($attachmentsPayload)) {
                $extra['attachments'] = $attachmentsPayload;
                error_log('[SUPPORT][CREATE] Adding ' . count($attachmentsPayload) . ' attachments to MonkeysMail payload');
            } else {
                error_log('[SUPPORT][CREATE] No attachments to include in MonkeysMail payload');
            }

            error_log('[SUPPORT][CREATE] Calling MonkeysMailService::sendSimple()');
            $this->mail->sendSimple(
                $toAddress,
                '[Support] ' . $subject,
                $html,
                null,   // text
                null,   // fromEmail (ignored)
                null,   // fromName  (ignored)
                false,  // async
                $extra  // includes tags, metadata, attachments
            );
            error_log('[SUPPORT][CREATE] sendSimple() finished without exception');
        } catch (\Throwable $e) {
            error_log('[SUPPORT][EMAIL][ERR] ' . $e->getMessage());
            error_log('[SUPPORT][EMAIL][TRACE] ' . $e->getTraceAsString());
            throw new RuntimeException('Unable to send support request', 500);
        }

        error_log('[SUPPORT][CREATE] Returning 202 OK to client');
        return new JsonResponse(['status' => 'ok'], 202);
    }

    /**
     * Flatten nested uploaded files into a simple list of UploadedFileInterface.
     *
     * @param mixed $node
     * @return UploadedFileInterface[]
     */
    private function flattenUploads(mixed $node): array
    {
        $out   = [];
        $stack = [$node];

        while (!empty($stack)) {
            $curr = array_pop($stack);
            if ($curr === null) {
                continue;
            }

            if ($curr instanceof UploadedFileInterface) {
                $out[] = $curr;
                continue;
            }

            if (is_array($curr)) {
                foreach ($curr as $v) {
                    $stack[] = $v;
                }
                continue;
            }

            // Ignore scalars/objects that aren't file instances
            error_log('[SUPPORT][CREATE][ATT][FLATTEN] Ignoring non-file value type=' . get_debug_type($curr));
        }

        return $out;
    }
}
