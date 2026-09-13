<?php

namespace App\Http\Controllers;

use App\Enums\ResearchStatus;
use App\Http\Requests\StoreCampaignRequest;
use App\Jobs\ProcessResearchRun;
use App\Models\Prospect;
use App\Models\ResearchRun;
use App\Services\AgentServiceClient;
use App\Services\ResearchResultIngestor;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\View\View;
use Throwable;

class ResearchController extends Controller
{
    public function index(Request $request): View
    {
        $organization = $request->user()->organization;

        return view('research.index', [
            'runs' => ResearchRun::whereHas('campaign', fn ($query) => $query->whereBelongsTo($organization))->with('campaign.organization')->withCount('prospects')->latest()->limit(8)->get(),
            'demoMode' => (bool) config('services.agent.demo_mode'),
            'organization' => $organization,
        ]);
    }

    public function store(StoreCampaignRequest $request): RedirectResponse
    {
        $data = $request->validated();
        $list = fn (string $key) => collect(preg_split('/[\r\n,]+/', $data[$key] ?? ''))->map(fn ($v) => trim($v))->filter()->values()->all();
        $organization = $request->user()->organization;
        $profile = ['name' => $data['organization_name'] ?: parse_url($data['website'], PHP_URL_HOST), 'website' => $data['website'], 'geography' => $data['geography'] ?? null, 'ein' => $data['ein'] ?? null, 'nonprofit_status' => $data['nonprofit_status'] ?? 'unknown', 'fiscal_sponsorship_status' => $data['fiscal_sponsorship_status'] ?? 'unknown', 'mission' => $data['mission'] ?? null, 'program_areas' => $list('program_areas'), 'populations_served' => $list('populations_served'), 'annual_budget' => $data['annual_budget'] ?? null, 'staff_size' => $data['staff_size'] ?? null, 'desired_grant_min' => $data['desired_grant_min'] ?? null, 'desired_grant_max' => $data['desired_grant_max'] ?? null, 'prefers_unrestricted' => (bool) ($data['prefers_unrestricted'] ?? false), 'keywords' => $list('keywords'), 'exclusions' => $list('exclusions')];
        $organization->update(array_filter($profile, fn ($value) => $value !== null && $value !== []));
        $campaign = $organization->campaigns()->create(['title' => $data['campaign_title'], 'description' => $data['description'], 'goal_amount' => $data['goal_amount'], 'board_members' => collect(preg_split('/[\r\n,]+/', $data['board_members'] ?? ''))->map(fn ($v) => trim($v))->filter()->values()->all()]);
        $run = $campaign->researchRuns()->create(['uuid' => (string) Str::uuid(), 'status' => ResearchStatus::Queued]);

        return redirect()->route('research.show', $run);
    }

    public function execute(ResearchRun $researchRun, AgentServiceClient $client, ResearchResultIngestor $ingestor): JsonResponse
    {
        $this->guardResearchRun($researchRun);
        $researchRun->refresh();
        if ($researchRun->status === ResearchStatus::Completed) {
            return response()->json(['status' => 'completed']);
        }
        if ($researchRun->status === ResearchStatus::Failed) {
            return response()->json(['status' => 'failed', 'message' => $researchRun->failure_message], 409);
        }
        if ($researchRun->status === ResearchStatus::Analyzing) {
            if ($researchRun->started_at?->lt(now()->subSeconds(130))) {
                $researchRun->update(['status' => ResearchStatus::Failed, 'failure_message' => 'Research exceeded its execution lease and was stopped. Start a new run.']);

                return response()->json(['status' => 'failed', 'message' => $researchRun->failure_message], 503);
            }

            return response()->json(['status' => 'running'], 202);
        }

        $claimed = ResearchRun::query()
            ->whereKey($researchRun->id)
            ->where('status', ResearchStatus::Queued->value)
            ->update(['status' => ResearchStatus::Analyzing->value, 'started_at' => now(), 'failure_message' => null, 'updated_at' => now()]);
        if (! $claimed) {
            return response()->json(['status' => 'running'], 202);
        }

        try {
            ProcessResearchRun::dispatch($researchRun->id);
            $researchRun->refresh();

            return $researchRun->status === ResearchStatus::Completed
                ? response()->json(['status' => 'completed'])
                : response()->json(['status' => 'running'], 202);
        } catch (Throwable $exception) {
            (new ProcessResearchRun($researchRun->id))->failed($exception);

            return response()->json(['status' => 'failed', 'message' => $exception->getMessage()], 503);
        }
    }

    public function show(ResearchRun $researchRun): View
    {
        $this->guardResearchRun($researchRun);
        $researchRun->load('campaign.organization', 'events', 'prospects.claims.evidence', 'prospects.relationships', 'prospects.grants');

        $metrics = [
            'researched' => $researchRun->prospects->count(),
            'strong' => $researchRun->prospects->where('fit_score', '>=', 75)->count(),
            'pipeline' => $researchRun->prospects->sum('recommended_ask_max'),
            'warm_paths' => $researchRun->prospects->filter(fn (Prospect $prospect): bool => $prospect->relationships->isNotEmpty())->count(),
            'ready' => $researchRun->prospects->where('opportunity_status', 'ready_to_pursue')->count(),
            'investigate' => $researchRun->prospects->where('opportunity_status', 'worth_investigating')->count(),
            'historical' => $researchRun->prospects->where('opportunity_status', 'historical_signal')->count(),
        ];

        return view('research.show', ['run' => $researchRun, 'metrics' => $metrics]);
    }

    public function prospect(Prospect $prospect): View
    {
        $this->guardProspect($prospect);
        $prospect->load('researchRun.campaign.organization', 'researchRun.events', 'claims.evidence', 'grants', 'people', 'relationships', 'scoreComponents', 'pathToMoneySteps.claim.evidence', 'researchTasks');

        return view('research.prospect', compact('prospect'));
    }

    public function feedback(Request $request, Prospect $prospect): RedirectResponse
    {
        $this->guardProspect($prospect);
        $data = $request->validate(['status' => 'required|in:interested,not_relevant,ineligible,applied,won,lost,saved', 'note' => 'nullable|string|max:1000']);
        $prospect->feedback()->create($data);

        return back()->with('status', 'Recommendation updated.');
    }

    public function workspace(Prospect $prospect): View
    {
        $this->guardProspect($prospect);
        $prospect->load('researchRun.campaign.organization', 'claims.evidence', 'grants');
        $organization = $prospect->researchRun->campaign->organization;
        $workspace = $prospect->workspace()->firstOrCreate([], ['content' => ['mission' => $organization->mission, 'organization_overview' => null, 'programs' => $organization->program_areas ?? [], 'populations' => $organization->populations_served ?? [], 'annual_budget' => $organization->annual_budget, 'missing' => array_values(array_filter(['mission' => $organization->mission ? null : 'Mission', 'budget' => $organization->annual_budget ? null : 'Annual budget', 'programs' => $organization->program_areas ? null : 'Program descriptions', 'impact' => 'Impact data', 'leadership' => 'Leadership information']))]]);

        return view('research.workspace', compact('prospect', 'workspace', 'organization'));
    }

    private function guardResearchRun(ResearchRun $researchRun): void
    {
        abort_unless($researchRun->campaign()->where('organization_id', request()->user()->organization_id)->exists(), 404);
    }

    private function guardProspect(Prospect $prospect): void
    {
        abort_unless($prospect->researchRun()->whereHas('campaign', fn ($query) => $query->where('organization_id', request()->user()->organization_id))->exists(), 404);
    }
}
