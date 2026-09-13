<?php

namespace Tests\Unit\Services;

use App\Services\AgentServiceClient;
use RuntimeException;
use Tests\TestCase;

class AgentServiceClientTest extends TestCase
{
    public function test_local_pipeline_failure_does_not_expose_internal_diagnostics(): void
    {
        $client = new class extends AgentServiceClient
        {
            public function invokeInvalidPayload(): array
            {
                return $this->runLocalAgent([], false);
            }
        };

        try {
            $client->invokeInvalidPayload();
            $this->fail('Expected the invalid pipeline payload to fail.');
        } catch (RuntimeException $exception) {
            $this->assertSame('The local Strands pipeline failed closed. Check the agent-service diagnostics.', $exception->getMessage());
            $this->assertStringNotContainsString('Traceback', $exception->getMessage());
        }
    }
}
