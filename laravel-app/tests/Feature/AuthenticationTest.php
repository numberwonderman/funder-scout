<?php

namespace Tests\Feature;

use App\Models\Organization;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AuthenticationTest extends TestCase
{
    use RefreshDatabase;

    public function test_guest_is_redirected_to_login(): void
    {
        auth()->logout();

        $this->get('/')->assertRedirect(route('login'));
        $this->get(route('login'))->assertOk()->assertSee('Sign in to your workspace');
    }

    public function test_registration_creates_an_organization_workspace(): void
    {
        auth()->logout();

        $response = $this->post(route('register.store'), ['name' => 'Alex Rivera', 'email' => 'alex@example.org', 'organization_name' => 'Community Water Lab', 'website' => 'https://water-lab.example.org', 'password' => 'secure-pass', 'password_confirmation' => 'secure-pass']);

        $organization = Organization::where('name', 'Community Water Lab')->firstOrFail();
        $response->assertRedirect(route('organization.edit'));
        $this->assertAuthenticatedAs(User::where('email', 'alex@example.org')->firstOrFail());
        $this->assertSame($organization->id, auth()->user()->organization_id);
    }

    public function test_user_cannot_access_another_organizations_research(): void
    {
        $other = Organization::create(['name' => 'Other Organization', 'website' => 'https://other.example.org']);
        $campaign = $other->campaigns()->create(['title' => 'Private campaign', 'description' => 'Private research belonging to another organization.', 'goal_amount' => 10000]);
        $run = $campaign->researchRuns()->create(['uuid' => fake()->uuid(), 'status' => 'queued']);

        $this->get(route('research.show', $run))->assertNotFound();
        $this->post(route('research.execute', $run))->assertNotFound();
    }
}
