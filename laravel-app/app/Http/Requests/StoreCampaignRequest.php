<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreCampaignRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return ['organization_id' => 'nullable|integer|exists:organizations,id', 'organization_name' => 'nullable|string|max:120', 'website' => 'required|url:http,https|max:255', 'ein' => 'nullable|string|max:20', 'nonprofit_status' => 'nullable|in:unknown,501c3,other', 'fiscal_sponsorship_status' => 'nullable|in:unknown,sponsored,not_sponsored', 'mission' => 'nullable|string|max:2000', 'program_areas' => 'nullable|string|max:1000', 'populations_served' => 'nullable|string|max:1000', 'annual_budget' => 'nullable|integer|min:0|max:1000000000', 'staff_size' => 'nullable|integer|min:0|max:100000', 'desired_grant_min' => 'nullable|integer|min:0', 'desired_grant_max' => 'nullable|integer|min:0|gte:desired_grant_min', 'keywords' => 'nullable|string|max:1000', 'exclusions' => 'nullable|string|max:1000', 'prefers_unrestricted' => 'nullable|boolean', 'campaign_title' => 'required|string|max:160', 'description' => 'required|string|min:20|max:3000', 'goal_amount' => 'required|integer|min:500|max:100000000', 'geography' => 'nullable|string|max:160', 'board_members' => 'nullable|string|max:2000'];
    }
}
