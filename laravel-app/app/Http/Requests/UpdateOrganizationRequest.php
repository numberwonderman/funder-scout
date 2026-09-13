<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UpdateOrganizationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, string>
     */
    public function rules(): array
    {
        return ['name' => 'required|string|max:120', 'website' => 'required|url:http,https|max:255', 'ein' => 'nullable|string|max:20', 'nonprofit_status' => 'required|in:unknown,501c3,other', 'fiscal_sponsorship_status' => 'required|in:unknown,sponsored,not_sponsored', 'mission' => 'required|string|min:20|max:2000', 'geography' => 'nullable|string|max:160', 'program_areas' => 'nullable|string|max:1000', 'populations_served' => 'nullable|string|max:1000', 'organization_age' => 'nullable|integer|min:0|max:500', 'annual_budget' => 'nullable|integer|min:0|max:1000000000', 'staff_size' => 'nullable|integer|min:0|max:100000', 'desired_funding_categories' => 'nullable|string|max:1000', 'desired_grant_min' => 'nullable|integer|min:0', 'desired_grant_max' => 'nullable|integer|min:0|gte:desired_grant_min', 'needs' => 'nullable|string|max:1000', 'keywords' => 'nullable|string|max:1000', 'exclusions' => 'nullable|string|max:1000', 'prefers_unrestricted' => 'nullable|boolean'];
    }
}
