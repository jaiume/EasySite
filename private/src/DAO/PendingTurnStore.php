<?php

declare(strict_types=1);

namespace App\DAO;

final class PendingTurnStore
{
    public function __construct(
        private readonly string $file,
    ) {
    }

    public function exists(): bool
    {
        return $this->load() !== null;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function load(): ?array
    {
        if (!is_readable($this->file)) {
            return null;
        }
        $data = json_decode((string) file_get_contents($this->file), true);
        if (!is_array($data) || !isset($data['messages']) || !is_array($data['messages']) || $data['messages'] === []) {
            return null;
        }

        return $data;
    }

    /**
     * @param array<string, mixed> $state
     */
    public function save(array $state): void
    {
        $dir = dirname($this->file);
        if (!is_dir($dir)) {
            mkdir($dir, 0770, true);
        }
        $json = json_encode($state, JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
        if (!is_string($json)) {
            return;
        }
        file_put_contents($this->file, $json, LOCK_EX);
    }

    /**
     * Ignore writes from a run that is no longer the active continuation.
     *
     * @param array<string, mixed> $state
     */
    public function saveIfCurrent(string $runId, array $state): void
    {
        $current = $this->load();
        $owner = is_array($current) ? (string) ($current['run_id'] ?? '') : '';
        if ($owner !== '' && $owner !== $runId) {
            return;
        }
        $state['run_id'] = $runId;
        if (!array_key_exists('auto_continue', $state)) {
            $state['auto_continue'] = is_array($current) ? ($current['auto_continue'] ?? true) : true;
        }
        $this->save($state);
    }

    /**
     * Take ownership of the pending turn, replacing any previous run.
     *
     * @param array<string, mixed> $state
     */
    public function claim(string $runId, array $state): void
    {
        $state['run_id'] = $runId;
        $state['auto_continue'] = true;
        $this->save($state);
    }

    public function haltAuto(): void
    {
        $current = $this->load();
        if ($current === null) {
            return;
        }
        $current['auto_continue'] = false;
        $this->save($current);
    }

    public function resumeAuto(): void
    {
        $current = $this->load();
        if ($current === null) {
            return;
        }
        $current['auto_continue'] = true;
        $this->save($current);
    }

    public function allowsAutoContinue(): bool
    {
        $current = $this->load();
        if ($current === null) {
            return false;
        }

        return ($current['auto_continue'] ?? true) !== false;
    }

    public function clear(): void
    {
        if (is_file($this->file)) {
            @unlink($this->file);
        }
    }

    public function clearIfRun(string $runId): void
    {
        $current = $this->load();
        if ($current === null) {
            return;
        }
        $owner = (string) ($current['run_id'] ?? '');
        if ($owner === '' || $owner === $runId) {
            $this->clear();
        }
    }
}
