<?php

declare(strict_types=1);

namespace App\Service;

use RuntimeException;

final class MonkeysMailService
{
    private string $apiKey;
    private string $baseUrl;

    private const DEFAULT_FROM_EMAIL = 'no-reply@monkeysraiser.com';
    private const DEFAULT_FROM_NAME  = 'MonkeysRaiser';

    public function __construct(?string $apiKey = null, ?string $baseUrl = null)
    {
        // Read from DI or env
        $this->apiKey  = "cad2b3f669cfb3db.bde36023da34fdf6bac5f4a541ba5f2dcddb44a4c7420e7d6aee7b2f974efd94";
        $this->baseUrl = rtrim(
            $baseUrl
                ?: (string) getenv('MONKEYSMAIL_API_BASE')
                ?: 'https://smtp.monkeysmail.com',
            '/'
        );
    }

    /**
     * Low-level send wrapper for POST /messages/send
     *
     * @param array<string,mixed> $payload
     * @return array<string,mixed>
     */
    public function send(array $payload, bool $sync = false): array
    {
        if ($sync) {
            $payload['mode'] = $payload['mode'] ?? 'sync';
        }

        // Debug: log presence of attachments before sending
        $attCount = isset($payload['attachments']) && is_array($payload['attachments'])
            ? count($payload['attachments'])
            : 0;
        error_log('[MAIL][SEND] attachments count=' . $attCount);

        $url  = $this->baseUrl . '/messages/send';
        $json = json_encode($payload, JSON_THROW_ON_ERROR);

        // Optional: log a truncated JSON preview (avoid huge logs)
        error_log('[MAIL][SEND] payload preview=' . substr($json, 0, 1000));

        $ch = curl_init($url);
        if ($ch === false) {
            throw new RuntimeException('Unable to initialize cURL for MonkeysMailService');
        }

        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_HTTPHEADER     => [
                'Content-Type: application/json',
                'X-API-Key: ' . $this->apiKey,
            ],
            CURLOPT_POSTFIELDS     => $json,
            CURLOPT_RETURNTRANSFER => true,
        ]);

        $body = curl_exec($ch);
        if ($body === false) {
            $err = curl_error($ch);
            curl_close($ch);
            throw new RuntimeException('MonkeysMail HTTP error: ' . $err);
        }

        $status = curl_getinfo($ch, CURLINFO_RESPONSE_CODE) ?: 0;
        curl_close($ch);

        $data = json_decode($body, true);
        if (!is_array($data)) {
            $data = ['raw' => $body];
        }

        if ($status < 200 || $status >= 300) {
            $msg = (string)($data['message'] ?? $data['error'] ?? 'Unknown error');
            error_log('[MAIL][SEND][ERR] status=' . $status . ' message=' . $msg);
            throw new RuntimeException(
                sprintf('MonkeysMail API error (%d): %s', $status, $msg),
                $status
            );
        }

        /** @var array<string,mixed> $data */
        return $data;
    }

    /**
     * Simple convenience for single-recipient sends.
     *
     * NOTE: Sender is ALWAYS:
     *   - email: no-reply@monkeysraiser.com
     *   - name:  MonkeysRaiser
     *
     * @throws \JsonException
     */
    public function sendSimple(
        string $to,
        string $subject,
        string $html,
        ?string $text = null,
        ?string $fromEmail = null,
        ?string $fromName = null,
        bool $sync = false,
        array $extra = []
    ): array {
        $payload = array_merge($extra, [
            'from' => [
                'email' => self::DEFAULT_FROM_EMAIL,
                'name'  => self::DEFAULT_FROM_NAME,
            ],
            'to'      => [$to],
            'subject' => $subject,
            'html'    => $html,
        ]);

        if ($text !== null) {
            $payload['text'] = $text;
        }

        return $this->send($payload, $sync);
    }

}
