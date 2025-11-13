<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\Media;
use App\Entity\Project;
use App\Entity\User;
use MonkeysLegion\Http\Message\JsonResponse;
use MonkeysLegion\Repository\EntityRepository;
use MonkeysLegion\Repository\RepositoryFactory;
use MonkeysLegion\Router\Attributes\Route;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\UploadedFileInterface;
use Random\RandomException;
use RuntimeException;

final class MediaController
{
    private EntityRepository $projects;
    private EntityRepository $media;
    private EntityRepository $users;

    public function __construct(
        private RepositoryFactory $repos,
    ) {
        $this->projects = $this->repos->getRepository(Project::class);
        $this->media    = $this->repos->getRepository(Media::class);
        $this->users    = $this->repos->getRepository(User::class);
    }

    /**
     * POST /projects/{hash}/media
     *
     * Upload ONE file and attach it to a "slot" on the given project.
     *
     * multipart/form-data:
     *   slot: "logo" | "banner" | "pitchDeck" | "gallery"
     *   file: binary
     *
     * Auth required.
     *
     * Response 200:
     * {
     *   "slot": "logo",
     *   "media": {
     *     "id": 123,
     *     "hash": "a1b2c3d4e5f6a7b8",
     *     "url": "/uploads/abc123.png",
     *     "type": "image/png"
     *   },
     *   "project": {
     *     "id": 42,
     *     "hash": "p9c8d7e6f5a4b3c2",
     *     "name": "ColibriV"
     *   }
     * }
     *
     * @throws \ReflectionException
     * @throws RandomException
     * @throws \JsonException
     */
    #[Route(methods: 'POST', path: '/projects/{hash}/media')]
    public function uploadProjectMedia(ServerRequestInterface $request): JsonResponse
    {
        // 1. auth user
        $userId = (int)$request->getAttribute('user_id', 0);
        if ($userId <= 0) {
            throw new RuntimeException('Unauthorized', 401);
        }

        /** @var ?User $actor */
        $actor = $this->users->find($userId);
        if (!$actor) {
            throw new RuntimeException('Unauthorized', 401);
        }

        // 2. resolve project by hash
        $hash = $request->getAttribute('hash');
        if (!is_string($hash) || $hash === '') {
            throw new RuntimeException('Invalid project identifier', 400);
        }

        /** @var ?Project $project */
        $project = $this->projects->findOneBy(['hash' => $hash]);
        if (!$project) {
            throw new RuntimeException('Project not found', 404);
        }

        // 3. ensure the actor can edit this project
        $this->assertUserCanEditProject($actor, $project);

        // 4. do the upload + attach
        return $this->handleUpload($request, $project);
    }

    /**
     * GET /media/{hash}
     *
     * Public lookup of ONE Media blob by its hash.
     * No auth required. (If you want to lock down pitch decks later,
     * you could add perm logic based on slot/type.)
     *
     * Response 200:
     * {
     *   "id": 99,
     *   "hash": "abc123...",
     *   "url": "/uploads/foo.png",
     *   "type": "image/png",
     *   "project": { "id": 42, "hash": "...", "name": "ColibriV" },
     *   "ownerUser": { "id": 7, "fullName": "Jane Founder" }
     * }
     *
     * @throws \ReflectionException
     * @throws \JsonException
     */
    #[Route(methods: 'GET', path: '/media/{hash}')]
    public function showByHash(ServerRequestInterface $request): JsonResponse
    {
        $hash = $request->getAttribute('hash');
        if (!is_string($hash) || $hash === '') {
            throw new RuntimeException('Invalid media identifier', 400);
        }

        /** @var ?Media $media */
        $media = $this->media->findOneBy(['hash' => $hash]);
        if (!$media) {
            throw new RuntimeException('Media not found', 404);
        }

        // figure out which project (if any) this media is attached to
        $linkedProject = $media->getProjectLogo()
            ?? $media->getProjectBanner()
            ?? $media->getProjectPitchDeck();

        if (!$linkedProject) {
            $galleryProjects = $media->getProjectGallery();
            if (!empty($galleryProjects) && $galleryProjects[0] instanceof Project) {
                $linkedProject = $galleryProjects[0];
            }
        }

        $projectPayload = null;
        if ($linkedProject instanceof Project) {
            $projectPayload = [
                'id'   => $linkedProject->getId(),
                'hash' => $linkedProject->getHash(),
                'name' => $linkedProject->getName(),
            ];
        }

        // maybe it's user profile media (avatar/banner)
        $linkedUser = $media->getUserPicture() ?? $media->getUserBanner();
        $userPayload = null;
        if ($linkedUser instanceof User) {
            $userPayload = [
                'id'       => $linkedUser->getId(),
                'fullName' => $linkedUser->getFullName(),
            ];
        }

        return new JsonResponse([
            'id'   => $media->getId(),
            'hash' => $media->getHash(),
            'url'  => $media->getUrl(),
            'type' => $media->getType(),

            'project'   => $projectPayload,
            'ownerUser' => $userPayload,
        ], 200);
    }

