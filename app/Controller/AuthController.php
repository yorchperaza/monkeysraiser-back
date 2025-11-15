<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\Founder;
use App\Entity\Investor;
use App\Entity\Newsletter;
use App\Entity\PasswordResetToken;
use App\Entity\Role;
use App\Entity\User;
use DateTimeInterface;
use MonkeysLegion\Auth\AuthService;
use MonkeysLegion\Auth\PasswordHasher;
use MonkeysLegion\Http\Message\JsonResponse;
use MonkeysLegion\Repository\EntityRepository;
use MonkeysLegion\Repository\RepositoryFactory;
use MonkeysLegion\Router\Attributes\Route;
use Psr\Http\Message\ServerRequestInterface;
use Random\RandomException;
use RuntimeException;
use MonkeysLegion\Mlc\Config as MlcConfig;
use MonkeysLegion\Auth\JwtService;
use App\Service\MonkeysMailService;
use MonkeysLegion\Template\Renderer;

/**
 * MonkeysRaiser auth endpoints
 *
 * - POST /auth/register
 * - POST /auth/login
 * - GET  /auth/me
 */
final class AuthController
{
    private EntityRepository $users;
    private EntityRepository $founder;
    private EntityRepository $investor;
    private EntityRepository $role;
    private EntityRepository $newsletter;
    private EntityRepository $passwordResets;

    public function __construct(
        private RepositoryFactory $repos,
        private PasswordHasher $hasher,
        private AuthService $auth,
        private MlcConfig $config,
        private JwtService $jwt,
        private MonkeysMailService $mail,
        private Renderer $renderer,
    ) {
        $this->users = $this->repos->getRepository(User::class);
        $this->founder = $this->repos->getRepository(Founder::class);
        $this->investor = $this->repos->getRepository(Investor::class);
        $this->role = $this->repos->getRepository(Role::class);
        $this->newsletter = $this->repos->getRepository(Newsletter::class);
        $this->passwordResets = $this->repos->getRepository(PasswordResetToken::class);
    }

    /**
     * POST /auth/register
     *
     * Body JSON:
     * {
     *   "email": "founder@example.com",
     *   "password": "secret123",
     *   "fullName": "Jane Founder"        (optional)
     * }
     *
     * Rules:
     *  - email and password are required
     *  - email must be unique
     *  - password >= 8 chars (you can tune)
     *
     * Response 201:
     * {
     *   "id": 1,
     *   "email": "founder@example.com",
     *   "fullName": "Jane Founder"
     * }
     *
     * Response 409 if email exists
     * Response 400 on bad input
     *
     * @throws \JsonException
     * @throws \ReflectionException
     * @throws \DateMalformedStringException
     */
    #[Route(methods: 'POST', path: '/auth/register')]
    public function register(ServerRequestInterface $request): JsonResponse
    {
        // Parse & validate body
        $dataRaw = (string) $request->getBody();
        $data = json_decode($dataRaw, true, JSON_THROW_ON_ERROR);

        $email    = strtolower(trim((string)($data['email'] ?? '')));
        $password = (string)($data['password'] ?? '');
        $fullName = isset($data['fullName']) ? trim((string)$data['fullName']) : null;
        $roleSlug = strtolower(trim((string)($data['role'] ?? 'founder'))); // founder | investor

        if ($email === '' || $password === '') {
            throw new RuntimeException('Email and password are required', 400);
        }
        if (strlen($password) < 8) {
            throw new RuntimeException('Password must be at least 8 characters', 400);
        }
        if (!in_array($roleSlug, ['founder', 'investor'], true)) {
            throw new RuntimeException('Invalid role', 400);
        }

        // Uniqueness check
        /** @var ?User $existing */
        $existing = $this->users->findOneBy(['email' => $email]);
        if ($existing) {
            throw new RuntimeException('User with this email already exists', 409);
        }

        // Create user
        $user = new User();
        $user
            ->setEmail($email)
            ->setPasswordHash($this->hasher->hash($password))
            ->setFullName($fullName);

        // Persist user first to get an ID
        $this->users->save($user);

        $role = $this->role->findOneBy(['slug' => $roleSlug]);
        if (!$role) {
            throw new RuntimeException('Role not found: ' . $roleSlug, 500);
        }
        // Assign role
        $this->users->attachRelation($user, 'roles', $role->getId());

        $newsletter = new Newsletter();
        $newsletter
            ->setEmail($email)
            ->setSubscribedAt(new \DateTimeImmutable('now', new \DateTimeZone('UTC')));

        $this->newsletter->save($newsletter);

        $this->sendWelcomeEmail($user, $roleSlug);

        // Minimal response including the chosen role
        return new JsonResponse([
            'id'        => $user->getId(),
            'email'     => $user->getEmail(),
            'fullName'  => $user->getFullName(),
            'role'      => $roleSlug,
        ], 201);
    }

