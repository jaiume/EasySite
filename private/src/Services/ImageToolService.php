<?php

declare(strict_types=1);

namespace App\Services;

use App\Support\ImageWriter;
use App\Support\PathGuardException;
use App\Support\ServiceResult;

final class ImageToolService
{
    public function __construct(
        private readonly OpenRouterClient $openRouter,
        private readonly SpendService $spend,
        private readonly ImageWriter $images,
        private readonly ToolLogger $logger,
    ) {
    }

    /**
     * @param array<string, mixed> $args
     * @return array{success: bool, message: string, data: mixed, error: mixed}
     */
    public function generateImage(array $args, string $model): array
    {
        $prompt = trim((string) ($args['prompt'] ?? ''));
        if ($prompt === '') {
            return ServiceResult::fail('Prompt is required.', 'VALIDATION_ERROR');
        }
        $cap = $this->spend->assertUnderCap();
        if (!$cap['success']) {
            return $cap;
        }
        $aspect = isset($args['aspect_ratio']) && is_string($args['aspect_ratio']) ? $args['aspect_ratio'] : null;
        try {
            $response = $this->openRouter->generateImage($model, $prompt, $aspect);
        } catch (\Throwable $e) {
            return ServiceResult::fail('Image generation failed.', 'IMAGE_GEN_FAILED', [$e->getMessage()]);
        }
        $cost = 0.0;
        if (isset($response['usage']['cost']) && is_numeric($response['usage']['cost'])) {
            $cost = (float) $response['usage']['cost'];
        }
        $this->spend->record($cost, 'image', $model);
        $b64 = $response['data'][0]['b64_json'] ?? null;
        if (!is_string($b64) || $b64 === '') {
            return ServiceResult::fail('Image API returned no image data.', 'IMAGE_GEN_FAILED');
        }
        $bytes = base64_decode($b64, true);
        if ($bytes === false) {
            return ServiceResult::fail('Image API returned invalid data.', 'IMAGE_GEN_FAILED');
        }
        $name = $args['filename'] ?? ('generated-' . date('YmdHis') . '.png');
        try {
            $saved = $this->images->save($bytes, is_string($name) ? $name : 'generated.png');
        } catch (PathGuardException $e) {
            return ServiceResult::fail($e->getMessage(), 'PATH_DENIED');
        }
        $this->logger->log('generate_image', $saved['path'], $saved['bytes'], $cost);

        return ServiceResult::ok('Generated and saved image', $saved);
    }
}
