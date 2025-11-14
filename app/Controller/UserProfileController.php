<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\Founder;
use App\Entity\Investor;
use App\Entity\Media;
use App\Entity\User;
use App\Entity\Role;
use DateTimeImmutable;
use MonkeysLegion\Http\Message\JsonResponse;
use MonkeysLegion\Repository\EntityRepository;
use MonkeysLegion\Repository\RepositoryFactory;
use MonkeysLegion\Router\Attributes\Route;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\UploadedFileInterface;
use RuntimeException;
use Random\RandomException;

final class UserProfileController
{
    private EntityRepository $users;
    private EntityRepository $mediaRepo;
    private EntityRepository $founders;
    private EntityRepository $investors;
    private EntityRepository $roles;

    public function __construct(
        private RepositoryFactory $repos,
    ) {
        $this->users     = $this->repos->getRepository(User::class);
        $this->mediaRepo = $this->repos->getRepository(Media::class);
        $this->founders  = $this->repos->getRepository(Founder::class);
        $this->investors = $this->repos->getRepository(Investor::class);
        $this->roles     = $this->repos->getRepository(Role::class);
    }

    /**
     * @throws \JsonException
     */
    #[Route(methods: 'GET', path: '/me')]
    public function me(ServerRequestInterface $request): JsonResponse
    {
        $user = $this->requireAuthUser($request);
        return new JsonResponse($this->serializeUser($user, true), 200);
    }

    /**
     * @throws \ReflectionException
     * @throws \JsonException
     */
    #[Route(methods: 'GET', path: '/users/{id}')]
    public function show(ServerRequestInterface $request): JsonResponse
    {
        $id = (int)$request->getAttribute('id');
        if ($id <= 0) {
            throw new RuntimeException('Invalid user id', 400);
        }

        /** @var ?User $user */
        $user = $this->users->find($id);
        if (!$user) {
            throw new RuntimeException('User not found', 404);
        }

        return new JsonResponse($this->serializeUser($user, false), 200);
    }

    /**
     * GET /profiles/{hash}
     * Returns public profile (by Founder.hash or Investor.hash).
     */
    #[Route(methods: 'GET', path: '/profiles/{hash}')]
    public function showByProfileHash(ServerRequestInterface $request): JsonResponse
    {
        $hash = (string)$request->getAttribute('hash');
        $hash = trim($hash);

        if ($hash === '' || !ctype_xdigit($hash) || strlen($hash) !== 32) {
            throw new RuntimeException('Invalid profile hash', 400);
        }

        /** @var ?Founder $founder */
        $founder = $this->founders->findOneBy(['hash' => $hash]);
        /** @var ?Investor $investor */
        $investor = $founder ? null : $this->investors->findOneBy(['hash' => $hash]);

        if (!$founder && !$investor) {
            throw new RuntimeException('Profile not found', 404);
        }

        $profileType = $founder ? 'founder' : 'investor';
        $user = $founder ? $founder->getUser() : ($investor ? $investor->getInvestor() : null);
        if (!$user instanceof User) {
            throw new RuntimeException('Related user not found', 404);
        }

        $payload = $this->serializeUser($user, false);
        if ($founder) {
            $payload['founder']  = $this->serializeFounder($founder);
            $payload['investor'] = null;
        } else {
            $payload['investor'] = $this->serializeInvestor($investor);
            $payload['founder']  = null;
        }
        $payload['profileType'] = $profileType;
        $payload['profileHash'] = $hash;

        return new JsonResponse($payload, 200);
    }