    /**
     * POST /auth/login
     *
     * Body:
     * {
     *   "email": "founder@example.com",
     *   "password": "secret123"
     * }
     *
     * Response:
     * {
     *   "token": "<jwt>"
     * }
     *
     * Flow:
     *  - validate email/password presence
     *  - ask AuthService to verify creds & mint JWT
     *  - fetch that same user
     *  - set lastLoginAt = now (UTC)
     *  - save
     *
     * @throws \JsonException
     * @throws \DateMalformedStringException
     */
    #[Route(methods: 'POST', path: '/auth/login')]
    public function login(ServerRequestInterface $request): JsonResponse
    {
        $data = json_decode((string)$request->getBody(), true, JSON_THROW_ON_ERROR);
        $email = (string)($data['email'] ?? '');
        $password = (string)($data['password'] ?? '');
        if ($email === '' || $password === '') {
            throw new RuntimeException('Email and password are required', 400);
        }

        $token = $this->auth->login($email, $password);

        // optional: bump lastLoginAt (left as you had it)
        /** @var User|null $user */
        $user = $this->users->findOneBy(['email' => $email]);
        if ($user) {
            $nowUtc = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
            $user->setLastLoginAt($nowUtc);
            $this->users->save($user);
        }

        // Use the token's real exp to avoid clock drift
        $exp = $this->jwt->getExpFrom($token);
        $secure = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
            || ((int)($_SERVER['SERVER_PORT'] ?? 0) === 443);

        // HttpOnly cookie aligned to token exp
        setcookie('token', $token, [
            'expires'  => $exp > 0 ? $exp : time() + $this->jwt->getTtl(),
            'path'     => '/',
            'secure'   => $secure,
            'httponly' => true,
            'samesite' => 'Lax',
        ]);

        $profile = $user ? $this->getProfileStatus($user) : ['hasProfile' => false, 'profileType' => null, 'profileHash' => null];

        return new JsonResponse([
            'token'       => $token,
            'exp'         => $exp,
            'ttl'         => $this->jwt->getTtl(),
            'leeway'      => $this->jwt->getLeeway(),
            'nbfSkew'     => $this->jwt->getNbfSkew(),
            'hasProfile'  => $profile['hasProfile'],
            'profileType' => $profile['profileType'],
            'profileHash' => $profile['profileHash'],
        ], 200);
    }