    /**
     * -------------------------------------------------
     * Internal helpers
     * -------------------------------------------------
     */

    /**
     * Check edit permission:
     * user must be project author OR in project->getUsers()
     */
    private function assertUserCanEditProject(User $actor, Project $project): void
    {
        $isAuthor = $project->getAuthor()
            && $project->getAuthor()->getId() === $actor->getId();

        $isCollaborator = false;
        foreach ($project->getUsers() as $u) {
            if ($u instanceof User && $u->getId() === $actor->getId()) {
                $isCollaborator = true;
                break;
            }
        }

        if (!$isAuthor && !$isCollaborator) {
            throw new RuntimeException('Forbidden', 403);
        }
    }

    /**
     * Core upload logic:
     * - read multipart/form-data
     * - persist file to disk
     * - create Media row with hash
     * - attach Media to correct slot on Project
     *
     * @throws RandomException
     * @throws \ReflectionException
     * @throws \JsonException
     */
    private function handleUpload(ServerRequestInterface $request, Project $project): JsonResponse
    {
        // read form fields
        $parsedBody = $request->getParsedBody();
        $slot = isset($parsedBody['slot']) ? trim((string)$parsedBody['slot']) : '';
        if ($slot === '') {
            throw new RuntimeException('slot is required', 400);
        }

        $uploadedFiles = $request->getUploadedFiles();
        if (
            !isset($uploadedFiles['file']) ||
            !$uploadedFiles['file'] instanceof UploadedFileInterface
        ) {
            throw new RuntimeException('file is required', 400);
        }

        /** @var UploadedFileInterface $file */
        $file = $uploadedFiles['file'];

        if ($file->getError() !== UPLOAD_ERR_OK) {
            throw new RuntimeException('Upload failed', 400);
        }

        // build filename
        $clientFilename = $file->getClientFilename() ?? 'upload.bin';
        $ext      = pathinfo($clientFilename, PATHINFO_EXTENSION);
        $mimeType = $file->getClientMediaType() ?? 'application/octet-stream';

        $randomName = bin2hex(random_bytes(8));
        $safeExt    = $ext !== '' ? ('.' . preg_replace('/[^a-zA-Z0-9]/', '', $ext)) : '';
        $finalName  = $randomName . $safeExt;

        // ensure upload dir
        $uploadDir  = __DIR__ . '/../../public/uploads';
        if (!is_dir($uploadDir)) {
            @mkdir($uploadDir, 0775, true);
        }

        $absolutePath = $uploadDir . '/' . $finalName;
        $publicUrl    = '/uploads/' . $finalName;

        // move file
        $file->moveTo($absolutePath);

        // create Media entity
        $mediaHash = bin2hex(random_bytes(8));
        $media = new Media();
        $media
            ->setUrl($publicUrl)
            ->setType($mimeType)
            ->setHash($mediaHash);

        $this->media->save($media);

        // attach to the right slot
        switch ($slot) {
            case 'logo':
                $project->setLogo($media);
                break;

            case 'banner':
                $project->setBanner($media);
                break;

            case 'pitchDeck':
                $project->setPitchDeck($media);
                break;

            case 'gallery':
                $project->setGallery($media);
                break;

            default:
                throw new RuntimeException('Invalid slot. Use logo|banner|pitchDeck|gallery.', 400);
        }

        // persist the project update linking that media
        $this->projects->save($project);

        // response
        return new JsonResponse([
            'slot'  => $slot,
            'media' => [
                'id'   => $media->getId(),
                'hash' => $media->getHash(),
                'url'  => $media->getUrl(),
                'type' => $media->getType(),
            ],
            'project' => [
                'id'   => $project->getId(),
                'hash' => $project->getHash(),
                'name' => $project->getName(),
            ],
        ], 200);
    }
}
