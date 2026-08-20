<?php

declare(strict_types=1);

namespace App\Support;

final class ServiceResult
{
    /**
     * @param array<string, mixed>|null $data
     * @param array<string, mixed>|null $error
     * @return array{success: bool, message: string, data: mixed, error: mixed}
     */
    public static function ok(string $message, mixed $data = []): array
    {
        return [
            'success' => true,
            'message' => $message,
            'data' => $data,
            'error' => null,
        ];
    }

    /**
     * @param list<string> $details
     * @return array{success: bool, message: string, data: mixed, error: array{code: string, details: list<string>}}
     */
    public static function fail(string $message, string $code, array $details = []): array
    {
        return [
            'success' => false,
            'message' => $message,
            'data' => null,
            'error' => [
                'code' => $code,
                'details' => $details,
            ],
        ];
    }
}
