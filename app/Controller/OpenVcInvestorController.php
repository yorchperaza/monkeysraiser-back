<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\OpenVcInvestor;
use App\Entity\Media;
use App\Repository\OpenVcInvestorRepository;
use DateTimeImmutable;
use DateTimeZone;
use MonkeysLegion\Http\Message\JsonResponse;
use MonkeysLegion\Repository\EntityRepository;
use MonkeysLegion\Repository\RepositoryFactory;
use MonkeysLegion\Router\Attributes\Route;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\UploadedFileInterface;
use RuntimeException;
use Random\RandomException;

final class OpenVcInvestorController
{
    private OpenVcInvestorRepository $repository;
    private EntityRepository $mediaRepo;

    public function __construct(
        private RepositoryFactory $repos
    ) {
        /** @var OpenVcInvestorRepository $repo */
        $repo = $this->repos->create(OpenVcInvestorRepository::class);
        $this->repository = $repo;
        $this->mediaRepo = $this->repos->getRepository(Media::class);
    }

    /**
     * POST /open-vc-investors
     */
    #[Route(methods: 'POST', path: '/open-vc-investors')]
    public function create(ServerRequestInterface $request): JsonResponse
    {
        // Use parseData helper to handle multipart/form-data vs JSON
        [$data, $uploadedFiles] = $this->parseData($request);
        if (!is_array($data)) {
            $data = [];
        }

        $investor = new OpenVcInvestor();
        $investor->setFundName($data['fundName'] ?? '');
        
        if (isset($data['verified'])) $investor->setVerified((bool)$data['verified']);
        if (isset($data['linkedin'])) $investor->setLinkedin($data['linkedin']);
        if (isset($data['website'])) $investor->setWebsite($data['website']);
        if (isset($data['description'])) $investor->setDescription($data['description']);
        if (isset($data['valueAdd'])) $investor->setValueAdd($data['valueAdd']);
        if (isset($data['globalHq'])) $investor->setGlobalHq($data['globalHq']);
        if (isset($data['checkSizeMin'])) $investor->setCheckSizeMin((int)$data['checkSizeMin']);
        if (isset($data['checkSizeMax'])) $investor->setCheckSizeMax((int)$data['checkSizeMax']);
        if (isset($data['team'])) $investor->setTeam($data['team']);
        if (isset($data['sourcePage'])) $investor->setSourcePage($data['sourcePage']);

        // JSON fields
        if (isset($data['firmType'])) $investor->setFirmType($data['firmType']);
        if (isset($data['fundingStages'])) $investor->setFundingStages($data['fundingStages']);
        if (isset($data['targetCountries'])) $investor->setTargetCountries($data['targetCountries']);

        // Handle Logo Upload
        if (is_array($uploadedFiles) && isset($uploadedFiles['logo'])) {
            $expanded = $this->expandFileArray($uploadedFiles['logo']);
            if (!empty($expanded)) {
                $norm = $this->normalizeUploadedFile($expanded[0]);
                if ((int)$norm['error'] === UPLOAD_ERR_OK) {
                    $media = $this->createMediaFromNormalizedFile($norm, null); // null author for now or strict?
                    // Note: OpenVcInvestor doesn't enforce authorUser on Media, but Media might.
                    // If Media.authorUser is nullable, we are fine. 
                    // OpenVcInvestor.logo is OneToOne.
                    
                    // Assign inverse side if needed, or just persist media
                    $media->setOpenVcInvestorLogo($investor);
                    $this->mediaRepo->save($media);
                    
                    $investor->setLogo($media);
                }
            }
        }

        $this->repository->save($investor);

        return new JsonResponse(['id' => $investor->getId()], 201);
    }

    /**
     * DELETE /open-vc-investors/{id}
     */
    #[Route(methods: 'DELETE', path: '/open-vc-investors/{id}')]
    public function delete(ServerRequestInterface $request): JsonResponse
    {
        $id = $request->getAttribute('id');
        if (!$id) {
            throw new RuntimeException('ID required', 400);
        }

        /** @var ?OpenVcInvestor $entity */
        $entity = $this->repository->find($id);
        if (!$entity) {
            throw new RuntimeException('Investor not found', 404);
        }

        // Delete associated logo if exists
        $logo = $entity->getLogo();
        if ($logo) {
            $this->mediaRepo->delete($logo);
        }

        $this->repository->delete($entity);

        return new JsonResponse(['success' => true], 200);
    }

