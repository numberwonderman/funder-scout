<?php

namespace App\Services;

use App\Models\Organization;
use App\Models\ResearchRun;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Symfony\Component\Process\Process;
use Throwable;

class AgentServiceClient
{
    protected function client(): PendingRequest
    {
        return Http::baseUrl((string) config('services.agent.url'))
            ->withHeader('X-Agent-Secret', (string) config('services.agent.secret'))
            ->acceptJson()->asJson()
            ->connectTimeout(5)
            ->timeout(120)
            ->retry(3, 350, fn (Throwable $exception): bool => $exception instanceof ConnectionException);
    }

    /**
     * @param  (callable(string $node, string $status, string $message): void)|null  $onProgress
     */
    public function research(ResearchRun $run, ?callable $onProgress = null): array
    {
        $campaign = $run->campaign->load('organization');

        $nonprofit = collect($campaign->organization->only(['name', 'website', 'ein', 'nonprofit_status', 'fiscal_sponsorship_status', 'mission', 'program_areas', 'populations_served', 'organization_age', 'annual_budget', 'staff_size', 'desired_funding_categories', 'desired_grant_min', 'desired_grant_max', 'needs', 'prefers_unrestricted', 'keywords', 'exclusions']))->all();
        foreach (['program_areas', 'populations_served', 'desired_funding_categories', 'needs', 'keywords', 'exclusions'] as $listField) {
            $nonprofit[$listField] = is_array($nonprofit[$listField] ?? null) ? $nonprofit[$listField] : [];
        }

        $payload = [
            'research_run_id' => $run->uuid,
            'nonprofit' => $nonprofit,
            'campaign' => ['title' => $campaign->title, 'description' => $campaign->description, 'goal_amount' => $campaign->goal_amount, 'geography' => $campaign->organization->geography, 'board_members' => $campaign->board_members ?? []],
        ];

        try {
            return $this->streamResearch($payload, $onProgress);
        } catch (ConnectionException $exception) {
            try {
                return $this->runLocalAgent($payload, (bool) config('services.agent.demo_mode'));
            } catch (RuntimeException) {
                throw $exception;
            }
        }
    }

    /**
     * Live mode streams newline-delimited progress/result/error events so the
     * caller can persist each research node's completion as it actually
     * happens, instead of learning about all of them at once when the whole
     * bounded graph finishes. Demo mode (and any other plain JSON response,
     * such as an auth failure) is read the normal, buffered way.
     *
     * @param  (callable(string $node, string $status, string $message): void)|null  $onProgress
     */
    protected function streamResearch(array $payload, ?callable $onProgress): array
    {
        $response = $this->client()->withOptions(['stream' => true])->post('/research', $payload);

        if (! str_contains((string) $response->header('Content-Type'), 'application/x-ndjson')) {
            return $response->throw()->json();
        }

        $body = $response->toPsrResponse()->getBody();
        $buffer = '';
        $result = null;

        while (! $body->eof()) {
            $buffer .= $body->read(8192);
            while (($newlinePosition = strpos($buffer, "\n")) !== false) {
                $line = trim(substr($buffer, 0, $newlinePosition));
                $buffer = substr($buffer, $newlinePosition + 1);
                if ($line === '') {
                    continue;
                }
                $event = json_decode($line, true);
                if (! is_array($event)) {
                    continue;
                }
                match ($event['type'] ?? null) {
                    'progress' => $onProgress?->__invoke((string) $event['node'], (string) ($event['status'] ?? 'completed'), (string) ($event['message'] ?? '')),
                    'result' => $result = $event['response'] ?? null,
                    'error' => throw new RuntimeException((string) ($event['detail'] ?? 'Live Strands research failed closed [unknown]')),
                    default => null,
                };
            }
        }

        if (! is_array($result)) {
            throw new RuntimeException('The research service closed its stream without producing a result.');
        }

        return $result;
    }

    public function enrichOrganization(Organization $organization): array
    {
        return $this->client()
            ->timeout(45)
            ->post('/enrich-organization', ['website' => $organization->website])
            ->throw()
            ->json();
    }

    protected function runLocalAgent(array $payload, bool $demoMode): array
    {
        $agentDirectory = base_path('../agent-service');
        $process = new Process([$agentDirectory.'/.venv/bin/python', '-m', 'app.cli'], $agentDirectory, ['DEMO_MODE' => $demoMode ? 'true' : 'false']);
        $process->setInput(json_encode($payload, JSON_THROW_ON_ERROR));
        $process->setTimeout($demoMode ? 30 : 120);
        $process->run();

        if (! $process->isSuccessful()) {
            throw new RuntimeException('The local Strands pipeline failed closed. Check the agent-service diagnostics.');
        }

        return json_decode($process->getOutput(), true, flags: JSON_THROW_ON_ERROR);
    }
}
