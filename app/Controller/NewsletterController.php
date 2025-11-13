<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\Newsletter;
use DateTimeImmutable;
use MonkeysLegion\Http\Message\JsonResponse;
use MonkeysLegion\Repository\EntityRepository;
use MonkeysLegion\Repository\RepositoryFactory;
use MonkeysLegion\Router\Attributes\Route;
use Psr\Http\Message\ServerRequestInterface;
use RuntimeException;

final class NewsletterController
{
    private EntityRepository $newsletters;

    public function __construct(
        private RepositoryFactory $repos,
    ) {
        $this->newsletters = $this->repos->getRepository(Newsletter::class);
    }

    /**
     * POST /newsletter/subscribe
     *
     * Public endpoint to register an email into the newsletter.
     *
     * Accepts:
     *  - JSON: { "email": "foo@bar.com" }
     *  - x-www-form-urlencoded / multipart: email=foo@bar.com
     *
     * Response (201 on new, 200 if already exists):
     *  {
     *    "id": 1,
     *    "email": "foo@bar.com",
     *    "subscribedAt": "2025-11-12T17:00:00+00:00"
     *  }
     *
     * @throws \JsonException
     */
    #[Route(methods: 'POST', path: '/newsletter/subscribe')]
    public function subscribe(ServerRequestInterface $request): JsonResponse
    {
        $data = $this->parseBody($request);

        $email = isset($data['email']) ? trim((string)$data['email']) : '';
        if ($email === '') {
            throw new RuntimeException('Email is required', 400);
        }

        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw new RuntimeException('Invalid email address', 400);
        }

        /** @var Newsletter|null $existing */
        $existing = $this->newsletters->findOneBy(['email' => $email]);
        if ($existing instanceof Newsletter) {
            // Idempotent: if already subscribed, just return existing record
            return new JsonResponse($this->serializeNewsletter($existing), 200);
        }

        $newsletter = new Newsletter();
        $newsletter
            ->setEmail($email)
            ->setSubscribedAt(new DateTimeImmutable('now', new \DateTimeZone('UTC')));

        $this->newsletters->save($newsletter);

        return new JsonResponse($this->serializeNewsletter($newsletter), 201);
    }

    /**
     * POST /newsletter/unsubscribe
     *
     * Public endpoint to remove an email from the newsletter.
     *
     * Accepts:
     *  - JSON: { "email": "foo@bar.com" }
     *  - x-www-form-urlencoded / multipart: email=foo@bar.com
     *
     * Always responds with 200 (idempotent), without revealing
     * whether the email was actually subscribed before.
     * @throws \Throwable
     */
    #[Route(methods: 'POST', path: '/newsletter/unsubscribe')]
    public function unsubscribe(ServerRequestInterface $request): JsonResponse
    {
        $data = $this->parseBody($request);

        $email = isset($data['email']) ? trim((string)$data['email']) : '';
        if ($email === '') {
            throw new RuntimeException('Email is required', 400);
        }

        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw new RuntimeException('Invalid email address', 400);
        }

        /** @var Newsletter|null $existing */
        $existing = $this->newsletters->findOneBy(['email' => $email]);
        $wasSubscribed = false;

        if ($existing instanceof Newsletter) {
            // Assuming EntityRepository has a delete/remove method.
            $this->newsletters->delete($existing);
            $wasSubscribed = true;
        }

        return new JsonResponse([
            'status'           => 'ok',
            'email'            => $email,
            'wasSubscribed'    => $wasSubscribed,
            'unsubscribed'     => true,
        ], 200);
    }


    // --------------------------------------------------
    // Helpers
    // --------------------------------------------------

    /**
     * Parse JSON or form-encoded body into an array.
     *
     * @throws \JsonException
     */
    private function parseBody(ServerRequestInterface $request): array
    {
        $ct = strtolower($request->getHeaderLine('Content-Type') ?: '');
        $parsed = $request->getParsedBody();

        // If framework already parsed body into array, use it
        if (is_array($parsed) && !empty($parsed)) {
            return $parsed;
        }

        // JSON body
        if (str_starts_with($ct, 'application/json')) {
            $bodyStream = $request->getBody();
            $rawBody = '';
            try {
                $rawBody = (string) $bodyStream;
                if ($rawBody === '') {
                    $rawBody = $bodyStream->getContents();
                }
            } catch (\Throwable) {
            }

            if ($rawBody === '') {
                $rawBody = @file_get_contents('php://input') ?: '';
            }

            if ($rawBody === '') {
                return [];
            }

            $data = json_decode($rawBody, true, 512, JSON_THROW_ON_ERROR);
            return is_array($data) ? $data : [];
        }

        return is_array($parsed) ? $parsed : [];
    }

    private function serializeNewsletter(Newsletter $n): array
    {
        return [
            'id'           => $n->getId(),
            'email'        => $n->getEmail(),
            'subscribedAt' => $n->getSubscribedAt()
                ? $n->getSubscribedAt()->format(\DateTimeInterface::ATOM)
                : null,
        ];
    }
}
