<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\Media;
use App\Entity\Project;
use App\Entity\User;
use App\Entity\Founder;
use App\Entity\Investor;
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

final class ProjectController
{
    private EntityRepository $projects;
    private EntityRepository $users;
    private EntityRepository $founders;
    private EntityRepository $investors;

    public function __construct(
        private RepositoryFactory $repos,
        private MonkeysMailService $mail,
        private Renderer $renderer,
    ) {
        $this->projects = $this->repos->getRepository(Project::class);
        $this->users    = $this->repos->getRepository(User::class);
        $this->founders  = $this->repos->getRepository(Founder::class);
        $this->investors = $this->repos->getRepository(Investor::class);
    }

    /**
     * POST /projects
     *
     * Accepted contributor inputs (all optional):
     *  - contributors: int[]                // initial full set (same as "replace" at create)
     *  - contributorsEmails: string[]       // emails to resolve & include
     *  - addContributors: int[]             // extra IDs to include
     *  - addContributorEmails: string[]     // extra emails to include
     *
     * Notes:
     *  - Owner is never added as an explicit contributor (skipped).
     *  - Unknown emails are returned in _warnings.emails_not_found.
     *
     * @throws \JsonException
     * @throws \ReflectionException
     * @throws RandomException
     */
    #[Route(methods: 'POST', path: '/projects')]
    public function create(ServerRequestInterface $request): JsonResponse
    {
        try {

            $userId = (int)$request->getAttribute('user_id', 0);

            if ($userId <= 0) {
                throw new RuntimeException('Unauthorized', 401);
            }

            /** @var ?User $author */
            $author = $this->users->find($userId);
            if (!$author) {
                throw new RuntimeException('Unauthorized', 401);
            }

            // 2) Parse body
            $parsedBody = $request->getParsedBody();
            $isMultipart = is_array($parsedBody) && isset($parsedBody['data']);

            if ($isMultipart) {
                $rawDataField = (string)$parsedBody['data'];
                $data = json_decode($rawDataField, true, 512, JSON_THROW_ON_ERROR);
            } else {
                $rawBody = (string)$request->getBody();
                $data = json_decode($rawBody, true, 512, JSON_THROW_ON_ERROR);
            }
            if (!is_array($data)) { $data = []; }

            // Required
            $name    = isset($data['name']) ? trim((string)$data['name']) : '';
            $tagline = isset($data['tagline']) ? trim((string)$data['tagline']) : '';

            if ($name === '' || $tagline === '') {
                throw new RuntimeException('Project name and tagline are required', 400);
            }

            // Location (JSON array/object OR legacy string "State, Country")
            $location = null;
            if (array_key_exists('location', $data)) {
                if (is_array($data['location'])) {
                    // expected shape: { country: "...", state: "...", iso2: "CR" }
                    $country = isset($data['location']['country']) ? trim((string)$data['location']['country']) : null;
                    $state   = isset($data['location']['state'])   ? trim((string)$data['location']['state'])   : null;
                    $iso2    = isset($data['location']['iso2'])    ? strtoupper(trim((string)$data['location']['iso2'])) : null;

                    // Keep only meaningful keys
                    $locArr = array_filter([
                        'country' => $country ?: null,
                        'state'   => $state ?: null,
                        'iso2'    => $iso2 ?: null,
                    ], static fn($v) => $v !== null);

                    $location = !empty($locArr) ? $locArr : null;
                } elseif (is_string($data['location']) && trim($data['location']) !== '') {
                    // backward-compat: "State, Country" or "Country"
                    $parts = array_values(array_filter(array_map('trim', explode(',', $data['location']))));
                    if (count($parts) >= 2) {
                        $location = ['state' => $parts[0], 'country' => $parts[1]];
                    } elseif (count($parts) === 1) {
                        $location = ['country' => $parts[0]];
                    }
                }
            }

            // Optional (strings / text)
            $stage            = isset($data['stage']) ? trim((string)$data['stage']) : null;
            $foundedRaw       = isset($data['founded']) ? trim((string)$data['founded']) : null;
            $category         = isset($data['category']) && is_array($data['category']) ? $data['category'] : null;
            $elevatorPitch    = isset($data['elevatorPitch']) ? trim((string)$data['elevatorPitch']) : null;
            $problemStatement = isset($data['problemStatement']) ? trim((string)$data['problemStatement']) : null;
            $solution         = isset($data['solution']) ? trim((string)$data['solution']) : null;
            $model            = isset($data['model']) ? trim((string)$data['model']) : null;
            $traction         = isset($data['traction']) ? trim((string)$data['traction']) : null;
            $urls             = isset($data['urls']) && is_array($data['urls']) ? $data['urls'] : null;
            $social           = isset($data['social']) && is_array($data['social']) ? $data['social'] : null;
            $demoVideo        = isset($data['demoVideo']) ? trim((string)$data['demoVideo']) : null;

            // Optional (numbers)
            $teamSize               = isset($data['teamSize']) ? (int)$data['teamSize'] : null;
            $capitalSought          = isset($data['capitalSought']) ? (int)$data['capitalSought'] : null;
            $valuation              = isset($data['valuation']) ? (int)$data['valuation'] : null;
            $foundingTarget         = isset($data['foundingTarget']) ? (int)$data['foundingTarget'] : null;
            $previuosAmountFounding = isset($data['previousAmountFunding']) ? (int)$data['previousAmountFunding'] : null;
            $previuosRound          = isset($data['previousRound']) ? trim((string)$data['previousRound']) : null;
            $currentFoundingAmount  = isset($data['currentFoundingAmount']) ? (int)$data['currentFoundingAmount'] : null;

            // Optional (status from client)
            $statusRaw = isset($data['status']) ? trim((string)$data['status']) : 'draft';
            $allowedStatuses = ['draft', 'pending_review'];
            $status = in_array($statusRaw, $allowedStatuses, true) ? $statusRaw : 'draft';

            // Dates
            $founded = null;
            if ($foundedRaw !== null && $foundedRaw !== '') {
                try {
                    if (preg_match('/^\d{4}$/', $foundedRaw)) {
                        $founded = new \DateTimeImmutable($foundedRaw . '-01-01', new \DateTimeZone('UTC'));
                    } elseif (preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $foundedRaw)) {
                        $founded = new \DateTimeImmutable($foundedRaw . '-01', new \DateTimeZone('UTC'));
                    } elseif (preg_match('/^\d{4}-(0[1-9]|1[0-2])-(0[1-9]|[12]\d|3[01])$/', $foundedRaw)) {
                        $founded = new \DateTimeImmutable($foundedRaw, new \DateTimeZone('UTC'));
                    } else {
                        $tmp = new \DateTimeImmutable($foundedRaw, new \DateTimeZone('UTC'));
                        $founded = new \DateTimeImmutable($tmp->format('Y-m-d'), new \DateTimeZone('UTC'));
                    }
                } catch (\Throwable $e) {
                    throw new \RuntimeException('Invalid founded date format', 400);
                }
            }

            $previousRoundDateRaw = isset($data['previousRoundDate']) ? trim((string)$data['previousRoundDate']) : null;
            $previousRoundDate = null;
            if ($previousRoundDateRaw !== null && $previousRoundDateRaw !== '') {
                try {
                    $previousRoundDate = new \DateTimeImmutable($previousRoundDateRaw, new \DateTimeZone('UTC'));
                } catch (\Throwable $e) {
                    throw new \RuntimeException('Invalid previous round date format', 400);
                }
            }

            // ---------------------------
            // Contributors (IDs / Emails)
            // ---------------------------
            $asIntIds = static function($v): array {
                if (!is_array($v)) return [];
                $out = [];
                foreach ($v as $x) { $n = (int)$x; if ($n > 0) $out[$n] = true; }
                return array_keys($out);
            };
            $asEmails = static function($v): array {
                if (!is_array($v)) return [];
                $out = [];
                foreach ($v as $x) {
                    $e = trim((string)$x);
                    if ($e !== '' && filter_var($e, FILTER_VALIDATE_EMAIL)) { $out[strtolower($e)] = true; }
                }
                return array_keys($out);
            };

            $notFoundEmails = [];
            $resolveEmailsStrict = function(array $emails) use (&$notFoundEmails): array {
                $ids = [];
                foreach ($emails as $em) {
                    /** @var User|null $found */
                    $found = $this->users->findOneBy(['email' => $em]);
                    if ($found instanceof User) { $ids[$found->getId()] = true; }
                    else { $notFoundEmails[] = $em; }
                }
                return array_keys($ids);
            };

            // Inputs parity with update():
            $replaceIds        = $asIntIds($data['contributors']           ?? null);
            $replaceEmails     = $asEmails($data['contributorsEmails']     ?? null);
            $addIds            = $asIntIds($data['addContributors']        ?? null);
            $addEmails         = $asEmails($data['addContributorEmails']   ?? null);

            // At creation, "replace" just seeds the set
            $seedIds = array_unique(array_merge(
                $replaceIds,
                $resolveEmailsStrict($replaceEmails)
            ));
            $extraIds = array_unique(array_merge(
                $addIds,
                $resolveEmailsStrict($addEmails)
            ));
            $candidateContribIds = array_unique(array_merge($seedIds, $extraIds));

            // Skip owner if present
            $authorId = $author->getId();
            $candidateContribIds = array_values(array_filter(
                $candidateContribIds,
                static fn(int $id) => $id !== $authorId
            ));

            // 3) Build entity
            $project = new Project();
            $project
                ->setName($name)
                ->setTagline($tagline)
                ->setStage($stage)
                ->setLocation($location)
                ->setFounded($founded)
                ->setCategory($category)
                ->setElevatorPitch($elevatorPitch)
                ->setProblemStatement($problemStatement)
                ->setSolution($solution)
                ->setModel($model)
                ->setTraction($traction)
                ->setUrls($urls)
                ->setSocial($social)
                ->setDemoVideo($demoVideo)
                ->setTeamSize($teamSize)
                ->setCapitalSought($capitalSought)
                ->setValuation($valuation)
                ->setFoundingTarget($foundingTarget)
                ->setPreviuosAmountFounding($previuosAmountFounding)
                ->setPreviuosRound($previuosRound)
                ->setPreviousRoundDate($previousRoundDate)
                ->setCurrentFoundingAmount($currentFoundingAmount)
                ->setStatus($status)
                ->setPublishDate(new \DateTimeImmutable('now', new \DateTimeZone('UTC')))
                ->setUpdateDate(new \DateTimeImmutable('now', new \DateTimeZone('UTC')))
                ->setAuthor($author)
                ->addUser($author);

            // 4) Hash
            $hash = bin2hex(random_bytes(16));
            $project->setHash($hash);

            // 5) Save project first
            $this->projects->save($project);

            // 6) Media uploads
            $this->processMediaUploads($request, $project, $author);

            // 6.1) Attach contributors (after we have a project ID)
            if (!empty($candidateContribIds)) {
                foreach ($candidateContribIds as $cid) {
                    $u = $this->users->find($cid);
                    if ($u instanceof User) {
                        $this->projects->attachRelation($project, 'users', $u->getId());
                    }
                }
            }

            // 7) Save again with media + relations
            $this->projects->save($project);

            // Ensure author relation is set (kept as in your existing code).
            $this->projects->attachRelation($project, 'users', $author->getId());

            // ---------------------------
            // INVITE_HOOK (create)
            // ---------------------------
            $inviteEmails = array_values(array_unique($notFoundEmails));
            $invitesScheduled = false;
            if (!empty($inviteEmails)) {
                $invitesScheduled = $this->maybeScheduleContributorInvites($inviteEmails, $project, $author);
            }

            // 8) Respond
            error_log('[PROJECT][CREATE] success, returning 201');
            $payload = $this->serializeProject($project);
            if (!empty($notFoundEmails)) {
                $payload['_warnings'] = [
                    'emails_not_found' => array_values(array_unique($notFoundEmails)),
                ];
                $payload['_invite'] = [
                    'candidates' => $inviteEmails,
                    'scheduled'  => (bool)$invitesScheduled,
                    'mode'       => 'create',
                ];
            }

            return new JsonResponse($payload, 201);
        } catch (\Throwable $e) {
            error_log('[PROJECT][CREATE][FATAL] ' .
                get_class($e) . ' ' .
                $e->getMessage() . ' @ ' .
                $e->getFile() . ':' . $e->getLine()
            );
            throw $e;
        }
    }

    /**
     * @throws \DateInvalidTimeZoneException
     * @throws RandomException
     * @throws \DateMalformedStringException
     * @throws \Throwable
     * @throws \JsonException
     * @throws \ReflectionException
     */
    #[Route(methods: 'POST', path: '/projects/{hash}')]
    public function updateByHashPost(ServerRequestInterface $request): JsonResponse
    {
        $uid = (int) $request->getAttribute('user_id', 0);
        if ($uid <= 0) { throw new RuntimeException('Unauthorized', 401); }
        /** @var User|null $actor */
        $actor = $this->users->find($uid);
        if (!$actor) { throw new RuntimeException('Unauthorized', 401); }

        $hash = $request->getAttribute('hash');
        if (!is_string($hash) || $hash === '') { throw new RuntimeException('Invalid project identifier', 400); }

        /** @var Project|null $project */
        $project = $this->projects->findOneBy(['hash' => $hash]);
        if (!$project) { throw new RuntimeException('Project not found', 404); }

        // --- AuthZ: author OR existing contributor may edit ---
        $author      = $project->getAuthor();
        $authorId    = $author?->getId() ?? 0;
        $isAuthor    = $authorId === $actor->getId();
        $isContributor = false;
        foreach ($project->getUsers() as $u) {
            if ($u instanceof User && $u->getId() === $actor->getId()) { $isContributor = true; break; }
        }
        if (!$isAuthor && !$isContributor) { throw new RuntimeException('Forbidden', 403); }

        // --- Parse body (JSON or multipart with "data") ---
        $parsedBody  = $request->getParsedBody();
        $isMultipart = is_array($parsedBody) && array_key_exists('data', $parsedBody);
        $raw         = $isMultipart ? (string)($parsedBody['data'] ?? '') : (string)$request->getBody();
        $data        = $raw !== '' ? json_decode($raw, true, 512, JSON_THROW_ON_ERROR) : [];
        if (!is_array($data)) { $data = []; }

        // --- helpers ---
        $truthy = static fn($v): bool => ($v === true || $v === 1 || $v === '1' || $v === 'true' || $v === 'on');

        $parseFounded = static function(?string $raw): ?\DateTimeImmutable {
            if ($raw === null || $raw === '') return null;
            if (preg_match('/^\d{4}$/', $raw))  return new \DateTimeImmutable($raw . '-01-01', new \DateTimeZone('UTC'));
            if (preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $raw)) return new \DateTimeImmutable($raw . '-01', new \DateTimeZone('UTC'));
            try {
                $tmp = new \DateTimeImmutable($raw, new \DateTimeZone('UTC'));
                return new \DateTimeImmutable($tmp->format('Y-m-d'), new \DateTimeZone('UTC'));
            } catch (\Throwable) { throw new RuntimeException('Invalid founded date format', 400); }
        };
        $parseIso = static function(?string $raw): ?\DateTimeImmutable {
            if ($raw === null || $raw === '') return null;
            try { return new \DateTimeImmutable($raw, new \DateTimeZone('UTC')); }
            catch (\Throwable) { throw new RuntimeException('Invalid previous round date format', 400); }
        };

        // --- Partial field updates (if present) ---
        if (array_key_exists('name', $data))               $project->setName(trim((string)$data['name']) ?: null);
        if (array_key_exists('tagline', $data))            $project->setTagline(trim((string)$data['tagline']) ?: null);
        if (array_key_exists('stage', $data))              $project->setStage(trim((string)$data['stage']) ?: null);
        if (array_key_exists('elevatorPitch', $data))      $project->setElevatorPitch(trim((string)$data['elevatorPitch']) ?: null);
        if (array_key_exists('problemStatement', $data))   $project->setProblemStatement(trim((string)$data['problemStatement']) ?: null);
        if (array_key_exists('solution', $data))           $project->setSolution(trim((string)$data['solution']) ?: null);
        if (array_key_exists('model', $data))              $project->setModel(trim((string)$data['model']) ?: null);
        if (array_key_exists('traction', $data))           $project->setTraction(trim((string)$data['traction']) ?: null);
        if (array_key_exists('demoVideo', $data))          $project->setDemoVideo(trim((string)$data['demoVideo']) ?: null);

        if (array_key_exists('status', $data)) {
            $statusRaw = trim((string)$data['status']);
            $allowed = ['draft', 'pending_review'];
            $project->setStatus(in_array($statusRaw, $allowed, true) ? $statusRaw : 'draft');
        }

        if (array_key_exists('category', $data)) $project->setCategory(is_array($data['category']) ? $data['category'] : null);
        if (array_key_exists('urls', $data))     $project->setUrls(is_array($data['urls']) ? $data['urls'] : null);
        if (array_key_exists('social', $data))   $project->setSocial(is_array($data['social']) ? $data['social'] : null);

        if (array_key_exists('teamSize', $data))              $project->setTeamSize($data['teamSize'] !== null ? (int)$data['teamSize'] : null);
        if (array_key_exists('capitalSought', $data))         $project->setCapitalSought($data['capitalSought'] !== null ? (int)$data['capitalSought'] : null);
        if (array_key_exists('valuation', $data))             $project->setValuation($data['valuation'] !== null ? (int)$data['valuation'] : null);
        if (array_key_exists('foundingTarget', $data))        $project->setFoundingTarget($data['foundingTarget'] !== null ? (int)$data['foundingTarget'] : null);
        if (array_key_exists('previousAmountFunding', $data)) $project->setPreviuosAmountFounding($data['previousAmountFunding'] !== null ? (int)$data['previousAmountFunding'] : null);
        if (array_key_exists('previousRound', $data))         $project->setPreviuosRound($data['previousRound'] !== null ? trim((string)$data['previousRound']) : null);
        if (array_key_exists('currentFoundingAmount', $data)) $project->setCurrentFoundingAmount($data['currentFoundingAmount'] !== null ? (int)$data['currentFoundingAmount'] : null);

        if (array_key_exists('founded', $data))               $project->setFounded($parseFounded($data['founded'] !== null ? trim((string)$data['founded']) : null));
        if (array_key_exists('previousRoundDate', $data))     $project->setPreviousRoundDate($parseIso($data['previousRoundDate'] !== null ? trim((string)$data['previousRoundDate']) : null));
        if (array_key_exists('location', $data))              $project->setLocation(is_array($data['location']) ? $data['location'] : null);

        // --- Optional media removals ---
        if ($truthy($data['removeLogo'] ?? false))      $project->removeLogo();
        if ($truthy($data['removeBanner'] ?? false))    $project->removeBanner();
        if ($truthy($data['removePitchDeck'] ?? false)) $project->removePitchDeck();

        // --- Save base fields ---
        $project->setUpdateDate(new \DateTimeImmutable('now', new \DateTimeZone('UTC')));
        $this->projects->save($project);

        // --- Media uploads (only if multipart) ---
        if ($isMultipart) {
            $this->processMediaUploads($request, $project, $actor);
            $this->projects->save($project);
        }

        // ---------------------------
        // Contributors management
        // ---------------------------
        $asIntIds = static function($v): array {
            if (!is_array($v)) return [];
            $out = [];
            foreach ($v as $x) { $n = (int)$x; if ($n > 0) $out[$n] = true; }
            return array_keys($out);
        };
        $asEmails = static function($v): array {
            if (!is_array($v)) return [];
            $out = [];
            foreach ($v as $x) {
                $e = trim((string)$x);
                if ($e !== '' && filter_var($e, FILTER_VALIDATE_EMAIL)) { $out[strtolower($e)] = true; }
            }
            return array_keys($out);
        };

        // Owner: may replace, add/remove by IDs or emails
        // Contributor: may NOT "replace"; may add by email; may remove by id or email (but never the owner)
        $replaceIds        = $asIntIds($data['contributors']           ?? null);
        $replaceEmails     = $asEmails($data['contributorsEmails']     ?? null);

        $addIds            = $asIntIds($data['addContributors']        ?? null);
        $addEmails         = $asEmails($data['addContributorEmails']   ?? null);

        $removeIds         = $asIntIds($data['removeContributors']     ?? null);
        $removeEmails      = $asEmails($data['removeContributorEmails'] ?? null);

        // Resolve emails -> user IDs
        $emailLookup = function(array $emails): array {
            $ids = [];
            foreach ($emails as $em) {
                /** @var User|null $found */
                $found = $this->users->findOneBy(['email' => $em]);
                if ($found instanceof User) { $ids[$found->getId()] = true; }
            }
            return array_keys($ids);
        };

        $notFoundEmails = [];
        $resolveEmailsStrict = function(array $emails) use (&$notFoundEmails) : array {
            $ids = [];
            foreach ($emails as $em) {
                /** @var User|null $found */
                $found = $this->users->findOneBy(['email' => $em]);
                if ($found instanceof User) { $ids[$found->getId()] = true; }
                else { $notFoundEmails[] = $em; }
            }
            return array_keys($ids);
        };

        // Effective sets honoring role rules
        $effectiveReplace = [];
        if ($isAuthor) {
            $effectiveReplace = array_unique(array_merge(
                $replaceIds,
                $resolveEmailsStrict($replaceEmails)
            ));
        }

        $effectiveAdd = array_unique(array_merge(
            $isAuthor ? $addIds : [],
            $resolveEmailsStrict($addEmails)
        ));

        $effectiveRemove = array_unique(array_merge(
            $removeIds,
            $emailLookup($removeEmails)
        ));

        // Never allow removing the owner
        $effectiveRemove = array_values(array_filter($effectiveRemove, fn(int $id) => $id !== $authorId));

        // Apply changes
        if ($isAuthor && !empty($effectiveReplace)) {
            foreach ($project->getUsers() as $u) {
                if (!$u instanceof User) continue;
                if ($u->getId() === $authorId) continue;
                $this->projects->detachRelation($project, 'users', $u->getId());
            }
            foreach ($effectiveReplace as $idToAttach) {
                if ($idToAttach === $authorId) continue;
                $u = $this->users->find($idToAttach);
                if ($u instanceof User) { $this->projects->attachRelation($project, 'users', $u->getId()); }
            }
        } else {
            foreach ($effectiveAdd as $idToAttach) {
                if ($idToAttach === $authorId) continue;
                $u = $this->users->find($idToAttach);
                if ($u instanceof User) { $this->projects->attachRelation($project, 'users', $u->getId()); }
            }
            foreach ($effectiveRemove as $idToDetach) {
                if ($idToDetach === $authorId) continue;
                $this->projects->detachRelation($project, 'users', $idToDetach);
            }
        }

        // persist any field updates you already did earlier
        $project->setUpdateDate(new \DateTimeImmutable('now', new \DateTimeZone('UTC')));
        $this->projects->save($project);

        // ---------------------------
        // INVITE_HOOK (update)
        // ---------------------------
        $inviteEmails = array_values(array_unique($notFoundEmails));
        $invitesScheduled = false;
        if (!empty($inviteEmails)) {
            $invitesScheduled = $this->maybeScheduleContributorInvites($inviteEmails, $project, $actor);
        }

        $payload = $this->serializeProject($project);
        if (!empty($notFoundEmails)) {
            $payload['_warnings'] = [
                'emails_not_found' => array_values(array_unique($notFoundEmails)),
            ];
            $payload['_invite'] = [
                'candidates' => $inviteEmails,
                'scheduled'  => (bool)$invitesScheduled,
                'mode'       => 'update',
            ];
        }

        return new JsonResponse($payload, 200);
    }

    /**
     * @throws \ReflectionException
     * @throws \JsonException
     */
    #[Route(methods: 'GET', path: '/projects/{id}')]
    public function show(ServerRequestInterface $request): JsonResponse
    {
        $idAttr = $request->getAttribute('id');

        // Check if strictly numeric ID
        if (is_numeric($idAttr) && (string)(int)$idAttr === (string)$idAttr) {
            $id = (int)$idAttr;
            if ($id <= 0) {
                 throw new RuntimeException('Invalid project id', 400);
            }
            /** @var ?Project $project */
            $project = $this->projects->find($id);
        } else {
            // Assume it's a hash
            $hash = (string)$idAttr;
            if ($hash === '') {
                throw new RuntimeException('Invalid project identifier', 400);
            }
             /** @var ?Project $project */
            $project = $this->projects->findOneBy(['hash' => $hash]);
        }

        if (!$project) {
            throw new RuntimeException('Project not found', 404);
        }

        return new JsonResponse(
            $this->serializeProject($project),
            200
        );
    }

    /**
     * @throws \JsonException
     */
    #[Route(methods: 'GET', path: '/projects/{hash}')]
    public function showByHash(ServerRequestInterface $request): JsonResponse
    {
        $hash = $request->getAttribute('hash');
        if (!is_string($hash) || $hash === '') {
            throw new RuntimeException('Invalid project identifier', 400);
        }

        /** @var ?Project $project */
        $project = $this->projects->findOneBy(['hash' => $hash]);

        if (!$project) {
            throw new RuntimeException('Project not found', 404);
        }

        return new JsonResponse(
            $this->serializeProject($project),
            200
        );
    }

    /**
     * @throws \Throwable
     * @throws RandomException
     */
    private function processMediaUploads(ServerRequestInterface $request, Project $project, User $user): void
    {
        $uploadedFiles = $request->getUploadedFiles();

        if (empty($uploadedFiles)) {
            return;
        }

        $mediaRepo = $this->repos->getRepository(Media::class);

        // --- Single-file slots (robust to arrays & multi-file shape) ---
        foreach (['logo', 'banner', 'pitchDeck'] as $slot) {
            if (!array_key_exists($slot, $uploadedFiles)) {
                continue;
            }

            $expanded = $this->expandFileArray($uploadedFiles[$slot]);
            if (empty($expanded)) {
                continue;
            }

            // For single-slot fields, take the first valid file
            $picked = $expanded[0];

            $norm = $this->normalizeUploadedFile($picked);
            if ((int)$norm['error'] === UPLOAD_ERR_INI_SIZE) {
                continue;
            }
            if ((int)$norm['error'] === UPLOAD_ERR_FORM_SIZE) {
                continue;
            }

            if ((int)$norm['error'] !== UPLOAD_ERR_OK) {
                continue;
            }

            $media = $this->createMediaFromNormalizedFile($norm, $user);

            // Link FK from Media -> Project
            match ($slot) {
                'logo'      => $media->setProjectLogo($project),
                'banner'    => $media->setProjectBanner($project),
                'pitchDeck' => $media->setProjectPitchDeck($project),
            };

            $mediaRepo->save($media);

            // Link Project -> Media
            match ($slot) {
                'logo'      => $project->setLogo($media),
                'banner'    => $project->setBanner($media),
                'pitchDeck' => $project->setPitchDeck($media),
            };
        }

        // --- Gallery: accept "gallery" OR "gallery[]" and coerce to list ---
        $galleryRaw = $uploadedFiles['gallery'] ?? ($uploadedFiles['gallery[]'] ?? null);
        $galleryItems = $this->expandFileArray($galleryRaw);

        if (!empty($galleryItems)) {
            foreach ($galleryItems as $i => $item) {
                $norm = $this->normalizeUploadedFile($item);

                if ((int)$norm['error'] !== UPLOAD_ERR_OK) {
                    continue;
                }

                $media = $this->createMediaFromNormalizedFile($norm, $user);
                $media->setProjectGallery($project);
                $mediaRepo->save($media);

                $project->addMedia($media);
            }
        }
    }

    private function serializeProject(Project $project): array
    {
        $author   = $project->getAuthor();

        // Build contributors (exclude owner, unique by id)
        $contributors       = $this->fetchContributors($project);

        return [
            'id'    => $project->getId(),
            'hash'  => $project->getHash(),

            'name'    => $project->getName(),
            'tagline' => $project->getTagline(),
            'stage'   => $project->getStage(),
            'founded' => $project->getFounded()?->format('Y-m-d'),
            'category'         => $project->getCategory(),
            'elevatorPitch'    => $project->getElevatorPitch(),
            'problemStatement' => $project->getProblemStatement(),
            'solution'         => $project->getSolution(),
            'model'            => $project->getModel(),
            'traction'         => $project->getTraction(),

            'urls'      => $project->getUrls(),
            'social'    => $project->getSocial(),
            'demoVideo' => $project->getDemoVideo(),

            'location'              => $project->getLocation(),
            'teamSize'              => $project->getTeamSize(),
            'capitalSought'         => $project->getCapitalSought(),
            'valuation'             => $project->getValuation(),
            'foundingTarget'        => $project->getFoundingTarget(),
            'previousAmountFunding' => $project->getPreviuosAmountFounding(),
            'previousRound'         => $project->getPreviuosRound(),
            'previousRoundDate'     => $project->getPreviousRoundDate()
                ? $project->getPreviousRoundDate()->format(\DateTimeInterface::ATOM)
                : null,

            'status'      => $project->getStatus(),
            'publishDate' => $project->getPublishDate()
                ? $project->getPublishDate()->format(\DateTimeInterface::ATOM)
                : null,

            'boost'      => $project->getBoost() ?? false,
            'boostDate'  => $project->getBoostDate()
                ? $project->getBoostDate()->format(\DateTimeInterface::ATOM)
                : null,
            'superBoost' => $project->getSuperBoost() ?? false,
            'superBoostDate' => $project->getSuperBoostDate()
                ? $project->getSuperBoostDate()->format(\DateTimeInterface::ATOM)
                : null,

            'media' => [
                'logo' => $project->getLogo()
                    ? $this->serializeMedia($project->getLogo())
                    : null,
                'banner' => $project->getBanner()
                    ? $this->serializeMedia($project->getBanner())
                    : null,
                'pitchDeck' => $project->getPitchDeck()
                    ? $this->serializeMedia($project->getPitchDeck())
                    : null,
                'gallery' => array_values(array_map(
                    fn ($m) => $this->serializeMedia($m),
                    $project->getMediaGallery()
                )),
            ],

            'author' => $author ? $this->serializeUserLite($author) : null,

            'contributors'       => $contributors,          // already built via fetchContributors()
            'contributorsEmails' => array_values(array_unique(array_filter(array_map(
                static fn(array $row) => $row['email'] ?? null,
                $contributors
            )))),
        ];
    }

    /**
     * Return contributors (excluding the author) using the join table.
     * Robust against lazy-loading on GET.
     *
     * @return array<array{id:int, fullName:?string, email:?string, picture:?array, founderHash:?string}>
     * @throws \ReflectionException
     */
    private function fetchContributors(Project $project): array
    {
        $pid = (int) $project->getId();
        if ($pid <= 0) { return []; }

        $authorId = (int) ($project->getAuthor()?->getId() ?? 0);

        $rows = (clone $this->projects->qb)
            ->distinct()
            ->select('pu.user_id AS id')
            ->from('project_user', 'pu')
            ->where('pu.project_id', '=', $pid)
            ->orderBy('pu.user_id', 'ASC')
            ->fetchAll();

        $seen = [];
        $out  = [];

        foreach ($rows as $r) {
            $uid = isset($r['id']) ? (int) $r['id'] : 0;
            if ($uid <= 0 || $uid === $authorId || isset($seen[$uid])) { continue; }
            $seen[$uid] = true;

            /** @var User|null $u */
            $u = $this->users->find($uid);
            if ($u instanceof User) {
                $out[] = $this->serializeUserLite($u);
            }
        }

        return $out;
    }


    /**
     * Small helper to avoid leaking full Media object.
     * Adjust to match your actual Media API surface.
     *
     * @param object $media
     * @return array{id:int|null,url:string|null,type:string|null,hash:string|null}
     */
    private function serializeMedia(object $media): array
    {
        return [
            'id'   => method_exists($media, 'getId')   ? $media->getId()   : null,
            'url'  => method_exists($media, 'getUrl')  ? $media->getUrl()  : null,
            'type' => method_exists($media, 'getType') ? $media->getType() : null,
            'hash' => method_exists($media, 'getHash') ? $media->getHash() : null,
        ];
    }

    /**
     * @throws \Throwable
     * @throws RandomException
     */
    private function createMediaFromNormalizedFile(array $norm, User $user): Media
    {
        // $norm has: clientName, mime, tmpPath, psr (UploadedFileInterface|null)

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

        // If we got a PSR-7 UploadedFile we can just ->moveTo()
        if ($norm['psr'] instanceof UploadedFileInterface) {
            try {
                $norm['psr']->moveTo($absolutePath);
            } catch (\Throwable $e) {
                throw $e;
            }
        } else {
            // fallback: plain PHP tmp file copy
            if (!isset($norm['tmpPath']) || !is_readable($norm['tmpPath'])) {
                throw new RuntimeException('Upload tmp file missing', 500);
            }
            if (!@move_uploaded_file($norm['tmpPath'], $absolutePath)) {
                // if move_uploaded_file fails because tmpPath isn't actually an HTTP upload
                // fallback to rename/copy
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
                'psr'        => $file, // keep original for moveTo
                'size'       => method_exists($file, 'getSize') ? $file->getSize() : null,
            ];
        }

        // array fallback (superglobal style)
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
     * Expand a PHP "multi-file" array (name[], type[], tmp_name[], error[], size[])
     * into a flat list of single-file arrays. If it's already a single file
     * (UploadedFileInterface OR array with scalar 'name'), returns a one-item list.
     * If it's already an array of UploadedFileInterface, returns as-is.
     *
     * @param mixed $raw
     * @return array<int, UploadedFileInterface|array>
     */
    private function expandFileArray($raw): array
    {
        // Case 1: Already an UploadedFileInterface
        if ($raw instanceof UploadedFileInterface) {
            return [$raw];
        }

        // Case 2: Already a flat array (superglobal style) with scalar 'name'
        if (is_array($raw) && isset($raw['name']) && !is_array($raw['name'])) {
            return [$raw];
        }

        // Case 3: PHP multi-file shape: keys with arrays (name[], type[], tmp_name[], error[], size[])
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

        // Case 4: An array of UploadedFileInterface
        if (is_array($raw) && !empty($raw) && $raw[0] instanceof UploadedFileInterface) {
            return $raw;
        }

        // Fallback: unknown/empty -> nothing to process
        return [];
    }

    private function iniBytes(string $key): ?int
    {
        $val = ini_get($key);
        if ($val === false || $val === '') {
            return null;
        }
        $val = trim($val);
        $last = strtolower($val[strlen($val) - 1]);
        $num = (int)$val;
        switch ($last) {
            case 'g': $num *= 1024;
            // no break
            case 'm': $num *= 1024;
            // no break
            case 'k': $num *= 1024;
        }
        return $num;
    }

    #[Route(methods: 'GET', path: '/profiles/{hash}/projects')]
    public function listByProfileHash(ServerRequestInterface $request): JsonResponse
    {
        $hash = $request->getAttribute('hash');
        if (!is_string($hash) || $hash === '') {
            throw new RuntimeException('Invalid profile identifier', 400);
        }

        /** @var Founder|null $f */
        $f = $this->founders->findOneBy(['hash' => $hash]);

        $owner = null;
        if ($f && $f->getUser()) {
            $owner = $f->getUser();
        } else {
            /** @var Investor|null $i */
            $i = $this->investors->findOneBy(['hash' => $hash]);
            if ($i && method_exists($i, 'getUser') && $i->getUser()) {
                $owner = $i->getUser();
            }
        }

        if (!$owner) {
            throw new RuntimeException('Profile not found', 404);
        }

        $uid = (int) $owner->getId();

        /** @var \MonkeysLegion\Repository\EntityRepository $projectRepo */
        $projectRepo = $this->projects;

        $rows = (clone $projectRepo->qb)
            ->distinct()
            ->select('p.id AS id')
            ->from('project', 'p')
            ->leftJoin('project_user', 'pu', 'pu.project_id', '=', 'p.id')
            ->whereRaw('(p.author_id = ? OR pu.user_id = ?)', [$uid, $uid])
            ->orderBy('p.id', 'DESC')          // <- newest id first
            ->fetchAll();

        $projects = [];
        foreach ($rows as $r) {
            $pid = isset($r['id']) ? (int) $r['id'] : 0;
            if ($pid > 0) {
                $p = $projectRepo->find($pid);
                if ($p instanceof Project) {
                    $projects[] = $p;
                }
            }
        }

        $out = array_values(array_filter(array_map(function ($p) {
            return $p instanceof Project ? $this->summarizeProjectForList($p) : null;
        }, $projects)));

        return new JsonResponse($out, 200);
    }

    /**
     * Compact project representation for list endpoints.
     *
     * @return array{
     *   hash:string|null,
     *   title:string|null,
     *   categories:?array,
     *   image:?array{id:int|null,url:string|null,type:string|null,hash:string|null}
     * }
     */
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

    /**
     * Pick the "first" image for a project:
     * priority = banner → logo → first gallery item.
     *
     * @return array{id:int|null,url:string|null,type:string|null,hash:string|null}|null
     */
    private function pickFirstProjectImage(Project $project): ?array
    {
        if ($project->getBanner()) {
            return $this->serializeMedia($project->getBanner());
        }
        if ($project->getLogo()) {
            return $this->serializeMedia($project->getLogo());
        }
        $gallery = $project->getMediaGallery();
        if (!empty($gallery)) {
            // ensure we take the first gallery item deterministically
            $first = reset($gallery);
            if ($first) {
                return $this->serializeMedia($first);
            }
        }
        return null;
    }

    #[Route(methods: 'GET', path: '/projects/{hash}/favorite')]
    public function isFavorite(ServerRequestInterface $request): JsonResponse
    {
        // 1) Soft-fail for unauthenticated or unknown user
        $uid = (int) $request->getAttribute('user_id', 0);
        if ($uid <= 0) {
            return new JsonResponse(['favorited' => false], 200);
        }

        /** @var User|null $user */
        $user = $this->users->find($uid);
        if (!$user) {
            return new JsonResponse(['favorited' => false], 200);
        }

        // 2) Soft-fail on bad/missing hash
        $hash = $request->getAttribute('hash');
        if (!is_string($hash) || $hash === '') {
            return new JsonResponse(['favorited' => false], 200);
        }

        // 3) Soft-fail if project not found
        /** @var ?Project $project */
        $project = $this->projects->findOneBy(['hash' => $hash]);
        if (!$project) {
            return new JsonResponse(['favorited' => false], 200);
        }

        // 4) DB check wrapped in try/catch → never throws to client
        try {
            $cnt = (clone $this->users->qb)
                ->from('favorite_project', 'uf')
                ->where('uf.user_id', '=', $uid)
                ->andWhere('uf.project_id', '=', (int) $project->getId())
                ->count();

            return new JsonResponse(['favorited' => $cnt > 0], 200);
        } catch (\Throwable $e) {
            return new JsonResponse(['favorited' => false], 200);
        }
    }

    /**
     * POST /projects/{hash}/favorite
     * Favorite a project (identified only by its hash).
     * @throws \ReflectionException
     * @throws \JsonException
     */
    #[Route(methods: 'POST', path: '/projects/{hash}/favorite')]
    public function favorite(ServerRequestInterface $request): JsonResponse
    {
        $uid = (int) $request->getAttribute('user_id', 0);
        if ($uid <= 0) {
            throw new \RuntimeException('Unauthorized', 401);
        }

        /** @var User|null $user */
        $user = $this->users->find($uid);
        if (!$user) {
            throw new \RuntimeException('Unauthorized', 401);
        }

        $hash = $request->getAttribute('hash');
        if (!is_string($hash) || $hash === '') {
            throw new \RuntimeException('Invalid project identifier', 400);
        }

        /** @var Project|null $project */
        $project = $this->projects->findOneBy(['hash' => $hash]);
        if (!$project) {
            throw new \RuntimeException('Project not found', 404);
        }

        // Owning side is User.favorites → attach on users repo
        $this->users->attachRelation($user, 'favorites', $project->getId());

        return new JsonResponse([
            'ok'        => true,
            'favorited' => true,
            'project'   => [
                'id'   => $project->getId(),
                'hash' => $project->getHash(),
                'name' => $project->getName(),
            ],
        ], 200);
    }

    /**
     * DELETE /projects/{hash}/favorite
     * Remove from favorites (identified only by hash).
     * @throws \ReflectionException
     * @throws \JsonException
     */
    #[Route(methods: 'DELETE', path: '/projects/{hash}/favorite')]
    public function unfavorite(ServerRequestInterface $request): JsonResponse
    {
        $uid = (int) $request->getAttribute('user_id', 0);
        if ($uid <= 0) {
            throw new \RuntimeException('Unauthorized', 401);
        }

        /** @var User|null $user */
        $user = $this->users->find($uid);
        if (!$user) {
            throw new \RuntimeException('Unauthorized', 401);
        }

        $hash = $request->getAttribute('hash');
        if (!is_string($hash) || $hash === '') {
            throw new \RuntimeException('Invalid project identifier', 400);
        }

        /** @var Project|null $project */
        $project = $this->projects->findOneBy(['hash' => $hash]);
        if (!$project) {
            throw new \RuntimeException('Project not found', 404);
        }

        $this->users->detachRelation($user, 'favorites', $project->getId());

        return new JsonResponse([
            'ok'        => true,
            'favorited' => false,
            'project'   => [
                'id'   => $project->getId(),
                'hash' => $project->getHash(),
                'name' => $project->getName(),
            ],
        ], 200);
    }

    /**
     * @throws \ReflectionException
     * @throws \JsonException
     */
    #[Route(methods: 'GET', path: '/projects/boosted')]
    public function listBoosted(ServerRequestInterface $request): JsonResponse
    {
        $q = $request->getQueryParams();
        $limit = (int)($q['limit'] ?? 24);
        if ($limit <= 0) { $limit = 24; }

        $rows = (clone $this->projects->qb)
            ->distinct()
            ->select('p.id AS id')
            ->from('project', 'p')
            ->whereRaw('COALESCE(p.boost, 0) = 1')
            ->orderByRaw('COALESCE(p.boostDate, p.publishDate) DESC')
            ->orderBy('p.id', 'DESC')
            ->limit($limit)
            ->fetchAll();

        $projects = [];
        foreach ($rows as $r) {
            $pid = isset($r['id']) ? (int) $r['id'] : 0;
            if ($pid > 0) {
                $p = $this->projects->find($pid);
                if ($p instanceof Project) {
                    $projects[] = $p;
                }
            }
        }

        $out = array_values(array_filter(array_map(
            fn ($p) => $p instanceof Project ? $this->summarizeProjectForList($p) : null,
            $projects
        )));

        return new JsonResponse($out, 200);
    }

    /**
     * @throws \ReflectionException
     * @throws \JsonException
     */
    #[Route(methods: 'GET', path: '/projects/superboosted')]
    public function listSuperBoosted(ServerRequestInterface $request): JsonResponse
    {
        $q = $request->getQueryParams();
        $limit = (int)($q['limit'] ?? 24);
        if ($limit <= 0) { $limit = 24; }

        $rows = (clone $this->projects->qb)
            ->distinct()
            ->select('p.id AS id')
            ->from('project', 'p')
            ->whereRaw('COALESCE(p.superBoost, 0) = 1')
            ->orderByRaw('COALESCE(p.superBoostDate, p.publishDate) DESC')
            ->orderBy('p.id', 'DESC')
            ->limit($limit)
            ->fetchAll();

        $projects = [];
        foreach ($rows as $r) {
            $pid = isset($r['id']) ? (int) $r['id'] : 0;
            if ($pid > 0) {
                $p = $this->projects->find($pid);
                if ($p instanceof Project) {
                    $projects[] = $p;
                }
            }
        }

        $out = array_values(array_filter(array_map(
            fn ($p) => $p instanceof Project ? $this->summarizeProjectForList($p) : null,
            $projects
        )));

        return new JsonResponse($out, 200);
    }

    /**
     * @throws \ReflectionException
     * @throws \Throwable
     * @throws \JsonException
     */
    #[Route(methods: 'GET', path: '/projects')]
    public function list(ServerRequestInterface $request): JsonResponse
    {
        $q = $request->getQueryParams();

        // --- pagination ---
        $page = max(1, (int)($q['page'] ?? 1));
        $perPage = (int)($q['perPage'] ?? 24);
        if ($perPage <= 0) $perPage = 24;
        if ($perPage > 100) $perPage = 100;
        $offset = ($page - 1) * $perPage;

        // --- sort mode ---
        $sort = strtolower((string)($q['sort'] ?? 'recent'));
        $orderExpr = match ($sort) {
            'boost' => 'COALESCE(p.superBoostDate, p.boostDate, p.publishDate) ASC, p.id ASC',
            default => 'COALESCE(p.publishDate, p.id) DESC, p.id DESC',
        };

        // --- base query (reused for count & page) ---
        $base = (clone $this->projects->qb)
            ->from('project', 'p');

        // status gate (public only)
        $base->where('p.status', '=', 'published');

        // boosted control
        $boosted   = strtolower((string)($q['boosted'] ?? 'include')); // include|exclude|only
        $superOnly = ((string)($q['superOnly'] ?? '0')) === '1';

        if ($superOnly) {
            $base->whereRaw('COALESCE(p.superBoost, 0) = 1');
        } else {
            if ($boosted === 'exclude') {
                $base->whereRaw('(COALESCE(p.boost, 0) = 0 AND COALESCE(p.superBoost, 0) = 0)');
            } elseif ($boosted === 'only') {
                $base->whereRaw('(COALESCE(p.boost, 0) = 1 OR COALESCE(p.superBoost, 0) = 1)');
            }
        }

        // stage filter (CSV)
        if (!empty($q['stage'])) {
            $stages = array_values(array_filter(array_map('trim', explode(',', (string)$q['stage']))));
            if (!empty($stages)) {
                $base->whereIn('p.stage', $stages);
            }
        }

        // category filter (CSV) — MySQL JSON array using JSON_SEARCH
        if (!empty($q['category'])) {
            $cats = array_values(array_filter(array_map('trim', explode(',', (string)$q['category']))));
            if ($cats) {
                $base->andWhereGroup(function($g) use ($cats) {
                    foreach ($cats as $idx => $c) {
                        if ($idx === 0) {
                            $g->whereRaw('JSON_SEARCH(p.category, "one", ?) IS NOT NULL', [$c]);
                        } else {
                            $g->orWhereRaw('JSON_SEARCH(p.category, "one", ?) IS NOT NULL', [$c]);
                        }
                    }
                });
            }
        }

        // --- location filters ---
        $loc = trim((string)($q['loc'] ?? ''));
        $country = trim((string)($q['country'] ?? ''));
        $state = trim((string)($q['state'] ?? ''));
        $iso2 = strtoupper(trim((string)($q['iso2'] ?? '')));

        // If you store location as JSON object like {"country":"...", "state":"...", "iso2":"US"}
        // AND sometimes as plain string, we match both shapes.
        if ($country !== '') {
            $base->andWhereGroup(function($g) use ($country) {
                $g->whereRaw('LOWER(JSON_UNQUOTE(JSON_EXTRACT(p.location, "$.country"))) LIKE LOWER(?)', ['%' . $country . '%'])
                    ->orWhereLike('LOWER(p.location)', '%' . mb_strtolower($country) . '%');
            });
        }
        if ($state !== '') {
            $base->andWhereGroup(function($g) use ($state) {
                $g->whereRaw('LOWER(JSON_UNQUOTE(JSON_EXTRACT(p.location, "$.state"))) LIKE LOWER(?)', ['%' . $state . '%'])
                    ->orWhereLike('LOWER(p.location)', '%' . mb_strtolower($state) . '%');
            });
        }
        if ($iso2 !== '' && strlen($iso2) === 2) {
            $base->andWhereGroup(function($g) use ($iso2) {
                $g->whereRaw('UPPER(JSON_UNQUOTE(JSON_EXTRACT(p.location, "$.iso2"))) = ?', [$iso2])
                    ->orWhereRaw('UPPER(p.location) LIKE ?', ['%' . $iso2 . '%']);
            });
        }
        if ($loc !== '') {
            $like = '%' . mb_strtolower($loc) . '%';
            $base->andWhereGroup(function ($g) use ($like) {
                $g->whereLike('LOWER(JSON_UNQUOTE(JSON_EXTRACT(p.location, "$.country")))', $like)
                    ->orWhereLike('LOWER(JSON_UNQUOTE(JSON_EXTRACT(p.location, "$.state")))', $like)
                    ->orWhereLike('LOWER(JSON_UNQUOTE(JSON_EXTRACT(p.location, "$.city")))', $like)
                    ->orWhereLike('LOWER(p.location)', $like);
            });
        }

        // simple text search (split words; each word must match any searchable field)
        if (!empty($q['q'])) {
            $needle = trim((string)$q['q']);
            $words = preg_split('/\s+/', mb_strtolower($needle)) ?: [];
            foreach ($words as $w) {
                if ($w === '') continue;
                $like = '%' . $w . '%';
                $base->andWhereGroup(function ($g) use ($like) {
                    $g->whereLike('LOWER(p.name)', $like)
                        ->orWhereLike('LOWER(p.tagline)', $like)
                        ->orWhereLike('LOWER(p.elevatorPitch)', $like)
                        ->orWhereLike('LOWER(p.problemStatement)', $like)
                        ->orWhereLike('LOWER(p.solution)', $like)
                        ->orWhereLike('LOWER(p.model)', $like)
                        ->orWhereLike('LOWER(p.traction)', $like)
                        ->orWhereLike('LOWER(JSON_UNQUOTE(JSON_EXTRACT(p.location, "$.country")))', $like)
                        ->orWhereLike('LOWER(JSON_UNQUOTE(JSON_EXTRACT(p.location, "$.state")))', $like)
                        ->orWhereLike('LOWER(JSON_UNQUOTE(JSON_EXTRACT(p.location, "$.city")))', $like)
                        ->orWhereLike('LOWER(p.location)', $like);
                });
            }
        }

        // --- totals using builder's count() ---
        $total = $base->duplicate()->count();
        $pages = (int)max(1, (int)ceil($total / max(1, $perPage)));

        // --- page of IDs ---
        $idRows = $base->duplicate()
            ->distinct()
            ->select('p.id AS id')
            ->orderByRaw($orderExpr)
            ->limit($perPage)
            ->offset($offset)
            ->fetchAll();

        // hydrate & summarize
        $items = [];
        foreach ($idRows as $r) {
            $pid = isset($r['id']) ? (int)$r['id'] : 0;
            if ($pid <= 0) continue;
            $p = $this->projects->find($pid);
            if ($p instanceof Project) {
                $items[] = $this->summarizeProjectForList($p);
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
     * Placeholder for future invitation scheduling.
     * Return true once you actually enqueue or send invites.
     */
    private function maybeScheduleContributorInvites(array $emails, Project $project, User $inviter): bool
    {
        // Normalize and validate
        $valid = [];
        foreach ($emails as $raw) {
            $email = strtolower(trim((string)$raw));
            if ($email && filter_var($email, FILTER_VALIDATE_EMAIL)) {
                $valid[$email] = true;
            }
        }
        if (!$valid) return false;

        // Context
        $inviterName = trim((string)($inviter->getFullName() ?? '')) ?: ($inviter->getEmail() ?? 'A MonkeysRaiser user');
        $projectName = (string)($project->getName() ?? 'a project');
        $projectTagline = (string)($project->getTagline() ?? '');
        $projectHash = (string)($project->getHash() ?? '');

        $frontendBase = rtrim((string)(getenv('FRONTEND_BASE_URL') ?: 'https://monkeysraiser.com'), '/');
        $projectUrl = $frontendBase . '/projects/' . rawurlencode($projectHash);

        $subject = sprintf('%s invited you to collaborate on %s', $inviterName, $projectName);

        $sentAny = false;

        foreach (array_keys($valid) as $email) {
            try {
                // Render the email template via MonkeysLegion Renderer
                $html = $this->renderer->render('emails.contributor_invite', [
                    'projectName'    => $projectName,
                    'projectTagline' => $projectTagline,
                    'projectUrl'     => $projectUrl,
                    'inviterName'    => $inviterName,
                    'email'          => $email,
                ]);

                // Send with MonkeysMailService
                $this->mail->sendSimple(
                    $email,
                    $subject,
                    $html,
                    null,
                    null,
                    null,
                    false,
                    [
                        'tags' => ['contributor_invite', 'projects'],
                        'metadata' => [
                            'projectId'   => $project->getId(),
                            'projectHash' => $projectHash,
                            'inviterId'   => $inviter->getId(),
                            'email'       => $email,
                        ],
                    ]
                );

                $sentAny = true;

            } catch (\Throwable $e) {
                error_log('[CONTRIB_INVITE] Failed for '.$email.': '.$e->getMessage());
            }
        }

        return $sentAny;
    }

    /**
     * Best-effort founder hash lookup for a given user.
     * Tries the relation first, then falls back to a direct query.
     * @throws \ReflectionException
     */
    private function founderHashByUserId(int $userId): ?string
    {
        if ($userId <= 0) return null;

        // Try entity relation path quickly (if hydrated).
        /** @var User|null $u */
        $u = $this->users->find($userId);
        if ($u instanceof User) {
            $related = $u->getFounder();
            if ($related && method_exists($related, 'getHash')) {
                $hash = $related->getHash();
                if (is_string($hash) && $hash !== '') {
                    return $hash;
                }
            }
        }

        // Fallback: query founders by user_id.
        $rows = (clone $this->founders->qb)
            ->select('f.hash AS hash')
            ->from('founder', 'f')
            ->where('f.user_id', '=', $userId)
            ->orderBy('f.id', 'DESC')
            ->fetchAll();

        if (!empty($rows)) {
            $hash = (string)($rows[0]['hash'] ?? '');
            return $hash !== '' ? $hash : null;
        }

        return null;
    }

    /**
     * GET /me/favorites
     * Returns the current user's favorited projects (compact list).
     *
     * Query params:
     *  - page:    int (default 1)
     *  - perPage: int (default 24, max 100)
     *  - includeUnpublished: "1" to include drafts (default "0" = published only)
     *
     * @throws \JsonException
     * @throws \ReflectionException
     */
    #[Route(methods: 'GET', path: '/me/favorites')]
    public function listMyFavorites(ServerRequestInterface $request): JsonResponse
    {

        try {
            // --- Auth ---
            $uid = (int) $request->getAttribute('user_id', 0);

            /** @var User|null $me */
            $me = $this->users->find($uid);
            if (!$me) {
                throw new \RuntimeException('Unauthorized', 401);
            }
            // Use the actual user id so WHERE uf.user_id = ? is correct
            $uid = (int) $me->getId();

            // --- pagination + flags ---
            $q = $request->getQueryParams();
            $page    = max(1, (int)($q['page'] ?? 1));
            $perPage = (int)($q['perPage'] ?? 24);
            if ($perPage <= 0)  { $perPage = 24; }
            if ($perPage > 100) { $perPage = 100; }
            $offset  = ($page - 1) * $perPage;
            $includeUnpublished = ((string)($q['includeUnpublished'] ?? '0')) === '1';

            // --- Base query over favorites join table ---
            $base = (clone $this->users->qb)
                ->from('favorite_project', 'uf')
                ->leftJoin('project', 'p', 'p.id', '=', 'uf.project_id')
                ->where('uf.user_id', '=', $uid);

            if (!$includeUnpublished) {
                $base->andWhere('p.status', '=', 'published');
            }

            // Totals
            $total = 0;
            try {
                $total = $base->duplicate()->count();
            } catch (\Throwable $e) {
                $total = 0;
            }
            $pages = (int) max(1, (int) ceil($total / max(1, $perPage)));

            // Page rows: prefer favorite recency if column exists; otherwise project recency
            $qPage = $base->duplicate()
                ->select('p.id AS id');

            $qPage->limit($perPage)->offset($offset);

            $idRows = [];
            try {
                $idRows = $qPage->fetchAll();
            } catch (\Throwable $e) {
                $idRows = [];
            }

            // Hydrate & summarize
            $items = [];
            $hydrOk = 0; $hydrMiss = 0;
            foreach ($idRows as $idx => $r) {
                $pid = isset($r['id']) ? (int)$r['id'] : 0;
                if ($pid <= 0) {
                    $hydrMiss++;
                    continue;
                }

                /** @var \App\Entity\Project|null $p */
                $p = $this->projects->find($pid);
                if (!$p instanceof Project) {
                    $hydrMiss++;
                    continue;
                }

                $row = $this->summarizeProjectForList($p);
                $row['favorited'] = true;
                $items[] = $row;
                $hydrOk++;
            }

            $payload = [
                'page'    => $page,
                'perPage' => $perPage,
                'total'   => $total,
                'pages'   => $pages,
                'items'   => $items,
            ];

            return new JsonResponse($payload, 200);

        } catch (\Throwable $e) {
            throw $e;
        }
    }

    /**
     * GET /me/projects
     * Returns projects where the current user is author and (optionally) contributor.
     *
     * Query params:
     *  - page, perPage
     *  - includeContributed=1|0   (default 1)
     *  - includeUnpublished=1|0   (default 0 -> published only)
     *  - q, stage, category, country, state, iso2, loc
     *  - sort=recent|boost        (default recent)
     */
    #[Route(methods: 'GET', path: '/me/projects')]
    public function listMyProjects(ServerRequestInterface $request): JsonResponse
    {
        try {
            // --- Auth ---
            $uid = (int) $request->getAttribute('user_id', 0);
            /** @var User|null $me */
            $me = $this->users->find($uid);
            if (!$me) {
                throw new \RuntimeException('Unauthorized', 401);
            }
            $uid = (int) $me->getId();

            // --- pagination + flags ---
            $q = $request->getQueryParams();
            $page    = max(1, (int)($q['page'] ?? 1));
            $perPage = (int)($q['perPage'] ?? 24);
            if ($perPage <= 0)  { $perPage = 24; }
            if ($perPage > 100) { $perPage = 100; }
            $offset  = ($page - 1) * $perPage;

            $includeContributed = ((string)($q['includeContributed'] ?? '1')) === '1';
            $includeUnpublished = ((string)($q['includeUnpublished'] ?? '0')) === '1';

            $sort = strtolower((string)($q['sort'] ?? 'recent'));
            $orderExpr = match ($sort) {
                'boost' => 'COALESCE(p.superBoostDate, p.boostDate, p.publishDate, p.updateDate, p.id) DESC, p.id DESC',
                default => 'COALESCE(p.publishDate, p.updateDate, p.id) DESC, p.id DESC',
            };

            // --- base query (author or contributor) ---
            $base = (clone $this->projects->qb)
                ->from('project', 'p')
                ->leftJoin('project_user', 'pu', 'pu.project_id', '=', 'p.id');

            if ($includeContributed) {
                $base->whereGroup(function($g) use ($uid) {
                    $g->where('p.author_id', '=', $uid)
                        ->orWhere('pu.user_id', '=', $uid);
                });
            } else {
                $base->where('p.author_id', '=', $uid);
            }

            if (!$includeUnpublished) {
                $base->andWhere('p.status', '=', 'published');
            }

            // --- stage filter (CSV) ---
            if (!empty($q['stage'])) {
                $stages = array_values(array_filter(array_map('trim', explode(',', (string)$q['stage']))));
                if ($stages) {
                    $base->whereIn('p.stage', $stages);
                }
            }

            // --- category filter (CSV) -> single raw OR group ---
            if (!empty($q['category'])) {
                $cats = array_values(array_filter(array_map('trim', explode(',', (string)$q['category']))));
                if ($cats) {
                    $place = implode(' OR ', array_fill(0, count($cats), 'JSON_SEARCH(p.category, "one", ?) IS NOT NULL'));
                    $base->whereRaw('(' . $place . ')', $cats);
                }
            }

            // --- location filters (each as ONE raw clause) ---
            $country = trim((string)($q['country'] ?? ''));
            if ($country !== '') {
                $like = '%' . mb_strtolower($country) . '%';
                $base->whereRaw('(LOWER(JSON_UNQUOTE(JSON_EXTRACT(p.location, "$.country"))) LIKE ? OR LOWER(p.location) LIKE ?)', [$like, $like]);
            }

            $state = trim((string)($q['state'] ?? ''));
            if ($state !== '') {
                $like = '%' . mb_strtolower($state) . '%';
                $base->whereRaw('(LOWER(JSON_UNQUOTE(JSON_EXTRACT(p.location, "$.state"))) LIKE ? OR LOWER(p.location) LIKE ?)', [$like, $like]);
            }

            $iso2 = strtoupper(trim((string)($q['iso2'] ?? '')));
            if ($iso2 !== '' && strlen($iso2) === 2) {
                $base->whereRaw('(UPPER(JSON_UNQUOTE(JSON_EXTRACT(p.location, "$.iso2"))) = ? OR UPPER(p.location) LIKE ?)', [$iso2, '%'.$iso2.'%']);
            }

            $loc = trim((string)($q['loc'] ?? ''));
            if ($loc !== '') {
                $like = '%' . mb_strtolower($loc) . '%';
                $base->whereRaw('('
                    . 'LOWER(JSON_UNQUOTE(JSON_EXTRACT(p.location, "$.country"))) LIKE ? OR '
                    . 'LOWER(JSON_UNQUOTE(JSON_EXTRACT(p.location, "$.state"))) LIKE ? OR '
                    . 'LOWER(JSON_UNQUOTE(JSON_EXTRACT(p.location, "$.city"))) LIKE ? OR '
                    . 'LOWER(p.location) LIKE ?'
                    . ')', [$like, $like, $like, $like]
                );
            }

            // --- text search (words ANDed; each word ORs across fields) in ONE raw clause per "q" ---
            if (!empty($q['q'])) {
                $needle = trim((string)$q['q']);
                $words = array_values(array_filter(preg_split('/\s+/', mb_strtolower($needle)) ?: []));
                if ($words) {
                    $fields = [
                        'LOWER(p.name)',
                        'LOWER(p.tagline)',
                        'LOWER(p.elevatorPitch)',
                        'LOWER(p.problemStatement)',
                        'LOWER(p.solution)',
                        'LOWER(p.model)',
                        'LOWER(p.traction)',
                        'LOWER(JSON_UNQUOTE(JSON_EXTRACT(p.location, "$.country")))',
                        'LOWER(JSON_UNQUOTE(JSON_EXTRACT(p.location, "$.state")))',
                        'LOWER(JSON_UNQUOTE(JSON_EXTRACT(p.location, "$.city")))',
                        'LOWER(p.location)',
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

            // --- TOTALS (manual COUNT subquery; do NOT use qb->count()) ---
            $countQb = $base->duplicate()->distinct()->select('p.id');
            $innerSql    = $countQb->toSql();
            $innerParams = $countQb->getParams();
            $sqlCount = 'SELECT COUNT(*) AS cnt FROM (' . $innerSql . ') AS t';

            $pdo  = $this->projects->qb->connection()->pdo();
            $stmt = $pdo->prepare($sqlCount);
            $stmt->execute($innerParams);
            $row   = $stmt->fetch(\PDO::FETCH_ASSOC);
            $total = (int)($row['cnt'] ?? 0);
            $pages = (int) max(1, (int) ceil($total / max(1, $perPage)));

            // --- page ids ---
            $qPage = $base->duplicate()
                ->distinct()
                ->select('p.id AS id')
                ->orderByRaw($orderExpr)
                ->limit($perPage)
                ->offset($offset);

            $idRows = [];
            try {
                $idRows = $qPage->fetchAll();
            } catch (\Throwable $e) {
                $idRows = [];
            }

            // hydrate & summarize
            $items = [];
            $hydrOk = 0; $hydrMiss = 0;
            foreach ($idRows as $idx => $r) {
                $pid = isset($r['id']) ? (int)$r['id'] : 0;
                if ($pid <= 0) {
                    $hydrMiss++;
                    continue;
                }

                /** @var \App\Entity\Project|null $p */
                $p = $this->projects->find($pid);
                if (!$p instanceof Project) {
                    $hydrMiss++;
                    continue;
                }

                $row = $this->summarizeProjectForList($p);
                $row['isOwner']       = (int)($p->getAuthor()?->getId() ?? 0) === $uid;
                $row['isContributor'] = !$row['isOwner'];
                $items[] = $row;
                $hydrOk++;
            }

            return new JsonResponse([
                'page'    => $page,
                'perPage' => $perPage,
                'total'   => $total,
                'pages'   => $pages,
                'items'   => $items,
            ], 200);

        } catch (\Throwable $e) {
            throw $e;
        }
    }

    /**
     * Compact user serializer for API payloads.
     * Includes id, name, email, picture (Media), and founderHash.
     *
     * @return array{id:int, fullName:?string, email:?string, picture:?array, founderHash:?string}
     */
    private function serializeUserLite(User $u): array
    {
        $pic = $u->getPicture();
        return [
            'id'          => $u->getId(),
            'fullName'    => $u->getFullName(),
            'email'       => $u->getEmail(),
            'picture'     => $pic ? $this->serializeMedia($pic) : null,
            'founderHash' => $this->founderHashByUserId($u->getId()),
        ];
    }

    /**
     * GET /me/projects/keys
     * Returns only { name, hash } for projects where the current user
     * is the author or (optionally) a contributor.
     *
     * Query params:
     *  - includeContributed=1|0   (default 1)
     *  - includeUnpublished=1|0   (default 0 -> published only)
     * @throws \ReflectionException
     */
    #[Route(methods: 'GET', path: '/me/projects/keys')]
    public function listMyProjectKeys(ServerRequestInterface $request): JsonResponse
    {
        // --- Auth ---
        $uid = (int) $request->getAttribute('user_id', 0);
        /** @var User|null $me */
        $me = $this->users->find($uid);
        if (!$me) {
            throw new \RuntimeException('Unauthorized', 401);
        }
        $uid = (int) $me->getId();

        // --- Flags ---
        $q = $request->getQueryParams();
        $includeContributed = ((string)($q['includeContributed'] ?? '1')) === '1';
        $includeUnpublished = ((string)($q['includeUnpublished'] ?? '0')) === '1';

        // --- Query (IDs only) ---
        $qb = (clone $this->projects->qb)
            ->distinct()
            ->select('p.hash AS hash, p.name AS name')
            ->from('project', 'p')
            ->leftJoin('project_user', 'pu', 'pu.project_id', '=', 'p.id');

        if ($includeContributed) {
            $qb->whereGroup(function($g) use ($uid) {
                $g->where('p.author_id', '=', $uid)
                    ->orWhere('pu.user_id', '=', $uid);
            });
        } else {
            $qb->where('p.author_id', '=', $uid);
        }

        if (!$includeUnpublished) {
            $qb->andWhere('p.status', '=', 'published');
        }

        // Sort by recency-ish (publish/update/id)
        $qb->orderByRaw('COALESCE(p.publishDate, p.updateDate, p.id) DESC')
            ->orderBy('p.id', 'DESC');

        $rows = [];
        try {
            $rows = $qb->fetchAll();
        } catch (\Throwable $e) {
            $rows = [];
        }

        // Normalize & dedupe just in case
        $seen = [];
        $items = [];
        foreach ($rows as $r) {
            $hash = (string)($r['hash'] ?? '');
            if ($hash === '' || isset($seen[$hash])) { continue; }
            $seen[$hash] = true;
            $items[] = [
                'name' => isset($r['name']) ? (string)$r['name'] : null,
                'hash' => $hash,
            ];
        }

        return new JsonResponse($items, 200);
    }

    /**
     * Auth guard: requires an authenticated user, no admin check.
     * @throws \ReflectionException
     */
    private function requireAdmin(ServerRequestInterface $request): User
    {
        $uid = (int) $request->getAttribute('user_id', 0);
        error_log("[ADMIN/USER_ID] $uid");
        /** @var User|null $u */
        $u = $this->users->find($uid);
        if (!$u) {
            throw new RuntimeException('Unauthorized', 401);
        }
        // No role checks — any authenticated user is allowed.
        return $u;
    }

    /**
     * GET /admin/projects
     * Admin search/list with full filters & pagination.
     *
     * Query params:
     *  - page, perPage
     *  - sort=recent|boost        (default recent)
     *  - status: CSV (draft,pending_review,published,rejected,archived)
     *  - authorId: int
     *  - boosted=include|exclude|only
     *  - superOnly=1|0
     *  - stage, category (CSV)
     *  - country, state, iso2, loc
     *  - q (text search)
     * @throws \Throwable
     */
    #[Route(methods: 'GET', path: '/admin/projects')]
    public function adminList(ServerRequestInterface $request): JsonResponse
    {

        try {
            // --- Auth (comment out if intentionally open) ---
            $this->requireAdmin($request);
        } catch (\Throwable $e) {
            throw $e; // keep existing behavior; comment this line if you want to proceed without auth
        }

        try {
            $q = $request->getQueryParams();

            // pagination
            $page = max(1, (int)($q['page'] ?? 1));
            $perPage = (int)($q['perPage'] ?? 24);
            if ($perPage <= 0) $perPage = 24;
            if ($perPage > 100) $perPage = 100;
            $offset = ($page - 1) * $perPage;

            // sort
            $sort = strtolower((string)($q['sort'] ?? 'recent'));
            $orderExpr = match ($sort) {
                'boost' => 'COALESCE(p.superBoostDate, p.boostDate, p.publishDate, p.updateDate, p.id) DESC, p.id DESC',
                default => 'COALESCE(p.publishDate, p.updateDate, p.id) DESC, p.id DESC',
            };

            $base = (clone $this->projects->qb)->from('project', 'p');

            // status filter (CSV), default = all
            if (!empty($q['status'])) {
                $allowed = ['draft','pending_review','published','rejected','archived'];
                $statuses = array_values(array_filter(array_map('trim', explode(',', (string)$q['status'])), function($s) use ($allowed){
                    return in_array($s, $allowed, true);
                }));
                if ($statuses) {
                    $base->whereIn('p.status', $statuses);
                }
            }

            // author filter
            if (!empty($q['authorId'])) {
                $authorId = (int) $q['authorId'];
                if ($authorId > 0) {
                    $base->andWhere('p.author_id', '=', $authorId);
                }
            }

            // boost filters
            $boosted   = strtolower((string)($q['boosted'] ?? 'include')); // include|exclude|only
            $superOnly = ((string)($q['superOnly'] ?? '0')) === '1';
            if ($superOnly) {
                $base->whereRaw('COALESCE(p.superBoost, 0) = 1');
            } else {
                if ($boosted === 'exclude') {
                    $base->whereRaw('(COALESCE(p.boost, 0) = 0 AND COALESCE(p.superBoost, 0) = 0)');
                } elseif ($boosted === 'only') {
                    $base->whereRaw('(COALESCE(p.boost, 0) = 1 OR COALESCE(p.superBoost, 0) = 1)');
                }
            }

            // stage filter (CSV)
            if (!empty($q['stage'])) {
                $stages = array_values(array_filter(array_map('trim', explode(',', (string)$q['stage']))));
                if ($stages) {
                    $base->whereIn('p.stage', $stages);
                }
            }

            // category filter (CSV) — JSON array match
            if (!empty($q['category'])) {
                $cats = array_values(array_filter(array_map('trim', explode(',', (string)$q['category']))));
                if ($cats) {
                    $base->whereGroup(function($g) use ($cats) {
                        foreach ($cats as $i => $c) {
                            $clause = 'JSON_SEARCH(p.category, "one", ?) IS NOT NULL';
                            if ($i === 0) $g->whereRaw($clause, [$c]); else $g->orWhereRaw($clause, [$c]);
                        }
                    });
                }
            }

            // location filters
            $country = trim((string)($q['country'] ?? ''));
            $state   = trim((string)($q['state']   ?? ''));
            $iso2    = strtoupper(trim((string)($q['iso2'] ?? '')));
            $loc     = trim((string)($q['loc']     ?? ''));

            if ($country !== '') {
                $like = '%' . mb_strtolower($country) . '%';
                $base->whereRaw('(LOWER(JSON_UNQUOTE(JSON_EXTRACT(p.location, "$.country"))) LIKE ? OR LOWER(p.location) LIKE ?)', [$like, $like]);
            }
            if ($state !== '') {
                $like = '%' . mb_strtolower($state) . '%';
                $base->whereRaw('(LOWER(JSON_UNQUOTE(JSON_EXTRACT(p.location, "$.state"))) LIKE ? OR LOWER(p.location) LIKE ?)', [$like, $like]);
            }
            if ($iso2 !== '' && strlen($iso2) === 2) {
                $base->whereRaw('(UPPER(JSON_UNQUOTE(JSON_EXTRACT(p.location, "$.iso2"))) = ? OR UPPER(p.location) LIKE ?)', [$iso2, '%'.$iso2.'%']);
            }
            if ($loc !== '') {
                $like = '%' . mb_strtolower($loc) . '%';
                $base->whereRaw('('
                    . 'LOWER(JSON_UNQUOTE(JSON_EXTRACT(p.location, "$.country"))) LIKE ? OR '
                    . 'LOWER(JSON_UNQUOTE(JSON_EXTRACT(p.location, "$.state"))) LIKE ? OR '
                    . 'LOWER(JSON_UNQUOTE(JSON_EXTRACT(p.location, "$.city"))) LIKE ? OR '
                    . 'LOWER(p.location) LIKE ?'
                    . ')', [$like, $like, $like, $like]
                );
            }

            // text search
            if (!empty($q['q'])) {
                $needle = trim((string)$q['q']);
                $words = array_values(array_filter(preg_split('/\s+/', mb_strtolower($needle)) ?: []));
                if ($words) {
                    $fields = [
                        'LOWER(p.name)','LOWER(p.tagline)','LOWER(p.elevatorPitch)',
                        'LOWER(p.problemStatement)','LOWER(p.solution)','LOWER(p.model)','LOWER(p.traction)',
                        'LOWER(JSON_UNQUOTE(JSON_EXTRACT(p.location, "$.country")))',
                        'LOWER(JSON_UNQUOTE(JSON_EXTRACT(p.location, "$.state")))',
                        'LOWER(JSON_UNQUOTE(JSON_EXTRACT(p.location, "$.city")))',
                        'LOWER(p.location)',
                    ];
                    $andClauses = []; $params = [];
                    foreach ($words as $w) {
                        $like = '%'.$w.'%';
                        $orParts = [];
                        foreach ($fields as $f) { $orParts[] = "$f LIKE ?"; $params[] = $like; }
                        $andClauses[] = '(' . implode(' OR ', $orParts) . ')';
                    }
                    $base->whereRaw('(' . implode(' AND ', $andClauses) . ')', $params);
                }
            }

            // totals
            $total = 0;
            try {
                $total = $base->duplicate()->count();
            } catch (\Throwable $e) {
                throw $e;
            }

            $pages = (int) max(1, (int) ceil($total / max(1, $perPage)));

            // page ids
            $idRows = [];
            try {
                $idRows = $base->duplicate()
                    ->distinct()
                    ->select('p.id AS id')
                    ->orderByRaw($orderExpr)
                    ->limit($perPage)
                    ->offset($offset)
                    ->fetchAll();
            } catch (\Throwable $e) {
                throw $e;
            }

            $items = [];
            $iter = 0;
            foreach ($idRows as $r) {
                $iter++;
                $pid = (int)($r['id'] ?? 0);
                if ($pid <= 0) {
                    continue;
                }
                try {
                    $p = $this->projects->find($pid);
                } catch (\Throwable $e) {
                    continue;
                }

                if ($p instanceof Project) {
                    try {
                        $row = $this->summarizeProjectForList($p);
                        $row['id']          = $p->getId();
                        $row['status']      = $p->getStatus();
                        $row['publishDate'] = $p->getPublishDate() ? $p->getPublishDate()->format(\DateTimeInterface::ATOM) : null;
                        $row['updateDate']  = $p->getUpdateDate() ? $p->getUpdateDate()->format(\DateTimeInterface::ATOM) : null;
                        $row['author']      = $p->getAuthor() ? $this->serializeUserLite($p->getAuthor()) : null;
                        $row['superBoost']  = $p->getSuperBoost() ?? false;
                        $row['boost']       = $p->getBoost() ?? false;
                        $items[] = $row;
                    } catch (\Throwable $e) {
                    }
                }
            }

            return new JsonResponse([
                'page'    => $page,
                'perPage' => $perPage,
                'total'   => $total,
                'pages'   => $pages,
                'items'   => $items,
            ], 200);
        } catch (\Throwable $e) {
            throw $e;
        }
    }

    /**
     * POST /admin/projects/{id}
     * Admin edit a project by numeric id. Accepts JSON or multipart with "data".
     * Fields: any of the public fields + status (draft|pending_review|published|rejected|archived),
     * boost/superBoost flags and optional *_Date overrides (ISO8601).
     * Also supports media uploads: logo, banner, pitchDeck, gallery[].
     * @throws \ReflectionException
     * @throws \DateMalformedStringException
     */
    #[Route(methods: 'POST', path: '/admin/projects/{id}')]
    public function adminUpdate(ServerRequestInterface $request): JsonResponse
    {

        /** @var User $admin */
        $admin = $this->requireAdmin($request);

        $id = (int) $request->getAttribute('id');

        if ($id <= 0) {
            throw new RuntimeException('Invalid project id', 400);
        }

        /** @var Project|null $project */
        $project = $this->projects->find($id);
        if (!$project) {
            throw new RuntimeException('Project not found', 404);
        }

        // parse body (json or multipart)
        $parsed = $request->getParsedBody();
        $isMultipart = is_array($parsed) && array_key_exists('data', $parsed);

        $raw = $isMultipart ? (string)($parsed['data'] ?? '') : (string)$request->getBody();

        try {
            $data = $raw !== '' ? json_decode($raw, true, 512, JSON_THROW_ON_ERROR) : [];
        } catch (\Throwable $e) {
            throw new RuntimeException('Invalid JSON', 400);
        }
        if (!is_array($data)) { $data = []; }

        $trimOrNull = static fn($v) => (isset($v) && trim((string)$v) !== '') ? trim((string)$v) : null;
        $truthy = static fn($v): bool => ($v === true || $v === 1 || $v === '1' || $v === 'true' || $v === 'on');

        // text fields
        foreach (['name','tagline','stage','elevatorPitch','problemStatement','solution','model','traction','demoVideo','previousRound'] as $k) {
            if (array_key_exists($k, $data)) {
                $setter = 'set' . ucfirst($k);
                if (method_exists($project, $setter)) {
                    try {
                        $val = $trimOrNull($data[$k]);
                        $project->$setter($val);
                    } catch (\Throwable $e) {
                        throw $e;
                    }
                }
            }
        }

        // arrays
        if (array_key_exists('category', $data)) {
            $v = is_array($data['category']) ? $data['category'] : null;
            $project->setCategory($v);
        }
        if (array_key_exists('urls', $data)) {
            $v = is_array($data['urls']) ? $data['urls'] : null;
            $project->setUrls($v);
        }
        if (array_key_exists('social', $data)) {
            $v = is_array($data['social']) ? $data['social'] : null;
            $project->setSocial($v);
        }
        if (array_key_exists('location', $data)) {
            $v = is_array($data['location']) ? $data['location'] : null;
            $project->setLocation($v);
        }

        // numbers
        foreach (['teamSize','capitalSought','valuation','foundingTarget','previousAmountFunding','currentFoundingAmount'] as $k) {
            if (array_key_exists($k, $data)) {
                $setter = 'set' . ucfirst($k);
                if (method_exists($project, $setter)) {
                    try {
                        $v = ($data[$k] !== null && $data[$k] !== '') ? (int)$data[$k] : null;
                        $project->$setter($v);
                    } catch (\Throwable $e) {
                        throw $e;
                    }
                }
            }
        }

        // founded (Y / Y-m / Y-m-d allowed)
        if (array_key_exists('founded', $data)) {
            $foundedRaw = $trimOrNull($data['founded']);
            $founded = null;
            try {
                if ($foundedRaw) {
                    if (preg_match('/^\d{4}$/', $foundedRaw)) {
                        $founded = new \DateTimeImmutable($foundedRaw . '-01-01', new \DateTimeZone('UTC'));
                    } elseif (preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $foundedRaw)) {
                        $founded = new \DateTimeImmutable($foundedRaw . '-01', new \DateTimeZone('UTC'));
                    } else {
                        $tmp = new \DateTimeImmutable($foundedRaw, new \DateTimeZone('UTC'));
                        $founded = new \DateTimeImmutable($tmp->format('Y-m-d'), new \DateTimeZone('UTC'));
                    }
                }
                $project->setFounded($founded);
            } catch (\Throwable $e) {
                throw $e;
            }
        }

        // previousRoundDate & publishDate override (ISO8601)
        $parseIso = static function (?string $raw): ?\DateTimeImmutable {
            if (!$raw) return null;
            try {
                return new \DateTimeImmutable($raw, new \DateTimeZone('UTC'));
            } catch (\Throwable $e) {
                throw $e;
            }
        };
        if (array_key_exists('previousRoundDate', $data)) {
            $rawPrev = $trimOrNull($data['previousRoundDate']);
            $project->setPreviousRoundDate($parseIso($rawPrev));
        }
        if (array_key_exists('publishDate', $data)) {
            $rawPub = $trimOrNull($data['publishDate']);
            $project->setPublishDate($parseIso($rawPub));
        }

        // status (admin can set any of these)
        if (array_key_exists('status', $data)) {
            $statusRaw = strtolower((string)$data['status']);
            $allowed = ['draft','pending_review','published','rejected','archived'];
            if (!in_array($statusRaw, $allowed, true)) {
                throw new RuntimeException('Invalid status', 400);
            }
            $project->setStatus($statusRaw);
        }

        // boost/superBoost flags + dates
        if (array_key_exists('boost', $data)) {
            $flag = $truthy($data['boost']);
            $project->setBoost($flag);
            if ($project->getBoost() && !$project->getBoostDate()) {
                $project->setBoostDate(new \DateTimeImmutable('now', new \DateTimeZone('UTC')));
            }
        }
        if (array_key_exists('boostDate', $data)) {
            $raw = $trimOrNull($data['boostDate']);
            $project->setBoostDate($parseIso($raw));
        }

        if (array_key_exists('superBoost', $data)) {
            $flag = $truthy($data['superBoost']);
            $project->setSuperBoost($flag);
            if ($project->getSuperBoost() && !$project->getSuperBoostDate()) {
                $project->setSuperBoostDate(new \DateTimeImmutable('now', new \DateTimeZone('UTC')));
            }
        }
        if (array_key_exists('superBoostDate', $data)) {
            $raw = $trimOrNull($data['superBoostDate']);
            $project->setSuperBoostDate($parseIso($raw));
        }

        // optional media removals
        if (($data['removeLogo'] ?? null) !== null && $truthy($data['removeLogo'])) {
            $project->removeLogo();
        }
        if (($data['removeBanner'] ?? null) !== null && $truthy($data['removeBanner'])) {
            $project->removeBanner();
        }
        if (($data['removePitchDeck'] ?? null) !== null && $truthy($data['removePitchDeck'])) {
            $project->removePitchDeck();
        }

        // save base fields before media
        try {
            $project->setUpdateDate(new \DateTimeImmutable('now', new \DateTimeZone('UTC')));
            $this->projects->save($project);
        } catch (\Throwable $e) {
            throw $e;
        }

        // media uploads (if multipart)
        if ($isMultipart) {
            try {
                $this->processMediaUploads($request, $project, $admin);
            } catch (RandomException $e) {
                throw $e;
            } catch (\Throwable $e) {
                throw $e;
            }

            try {
                $this->projects->save($project);
            } catch (\Throwable $e) {
                throw $e;
            }
        }

        // (optional) change author
        if (isset($data['authorId'])) {
            $newAuthorId = (int) $data['authorId'];
            if ($newAuthorId > 0) {
                /** @var User|null $newAuthor */
                $newAuthor = $this->users->find($newAuthorId);
                if ($newAuthor instanceof User) {
                    $project->setAuthor($newAuthor);
                    $this->projects->save($project);
                } else {
                    throw new RuntimeException('authorId not found', 400);
                }
            }
        }

        // (optional) contributors replace/add/remove by IDs
        $asIntIds = static function($v): array {
            if (!is_array($v)) return [];
            $out = [];
            foreach ($v as $x) { $n = (int)$x; if ($n > 0) $out[$n] = true; }
            return array_keys($out);
        };

        $replaceIds = $asIntIds($data['contributors'] ?? null);
        $addIds     = $asIntIds($data['addContributors'] ?? null);
        $removeIds  = $asIntIds($data['removeContributors'] ?? null);
        $authorId = (int)($project->getAuthor()?->getId() ?? 0);

        try {
            if (!empty($replaceIds)) {
                foreach ($project->getUsers() as $u) {
                    if ($u instanceof User && $u->getId() !== $authorId) {
                        $this->projects->detachRelation($project, 'users', $u->getId());
                    }
                }
                foreach ($replaceIds as $cid) {
                    if ($cid === $authorId) continue;
                    $u = $this->users->find($cid);
                    if ($u instanceof User) {
                        $this->projects->attachRelation($project, 'users', $u->getId());
                    }
                }
            } else {
                foreach ($addIds as $cid) {
                    if ($cid === $authorId) continue;
                    $u = $this->users->find($cid);
                    if ($u instanceof User) {
                        $this->projects->attachRelation($project, 'users', $u->getId());
                    }
                }
                foreach ($removeIds as $cid) {
                    if ($cid === $authorId) continue;
                    $this->projects->detachRelation($project, 'users', $cid);
                }
            }
        } catch (\Throwable $e) {
            throw $e;
        }

        // final save
        try {
            $project->setUpdateDate(new \DateTimeImmutable('now', new \DateTimeZone('UTC')));
            $this->projects->save($project);
        } catch (\Throwable $e) {
            throw $e;
        }

        $resp = $this->serializeProject($project);
        return new JsonResponse($resp, 200);
    }

    /**
     * @throws \ReflectionException
     * @throws \JsonException
     */
    #[Route(methods: 'DELETE', path: '/projects/{hash}')]
    public function deleteByHash(ServerRequestInterface $request): JsonResponse
    {
        $uid = (int) $request->getAttribute('user_id', 0);
        if ($uid <= 0) {
            throw new RuntimeException('Unauthorized', 401);
        }
        /** @var User|null $actor */
        $actor = $this->users->find($uid);
        if (!$actor) {
            throw new RuntimeException('Unauthorized', 401);
        }

        $hash = $request->getAttribute('hash');
        if (!is_string($hash) || $hash === '') {
            throw new RuntimeException('Invalid project identifier', 400);
        }

        /** @var Project|null $project */
        $project = $this->projects->findOneBy(['hash' => $hash]);
        if (!$project) {
            throw new RuntimeException('Project not found', 404);
        }

        // Only author can delete
        $authorId = (int)($project->getAuthor()?->getId() ?? 0);
        if ($authorId !== (int)$actor->getId()) {
            throw new RuntimeException('Forbidden', 403);
        }

        $this->hardDeleteProject($project);
        return new JsonResponse(null, 204);
    }

    /**
     * @throws \ReflectionException
     * @throws \JsonException
     */
    #[Route(methods: 'DELETE', path: '/admin/projects/{id}')]
    public function adminDelete(ServerRequestInterface $request): JsonResponse
    {
        $this->requireAdmin($request);

        $id = (int) $request->getAttribute('id');
        if ($id <= 0) {
            throw new RuntimeException('Invalid project id', 400);
        }

        /** @var Project|null $project */
        $project = $this->projects->find($id);
        if (!$project) {
            throw new RuntimeException('Project not found', 404);
        }

        $this->hardDeleteProject($project);
        return new JsonResponse(null, 204);
    }

    /**
     * Physically remove project, relations and media (rows + files).
     * No return-value inspection from QB; no ints cast. Defensive and idempotent.
     *
     * @throws \ReflectionException
     */
    private function hardDeleteProject(Project $project): void
    {
        $pid = (int) $project->getId();
        if ($pid <= 0) {
            throw new RuntimeException('Invalid project', 400);
        }

        $qb = $this->projects->qb;
        $mediaRepo = $this->repos->getRepository(Media::class);

        // 1) Gather media to delete (logo/banner/deck + gallery)
        $mediaToDelete = [];
        $logo      = $project->getLogo();
        $banner    = $project->getBanner();
        $pitchDeck = $project->getPitchDeck();

        foreach ([$logo, $banner, $pitchDeck] as $m) {
            if ($m instanceof Media && $m->getId() > 0) {
                $mediaToDelete[$m->getId()] = $m;
            }
        }
        foreach ($project->getMediaGallery() as $gm) {
            if ($gm instanceof Media && $gm->getId() > 0) {
                $mediaToDelete[$gm->getId()] = $gm;
            }
        }

        // 2) Clear join tables (contributors & favorites)
        try { (clone $qb)->where('project_id', '=', $pid)->delete('project_user'); } catch (\Throwable $e) { error_log('[PROJECT][DELETE][WARN] project_user detach failed: ' . $e->getMessage()); }
        try { (clone $qb)->where('project_id', '=', $pid)->delete('favorite_project'); } catch (\Throwable $e) { error_log('[PROJECT][DELETE][WARN] favorite_project clear failed: ' . $e->getMessage()); }

        // 3) Null-out FK columns on project (logo/banner/pitchDeck) to avoid FK blocks
        try {
            (clone $qb)->where('id', '=', $pid)->update('project', [
                'logo_id'       => null,
                'banner_id'     => null,
                'pitch_deck_id' => null,
            ]);
        } catch (\Throwable $e) {
            throw $e;
        }

        // 4) Delete media physical files + rows
        foreach ($mediaToDelete as $m) {
            try {
                if (method_exists($mediaRepo, 'delete')) { /** @phpstan-ignore-next-line */ $mediaRepo->delete($m); }
                elseif (method_exists($mediaRepo, 'remove')) { /** @phpstan-ignore-next-line */ $mediaRepo->remove($m); }
                else { (clone $qb)->where('id', '=', (int)$m->getId())->delete('media'); }
            } catch (\Throwable $e) {
                throw $e;
            }
        }

        // 5) Safety: wipe any lingering gallery rows by correct FK (media.projectGallery_id)
        try { (clone $qb)->where('projectGallery_id', '=', $pid)->delete('media'); }
        catch (\Throwable $e) { throw $e; }

        // 6) Hard delete the project row (double-tap by id then by hash)
        try {
            (clone $qb)->where('id', '=', $pid)->delete('project')->execute();
            // Also try by hash in case of race/ORM oddities
            $h2 = (string)($project->getHash() ?? '');
            if ($h2 !== '') {
                (clone $qb)->where('hash', '=', $h2)->delete('project');
            }
        } catch (\Throwable $e) {
            throw $e;
        }

        // 7) Paranoid soft-hide (harmless if already gone; ensures lists won’t show it)
        try {
            (clone $qb)->where('id', '=', $pid)->update('project', [
                'status'       => 'deleted',
                'publish_date' => null,
                'boost'        => 0,
                'super_boost'  => 0,
            ]);
        } catch (\Throwable $e) {
            // ignore — likely already removed
        }

        // 8) Evict identity-map / caches if your repos expose such methods
        try { method_exists($this->projects, 'clear') && $this->projects->clear(); } catch (\Throwable $e) {}
        try { method_exists($mediaRepo, 'clear') && $mediaRepo->clear(); } catch (\Throwable $e) {}
    }

}
