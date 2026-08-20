<?php

declare(strict_types=1);

namespace App\Services;

use App\Support\Config;

final class ModelCatalogService
{
    public function __construct(
        private readonly Config $config,
        private readonly OpenRouterClient $openRouter,
    ) {
    }

    /**
     * @return array{chat: list<array{id: string, name: string, price: string}>, image: list<array{id: string, name: string, price: string}>}
     */
    public function catalog(): array
    {
        return [
            'chat' => $this->chatModels(false),
            'image' => $this->imageModels(false),
        ];
    }

    /**
     * @return array{chat: list<array{id: string, name: string, price: string}>, image: list<array{id: string, name: string, price: string}>}
     */
    public function refresh(): array
    {
        return [
            'chat' => $this->chatModels(true),
            'image' => $this->imageModels(true),
        ];
    }

    public function isAllowedChatModel(string $id): bool
    {
        $default = $this->config->string('openrouter.default_chat_model');
        if ($id === $default) {
            return true;
        }
        foreach ($this->chatModels(false) as $model) {
            if ($model['id'] === $id) {
                return true;
            }
        }

        return false;
    }

    public function isAllowedImageModel(string $id): bool
    {
        $default = $this->config->string('openrouter.default_image_model');
        if ($id === $default) {
            return true;
        }
        foreach ($this->imageModels(false) as $model) {
            if ($model['id'] === $id) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return list<array{id: string, name: string, price: string}>
     */
    private function chatModels(bool $force): array
    {
        if (!$force) {
            $cached = $this->readCache('chat-models-mm.json');
            if ($cached !== null) {
                return $cached;
            }
        }
        $raw = $this->openRouter->listChatModels();
        $out = [];
        $data = is_array($raw) && isset($raw['data']) && is_array($raw['data']) ? $raw['data'] : [];
        foreach ($data as $row) {
            if (!is_array($row) || !isset($row['id']) || !is_string($row['id'])) {
                continue;
            }
            if (!$this->hasModalities($row, ['text', 'image'], ['text'])) {
                continue;
            }
            $id = $row['id'];
            if (str_contains($id, 'embedding') || str_contains($id, 'whisper') || str_contains($id, 'moderation')) {
                continue;
            }
            $name = isset($row['name']) && is_string($row['name']) ? $row['name'] : $id;
            $out[] = [
                'id' => $id,
                'name' => $name,
                'price' => $this->priceLabel($row, false),
            ];
        }
        usort($out, static fn ($a, $b) => strcasecmp($a['name'], $b['name']));
        if ($out !== []) {
            $this->writeCache('chat-models-mm.json', $out);
        }

        return $out;
    }

    /**
     * @return list<array{id: string, name: string, price: string}>
     */
    private function imageModels(bool $force): array
    {
        if (!$force) {
            $cached = $this->readCache('image-models-io.json');
            if ($cached !== null) {
                return $cached;
            }
        }
        $raw = $this->openRouter->listImageModels();
        $out = [];
        $data = is_array($raw) && isset($raw['data']) && is_array($raw['data']) ? $raw['data'] : [];
        foreach ($data as $row) {
            if (!is_array($row) || !isset($row['id']) || !is_string($row['id'])) {
                continue;
            }
            if (!$this->hasModalities($row, ['text'], ['image'])) {
                continue;
            }
            $name = isset($row['name']) && is_string($row['name']) ? $row['name'] : $row['id'];
            $out[] = [
                'id' => $row['id'],
                'name' => $name,
                'price' => $this->priceLabel($row, true),
            ];
        }
        usort($out, static fn ($a, $b) => strcasecmp($a['name'], $b['name']));
        if ($out !== []) {
            $this->writeCache('image-models-io.json', $out);
        }

        return $out;
    }

    /**
     * @param array<string, mixed> $row
     * @param list<string> $needInput
     * @param list<string> $needOutput
     */
    private function hasModalities(array $row, array $needInput, array $needOutput): bool
    {
        $arch = $row['architecture'] ?? null;
        if (!is_array($arch)) {
            return false;
        }
        $inputs = isset($arch['input_modalities']) && is_array($arch['input_modalities'])
            ? $arch['input_modalities']
            : [];
        $outputs = isset($arch['output_modalities']) && is_array($arch['output_modalities'])
            ? $arch['output_modalities']
            : [];

        return $this->containsAll($inputs, $needInput) && $this->containsAll($outputs, $needOutput);
    }

    /**
     * @param list<mixed> $haystack
     * @param list<string> $needles
     */
    private function containsAll(array $haystack, array $needles): bool
    {
        foreach ($needles as $needle) {
            if (!in_array($needle, $haystack, true)) {
                return false;
            }
        }

        return true;
    }

    /**
     * @param array<string, mixed> $row
     */
    private function priceLabel(array $row, bool $imageModel): string
    {
        $pricing = $row['pricing'] ?? null;
        if (!is_array($pricing)) {
            return '';
        }
        $prompt = isset($pricing['prompt']) && is_numeric($pricing['prompt']) ? (float) $pricing['prompt'] : 0.0;
        $completion = isset($pricing['completion']) && is_numeric($pricing['completion']) ? (float) $pricing['completion'] : 0.0;
        $image = isset($pricing['image']) && is_numeric($pricing['image']) ? (float) $pricing['image'] : 0.0;
        $promptPerM = $prompt * 1_000_000;
        $completionPerM = $completion * 1_000_000;
        if ($imageModel && $image > 0 && $promptPerM <= 0 && $completionPerM <= 0) {
            return '$' . $this->formatUsd($image) . '/image';
        }
        if ($promptPerM <= 0 && $completionPerM <= 0) {
            if ($image > 0) {
                return '$' . $this->formatUsd($image) . '/image';
            }

            return 'free';
        }
        $label = '$' . $this->formatUsd($promptPerM) . '/$' . $this->formatUsd($completionPerM) . ' per 1M';
        if ($imageModel && $image > 0) {
            $label .= ', $' . $this->formatUsd($image) . '/image';
        }

        return $label;
    }

    private function formatUsd(float $n): string
    {
        if ($n <= 0) {
            return '0';
        }
        $decimals = $n >= 1 ? 2 : 4;
        $formatted = number_format($n, $decimals, '.', '');

        return rtrim(rtrim($formatted, '0'), '.');
    }

    /**
     * @return list<array{id: string, name: string, price: string}>|null
     */
    private function readCache(string $file): ?array
    {
        $path = $this->config->baseDir() . '/var/cache/' . $file;
        if (!is_readable($path)) {
            return null;
        }
        $ttl = $this->config->int('openrouter.catalog_ttl_seconds', 21600);
        if (filemtime($path) < time() - $ttl) {
            return null;
        }
        $decoded = json_decode((string) file_get_contents($path), true);
        if (!is_array($decoded)) {
            return null;
        }
        $out = [];
        foreach ($decoded as $row) {
            if (!is_array($row) || !isset($row['id']) || !is_string($row['id'])) {
                continue;
            }
            $out[] = [
                'id' => $row['id'],
                'name' => isset($row['name']) && is_string($row['name']) ? $row['name'] : $row['id'],
                'price' => isset($row['price']) && is_string($row['price']) ? $row['price'] : '',
            ];
        }
        if ($out !== [] && $out[0]['price'] === '') {
            return null;
        }

        return $out;
    }

    /**
     * @param list<array{id: string, name: string, price: string}> $data
     */
    private function writeCache(string $file, array $data): void
    {
        $dir = $this->config->baseDir() . '/var/cache';
        if (!is_dir($dir)) {
            mkdir($dir, 0770, true);
        }
        file_put_contents($dir . '/' . $file, json_encode($data, JSON_UNESCAPED_SLASHES));
    }
}