    /**
     * GET /auth/me
     *
     * Requires Authorization: Bearer <token>
     * Middleware should decode the token, validate it,
     * and inject user_id (int) into $request->getAttribute('user_id').
     *
     * Response 200:
     * {
     *   "id": 1,
     *   "email": "founder@example.com",
     *   "fullName": "Jane Founder",
     *   "title": null,
     *   "shortBio": null,
     *   "longBio": null,
     *   "social": null,
     *   "timeZone": null,
     *   "location": null,
     *   "roles": [...],
     *   "projects": [...]
     * }
     *
     * Response 401 if not authenticated
     * Response 404 if user not found
     *
     * @throws \ReflectionException
     */
    #[Route(methods: 'GET', path: '/auth/me')]
    public function me(ServerRequestInterface $request): JsonResponse
    {
        // 1) Require auth
        $userId = (int)$request->getAttribute('user_id', 0);
        if ($userId <= 0) {
            throw new RuntimeException('Unauthorized', 401);
        }

        // 2) Load user
        /** @var ?User $user */
        $user = $this->users->find($userId);
        if (!$user) {
            throw new RuntimeException('User not found', 404);
        }

        // 3) Shape response (no passwordHash)
        // We'll also include roles and projects in a simple way.
        $rolesPayload = array_map(
            static fn($role) => method_exists($role, 'getSlug') ? $role->getSlug() : null,
            $user->getRoles()
        );
        // filter nulls if Role doesn't have getName() yet
        $rolesPayload = array_values(array_filter($rolesPayload, static fn($r) => $r !== null));

        $projectsPayload = array_map(
            static fn($project) => method_exists($project, 'getId')
                ? [
                    'id'   => $project->getId(),
                    'name' => method_exists($project, 'getName') ? $project->getName() : null,
                ]
                : null,
            $user->getProjects()
        );
        $projectsPayload = array_values(array_filter($projectsPayload, static fn($p) => $p !== null));

        return new JsonResponse([
            'id'        => $user->getId(),
            'email'     => $user->getEmail(),
            'fullName'  => $user->getFullName(),
            'title'     => $user->getTitle(),
            'shortBio'  => $user->getShortBio(),
            'longBio'   => $user->getLongBio(),
            'social'    => $user->getSocial(),
            'timeZone'  => $user->getTimeZone(),
            'location'  => $user->getLocation(),

            // Relations
            'picture' => $user->getPicture()
                ? $this->serializeMedia($user->getPicture())
                : null,
            'banner'  => $user->getBanner()
                ? $this->serializeMedia($user->getBanner())
                : null,
            'founder' => $this->serializeFounder($user),
            'investor' => $this->serializeInvestor($user),
            // Collections
            'roles'    => $rolesPayload,
            'projects' => $projectsPayload,
        ], 200);
    }

    /**
     * Small helpers to avoid leaking full relation objects.
     * Adjust to match your actual Media/Founder/Investor API surface.
     */

    private function serializeMedia(object $media): array
    {
        // guessing that Media has ->getId(), ->getUrl(), ->getType()
        return [
            'id'   => method_exists($media, 'getId')   ? $media->getId()   : null,
            'url'  => method_exists($media, 'getUrl')  ? $media->getUrl()  : null,
            'type' => method_exists($media, 'getType') ? $media->getType() : null,
        ];
    }

    private function serializeFounder(User $user): array
    {

        $founder = $this->founder->findOneBy(['user' => $user]);
        if (!$founder) {
            return [
                'id'   => null,
                'hash' => null,
            ];
        }
        // keep it minimal for now
        return [
            'id'   => $founder->getId() ?? null,
            'hash' => $founder->getHash() ?? null,
        ];
    }

    private function serializeInvestor(User $user): array
    {
        $investor = $this->investor->findOneBy(['investor' => $user]);
        if (!$investor) {
            return [
                'id'   => null,
                'hash' => null,
            ];
        }

        return [
            'id'   => $investor->getId() ?? null,
            'hash' => $investor->getHash() ?? null,
            // later: check size, stage prefs, etc.
        ];
    }

