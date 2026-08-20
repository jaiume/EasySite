<?php

declare(strict_types=1);

namespace App\Services;

use App\DAO\JsonlWriter;
use App\Support\Config;
use App\Support\ServiceResult;

final class SpendService
{
    public function __construct(
        private readonly Config $config,
        private readonly OpenRouterClient $openRouter,
        private readonly JsonlWriter $spendLog,
    ) {
    }

    /**
     * @return array{success: bool, message: string, data: mixed, error: mixed}
     */
    public function assertUnderCap(): array
    {
        $status = $this->status();
        if (!$status['success']) {
            return $status;
        }
        $remaining = (float) ($status['data']['remaining'] ?? 0);
        if ($remaining <= 0) {
            return ServiceResult::fail(
                'Monthly OpenRouter spend cap reached. No further model calls until next month or a higher cap.',
                'SPEND_CAP',
            );
        }

        return $status;
    }

    /**
     * @return array{success: bool, message: string, data: mixed, error: mixed}
     */
    public function status(): array
    {
        $cap = $this->config->float('openrouter.monthly_spend_cap', 10.0);
        $local = $this->spendLog->sumFloat('cost');
        $remote = $this->openRouter->keyUsage();
        $used = $local;
        $source = 'local';
        if ($remote !== null) {
            $used = max($local, $remote);
            $source = 'openrouter';
        }
        $remaining = max(0, round($cap - $used, 4));

        return ServiceResult::ok('Spend status', [
            'cap' => $cap,
            'used' => round($used, 4),
            'remaining' => $remaining,
            'source' => $source,
        ]);
    }

    public function record(float $cost, string $kind, string $model): void
    {
        if ($cost < 0) {
            $cost = 0;
        }
        $this->spendLog->append([
            'time' => date('c'),
            'kind' => $kind,
            'model' => $model,
            'cost' => $cost,
        ]);
    }
}
