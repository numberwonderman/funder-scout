<?php

namespace App\Http\Controllers;

use App\Http\Requests\UpdateOrganizationRequest;
use App\Jobs\EnrichOrganizationProfile;
use App\Models\Prospect;
use App\Services\DataQualityService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class WorkspaceController extends Controller
{
    public function campaigns(Request $request): View
    {
        return view('workspace.campaigns', ['campaigns' => $request->user()->organization->campaigns()->with(['organization', 'researchRuns' => fn ($query) => $query->latest()])->latest()->get()]);
    }

    public function prospects(Request $request, DataQualityService $quality): View
    {
        $organization = $request->user()->organization;

        return view('workspace.prospects', ['prospects' => Prospect::whereHas('researchRun.campaign', fn ($query) => $query->whereBelongsTo($organization))->with('researchRun.campaign.organization', 'feedback')->orderByDesc('fit_score')->latest()->get(), 'quality' => $quality->metrics($organization)]);
    }

    public function organization(Request $request): View
    {
        return view('workspace.organization', ['organization' => $request->user()->organization]);
    }

    public function updateOrganization(UpdateOrganizationRequest $request): RedirectResponse
    {
        $data = $request->validated();
        foreach (['program_areas', 'populations_served', 'desired_funding_categories', 'needs', 'keywords', 'exclusions'] as $field) {
            $data[$field] = $this->list($data[$field] ?? null);
        }
        $data['prefers_unrestricted'] = $request->boolean('prefers_unrestricted');
        $request->user()->organization->update($data);

        return back()->with('status', 'Organization profile saved. Future research will use these details.');
    }

    public function enrichOrganization(Request $request): JsonResponse
    {
        $data = $request->validate(['website' => 'required|url:http,https|max:255']);
        $organization = $request->user()->organization;
        $organization->update(['website' => $data['website'], 'enrichment_status' => 'queued', 'enrichment_message' => 'Website research is queued.']);
        EnrichOrganizationProfile::dispatch($organization->id);

        return response()->json(['status' => 'queued', 'message' => $organization->enrichment_message], 202);
    }

    public function organizationEnrichmentStatus(Request $request): JsonResponse
    {
        $organization = $request->user()->organization;

        return response()->json([
            'status' => $organization->enrichment_status,
            'message' => $organization->enrichment_message,
            'profile' => $organization->only(['name', 'website', 'ein', 'nonprofit_status', 'mission', 'geography', 'program_areas', 'populations_served', 'organization_age', 'annual_budget', 'staff_size', 'needs', 'keywords']),
            'suggestions' => $organization->enrichment_suggestions ?? [],
        ]);
    }

    public function settings(): View
    {
        return view('workspace.settings', ['demoMode' => (bool) config('services.agent.demo_mode'), 'serviceUrl' => (string) config('services.agent.url'), 'crmConfigured' => filled(config('services.hubspot.access_token'))]);
    }

    /** @return array<int, string> */
    private function list(?string $value): array
    {
        return collect(preg_split('/[\r\n,]+/', $value ?? ''))->map(fn (string $item): string => trim($item))->filter()->unique()->values()->all();
    }
}