    /**
     * POST /me/upsert  (kept for compatibility)
     */
    #[Route(methods: 'POST', path: '/me/upsert')]
    public function upsertUserAndProfile(ServerRequestInterface $request): JsonResponse
    {
        $user = $this->requireAuthUser($request);
        [$data, $uploadedFiles] = $this->parseData($request, 'USER[UPSERT]');
        if (!is_array($data)) $data = [];

        $this->applyIfNonBlank($data, 'fullName', fn($v) => $user->setFullName($v));
        $this->applyIfNonBlank($data, 'title', fn($v) => $user->setTitle($v));
        $this->applyIfNonBlank($data, 'shortBio', fn($v) => $user->setShortBio((string)$v));
        $this->applyIfNonBlank($data, 'longBio', fn($v) => $user->setLongBio((string)$v));
        if (isset($data['social']) && !$this->isBlank($data['social']) && is_array($data['social'])) {
            $user->setSocial($data['social']);
        }
        $this->applyIfNonBlank($data, 'timeZone', fn($v) => $user->setTimeZone((string)$v));
        if (isset($data['location']) && is_array($data['location']) && $data['location'] !== []) {
            $user->setLocation($data['location']);
        }

        if (is_array($uploadedFiles) && !empty($uploadedFiles)) {
            foreach (['picture', 'banner'] as $slot) {
                if (!array_key_exists($slot, $uploadedFiles)) continue;
                $expanded = $this->expandFileArray($uploadedFiles[$slot]);
                if (empty($expanded)) continue;
                $norm = $this->normalizeUploadedFile($expanded[0]);
                if ((int)$norm['error'] !== UPLOAD_ERR_OK) continue;

                $media = $this->createMediaFromNormalizedFile($norm, $user);
                if ($slot === 'picture') {
                    $media->setUserPicture($user);
                    $this->mediaRepo->save($media);
                    $user->setPicture($media);
                } else {
                    $media->setUserBanner($user);
                    $this->mediaRepo->save($media);
                    $user->setBanner($media);
                }
            }
        }

        $type = isset($data['type']) ? strtolower(trim((string)$data['type'])) : '';
        if ($type && !in_array($type, ['founder', 'investor'], true)) {
            throw new RuntimeException('Profile type must be "founder" or "investor"', 400);
        }

        if ($type === 'founder') {
            $payload = isset($data['founder']) && is_array($data['founder']) ? $data['founder'] : [];
            $profile = $user->getFounder() ?: new Founder()->setUser($user);

            if (array_key_exists('yearsExpertise', $payload) && !$this->isBlank($payload['yearsExpertise'])) {
                $profile->setYearsExpertise((int)$payload['yearsExpertise']);
            }
            if (array_key_exists('expertise', $payload) && is_array($payload['expertise']) && $payload['expertise'] !== []) {
                $profile->setExpertise($payload['expertise']);
            }
            $this->applyIfNonBlank($payload, 'notable', fn($v) => $profile->setNotable((string)$v));
            $this->applyIfNonBlank($payload, 'personalWebsite', fn($v) => $profile->setPersonalWebsite((string)$v));
            if (array_key_exists('fundingPreferences', $payload) && is_array($payload['fundingPreferences']) && $payload['fundingPreferences'] !== []) {
                $profile->setFundingPreferences($payload['fundingPreferences']);
            }
            $this->applyIfNonBlank($payload, 'calendly', fn($v) => $profile->setCalendly((string)$v));
            if (!$profile->getHash()) {
                $profile->setHash(bin2hex(random_bytes(16)));
            }
            $this->founders->save($profile);
            $user->setFounder($profile);
            if ($user->getInvestor()) $user->removeInvestor();
            $this->ensureRole($user, 'founder');
        }

        if ($type === 'investor') {
            $payload = isset($data['investor']) && is_array($data['investor']) ? $data['investor'] : [];
            $profile = $user->getInvestor() ?: new Investor()->setInvestor($user);

            $this->applyIfNonBlank($payload, 'foundName', fn($v) => $profile->setFoundName((string)$v));
            $this->applyIfNonBlank($payload, 'fundWebsite', fn($v) => $profile->setFundWebsite((string)$v));
            if (array_key_exists('stageFocus', $payload) && is_array($payload['stageFocus']) && $payload['stageFocus'] !== []) {
                $profile->setStageFocus($payload['stageFocus']);
            }
            if (array_key_exists('sector', $payload) && is_array($payload['sector']) && $payload['sector'] !== []) {
                $profile->setSector($payload['sector']);
            }
            $this->applyIfNonBlank($payload, 'ticketSizeStart', fn($v) => $profile->setTicketSizeStart((int)$v));
            $this->applyIfNonBlank($payload, 'ticketSizeRangeEnd', fn($v) => $profile->setTicketSizeRangeEnd((int)$v));
            if (array_key_exists('geographicFocus', $payload) && is_array($payload['geographicFocus']) && $payload['geographicFocus'] !== []) {
                $profile->setGeographicFocus($payload['geographicFocus']);
            }
            $this->applyIfNonBlank($payload, 'avgCheckSize', fn($v) => $profile->setAvgCheckSize((int)$v));
            $this->applyIfNonBlank($payload, 'assetsManagement', fn($v) => $profile->setAssetsManagement((int)$v));
            $this->applyIfNonBlank($payload, 'previousInvestments', fn($v) => $profile->setPreviousInvestments((string)$v));
            $this->applyIfNonBlank($payload, 'leadInvestments', fn($v) => $profile->setLeadInvestments((int)$v));
            $this->applyIfNonBlank($payload, 'accreditation', fn($v) => $profile->setAccreditation((string)$v));
            $this->applyIfNonBlank($payload, 'personalWebsite', fn($v) => $profile->setPersonalWebsite((string)$v));
            $this->applyIfNonBlank($payload, 'preferredPartner', fn($v) => $profile->setPreferredPartner((string)$v));
            if (array_key_exists('pressLinks', $payload) && is_array($payload['pressLinks']) && $payload['pressLinks'] !== []) {
                $profile->setPressLinks($payload['pressLinks']);
            }
            if (!$profile->getHash()) {
                $profile->setHash(bin2hex(random_bytes(16)));
            }
            $this->investors->save($profile);
            $user->setInvestor($profile);
            if ($user->getFounder()) $user->removeFounder();
            $this->ensureRole($user, 'investor');
        }

        $this->users->save($user);
        return new JsonResponse($this->serializeUser($user, true), 200);
    }

