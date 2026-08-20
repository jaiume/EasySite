<?php

declare(strict_types=1);

use App\DAO\ChatStore;
use App\DAO\JsonlWriter;
use App\DAO\PendingTurnStore;
use App\DAO\RevisionStore;
use App\DAO\RunRegistry;
use App\Support\Config;
use App\Support\DnsResolver;
use App\Support\HttpFetcher;
use App\Support\ImageWriter;
use App\Support\InboxGuard;
use App\Support\PathGuard;
use App\Support\PhpDnsResolver;
use App\Support\TimeBudget;
use App\Support\UrlGuard;
use App\Services\SpendService;
use App\Services\ToolLogger;
use App\Services\OpenRouterClient;

$baseDir = dirname(__DIR__);

return [
    Config::class => static function () use ($baseDir): Config {
        $path = $baseDir . '/config/config.ini';
        if (!is_readable($path)) {
            throw new RuntimeException('Missing config.ini. Copy config.ini.example.');
        }

        return new Config($path, $baseDir);
    },

    TimeBudget::class => static function (Config $config): TimeBudget {
        $cache = $config->baseDir() . '/var/data/runtime-timeouts.json';

        return TimeBudget::detect(null, null, TimeBudget::defaultNginxPaths(), $cache);
    },

    DnsResolver::class => \DI\get(PhpDnsResolver::class),

    PathGuard::class => static function (Config $config): PathGuard {
        $staging = $config->stagingRoot();
        if (!is_dir($staging)) {
            mkdir($staging, 0775, true);
        }

        return new PathGuard($staging, $config->docroot(), $config->baseDir());
    },

    InboxGuard::class => static function (Config $config): InboxGuard {
        return new InboxGuard($config->baseDir() . '/var/data/inbox');
    },

    UrlGuard::class => static function (DnsResolver $dns): UrlGuard {
        return new UrlGuard($dns);
    },

    HttpFetcher::class => static function (UrlGuard $guard, Config $config): HttpFetcher {
        return new HttpFetcher(
            $guard,
            $config->int('security.fetch_timeout_seconds', 10),
            $config->int('security.max_redirects', 3),
            'TashIncCp/1.0 (site editor; +https://tashincconsulting.com)'
        );
    },

    ImageWriter::class => static function (PathGuard $guard, Config $config): ImageWriter {
        return new ImageWriter($guard, $config->int('security.image_max_bytes', 5242880));
    },

    ChatStore::class => static function (Config $config): ChatStore {
        return new ChatStore($config->baseDir() . '/var/data/chats/current.json');
    },

    PendingTurnStore::class => static function (Config $config): PendingTurnStore {
        return new PendingTurnStore($config->baseDir() . '/var/data/chats/pending.json');
    },

    RevisionStore::class => static function (Config $config): RevisionStore {
        return new RevisionStore($config->baseDir() . '/var/data/revisions');
    },

    RunRegistry::class => static function (Config $config): RunRegistry {
        return new RunRegistry($config->baseDir() . '/var/data/runs');
    },

    ToolLogger::class => static function (Config $config): ToolLogger {
        $path = $config->resolveVarPath($config->string('logging.tool_log', 'var/data/logs/tools.jsonl'));

        return new ToolLogger(new JsonlWriter($path));
    },

    SpendService::class => static function (Config $config, OpenRouterClient $client): SpendService {
        $path = $config->resolveVarPath($config->string('logging.spend_log', 'var/data/logs/spend.jsonl'));

        return new SpendService($config, $client, new JsonlWriter($path));
    },
];
