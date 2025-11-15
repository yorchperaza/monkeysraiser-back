<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\Plan;
use App\Entity\Project;
use App\Entity\User;
use MonkeysLegion\Http\Message\JsonResponse;
use MonkeysLegion\Repository\EntityRepository;
use MonkeysLegion\Repository\RepositoryFactory;
use MonkeysLegion\Router\Attributes\Route;
use Psr\Http\Message\ServerRequestInterface;
use RuntimeException;
use Stripe\StripeClient;
use App\Service\MonkeysMailService;
use MonkeysLegion\Template\Renderer;

final class PlanController
{
    private EntityRepository $plans;
    private EntityRepository $projects;
    private EntityRepository $users;

    public function __construct(
        private RepositoryFactory $repos,
        private MonkeysMailService $mail,
        private Renderer $renderer,
    ) {
        $this->plans    = $this->repos->getRepository(Plan::class);
        $this->projects = $this->repos->getRepository(Project::class);
        $this->users    = $this->repos->getRepository(User::class);
    }

    /**
     * POST /plans
     * Body (JSON):
     *  - name?: string
     *  - slug?: string
     *  - stripe_price_id?: string      (optional; if omitted and pricing provided, will be created)
     *  - stripe_product_id?: string    (optional; if omitted, will be created/derived)
     *  - amount?: int                  (smallest currency unit; optional if stripe_price_id provided)
     *  - currency?: string             (e.g. "usd", "eur", "crc")
     *  - product_name?: string         (defaults to plan name)
     *
     * NOTE: Project is NOT attached here. That happens after purchase.
     * @throws \JsonException
     */
    #[Route(methods: 'POST', path: '/plans')]
    public function create(ServerRequestInterface $request): JsonResponse
    {
        $uid = (int) $request->getAttribute('user_id', 0);
        if ($uid <= 0) {
            throw new RuntimeException('Unauthorized', 401);
        }

        $raw = (string) $request->getBody();
        $data = $raw !== '' ? json_decode($raw, true, 512, JSON_THROW_ON_ERROR) : [];
        if (!is_array($data)) { $data = []; }

        $plan = new Plan();
        $plan
            ->setName(isset($data['name']) ? trim((string)$data['name']) ?: null : null)
            ->setSlug(isset($data['slug']) ? trim((string)$data['slug']) ?: null : null);

        // If client signaled pricing intent, validate minimal fields to avoid silent nulls
        $wantsStripe = array_key_exists('amount', $data) || array_key_exists('currency', $data) || array_key_exists('product_name', $data);
        if ($wantsStripe) {
            $amt = isset($data['amount']) ? (int)$data['amount'] : null;
            $cur = isset($data['currency']) ? strtolower(trim((string)$data['currency'])) ?: null : null;
            if (!is_int($amt) || $amt <= 0 || !$cur) {
                throw new RuntimeException('Invalid pricing: amount (int, >0) and currency are required for one-time Stripe creation.', 400);
            }
        }

        // Ensure Stripe IDs (create if needed; one-time price)
        $stripeIds = $this->ensureStripeIds(
            stripePriceId: isset($data['stripe_price_id']) ? (trim((string)$data['stripe_price_id']) ?: null) : null,
            stripeProductId: isset($data['stripe_product_id']) ? (trim((string)$data['stripe_product_id']) ?: null) : null,
            planNameForProduct: $plan->getName(),
            pricing: [
                'amount'       => isset($data['amount']) ? (int)$data['amount'] : null,
                'currency'     => isset($data['currency']) ? strtolower(trim((string)$data['currency'])) ?: null : null,
                'product_name' => isset($data['product_name']) ? trim((string)$data['product_name']) ?: null : null,
            ]
        );

        $plan->setStripe_price_id($stripeIds['price'] ?? null);
        $plan->setStripe_product_id($stripeIds['product'] ?? null);

        // Persist local price:
        // - Prefer explicit amount from payload
        // - Else, if we now have a Stripe price, read its unit_amount
        if (isset($data['amount']) && is_numeric($data['amount'])) {
            $plan->setPrice((int)$data['amount']);
        } elseif ($plan->getStripe_price_id()) {
            $unit = $this->readStripeUnitAmount($plan->getStripe_price_id());
            if ($unit !== null) $plan->setPrice($unit);
        }

        $this->plans->save($plan);

        return new JsonResponse(
            $this->serializePlan($plan, includeStripe: true),
            201
        );
    }