    /**
     * PATCH /me
     * Edit base user fields (supports multipart for picture/banner and remove flags).
     */
    #[Route(methods: 'PATCH', path: '/me')]
    public function updateMe(ServerRequestInterface $request): JsonResponse
    {
        $user = $this->requireAuthUser($request);
        [$data, $uploadedFiles] = $this->parseData($request, 'USER[PATCH_ME]');
        if (!is_array($data)) $data = [];

        if (array_key_exists('fullName', $data))      $user->setFullName($this->blankToNull($data['fullName']));
        if (array_key_exists('title', $data))         $user->setTitle($this->blankToNull($data['title']));
        if (array_key_exists('shortBio', $data))      $user->setShortBio($this->blankToNull($data['shortBio']));
        if (array_key_exists('longBio', $data))       $user->setLongBio($this->blankToNull($data['longBio']));
        if (array_key_exists('timeZone', $data))      $user->setTimeZone($this->blankToNull($data['timeZone']));
        if (array_key_exists('social', $data) && is_array($data['social'])) $user->setSocial($data['social'] ?: null);
        if (array_key_exists('location', $data) && is_array($data['location'])) $user->setLocation($data['location'] ?: null);

        if (!empty($data['removePicture'])) $user->removePicture();
        if (!empty($data['removeBanner']))  $user->removeBanner();

        if (is_array($uploadedFiles) && !empty($uploadedFiles)) {
            foreach (['picture', 'banner'] as $slot) {
                if (!array_key_exists($slot, $uploadedFiles)) continue;
                $expanded = $this->expandFileArray($uploadedFiles[$slot]);
                if (empty($expanded)) continue;
                $norm = $this->normalizeUploadedFile($expanded[0]);
                if ((int)$norm['error'] !== UPLOAD_ERR_OK) continue;

                $media = $this->createMediaFromNormalizedFile($norm, $user);
                if ($slot === 'picture') {
                    $media->setUserPicture($user);
                    $this->mediaRepo->save($media);
                    $user->setPicture($media);
                } else {
                    $media->setUserBanner($user);
                    $this->mediaRepo->save($media);
                    $user->setBanner($media);
                }
            }
        }

        $this->users->save($user);
        return new JsonResponse($this->serializeUser($user, true), 200);
    }

