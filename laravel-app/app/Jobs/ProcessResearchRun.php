<?php

namespace App\Jobs;

use App\Enums\ResearchStatus;
use App\Models\ResearchRun;
use App\Services\AgentServiceClient;
use App\Services\CrmEnrichmentService;
use App\Services\ResearchResultIngestor;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Throwable;

class ProcessResearchRun implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 140;

    public function __construct(public int $researchRunId) {}

    public function handle(AgentServiceClient $client, CrmEnrichmentService $crm, ResearchResultIngestor $ingestor): void
    {
        $run = ResearchRun::with('campaign.organization')->findOrFail($this->researchRunId);
        if (in_array($run->status, [ResearchStatus::Completed, ResearchStatus::Failed], true)) {
            return;
        }
        $run->update(['status' => ResearchStatus::Analyzing, 'started_at' => $run->started_at ?? now(), 'failure_message' => null]);
        $run->events()->updateOrCreate(
            ['node' => 'campaign_analyst'],
            ['status' => 'running', 'message' => 'Understanding campaign fit and exclusions.', 'metadata' => []],
        );
        try {
            $payload = $client->research($run, function (string $node, string $status, string $message) use ($run): void {
                $run->events()->updateOrCreate(
                    ['node' => $node],
                    ['status' => $status, 'message' => $message, 'metadata' => []],
                );
            });
            $ingestor->ingest($run, $crm->enrich($payload));
        } catch (Throwable $exception) {
            $this->failed($exception);

            throw $exception;
        }
    }

    public function failed(?Throwable $exception): void
    {
        $serviceDetail = match (true) {
            $exception instanceof RequestException => (string) $exception->response?->json('detail', ''),
            // Live-mode streaming errors from the agent service arrive as a
            // plain RuntimeException carrying the same "[failure_code]" text
            // that used to live in a RequestException's JSON body.
            $exception instanceof \RuntimeException => $exception->getMessage(),
            default => '',
        };
        $message = match (true) {
            $exception instanceof QueryException => 'The research queue was temporarily unavailable. No results were saved.',
            $exception instanceof ConnectionException => 'The research service could not be reached. No results were saved.',
            str_contains($serviceDetail, '[timeout]') => 'The research service exceeded its configured deadline. No results were saved.',
            str_contains($serviceDetail, '[structured_output]') => 'The model response did not pass the required data schema. No results were saved.',
            str_contains($serviceDetail, '[no_verified_prospects]') => 'No prospect had enough verifiable source evidence to be saved.',
            str_contains($serviceDetail, '[no_relevant_prospects]') => 'No sufficiently relevant, evidence-backed funders were found for this campaign.',
            $exception instanceof RequestException => 'The research service failed closed before producing a validated result.',
            $exception instanceof Throwable => 'The research run failed closed. Check the application diagnostics.',
            default => 'The research run failed closed.',
        };

        ResearchRun::find($this->researchRunId)?->update(['status' => ResearchStatus::Failed, 'failure_message' => $message]);
    }
}
