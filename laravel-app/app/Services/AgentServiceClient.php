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

    public function research(ResearchRun $run): array
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
            return $this->client()->post('/research', $payload)->throw()->json();
        } catch (ConnectionException $exception) {
            try {
                return $this->runLocalAgent($payload, (bool) config('services.agent.demo_mode'));
            } catch (RuntimeException) {
                throw $exception;
            }
        }
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