    /**
     * POST /auth/heartbeat
     *
     * - Bumps lastActivityAt for authenticated users.
     * - Always returns 204 so sendBeacon/keepalive don’t retry.
     * @throws \JsonException
     */
    #[Route(methods: 'POST', path: '/auth/heartbeat')]
    public function heartbeat(ServerRequestInterface $request): JsonResponse
    {

        try {
            $userId = (int) $request->getAttribute('user_id', 0);

            if ($userId > 0) {
                $userRepo = $this->repos->getRepository(User::class);
                /** @var User|null $user */
                $user = $userRepo->find($userId);

                if ($user) {
                    $now = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
                    $prev = $user->getLastActivityAt();
                    $shouldWrite = !$prev || ($now->getTimestamp() - $prev->getTimestamp() >= 120);

                    if ($shouldWrite) {
                        $user->setLastActivityAt($now);
                        $userRepo->save($user);
                    }
                }
            }
        } catch (\Throwable $e) {
            error_log("[AUTH][HEARTBEAT][ERR] " . $e->getMessage());
            error_log($e->getTraceAsString());
        }

        return new JsonResponse(null, 204);
    }

    /**
     * POST /auth/refresh
     *
     * Stateless sliding session:
     *  - Accepts Authorization: Bearer <access_jwt>
     *  - Allows small grace (default 10m) so users can refresh right after exp
     *  - Returns a fresh access token { token }
     *
     * @throws \JsonException
     */
    #[Route(methods: 'POST', path: '/auth/refresh')]
    public function refresh(ServerRequestInterface $request): JsonResponse
    {
        $userId = (int) $request->getAttribute('user_id', 0);

        if ($userId <= 0) {
            $authz = $request->getHeaderLine('Authorization');

            if (!preg_match('/^Bearer\s+(.+)$/i', $authz, $m)) {
                throw new RuntimeException('Unauthorized', 401);
            }
            $accessToken = $m[1];
            try {
                $claims = $this->auth->decodeForRefresh($accessToken, 600);
            } catch (\Throwable $e) {
                throw new RuntimeException('Unauthorized', 401, $e);
            }

            $userId = (int)($claims['sub'] ?? 0);
            if ($userId <= 0) {
                throw new RuntimeException('Unauthorized', 401);
            }
        }

        $newToken = $this->auth->refreshAccessForUser($userId);
        $exp = $this->jwt->getExpFrom($newToken);

        $secure = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
            || ((int)($_SERVER['SERVER_PORT'] ?? 0) === 443);

        setcookie('token', $newToken, [
            'expires'  => $exp > 0 ? $exp : time() + $this->jwt->getTtl(),
            'path'     => '/',
            'secure'   => $secure,
            'httponly' => true,
            'samesite' => 'Lax',
        ]);

        return new JsonResponse([
            'token'    => $newToken,
            'exp'      => $exp,
            'ttl'      => $this->jwt->getTtl(),
            'leeway'   => $this->jwt->getLeeway(),
            'nbfSkew'  => $this->jwt->getNbfSkew(),
        ], 200);
    }

    private function getProfileStatus(User $user): array
    {
        // Try to resolve founder/investor via both object and id, to be safe with your repo API
        $founder = $this->founder->findOneBy(['user' => $user]) ?: $this->founder->findOneBy(['user' => $user->getId()]);
        $investor = $this->investor->findOneBy(['investor' => $user]) ?: $this->investor->findOneBy(['investor' => $user->getId()]);

        if ($founder) {
            $hash = method_exists($founder, 'getHash') ? $founder->getHash() : null;
            return ['hasProfile' => true, 'profileType' => 'founder', 'profileHash' => $hash];
        }
        if ($investor) {
            $hash = method_exists($investor, 'getHash') ? $investor->getHash() : null;
            return ['hasProfile' => true, 'profileType' => 'investor', 'profileHash' => $hash];
        }

        // Heuristic fallback (optional): if user has some basics filled, treat as hasProfile=false until a role-profile exists
        return ['hasProfile' => false, 'profileType' => null, 'profileHash' => null];
    }