    /**
     * PATCH /me/founder
     * Edit Founder profile (creates if missing; keeps existing hash).
     */
    #[Route(methods: 'PATCH', path: '/me/founder')]
    public function updateFounderProfile(ServerRequestInterface $request): JsonResponse
    {
        $user = $this->requireAuthUser($request);
        [$data] = $this->parseData($request, 'USER[PATCH_FOUNDER]');
        $payload = is_array($data) ? ($data['founder'] ?? $data) : [];

        $profile = $user->getFounder() ?: $this->fetchFounder($user) ?: (new Founder())->setUser($user);

        if (array_key_exists('yearsExpertise', $payload)) {
            $v = $payload['yearsExpertise'];
            $profile->setYearsExpertise($v === '' || $v === null ? null : (int)$v);
        }
        if (array_key_exists('expertise', $payload)) {
            $profile->setExpertise(is_array($payload['expertise']) ? $payload['expertise'] : null);
        }
        if (array_key_exists('notable', $payload)) {
            $profile->setNotable($this->blankToNull($payload['notable']));
        }
        if (array_key_exists('personalWebsite', $payload)) {
            $profile->setPersonalWebsite($this->blankToNull($payload['personalWebsite']));
        }
        if (array_key_exists('fundingPreferences', $payload)) {
            $profile->setFundingPreferences(is_array($payload['fundingPreferences']) ? $payload['fundingPreferences'] : null);
        }
        if (array_key_exists('calendly', $payload)) {
            $profile->setCalendly($this->blankToNull($payload['calendly']));
        }

        if (!$profile->getHash()) {
            $profile->setHash(bin2hex(random_bytes(16)));
        }

        $this->founders->save($profile);
        $user->setFounder($profile);
        if ($user->getInvestor()) $user->removeInvestor();
        $this->ensureRole($user, 'founder');
        $this->users->save($user);

        return new JsonResponse($this->serializeUser($user, true), 200);
    }

    /**
     * PATCH /me/investor
     * Edit Investor profile (creates if missing; keeps existing hash).
     */
    #[Route(methods: 'PATCH', path: '/me/investor')]
    public function updateInvestorProfile(ServerRequestInterface $request): JsonResponse
    {
        $user = $this->requireAuthUser($request);
        [$data] = $this->parseData($request, 'USER[PATCH_INVESTOR]');
        $payload = is_array($data) ? ($data['investor'] ?? $data) : [];

        $profile = $user->getInvestor() ?: $this->fetchInvestor($user) ?: (new Investor())->setInvestor($user);

        if (array_key_exists('foundName', $payload))           $profile->setFoundName($this->blankToNull($payload['foundName']));
        if (array_key_exists('fundWebsite', $payload))         $profile->setFundWebsite($this->blankToNull($payload['fundWebsite']));
        if (array_key_exists('stageFocus', $payload))          $profile->setStageFocus(is_array($payload['stageFocus']) ? $payload['stageFocus'] : null);
        if (array_key_exists('sector', $payload))              $profile->setSector(is_array($payload['sector']) ? $payload['sector'] : null);
        if (array_key_exists('ticketSizeStart', $payload))     $profile->setTicketSizeStart($this->toNullableInt($payload['ticketSizeStart']));
        if (array_key_exists('ticketSizeRangeEnd', $payload))  $profile->setTicketSizeRangeEnd($this->toNullableInt($payload['ticketSizeRangeEnd']));
        if (array_key_exists('geographicFocus', $payload))     $profile->setGeographicFocus(is_array($payload['geographicFocus']) ? $payload['geographicFocus'] : null);
        if (array_key_exists('avgCheckSize', $payload))        $profile->setAvgCheckSize($this->toNullableInt($payload['avgCheckSize']));
        if (array_key_exists('assetsManagement', $payload))    $profile->setAssetsManagement($this->toNullableInt($payload['assetsManagement']));
        if (array_key_exists('previousInvestments', $payload)) $profile->setPreviousInvestments($this->blankToNull($payload['previousInvestments']));
        if (array_key_exists('leadInvestments', $payload))     $profile->setLeadInvestments($this->toNullableInt($payload['leadInvestments']));
        if (array_key_exists('accreditation', $payload))       $profile->setAccreditation($this->blankToNull($payload['accreditation']));
        if (array_key_exists('personalWebsite', $payload))     $profile->setPersonalWebsite($this->blankToNull($payload['personalWebsite']));
        if (array_key_exists('preferredPartner', $payload))    $profile->setPreferredPartner($this->blankToNull($payload['preferredPartner']));
        if (array_key_exists('pressLinks', $payload))          $profile->setPressLinks(is_array($payload['pressLinks']) ? $payload['pressLinks'] : null);

        if (!$profile->getHash()) {
            $profile->setHash(bin2hex(random_bytes(16)));
        }

        $this->investors->save($profile);
        $user->setInvestor($profile);
        if ($user->getFounder()) $user->removeFounder();
        $this->ensureRole($user, 'investor');
        $this->users->save($user);

        return new JsonResponse($this->serializeUser($user, true), 200);
    }