    /**
     * POST /plans/{id}
     * Partial update. (No project linking here.)
     * @throws \ReflectionException
     */
    #[Route(methods: 'POST', path: '/plans/{id}')]
    public function update(ServerRequestInterface $request): JsonResponse
    {
        $uid = (int) $request->getAttribute('user_id', 0);
        if ($uid <= 0) {
            throw new RuntimeException('Unauthorized', 401);
        }

        $id = (int) $request->getAttribute('id');
        if ($id <= 0) {
            throw new RuntimeException('Invalid plan id', 400);
        }

        /** @var Plan|null $plan */
        $plan = $this->plans->find($id);
        if (!$plan) {
            throw new RuntimeException('Plan not found', 404);
        }

        $raw = (string) $request->getBody();
        $data = $raw !== '' ? json_decode($raw, true, 512, JSON_THROW_ON_ERROR) : [];
        if (!is_array($data)) { $data = []; }

        if (array_key_exists('name', $data)) { $plan->setName(trim((string)$data['name']) ?: null); }
        if (array_key_exists('slug', $data)) { $plan->setSlug(trim((string)$data['slug']) ?: null); }

        // Stripe: keep existing or (re)create from one-time pricing
        $stripeIds = $this->ensureStripeIds(
            stripePriceId: array_key_exists('stripe_price_id', $data) ? (trim((string)$data['stripe_price_id']) ?: null) : ($plan->getStripe_price_id() ?: null),
            stripeProductId: array_key_exists('stripe_product_id', $data) ? (trim((string)$data['stripe_product_id']) ?: null) : ($plan->getStripe_product_id() ?: null),
            planNameForProduct: $plan->getName(),
            pricing: [
                'amount'       => isset($data['amount']) ? (int)$data['amount'] : null,
                'currency'     => isset($data['currency']) ? strtolower(trim((string)$data['currency'])) ?: null : null,
                'product_name' => isset($data['product_name']) ? trim((string)$data['product_name']) ?: null : null,
            ]
        );
        $plan->setStripe_price_id($stripeIds['price'] ?? null);
        $plan->setStripe_product_id($stripeIds['product'] ?? null);

        // Update local price:
        // - If payload includes amount, use it
        // - Else, if Stripe price changed/existed, refresh from Stripe
        if (array_key_exists('amount', $data) && is_numeric($data['amount'])) {
            $plan->setPrice((int)$data['amount']);
        } elseif ($plan->getStripe_price_id()) {
            $unit = $this->readStripeUnitAmount($plan->getStripe_price_id());
            if ($unit !== null) $plan->setPrice($unit);
        }

        $this->plans->save($plan);

        return new JsonResponse(
            $this->serializePlan($plan, includeStripe: true),
            200
        );
    }

    /**
     * POST /plans/{id}/attach-project
     * Attach a project to a plan AFTER purchase.
     * Body (JSON): { "projectId"?: int, "projectHash"?: string }
     */
    #[Route(methods: 'POST', path: '/plans/{id}/attach-project')]
    public function attachProjectPostPurchase(ServerRequestInterface $request): JsonResponse
    {
        $uid = (int) $request->getAttribute('user_id', 0);
        if ($uid <= 0) {
            throw new RuntimeException('Unauthorized', 401);
        }

        $id = (int) $request->getAttribute('id');
        if ($id <= 0) {
            throw new RuntimeException('Invalid plan id', 400);
        }

        /** @var Plan|null $plan */
        $plan = $this->plans->find($id);
        if (!$plan) {
            throw new RuntimeException('Plan not found', 404);
        }

        $raw = (string) $request->getBody();
        $data = $raw !== '' ? json_decode($raw, true, 512, JSON_THROW_ON_ERROR) : [];
        if (!is_array($data)) { $data = []; }

        $projectId = isset($data['projectId']) ? (int)$data['projectId'] : 0;
        $projectHash = isset($data['projectHash']) ? trim((string)$data['projectHash']) : '';

        $target = null;
        if ($projectId > 0) {
            $target = $this->projects->find($projectId);
        } elseif ($projectHash !== '') {
            $target = $this->projects->findOneBy(['hash' => $projectHash]);
        }

        if (!$target instanceof Project) {
            throw new RuntimeException('Project not found', 404);
        }

        if (method_exists($target, 'setPlan')) {
            $target->setPlan($plan);
            $this->projects->save($target);
        } else {
            throw new RuntimeException('Project entity cannot set plan', 500);
        }

        return new JsonResponse([
            'ok'      => true,
            'plan'    => $this->serializePlan($plan, includeStripe: true),
            'project' => [
                'id'   => $target->getId(),
                'hash' => method_exists($target, 'getHash') ? $target->getHash() : null,
                'name' => method_exists($target, 'getName') ? $target->getName() : null,
            ],
        ], 200);
    }

    /**
     * GET /plans/{id}
     * Query: includeStripe=1|0
     */
    #[Route(methods: 'GET', path: '/plans/{id}')]
    public function showById(ServerRequestInterface $request): JsonResponse
    {
        $id = (int) $request->getAttribute('id');
        if ($id <= 0) {
            throw new RuntimeException('Invalid plan id', 400);
        }

        /** @var Plan|null $plan */
        $plan = $this->plans->find($id);
        if (!$plan) {
            throw new RuntimeException('Plan not found', 404);
        }

        $q = $request->getQueryParams();
        $includeStripe = ((string)($q['includeStripe'] ?? '1')) === '1';

        return new JsonResponse(
            $this->serializePlan($plan, includeStripe: $includeStripe),
            200
        );
    }

    /**
     * GET /plans/slug/{slug}
     * Query: includeStripe=1|0
     */
    #[Route(methods: 'GET', path: '/plans/slug/{slug}')]
    public function showBySlug(ServerRequestInterface $request): JsonResponse
    {
        $slug = (string) $request->getAttribute('slug');
        if ($slug === '') {
            throw new RuntimeException('Invalid plan slug', 400);
        }

        /** @var Plan|null $plan */
        $plan = $this->plans->findOneBy(['slug' => $slug]);
        if (!$plan) {
            throw new RuntimeException('Plan not found', 404);
        }

        $q = $request->getQueryParams();
        $includeStripe = ((string)($q['includeStripe'] ?? '1')) === '1';

        return new JsonResponse(
            $this->serializePlan($plan, includeStripe: $includeStripe),
            200
        );
    }

