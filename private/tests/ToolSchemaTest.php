<?php

declare(strict_types=1);

namespace App\Tests;

use App\Services\AgentLoopService;
use PHPUnit\Framework\TestCase;

final class ToolSchemaTest extends TestCase
{
    public function testEmptyToolPropertiesEncodeAsJsonObject(): void
    {
        $ref = new \ReflectionClass(AgentLoopService::class);
        /** @var AgentLoopService $loop */
        $loop = $ref->newInstanceWithoutConstructor();
        $json = json_encode($loop->toolDefinitions(), JSON_UNESCAPED_SLASHES);
        $this->assertNotFalse($json);
        self::assertStringContainsString('"name":"list_inbox"', $json);
        $this->assertStringContainsString('"name":"edit_file"', $json);
        $this->assertStringContainsString('"name":"inspect_page"', $json);
        $this->assertStringContainsString('"properties":{}', $json);
        $this->assertStringNotContainsString('"properties":[]', $json);
    }
}