    // -----------------------
    // Helpers
    // -----------------------

    private function requireAuthUser(ServerRequestInterface $request): User
    {
        $userId = (int)$request->getAttribute('user_id', 0);
        if ($userId <= 0) throw new RuntimeException('Unauthorized', 401);
        /** @var ?User $user */
        $user = $this->users->find($userId);
        if (!$user) throw new RuntimeException('Unauthorized', 401);
        return $user;
    }

    private function isBlank(mixed $v): bool
    {
        if ($v === null) return true;
        if (is_string($v)) return trim($v) === '';
        if (is_array($v)) return $v === [];
        return false;
    }

    private function applyIfNonBlank(array $data, string $key, callable $setter): void
    {
        if (!array_key_exists($key, $data)) return;
        if ($this->isBlank($data[$key])) return;
        $setter($data[$key]);
    }

    private function blankToNull(mixed $v): ?string
    {
        if ($v === null) return null;
        if (is_string($v) && trim($v) === '') return null;
        return is_string($v) ? $v : null;
    }

    private function toNullableInt(mixed $v): ?int
    {
        if ($v === null || $v === '') return null;
        return (int)$v;
    }

    private function parseData(ServerRequestInterface $request, string $ctx = 'USER'): array
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
            if (isset($_POST['data'])) {
                $raw = (string)$_POST['data'];
                $data = json_decode($raw ?: "{}", true);
                if (!is_array($data)) $data = [];
            }

            $filesOut = [];
            if (isset($_FILES) && is_array($_FILES)) {
                foreach ($_FILES as $name => $info) {
                    $filesOut[$name] = $info;
                }
            }
            return [$data, $filesOut];
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

    private function serializeUser(User $user, bool $includeEmail): array
    {
        $founder  = $user->getFounder()  ?: $this->fetchFounder($user);
        $investor = $user->getInvestor() ?: $this->fetchInvestor($user);

        return [
            'id'         => $user->getId(),
            'fullName'   => $user->getFullName(),
            'title'      => $user->getTitle(),
            'shortBio'   => $user->getShortBio(),
            'longBio'    => $user->getLongBio(),
            'social'     => $user->getSocial(),
            'timeZone'   => $user->getTimeZone(),
            'location'   => $user->getLocation(),
            'email'      => $includeEmail ? $user->getEmail() : null,
            'picture'    => $user->getPicture() ? $this->serializeMedia($user->getPicture()) : null,
            'banner'     => $user->getBanner() ? $this->serializeMedia($user->getBanner()) : null,
            'roles'      => array_values(array_map(
                fn($r) => $r instanceof Role ? ['id' => $r->getId(), 'name' => $r->getName(), 'slug' => $r->getSlug()] : null,
                $user->getRoles()
            )),
            'founder'    => $founder  ? $this->serializeFounder($founder)   : null,
            'investor'   => $investor ? $this->serializeInvestor($investor) : null,
            'lastLoginAt'=> $user->getLastLoginAt() ? $user->getLastLoginAt()->format(\DateTimeInterface::ATOM) : null,
        ];
    }