    /**
     * GET /open-vc-investors/{id}
     */
    #[Route(methods: 'GET', path: '/open-vc-investors/{id}')]
    public function show(ServerRequestInterface $request): JsonResponse
    {
        $id = $request->getAttribute('id');
        if (!$id) {
            throw new RuntimeException('ID required', 400);
        }

        $entity = $this->repository->find($id);
        if (!$entity) {
            throw new RuntimeException('Investor not found', 404);
        }

        // Ideally use a serializer here, but manual extracting for now matches ProjectController style lightly
        // or just rely on public props if Hydrator.extract wasn't used.
        // But since we want clean JSON, Hydrator::extract is better if accessible, or public properties.
        // Assuming simple response for now.
        
        // Using EntityRepository extract logic indirectly or manually constructing
        // Let's manually construct to be safe and explicit
        $data = [
            'id' => $entity->getId(),
            'fundName' => $entity->getFundName(),
            'verified' => $entity->isVerified(),
            'linkedin' => $entity->getLinkedin(),
            'website' => $entity->getWebsite(),
            'description' => $entity->getDescription(),
            'valueAdd' => $entity->getValueAdd(),
            'firmType' => $entity->getFirmType(),
            'globalHq' => $entity->getGlobalHq(),
            'fundingStages' => $entity->getFundingStages(),
            'checkSizeMin' => $entity->getCheckSizeMin(),
            'checkSizeMax' => $entity->getCheckSizeMax(),
            'targetCountries' => $entity->getTargetCountries(),
            'team' => $entity->getTeam(),
            'sourcePage' => $entity->getSourcePage(),
            'created' => $entity->created,
            'updated' => $entity->updated,
            'logo' => null,
        ];
        
        // Add logo if exists
        $logoId = $entity->logo_id;
        if ($logoId) {
            try {
                $freshMediaRepo = $this->repos->getRepository(Media::class);
                $refClass = new \ReflectionClass($freshMediaRepo);
                $qbProp = $refClass->getProperty('qb');
                $qbProp->setAccessible(true);
                $qb = $qbProp->getValue($freshMediaRepo);
                $pdo = $qb->pdo();
                
                $stmt = $pdo->prepare('SELECT id, url FROM media WHERE id = ?');
                $stmt->execute([$logoId]);
                $logoRow = $stmt->fetch(\PDO::FETCH_ASSOC);
                if ($logoRow) {
                    $data['logo'] = ['id' => $logoRow['id'], 'url' => $logoRow['url']];
                }
            } catch (\Throwable $e) {
                error_log("Logo fetch error: " . $e->getMessage());
            }
        }

        return new JsonResponse($data, 200);
    }

    /**
     * GET /open-vc-investors
     */
    #[Route(methods: 'GET', path: '/open-vc-investors')]
    public function search(ServerRequestInterface $request): JsonResponse
    {
        $params = $request->getQueryParams();
        $page = isset($params['page']) ? (int)$params['page'] : 1;
        $limit = isset($params['limit']) ? (int)$params['limit'] : 20;

        $filters = [];
        if (!empty($params['name'])) $filters['name'] = $params['name'];
        
        // Parse JSON array filters and extract first value for LIKE search
        if (!empty($params['targetCountries'])) {
            $decoded = json_decode($params['targetCountries'], true);
            $filters['targetCountries'] = is_array($decoded) && !empty($decoded) ? $decoded[0] : $params['targetCountries'];
        }
        if (!empty($params['firmType'])) {
            $decoded = json_decode($params['firmType'], true);
            $filters['firmType'] = is_array($decoded) && !empty($decoded) ? $decoded[0] : $params['firmType'];
        }
        if (!empty($params['fundingStages'])) {
            $decoded = json_decode($params['fundingStages'], true);
            $filters['fundingStages'] = is_array($decoded) && !empty($decoded) ? $decoded[0] : $params['fundingStages'];
        }

        try {
            error_log("OpenVcInvestorController::search - Calling repository search. Page: $page, Limit: $limit");
            $result = $this->repository->search($page, $limit, $filters);
        } catch (\Throwable $e) {
            error_log("OpenVcInvestorController::search - Exception: " . $e->getMessage());
            error_log("Stack trace: " . $e->getTraceAsString());
            throw $e;
        }

        /** @var OpenVcInvestor[] $items */
        $items = $result['items'];
        
        // Pre-load all media records using direct PDO to bypass repository findOneBy issues
        $logoIds = array_filter(array_map(fn($entity) => $entity->logo_id, $items));
        $mediaMap = [];
        
        if (!empty($logoIds)) {
            // Get PDO from repository's query builder
            $freshMediaRepo = $this->repos->getRepository(Media::class);
            try {
                // Use reflection to get the QueryBuilder's PDO
                $refClass = new \ReflectionClass($freshMediaRepo);
                $qbProp = $refClass->getProperty('qb');
                $qbProp->setAccessible(true);
                $qb = $qbProp->getValue($freshMediaRepo);
                $pdo = $qb->pdo();
                
                // Build IN clause for batch query
                $placeholders = implode(',', array_fill(0, count($logoIds), '?'));
                $sql = "SELECT id, url FROM media WHERE id IN ($placeholders)";
                $stmt = $pdo->prepare($sql);
                $stmt->execute(array_values($logoIds));
                $rows = $stmt->fetchAll(\PDO::FETCH_ASSOC);
                
                foreach ($rows as $row) {
                    $mediaMap[(int)$row['id']] = $row['url'];
                }
            } catch (\Throwable $e) {
                error_log("Media PDO query error: " . $e->getMessage());
            }
        }
        
        $mapped = array_map(function(OpenVcInvestor $entity) use ($mediaMap) {
            // Get logo URL from pre-loaded map
            $logoData = null;
            if ($entity->logo_id && isset($mediaMap[$entity->logo_id])) {
                $logoData = ['url' => $mediaMap[$entity->logo_id]];
            }
            
            return [
                'id' => $entity->getId(),
                'fundName' => $entity->getFundName(),
                'firmType' => $entity->firmType, // Raw JSON string
                'targetCountries' => $entity->targetCountries, // Raw JSON string
                'fundingStages' => $entity->fundingStages, // Raw JSON string
                'checkSizeMin' => $entity->getCheckSizeMin(),
                'checkSizeMax' => $entity->getCheckSizeMax(),
                'description' => $entity->getDescription(),
                'website' => $entity->getWebsite(),
                'logo' => $logoData,
            ];
        }, $items);

        return new JsonResponse([
            'data' => $mapped,
            'total' => $result['total'],
            'page' => $page,
            'limit' => $limit
        ], 200);
    }