    private function sendWelcomeEmail(User $user, string $roleSlug): void
    {
        $email = $user->getEmail();
        if (!$email) {
            return;
        }

        $fullName = $user->getFullName() ?? '';
        $roleSlug = strtolower($roleSlug);

        $frontendBase = rtrim(
            (string)(getenv('FRONTEND_BASE_URL') ?: 'https://monkeysraiser.com'),
            '/'
        );

        $primaryUrl = $frontendBase . '/dashboard/profile/create';

        $subject = 'Welcome to MonkeysRaiser';

        try {
            $html = $this->renderer->render('emails/welcome', [
                'fullName'  => $fullName,
                'role'      => $roleSlug,
                'primaryUrl'=> $primaryUrl,
            ]);

            $this->mail->sendSimple(
                $email,
                $subject,
                $html,
                null,
                null,
                null,
                false,
                [
                    'tags' => ['welcome', 'auth'],
                    'metadata' => [
                        'userId' => $user->getId(),
                        'role'   => $roleSlug,
                    ],
                ]
            );
        } catch (\Throwable $e) {
            error_log('[AUTH][WELCOME_EMAIL][ERR] ' . $e->getMessage());
            // don't block registration on email failure
        }
    }

    /**
     * @throws \DateMalformedStringException
     * @throws RandomException
     * @throws \JsonException
     */
    #[Route(methods: 'POST', path: '/auth/password/forgot')]
    public function forgot(ServerRequestInterface $request): JsonResponse
    {
        $data  = json_decode((string)$request->getBody(), true, JSON_THROW_ON_ERROR);
        $email = strtolower(trim((string)($data['email'] ?? '')));
        if ($email === '') {
            throw new RuntimeException('Email is required', 400);
        }

        /** @var ?User $user */
        $user = $this->users->findOneBy(['email' => $email]);

        // Always 202 (enumeration safe)
        if (!$user) {
            return new JsonResponse(['status' => 'ok'], 202);
        }

        // Create token pair
        [$rawToken, $tokenHash] = $this->generateResetTokenPair();
        $now    = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        $expiry = $now->modify('+60 minutes');

        // Persist a PasswordResetToken row
        /** @var PasswordResetToken $row */
        $row = new PasswordResetToken();
        $row->setEmail($email)
            ->setTokenHash($tokenHash)
            ->setCreatedAt($now)
            ->setExpiresAt($expiry)
            ->setUsedAt(null);
        $this->passwordResets->save($row);

        // Build token-only link
        // You can switch this to FRONTEND_BASE_URL if you prefer, like in sendWelcomeEmail
        $base = rtrim(
            (string)($_ENV['FRONTEND_BASE_URL'] ?? getenv('FRONTEND_BASE_URL') ?: 'https://monkeysraiser.com'),
            '/'
        );
        $resetUrl = $base . '/reset-password?token=' . urlencode($rawToken);

        // --- Send reset email (best-effort, non-fatal) ---
        try {
            $fullName = $user->getFullName() ?? '';

            $html = $this->renderer->render('emails/password-forgot', [
                'fullName'   => $fullName,
                'email'      => $email,
                'resetUrl'   => $resetUrl,
                'ttlMinutes' => 60, // keep in sync with +60 minutes above
            ]);

            $this->mail->sendSimple(
                $email,
                'Reset your MonkeysRaiser password',
                $html,
                null,
                null,
                null,
                false,
                [
                    'tags'     => ['password', 'reset', 'auth'],
                    'metadata' => [
                        'userId' => $user->getId(),
                    ],
                ]
            );
        } catch (\Throwable $e) {
            error_log('[AUTH][PW_FORGOT_EMAIL][ERR] ' . $e->getMessage());
            // Do NOT change response; we still return 202 so the endpoint is enumeration-safe
        }

        return new JsonResponse(['status' => 'ok'], 202);
    }


