<?php

declare(strict_types=1);

namespace App\Controllers\Api;

use App\Services\AttachmentService;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\UploadedFileInterface;

final class AttachmentsController
{
    public function __construct(
        private readonly AttachmentService $attachments,
    ) {
    }

    public function create(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $uploaded = $this->firstFile($request->getUploadedFiles());
        if ($uploaded === null || $uploaded->getError() !== UPLOAD_ERR_OK) {
            $response->getBody()->write(json_encode([
                'success' => false,
                'message' => 'Drop a file to attach.',
                'data' => null,
                'error' => ['code' => 'VALIDATION_ERROR', 'details' => []],
            ]));

            return $response->withHeader('Content-Type', 'application/json')->withStatus(400);
        }
        $bytes = $uploaded->getStream()->getContents();
        $name = $uploaded->getClientFilename() ?? 'upload';
        $mime = $uploaded->getClientMediaType() ?? '';
        $result = $this->attachments->save($bytes, $name, $mime);
        $status = $result['success'] ? 200 : 400;
        $response->getBody()->write(json_encode($result, JSON_UNESCAPED_SLASHES));

        return $response->withHeader('Content-Type', 'application/json')->withStatus($status);
    }

    /**
     * @param array<string, mixed> $files
     */
    private function firstFile(array $files): ?UploadedFileInterface
    {
        foreach ($files as $file) {
            if ($file instanceof UploadedFileInterface) {
                return $file;
            }
            if (is_array($file)) {
                $nested = $this->firstFile($file);
                if ($nested !== null) {
                    return $nested;
                }
            }
        }

        return null;
    }
}