    private function serializeFounder(Founder $f): array
    {
        return [
            'id' => $f->getId(),
            'yearsExpertise'     => $f->getYearsExpertise(),
            'expertise'          => $f->getExpertise(),
            'notable'            => $f->getNotable(),
            'personalWebsite'    => $f->getPersonalWebsite(),
            'fundingPreferences' => $f->getFundingPreferences(),
            'calendly'           => $f->getCalendly(),
            'hash'               => $f->getHash(),
        ];
    }

    private function serializeInvestor(Investor $i): array
    {
        return [
            'id' => $i->getId(),
            'foundName'             => $i->getFoundName(),
            'fundWebsite'           => $i->getFundWebsite(),
            'stageFocus'            => $i->getStageFocus(),
            'sector'                => $i->getSector(),
            'ticketSizeStart'       => $i->getTicketSizeStart(),
            'ticketSizeRangeEnd'    => $i->getTicketSizeRangeEnd(),
            'geographicFocus'       => $i->getGeographicFocus(),
            'avgCheckSize'          => $i->getAvgCheckSize(),
            'assetsManagement'      => $i->getAssetsManagement(),
            'previousInvestments'   => $i->getPreviousInvestments(),
            'leadInvestments'       => $i->getLeadInvestments(),
            'accreditation'         => $i->getAccreditation(),
            'personalWebsite'       => $i->getPersonalWebsite(),
            'preferredPartner'      => $i->getPreferredPartner(),
            'pressLinks'            => $i->getPressLinks(),
            'tesisDocuments'        => $i->getTesisDocuments() ? $this->serializeMedia($i->getTesisDocuments()) : null,
            'hash'                  => $i->getHash(),
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

    private function ensureRole(User $user, string $slug): void
    {
        /** @var ?Role $role */
        $role = $this->roles->findOneBy(['slug' => $slug]);
        if (!$role) {
            $role = new Role();
            $role->setSlug($slug)->setName(ucfirst($slug));
            $this->roles->save($role);
        }
        foreach ($user->getRoles() as $r) {
            if ($r instanceof Role && $r->getSlug() === $slug) return;
        }
        $user->addRole($role);
        $this->users->save($user);
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

    private function iniBytes(string $key): ?int
    {
        $val = ini_get($key);
        if ($val === false || $val === '') return null;
        $val = trim($val);
        $last = strtolower($val[strlen($val) - 1]);
        $num = (int)$val;
        switch ($last) {
            case 'g': $num *= 1024;
            case 'm': $num *= 1024;
            case 'k': $num *= 1024;
        }
        return $num;
    }

    /**
     * @throws \DateMalformedStringException
     * @throws RandomException
     */
    private function createMediaFromNormalizedFile(array $norm, User $user): Media
    {
        $clientFilename = $norm['clientName'] ?? 'upload.bin';
        $ext      = pathinfo($clientFilename, PATHINFO_EXTENSION);
        $mimeType = $norm['mime'] ?? 'application/octet-stream';

        $randomName = bin2hex(random_bytes(8));
        $safeExt    = $ext !== '' ? ('.' . preg_replace('/[^a-zA-Z0-9]/', '', $ext)) : '';
        $finalName  = $randomName . $safeExt;

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
            ->setAuthorUser($user)
            ->setHash(bin2hex(random_bytes(16)));

        return $media;
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
}
