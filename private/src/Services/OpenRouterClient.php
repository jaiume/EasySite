<?php

declare(strict_types=1);

namespace App\Services;

use App\Support\Config;
use App\Support\OpenRouterException;
use App\Support\TimeBudget;

final class OpenRouterClient
{
    public function __construct(
        private readonly Config $config,
        private readonly TimeBudget $timeBudget,
    ) {
    }

    public function apiKey(): string
    {
        return trim($this->config->string('openrouter.api_key'));
    }

    /**
     * @param list<array<string, mixed>> $messages
     * @param list<array<string, mixed>> $tools
     * @param callable(): bool|null $onProgress Return false to abort the HTTP request.
     * @return array<string, mixed>
     */
    public function chat(string $model, array $messages, array $tools, int $timeoutSeconds = 0, ?callable $onProgress = null): array
    {
        $payload = [
            'model' => $model,
            'messages' => $this->sanitizeMessages($messages),
            'tools' => $tools,
        ];

        return $this->postJson('https://openrouter.ai/api/v1/chat/completions', $payload, $timeoutSeconds, $onProgress);
    }

    /**
     * @return array<string, mixed>
     */
    public function generateImage(string $model, string $prompt, ?string $aspectRatio = null): array
    {
        $payload = [
            'model' => $model,
            'prompt' => $prompt,
        ];
        if ($aspectRatio !== null && $aspectRatio !== '') {
            $payload['aspect_ratio'] = $aspectRatio;
        }

        return $this->postJson('https://openrouter.ai/api/v1/images', $payload);
    }

    public function keyUsage(): ?float
    {
        $key = $this->apiKey();
        if ($key === '') {
            return null;
        }
        $result = $this->getJson('https://openrouter.ai/api/v1/key');
        if (!is_array($result) || !isset($result['data']) || !is_array($result['data'])) {
            return null;
        }
        $usage = $result['data']['usage'] ?? null;
        if (is_numeric($usage)) {
            return (float) $usage;
        }

        return null;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function listChatModels(): ?array
    {
        return $this->getJson('https://openrouter.ai/api/v1/models?input_modalities=text,image&output_modalities=text');
    }

    /**
     * @return array<string, mixed>|null
     */
    public function listImageModels(): ?array
    {
        return $this->getJson('https://openrouter.ai/api/v1/models?input_modalities=text&output_modalities=image');
    }

    /**
     * @param callable(): bool|null $onProgress Return false to abort the HTTP request.
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    private function postJson(string $url, array $payload, int $timeoutSeconds = 0, ?callable $onProgress = null): array
    {
        $key = $this->apiKey();
        if ($key === '') {
            throw new \RuntimeException('OpenRouter API key is not configured.');
        }
        $timeout = $timeoutSeconds > 0
            ? max(10, $timeoutSeconds)
            : $this->timeBudget->chatSeconds;
        $ch = curl_init($url);
        if ($ch === false) {
            throw new \RuntimeException('Unable to start OpenRouter request.');
        }
        $lastCheck = 0.0;
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => [
                'Authorization: Bearer ' . $key,
                'Content-Type: application/json',
                'HTTP-Referer: ' . $this->config->baseUrl(),
                'X-Title: Tash Inc Control',
            ],
            CURLOPT_POSTFIELDS => json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE),
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_TIMEOUT => $timeout,
            CURLOPT_NOPROGRESS => false,
            CURLOPT_PROGRESSFUNCTION => static function ($ch, $dt, $dn, $ut, $un) use ($onProgress, &$lastCheck): int {
                if (connection_aborted()) {
                    return 1;
                }
                $now = microtime(true);
                if ($now - $lastCheck < 0.4) {
                    return 0;
                }
                $lastCheck = $now;
                if ($onProgress === null) {
                    return 0;
                }

                return $onProgress() === false ? 1 : 0;
            },
        ]);
        $raw = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $err = curl_error($ch);
        curl_close($ch);
        $aborted = connection_aborted()
            || ($err !== '' && (stripos($err, 'aborted') !== false || stripos($err, 'callback') !== false));
        if ($aborted) {
            throw new OpenRouterException('Stopped.', $err !== '' ? $err : 'Request aborted.', 0, false, true);
        }
        if (!is_string($raw) || $raw === '') {
            $timedOut = $err !== '' && (stripos($err, 'timed out') !== false || stripos($err, 'timeout') !== false);
            $message = $timedOut ? 'The model did not respond in time.' : ($err !== '' ? $err : 'Empty OpenRouter response.');
            throw new OpenRouterException(
                $message,
                $err !== '' ? $err : 'Empty body from OpenRouter.',
                $status,
                $timedOut
            );
        }
        $decoded = json_decode($raw, true);
        if (!is_array($decoded)) {
            throw new OpenRouterException(
                'OpenRouter returned invalid JSON.',
                $raw,
                $status
            );
        }
        if ($status >= 400 || isset($decoded['error'])) {
            $msg = 'OpenRouter error';
            if (isset($decoded['error']['message']) && is_string($decoded['error']['message'])) {
                $msg = $decoded['error']['message'];
            }
            $details = json_encode($decoded, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            if (!is_string($details) || $details === '') {
                $details = $raw;
            }
            if ($status > 0) {
                $details = 'HTTP ' . $status . "\n" . $details;
            }
            throw new OpenRouterException($msg, $details, $status);
        }

        return $decoded;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function getJson(string $url): ?array
    {
        $key = $this->apiKey();
        $headers = ['Content-Type: application/json'];
        if ($key !== '') {
            $headers[] = 'Authorization: Bearer ' . $key;
        }
        $ch = curl_init($url);
        if ($ch === false) {
            return null;
        }
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_TIMEOUT => 30,
        ]);
        $raw = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch);
        if (!is_string($raw) || $status >= 400) {
            return null;
        }
        $decoded = json_decode($raw, true);

        return is_array($decoded) ? $decoded : null;
    }

    /**
     * Drop provider-only fields that bloat later rounds (Grok reasoning blobs).
     *
     * @param list<array<string, mixed>> $messages
     * @return list<array<string, mixed>>
     */
    private function sanitizeMessages(array $messages): array
    {
        $out = [];
        foreach ($messages as $row) {
            if (!is_array($row)) {
                continue;
            }
            unset($row['reasoning'], $row['reasoning_details'], $row['refusal']);
            $out[] = $row;
        }

        return $out;
    }
}