    /* ---------------------------------------------------------------------
     * POST /auth/password/reset
     * Body: { "email": "user@example.com", "token": "...", "password": "newPass" }
     * - Validates token (exists, not expired, not used, hash matches)
     * - Updates password, marks token used, optionally revokes sessions
     * ------------------------------------------------------------------- */
    /**
     * @throws \ReflectionException
     * @throws \DateMalformedStringException
     * @throws \JsonException
     */
    #[Route(methods: 'POST', path: '/auth/password/reset')]
    public function passwordReset(ServerRequestInterface $request): JsonResponse
    {
        $data     = json_decode((string)$request->getBody(), true, JSON_THROW_ON_ERROR);
        $token    = (string)($data['token'] ?? '');
        $password = (string)($data['password'] ?? '');

        if ($token === '' || $password === '') {
            throw new RuntimeException('token and password are required', 400);
        }
        if (strlen($password) < 8) {
            throw new RuntimeException('Password must be at least 8 characters', 400);
        }

        /** @var PasswordResetToken[] $rows */
        $rows = $this->passwordResets->findBy([], orderBy: ['id' => 'DESC']);
        $now  = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));

        $match = null;
        foreach ($rows as $row) {
            if ($row->getUsedAt()) continue;
            if ($row->getExpiresAt() && $row->getExpiresAt() < $now) continue;
            if (password_verify($token, (string)$row->getTokenHash())) { $match = $row; break; }
        }

        if (!$match) throw new RuntimeException('Invalid or expired token', 400);

        $email = strtolower((string)$match->getEmail());
        /** @var ?User $user */
        $user = $this->users->findOneBy(['email' => $email]);
        if (!$user) return new JsonResponse(['status' => 'ok'], 200);

        // Update password
        $user->setPasswordHash($this->hasher->hash($password));
        $this->users->save($user);

        // Mark token used
        $match->setUsedAt($now);
        $this->passwordResets->save($match);

        return new JsonResponse(['status' => 'ok'], 200);
    }

    /**
     * Returns [rawToken, tokenHash]
     * - rawToken: 43-char URL-safe (32 bytes b64url)
     * - tokenHash: bcrypt hash to store in DB
     * @throws RandomException
     */
    private function generateResetTokenPair(): array
    {
        $bytes    = random_bytes(32);
        $raw      = rtrim(strtr(base64_encode($bytes), '+/', '-_'), '='); // URL-safe
        $hash     = password_hash($raw, PASSWORD_BCRYPT, ['cost' => 12]);
        return [$raw, $hash];
    }

    /**
     * @throws \ReflectionException
     * @throws \DateMalformedStringException
     * @throws \JsonException
     */
    #[Route(methods: 'GET', path: '/auth/password/token-info')]
    public function tokenInfo(ServerRequestInterface $request): JsonResponse
    {
        $token = trim((string)($request->getQueryParams()['token'] ?? ''));
        if ($token === '') throw new RuntimeException('Missing token', 400);

        /** @var PasswordResetToken[] $rows */
        $rows = $this->passwordResets->findBy([], orderBy: ['id' => 'DESC']); // you can optimize with an index/lookup
        $now  = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));

        foreach ($rows as $row) {
            if ($row->getUsedAt()) continue;
            if ($row->getExpiresAt() && $row->getExpiresAt() < $now) continue;
            if (password_verify($token, (string)$row->getTokenHash())) {
                $email = (string)$row->getEmail();
                return new JsonResponse([
                    'email'      => $email,
                    'emailMask'  => $this->maskEmail($email),
                    'expiresAt'  => $row->getExpiresAt()?->format(DateTimeInterface::ATOM),
                    'ttlMin'     => $row->getExpiresAt() ? max(0, (int)ceil(($row->getExpiresAt()->getTimestamp() - $now->getTimestamp())/60)) : null,
                ], 200);
            }
        }

        // Invalid/expired
        return new JsonResponse(['message' => 'Invalid or expired token'], 400);
    }

    private function maskEmail(string $email): string
    {
        // simple mask: jo***@domain.com
        if (!str_contains($email, '@')) return $email;
        [$local, $domain] = explode('@', $email, 2);
        $keep = max(1, min(3, (int)floor(strlen($local)/2)));
        return substr($local, 0, $keep) . str_repeat('*', max(1, strlen($local)-$keep)) . '@' . $domain;
    }
}
