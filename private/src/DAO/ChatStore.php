<?php

declare(strict_types=1);

namespace App\DAO;

final class ChatStore
{
    public function __construct(
        private readonly string $file,
    ) {
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function load(): array
    {
        if (!is_readable($this->file)) {
            return [];
        }
        $data = json_decode((string) file_get_contents($this->file), true);
        if (!is_array($data) || !isset($data['messages']) || !is_array($data['messages'])) {
            return [];
        }

        /** @var list<array<string, mixed>> $messages */
        $messages = $data['messages'];

        return $messages;
    }

    /**
     * @param list<array<string, mixed>> $messages
     */
    public function save(array $messages): void
    {
        $dir = dirname($this->file);
        if (!is_dir($dir)) {
            mkdir($dir, 0770, true);
        }
        $payload = json_encode(['messages' => $messages], JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
        file_put_contents($this->file, $payload, LOCK_EX);
    }

    public function clear(): void
    {
        $this->save([]);
    }
}