    /**
     * GET /plans
     */
    #[Route(methods: 'GET', path: '/plans')]
    public function list(ServerRequestInterface $request): JsonResponse
    {
        $q = $request->getQueryParams();

        $page = max(1, (int)($q['page'] ?? 1));
        $perPage = (int)($q['perPage'] ?? 24);
        if ($perPage <= 0) $perPage = 24;
        if ($perPage > 100) $perPage = 100;
        $offset = ($page - 1) * $perPage;

        $needle = trim((string)($q['q'] ?? ''));
        $includeStripe = ((string)($q['includeStripe'] ?? '0')) === '1';

        $base = (clone $this->plans->qb)->from('plan', 'pl');

        if ($needle !== '') {
            $like = '%' . mb_strtolower($needle) . '%';
            $base->andWhereGroup(function($g) use ($like) {
                $g->whereLike('LOWER(pl.name)', $like)
                    ->orWhereLike('LOWER(pl.slug)', $like)
                    ->orWhereLike('LOWER(pl.stripe_price_id)', $like)
                    ->orWhereLike('LOWER(pl.stripe_product_id)', $like);
            });
        }

        $total = $base->duplicate()->count();
        $pages = (int) max(1, (int) ceil($total / max(1, $perPage)));

        $rows = $base->duplicate()
            ->select('pl.id AS id')
            ->orderBy('pl.id', 'DESC')
            ->limit($perPage)
            ->offset($offset)
            ->fetchAll();

        $items = [];
        foreach ($rows as $r) {
            $id = (int) ($r['id'] ?? 0);
            if ($id <= 0) continue;
            /** @var Plan|null $p */
            $p = $this->plans->find($id);
            if ($p instanceof Plan) {
                $items[] = $this->serializePlan($p, includeStripe: $includeStripe);
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
     * GET /plans/{id}/stripe
     */
    #[Route(methods: 'GET', path: '/plans/{id}/stripe')]
    public function refreshStripe(ServerRequestInterface $request): JsonResponse
    {
        $id = (int) $request->getAttribute('id');
        if ($id <= 0) {
            throw new RuntimeException('Invalid plan id', 400);
        }

        /** @var Plan|null $plan */
        $plan = $this->plans->find($id);
        if (!$plan) {
            throw new RuntimeException('Plan not found', 404);
        }

        $stripe = $this->fetchStripeBundle($plan->getStripe_price_id(), $plan->getStripe_product_id());

        return new JsonResponse([
            'id'      => $plan->getId(),
            'name'    => $plan->getName(),
            'slug'    => $plan->getSlug(),
            'stripe'  => $stripe,
        ], 200);
    }

    // -----------------------
    // Helpers
    // -----------------------

    private function serializePlan(Plan $plan, bool $includeStripe = false): array
    {
        $out = [
            'id'                => $plan->getId(),
            'name'              => $plan->getName(),
            'slug'              => $plan->getSlug(),
            'stripe_price_id'   => $plan->getStripe_price_id(),
            'stripe_product_id' => $plan->getStripe_product_id(),
            'price'             => $plan->getPrice(), // <-- smallest unit
            'projects'          => array_values(array_filter(array_map(static function ($p) {
                return method_exists($p, 'getId') ? (int)$p->getId() : null;
            }, $plan->getProjects()))),
        ];

        if ($includeStripe) {
            $out['stripe'] = $this->fetchStripeBundle($plan->getStripe_price_id(), $plan->getStripe_product_id());
        }

        return $out;
    }

    /** Ensure the current user is attached to the plan (idempotent). */
    private function ensureUserAttachedToPlan(Plan $plan, int $userId): void
    {
        if ($userId <= 0) return;
        foreach ($plan->getUsers() as $u) {
            if (method_exists($u, 'getId') && (int)$u->getId() === $userId) {
                return; // already attached
            }
        }
        try {
            $this->plans->attachRelation($plan, 'users', $userId);
        } catch (\Throwable $e) {
            error_log('[PLAN][ATTACH_USER] ' . $e->getMessage());
        }
    }

    /** True if PaymentIntent status is settled enough to grant access. */
    private function isIntentSettled(string $status): bool
    {
        $status = strtolower($status);
        return in_array($status, ['succeeded', 'processing', 'requires_capture'], true);
    }

    /**
     * Decide which payment methods to allow on the PaymentIntent.
     * Cards are always enabled. Affirm (USD). Klarna (common Klarna currencies).
     * You can expand this list as needed.
     *
     * @return string[]
     */
    private function allowedPaymentMethodTypes(string $currency): array
    {
        $cur = strtolower($currency);
        $types = ['card'];

        if ($cur === 'usd') {
            $types[] = 'affirm';
        }

        // Common Klarna presentment currencies
        if (in_array($cur, ['usd','eur','gbp','sek','nok','dkk'], true)) {
            $types[] = 'klarna';
        }

        return array_values(array_unique($types));
    }

    /**
     * Ensure we have Stripe IDs (create as needed) for ONE-TIME price.
     *
     * @param array{amount:?int,currency:?string,product_name:?string} $pricing
     * @return array{price:?string,product:?string}
     */
    private function ensureStripeIds(
        ?string $stripePriceId,
        ?string $stripeProductId,
        ?string $planNameForProduct,
        array $pricing
    ): array {
        $client = $this->stripeClient();
        if (!$client) {
            // Cannot talk to Stripe — keep whatever was provided (including nulls)
            return ['price' => $stripePriceId, 'product' => $stripeProductId];
        }

        $priceId   = $stripePriceId ?: null;
        $productId = $stripeProductId ?: null;

        // If a price was provided, verify and derive product
        if ($priceId) {
            try {
                $p = $client->prices->retrieve($priceId, []);
                if (!$productId && is_string($p->product)) {
                    $productId = $p->product;
                }
                return ['price' => $priceId, 'product' => $productId];
            } catch (\Throwable $e) {
                error_log('[PLAN][STRIPE] supplied price invalid: ' . $e->getMessage());
                $priceId = null; // fallback to creation if we also have pricing
            }
        }

        // Creation path for one-time price requires amount & currency
        $amount       = $pricing['amount'] ?? null;
        $currency     = $pricing['currency'] ?? null;
        $product_name = $pricing['product_name'] ?: ($planNameForProduct ?: null);

        if (!$priceId) {
            if (!is_int($amount) || $amount <= 0 || !$currency) {
                // No usable info to create — return what we have (likely nulls)
                return ['price' => $stripePriceId, 'product' => $productId];
            }

            // Ensure product
            if (!$productId) {
                $pname = $product_name ?: 'One-time Purchase';
                try {
                    $prod = $client->products->create([
                        'name'   => $pname,
                        'active' => true,
                    ]);
                    $productId = $prod->id;
                } catch (\Throwable $e) {
                    error_log('[PLAN][STRIPE] product create failed: ' . $e->getMessage());
                    return ['price' => $stripePriceId, 'product' => $stripeProductId];
                }
            } else {
                try {
                    $client->products->retrieve($productId, []);
                } catch (\Throwable $e) {
                    // If the referenced product doesn’t exist, create a fresh one
                    try {
                        $prod = $client->products->create([
                            'name'   => $product_name ?: ($planNameForProduct ?: 'One-time Purchase'),
                            'active' => true,
                        ]);
                        $productId = $prod->id;
                    } catch (\Throwable $e2) {
                        error_log('[PLAN][STRIPE] product retrieve/create failed: ' . $e2->getMessage());
                        return ['price' => $stripePriceId, 'product' => $stripeProductId];
                    }
                }
            }

            // Create ONE-TIME price (no "recurring")
            try {
                $newPrice = $client->prices->create([
                    'unit_amount' => $amount,
                    'currency'    => strtolower($currency),
                    'product'     => $productId,
                    'active'      => true,
                ]);
                $priceId = $newPrice->id;
            } catch (\Throwable $e) {
                error_log('[PLAN][STRIPE] price create failed: ' . $e->getMessage());
                return ['price' => $stripePriceId, 'product' => $productId];
            }
        }

        return ['price' => $priceId, 'product' => $productId];
    }

    /**
     * Fetch Stripe product/price details when IDs are present.
     */
    private function fetchStripeBundle(?string $priceId, ?string $productId): array
    {
        $client = $this->stripeClient();
        if (!$client) {
            return ['ok' => false, 'error' => 'Stripe not configured'];
        }

        try {
            $price = null;
            if ($priceId) {
                $priceObj = $client->prices->retrieve($priceId, []);
                $price = [
                    'id'          => $priceObj->id,
                    'active'      => (bool) $priceObj->active,
                    'currency'    => $priceObj->currency ?? null,
                    'unit_amount' => $priceObj->unit_amount ?? null,
                    'type'        => $priceObj->type ?? null, // "one_time"
                    'recurring'   => null,
                    'product'     => $priceObj->product ?? null,
                ];
                if (!$productId && is_string($priceObj->product)) {
                    $productId = $priceObj->product;
                }
            }

            $product = null;
            if ($productId) {
                $prodObj = $client->products->retrieve($productId, []);
                $product = [
                    'id'       => $prodObj->id,
                    'active'   => (bool) $prodObj->active,
                    'name'     => $prodObj->name ?? null,
                    'metadata' => $prodObj->metadata ? $prodObj->metadata->toArray() : [],
                ];
            }

            $display = [
                'amount'      => $price['unit_amount'] ?? null,
                'currency'    => $price['currency'] ?? null,
                'recurring'   => null,
                'productName' => $product['name'] ?? null,
            ];

            return [
                'ok'      => true,
                'price'   => $price,
                'product' => $product,
                'display' => $display,
            ];
        } catch (\Throwable $e) {
            return ['ok' => false, 'error' => $e->getMessage()];
        }
    }

    /** Reads unit_amount from Stripe Price safely */
    private function readStripeUnitAmount(?string $priceId): ?int
    {
        if (!$priceId) return null;
        $client = $this->stripeClient();
        if (!$client) return null;

        try {
            $p = $client->prices->retrieve($priceId, []);
            return is_numeric($p->unit_amount) ? (int)$p->unit_amount : null;
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * Central Stripe client. Prefers STRIPE_SECRET.
     */
    private function stripeClient(): ?StripeClient
    {
        $sk = $_ENV['STRIPE_SECRET']
            ?? getenv('STRIPE_SECRET')
            ?? $_ENV['STRIPE_SECRET_KEY']
            ?? getenv('STRIPE_SECRET_KEY')
            ?? $_ENV['STRIPE_SK']
            ?? getenv('STRIPE_SK')
            ?? '';

        if ($sk === '' || !class_exists(StripeClient::class)) {
            error_log('[PLAN][STRIPE] Missing key or Stripe SDK not installed');
            return null;
        }

        return new StripeClient($sk);
    }

    /**
     * POST /plans/{id}/purchase
     *
     * Auth required (uses user_id attribute).
     *
     * Body (JSON):
     * {
     *   "projectHash"?: string,                  // optional; if omitted, purchase is recorded without attachment
     *   "stripe"?: { "checkoutSessionId"?: string }  // optional verification
     * }
     * @throws \ReflectionException
     * @throws \JsonException
     */
    #[Route(methods: 'POST', path: '/plans/{id}/purchase')]
    public function purchase(ServerRequestInterface $request): JsonResponse
    {
        // --- Auth ---
        $uid = (int) $request->getAttribute('user_id', 0);
        if ($uid <= 0) {
            throw new RuntimeException('Unauthorized', 401);
        }

        // --- Plan lookup ---
        $id = (int) $request->getAttribute('id');
        if ($id <= 0) {
            throw new RuntimeException('Invalid plan id', 400);
        }
        /** @var Plan|null $plan */
        $plan = $this->plans->find($id);
        if (!$plan) {
            throw new RuntimeException('Plan not found', 404);
        }

        // --- Body parse ---
        $raw  = (string) $request->getBody();
        $data = $raw !== '' ? json_decode($raw, true, 512, JSON_THROW_ON_ERROR) : [];
        if (!is_array($data)) { $data = []; }

        $projectHash = isset($data['projectHash']) ? trim((string)$data['projectHash']) : '';
        $checkoutSid = isset($data['stripe']['checkoutSessionId'])
            ? trim((string)$data['stripe']['checkoutSessionId'])
            : '';

        // --- Optional: verify Stripe Checkout session (best-effort, non-fatal if SDK not configured) ---
        if ($checkoutSid !== '') {
            $client = $this->stripeClient();
            if ($client) {
                try {
                    $sess     = $client->checkout->sessions->retrieve($checkoutSid, []);
                    $paidOk   = ((string)($sess->payment_status ?? '')) === 'paid';
                    $statusOk = ((string)($sess->status ?? '')) === 'complete';
                    if (!$paidOk || !$statusOk) {
                        throw new RuntimeException('Payment not settled', 402);
                    }
                } catch (\Throwable $e) {
                    throw new RuntimeException('Stripe verification failed: ' . $e->getMessage(), 400);
                }
            }
        }

        // --- Attach the buyer to the plan (ManyToMany) ---
        $this->ensureUserAttachedToPlan($plan, $uid);

        // --- If a project hash is supplied, ensure the caller owns/contributes to it, then attach ---
        $attached = null;
        if ($projectHash !== '') {
            /** @var Project|null $project */
            $project = $this->projects->findOneBy(['hash' => $projectHash]);
            if (!$project instanceof Project) {
                throw new RuntimeException('Project not found', 404);
            }

            // AuthZ: must be author or contributor
            $isAuthor = ((int)($project->getAuthor()?->getId() ?? 0)) === $uid;
            $isContributor = false;
            foreach ($project->getUsers() as $u) {
                if (method_exists($u, 'getId') && (int)$u->getId() === $uid) {
                    $isContributor = true; break;
                }
            }
            if (!$isAuthor && !$isContributor) {
                throw new RuntimeException('Forbidden', 403);
            }

            if (!method_exists($project, 'setPlan')) {
                throw new RuntimeException('Project entity cannot set plan', 500);
            }
            $project->setPlan($plan);
            $this->projects->save($project);

            $attached = [
                'id'     => $project->getId(),
                'hash'   => method_exists($project, 'getHash') ? $project->getHash() : null,
                'name'   => method_exists($project, 'getName') ? $project->getName() : null,
                'status' => method_exists($project, 'getStatus') ? $project->getStatus() : null,
            ];
        }

        // --- Send confirmation email to buyer (best-effort) ---
        /** @var User|null $buyer */
        $buyer = $this->users->find($uid);
        if ($buyer instanceof User) {
            $this->sendPlanPurchaseConfirmation($plan, $buyer, [
                'flow'   => 'checkout',
                'stripe' => [
                    'checkoutSessionId' => $checkoutSid ?: null,
                ],
            ]);
        }

        // --- Respond ---
        return new JsonResponse([
            'ok'        => true,
            'plan'      => $this->serializePlan($plan, includeStripe: true),
            'attached'  => $attached,          // null if no projectHash provided
            'stripe'    => ['verified' => $checkoutSid !== ''],
        ], 201);
    }

    /** @return array{amount:int,currency:string} */
    private function resolveAmountCurrency(Plan $plan): array
    {
        // Prefer Stripe Price (amount + currency) if present.
        $priceId = $plan->getStripe_price_id();
        $amount  = null;
        $currency = null;

        if ($priceId) {
            $client = $this->stripeClient();
            if ($client) {
                try {
                    $p = $client->prices->retrieve($priceId, []);
                    if (is_numeric($p->unit_amount)) {
                        $amount = (int) $p->unit_amount;
                    }
                    if (is_string($p->currency) && $p->currency !== '') {
                        $currency = strtolower($p->currency);
                    }
                } catch (\Throwable $e) {
                    error_log('[PLAN][RESOLVE_AMT] price retrieve failed: ' . $e->getMessage());
                }
            }
        }

        // Fallback: use local persisted price and default currency
        if (!is_int($amount) || $amount <= 0) {
            $amount = (int) ($plan->getPrice() ?? 0);
        }
        if (!$currency) {
            // Use your preferred default. Could also pull from env.
            $currency = strtolower($_ENV['DEFAULT_CURRENCY'] ?? getenv('DEFAULT_CURRENCY') ?: 'usd');
        }

        if ($amount <= 0 || !$currency) {
            throw new RuntimeException('Plan pricing unavailable (amount/currency). Configure Stripe price or local price.', 400);
        }

        return ['amount' => $amount, 'currency' => $currency];
    }

    /**
     * POST /plans/{id}/purchase-intent
     * Body (JSON): { "projectHash"?: string }
     * Returns: { clientSecret: string, amount: int, currency: string }
     */
    #[Route(methods: 'POST', path: '/plans/{id}/purchase-intent')]
    public function createPurchaseIntent(ServerRequestInterface $request): JsonResponse
    {
        // --- Auth ---
        $uid = (int) $request->getAttribute('user_id', 0);
        if ($uid <= 0) {
            throw new RuntimeException('Unauthorized', 401);
        }

        // --- Plan ---
        $id = (int) $request->getAttribute('id');
        if ($id <= 0) {
            throw new RuntimeException('Invalid plan id', 400);
        }
        /** @var Plan|null $plan */
        $plan = $this->plans->find($id);
        if (!$plan) {
            throw new RuntimeException('Plan not found', 404);
        }

        // --- Input ---
        $raw  = (string) $request->getBody();
        $data = $raw !== '' ? json_decode($raw, true) : [];
        if (!is_array($data)) { $data = []; }
        $projectHash = isset($data['projectHash']) ? trim((string)$data['projectHash']) : '';

        // --- Stripe client ---
        $client = $this->stripeClient();
        if (!$client) {
            throw new RuntimeException('Stripe not configured on server', 500);
        }

        // --- Amount/Currency ---
        $ac = $this->resolveAmountCurrency($plan); // may throw 400

        // Decide payment method types (cards + BNPL)
        $pmt = $this->allowedPaymentMethodTypes($ac['currency']);

        // --- Create PaymentIntent (explicit payment_method_types) ---
        try {
            $pi = $client->paymentIntents->create([
                'amount'               => $ac['amount'],
                'currency'             => $ac['currency'],
                'payment_method_types' => $pmt, // <-- cards, affirm (USD), klarna
                'metadata'             => [
                    'plan_id'      => (string) $plan->getId(),
                    'user_id'      => (string) $uid,
                    'project_hash' => $projectHash,
                ],
                // Optional: helps some Klarna flows pick a locale
                'payment_method_options' => [
                    'klarna' => [
                        'preferred_locale' => $_ENV['PREFERRED_LOCALE'] ?? 'en-US',
                    ],
                ],
            ]);
        } catch (\Throwable $e) {
            throw new RuntimeException('Stripe PaymentIntent failed: ' . $e->getMessage(), 500);
        }

        return new JsonResponse([
            'clientSecret' => $pi->client_secret,
            'amount'       => $ac['amount'],
            'currency'     => $ac['currency'],
            'methods'      => $pmt,
        ], 201);
    }

    /**
     * POST /plans/{id}/finalize-intent
     * Body (JSON): { "paymentIntentId": string, "projectHash"?: string }
     * Attaches buyer (user) to plan and optionally attaches project after client-side confirmation.
     * @throws \ReflectionException
     * @throws \JsonException
     */
    #[Route(methods: 'POST', path: '/plans/{id}/finalize-intent')]
    public function finalizeIntent(ServerRequestInterface $request): JsonResponse
    {
        // --- Auth ---
        $uid = (int) $request->getAttribute('user_id', 0);
        if ($uid <= 0) {
            throw new RuntimeException('Unauthorized', 401);
        }

        // --- Plan ---
        $id = (int) $request->getAttribute('id');
        if ($id <= 0) {
            throw new RuntimeException('Invalid plan id', 400);
        }
        /** @var Plan|null $plan */
        $plan = $this->plans->find($id);
        if (!$plan) {
            throw new RuntimeException('Plan not found', 404);
        }

        // --- Body ---
        $raw  = (string) $request->getBody();
        $data = $raw !== '' ? json_decode($raw, true, 512, JSON_THROW_ON_ERROR) : [];
        if (!is_array($data)) { $data = []; }

        $piId        = isset($data['paymentIntentId']) ? trim((string)$data['paymentIntentId']) : '';
        $projectHash = isset($data['projectHash']) ? trim((string)$data['projectHash']) : '';

        if ($piId === '') {
            throw new RuntimeException('paymentIntentId is required', 400);
        }

        // --- Verify on Stripe ---
        $client = $this->stripeClient();
        if (!$client) {
            throw new RuntimeException('Stripe not configured on server', 500);
        }

        try {
            $pi = $client->paymentIntents->retrieve($piId, []);
        } catch (\Throwable $e) {
            throw new RuntimeException('PaymentIntent retrieve failed: ' . $e->getMessage(), 400);
        }

        $status = (string)($pi->status ?? '');
        if (!$this->isIntentSettled($status)) {
            throw new RuntimeException('Payment not settled (status=' . $status . ')', 402);
        }

        if (isset($pi->metadata['plan_id']) && (int)$pi->metadata['plan_id'] !== (int)$plan->getId()) {
            throw new RuntimeException('PaymentIntent does not belong to this plan', 400);
        }

        // --- Attach buyer to plan (idempotent) ---
        $this->ensureUserAttachedToPlan($plan, $uid);

        // --- Optionally attach the selected project to the plan (same rules as /purchase) ---
        $attached = null;
        if ($projectHash !== '') {
            /** @var Project|null $project */
            $project = $this->projects->findOneBy(['hash' => $projectHash]);
            if (!$project instanceof Project) {
                throw new RuntimeException('Project not found', 404);
            }

            // Must be author or contributor
            $isAuthor = ((int)($project->getAuthor()?->getId() ?? 0)) === $uid;
            $isContributor = false;
            foreach ($project->getUsers() as $u) {
                if (method_exists($u, 'getId') && (int)$u->getId() === $uid) {
                    $isContributor = true; break;
                }
            }
            if (!$isAuthor && !$isContributor) {
                throw new RuntimeException('Forbidden', 403);
            }

            if (!method_exists($project, 'setPlan')) {
                throw new RuntimeException('Project entity cannot set plan', 500);
            }
            $project->setPlan($plan);
            $this->projects->save($project);

            $attached = [
                'id'     => $project->getId(),
                'hash'   => method_exists($project, 'getHash') ? $project->getHash() : null,
                'name'   => method_exists($project, 'getName') ? $project->getName() : null,
                'status' => method_exists($project, 'getStatus') ? $project->getStatus() : null,
            ];
        }

        // --- Send confirmation email to buyer (best-effort) ---
        /** @var User|null $buyer */
        $buyer = $this->users->find($uid);
        if ($buyer instanceof User) {
            $this->sendPlanPurchaseConfirmation($plan, $buyer, [
                'flow'   => 'payment_intent',
                'stripe' => [
                    'paymentIntentId' => (string)$pi->id,
                    'status'          => (string)$pi->status,
                    'amount'          => isset($pi->amount) ? (int)$pi->amount : null,
                    'currency'        => isset($pi->currency) ? (string)$pi->currency : null,
                ],
            ]);
        }

        return new JsonResponse([
            'ok'       => true,
            'plan'     => $this->serializePlan($plan, includeStripe: true),
            'attached' => $attached,
            'stripe'   => [
                'intent' => [
                    'id'     => (string)$pi->id,
                    'status' => (string)$pi->status,
                    'types'  => $pi->payment_method_types,
                ],
            ],
        ], 200);
    }

    /**
     * GET /me/plans
     *
     * Lists plans attached to the authenticated user (ManyToMany: plan ↔ users),
     * and includes projects currently linked to each plan.
     *
     * Query params:
     *  - page, perPage
     *  - includeStripe=1|0
     * @throws \ReflectionException
     */
    #[Route(methods: 'GET', path: '/me/plans')]
    public function listMyPlans(ServerRequestInterface $request): JsonResponse
    {
        // --- Auth ---
        $uid = (int) $request->getAttribute('user_id', 0);
        if ($uid <= 0) {
            throw new RuntimeException('Unauthorized', 401);
        }

        // --- Query params ---
        $q = $request->getQueryParams();
        $page = max(1, (int)($q['page'] ?? 1));
        $perPage = (int)($q['perPage'] ?? 24);
        if ($perPage <= 0) $perPage = 24;
        if ($perPage > 100) $perPage = 100;
        $offset = ($page - 1) * $perPage;

        $includeStripe = ((string)($q['includeStripe'] ?? '0')) === '1';

        // --- Pivot rows for this user (plan_user has plan_id,user_id) ---
        $base = (clone $this->plans->qb)
            ->from('plan_user', 'pu')
            ->where('pu.user_id', '=', $uid);

        // Total distinct plan ids. Some builders don't support COUNT(DISTINCT),
        // so just group by and count the grouped rows.
        $distinctIdsRows = $base->duplicate()
            ->select('pu.plan_id AS id')
            ->groupBy('pu.plan_id')
            ->fetchAll();
        $total = is_array($distinctIdsRows) ? count($distinctIdsRows) : 0;
        $pages = (int) max(1, (int) ceil($total / max(1, $perPage)));

        // Page of distinct plan ids
        $rows = $base->duplicate()
            ->select('pu.plan_id AS id')
            ->groupBy('pu.plan_id')
            ->orderBy('pu.plan_id', 'DESC')
            ->limit($perPage)
            ->offset($offset)
            ->fetchAll();

        $items = [];
        foreach ($rows as $r) {
            $pid = (int) ($r['id'] ?? 0);
            if ($pid <= 0) continue;

            /** @var Plan|null $plan */
            $plan = $this->plans->find($pid);
            if (!$plan instanceof Plan) continue;

            // Serialize plan (includes 'projects' as IDs)
            $serialized = $this->serializePlan($plan, includeStripe: $includeStripe);

            // Project summaries linked to this plan
            $prows = (clone $this->projects->qb)
                ->from('project', 'p')
                ->select('p.id AS id, p.hash AS hash, p.name AS name, p.status AS status')
                ->where('p.plan_id', '=', $pid)
                ->orderBy('p.id', 'DESC')
                ->fetchAll();

            $serialized['projectSummaries'] = array_map(static function (array $row): array {
                return [
                    'id'     => isset($row['id']) ? (int)$row['id'] : null,
                    'hash'   => $row['hash']   ?? null,
                    'name'   => $row['name']   ?? null,
                    'status' => $row['status'] ?? null,
                ];
            }, is_array($prows) ? $prows : []);

            $items[] = $serialized;
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
     * DELETE /plans/{id}
     */
    #[Route(methods: 'DELETE', path: '/plans/{id}')]
    public function delete(ServerRequestInterface $request): JsonResponse
    {
        // --- Auth ---
        $uid = (int) $request->getAttribute('user_id', 0);
        if ($uid <= 0) {
            throw new RuntimeException('Unauthorized', 401);
        }

        // --- Plan id ---
        $id = (int) $request->getAttribute('id');
        if ($id <= 0) {
            throw new RuntimeException('Invalid plan id', 400);
        }

        /** @var Plan|null $plan */
        $plan = $this->plans->find($id);
        if (!$plan) {
            throw new RuntimeException('Plan not found', 404);
        }

        // Optional: block deletion if in use
        // if (count($plan->getProjects()) > 0) {
        //     throw new RuntimeException('Cannot delete a plan that has attached projects', 400);
        // }
        // if (count($plan->getUsers()) > 0) {
        //     throw new RuntimeException('Cannot delete a plan that has buyers attached', 400);
        // }

        // --- Stripe: disable associated price/product (best-effort) ---
        $this->disableStripeForPlan($plan);

        // --- Local deletion ---
        $this->plans->delete($plan);

        return new JsonResponse(['ok' => true], 200);
    }

    /**
     * Mark Stripe price/product as inactive for a given plan (best-effort).
     */
    private function disableStripeForPlan(Plan $plan): void
    {
        $priceId   = $plan->getStripe_price_id();
        $productId = $plan->getStripe_product_id();

        if (!$priceId && !$productId) {
            return;
        }

        $client = $this->stripeClient();
        if (!$client) {
            // Stripe not configured; nothing else we can do
            error_log('[PLAN][STRIPE_DISABLE] Stripe client unavailable');
            return;
        }

        // Disable price
        if ($priceId) {
            try {
                $client->prices->update($priceId, [
                    'active' => false,
                ]);
            } catch (\Throwable $e) {
                // Non-fatal: log and continue
                error_log('[PLAN][STRIPE_DISABLE] price disable failed: ' . $e->getMessage());
            }
        }

        // Disable product
        if ($productId) {
            try {
                $client->products->update($productId, [
                    'active' => false,
                ]);
            } catch (\Throwable $e) {
                // Non-fatal as well
                error_log('[PLAN][STRIPE_DISABLE] product disable failed: ' . $e->getMessage());
            }
        }
    }

    private function getFrontendBaseUrl(): string
    {
        return rtrim(
            (string)(getenv('FRONTEND_BASE_URL') ?: 'https://monkeysraiser.com'),
            '/'
        );
    }

    private function sendPlanPurchaseConfirmation(Plan $plan, User $buyer, array $stripeCtx = []): void
    {
        try {
            $to = trim((string)($buyer->getEmail() ?? ''));
            if ($to === '' || !filter_var($to, FILTER_VALIDATE_EMAIL)) {
                return;
            }

            $buyerName = trim((string)($buyer->getFullName() ?? ''));
            if ($buyerName === '') {
                $buyerName = $to;
            }

            $planName = trim((string)($plan->getName() ?? ''));
            if ($planName === '') {
                $planName = 'Your plan';
            }

            // Amount + currency (best-effort)
            $amount      = null;
            $currency    = null;
            $formatted   = null;

            try {
                $ac = $this->resolveAmountCurrency($plan); // may throw
                $amount   = $ac['amount'];
                $currency = strtoupper($ac['currency']);
                // assume 2 decimal places (OK for USD/EUR/CRC, etc.)
                $formatted = number_format($amount / 100, 2) . ' ' . $currency;
            } catch (\Throwable $e) {
                error_log('[PLAN][PURCHASE_EMAIL] resolveAmountCurrency failed: ' . $e->getMessage());
            }

            $frontendBase = $this->getFrontendBaseUrl();
            // Adjust if you have a different page for billing
            $plansUrl = $frontendBase . '/dashboard/plans';

            $subject = sprintf('Your MonkeysRaiser plan is active: %s', $planName);

            $html = $this->renderer->render('emails/plan_purchase_confirmation', [
                'buyerName'        => $buyerName,
                'buyerEmail'       => $to,
                'planName'         => $planName,
                'planSlug'         => $plan->getSlug(),
                'amount'           => $amount,
                'currency'         => $currency,
                'amountFormatted'  => $formatted,
                'plansUrl'         => $plansUrl,
                'stripeContext'    => $stripeCtx,
            ]);

            $this->mail->sendSimple(
                $to,
                $subject,
                $html,
                null,
                null,
                null,
                false,
                [
                    'tags' => ['plan_purchase', 'billing'],
                    'metadata' => [
                        'planId'   => $plan->getId(),
                        'planSlug' => $plan->getSlug(),
                        'userId'   => $buyer->getId(),
                        'flow'     => $stripeCtx['flow'] ?? null,
                        'piId'     => $stripeCtx['stripe']['paymentIntentId'] ?? null,
                        'csId'     => $stripeCtx['stripe']['checkoutSessionId'] ?? null,
                    ],
                ]
            );
        } catch (\Throwable $e) {
            // Never break the purchase flow because of email issues.
            error_log('[PLAN][PURCHASE_EMAIL][ERR] ' . $e->getMessage());
        }
    }

}