    // -----------------------
    // Helpers
    // -----------------------

    private function parseData(ServerRequestInterface $request): array
    {
        $ct = strtolower($request->getHeaderLine('Content-Type') ?: '');
        $parsed = $request->getParsedBody();
        $files  = $request->getUploadedFiles();

        if (str_starts_with($ct, 'multipart/form-data')) {
            if (is_array($parsed) && array_key_exists('data', $parsed)) {
                $raw  = (string)($parsed['data'] ?? '');
                $data = json_decode($raw ?: "{}", true);
                if (!is_array($data)) $data = [];
                return [$data, is_array($files) ? $files : []];
            }

            $data = [];
            // Some clients might send fields directly without 'data' wrapper
            if (is_array($parsed)) {
                $data = $parsed; 
            }
            // If there's a specific 'data' field that is json
            if (isset($_POST['data'])) {
                $raw = (string)$_POST['data'];
                $decoded = json_decode($raw ?: "{}", true);
                if (is_array($decoded)) {
                     // Merge or replace? Let's treat 'data' as primary if present and valid JSON
                     $data = array_merge($data, $decoded);
                }
            }

            $filesOut = [];
            if (isset($_FILES) && is_array($_FILES)) {
                // Should rely on PSR-7 $files mostly, but if empty/legacy:
            }
            return [$data, $files]; // Using PSR-7 files
        }

        if (is_array($parsed) && !empty($parsed)) {
            return [$parsed, []];
        }

        $bodyStream = $request->getBody();
        $rawBody = '';
        try {
            $rawBody = (string)$bodyStream;
            if ($rawBody === '') $rawBody = $bodyStream->getContents();
        } catch (\Throwable) {}
        if ($rawBody === '') {
            $rawBody = @file_get_contents('php://input') ?: '';
        }
        $data = json_decode($rawBody ?: "{}", true);
        if (!is_array($data)) $data = [];
        return [$data, []];
    }

    private function expandFileArray($raw): array
    {
        if ($raw instanceof UploadedFileInterface) return [$raw];
        if (is_array($raw) && isset($raw['name']) && !is_array($raw['name'])) return [$raw];
        if (is_array($raw) && isset($raw['name'], $raw['type'], $raw['tmp_name'], $raw['error']) && is_array($raw['name'])) {
            $out = [];
            $count = count($raw['name']);
            for ($i = 0; $i < $count; $i++) {
                $out[] = [
                    'name'     => $raw['name'][$i]     ?? null,
                    'type'     => $raw['type'][$i]     ?? 'application/octet-stream',
                    'tmp_name' => $raw['tmp_name'][$i] ?? null,
                    'error'    => $raw['error'][$i]    ?? UPLOAD_ERR_NO_FILE,
                    'size'     => $raw['size'][$i]     ?? null,
                ];
            }
            return $out;
        }
        if (is_array($raw) && !empty($raw) && $raw[0] instanceof UploadedFileInterface) return $raw;
        return [];
    }

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
     * @throws RandomException
     */
    private function createMediaFromNormalizedFile(array $norm, ?object $user): Media
    {
        $clientFilename = $norm['clientName'] ?? 'upload.bin';
        $ext      = pathinfo($clientFilename, PATHINFO_EXTENSION);
        $mimeType = $norm['mime'] ?? 'application/octet-stream';

        $randomName = bin2hex(random_bytes(8));
        $safeExt    = $ext !== '' ? ('.' . preg_replace('/[^a-zA-Z0-9]/', '', $ext)) : '';
        $finalName  = $randomName . $safeExt;

        // Hardcoded path based on UserProfileController
        $uploadDir = __DIR__ . '/../../public/uploads';
        if (!is_dir($uploadDir)) @mkdir($uploadDir, 0775, true);

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
            ->setHash(bin2hex(random_bytes(16)));
        
        if ($user && method_exists($media, 'setAuthorUser')) {
             $media->setAuthorUser($user);
        }

        return $media;
    }
}
