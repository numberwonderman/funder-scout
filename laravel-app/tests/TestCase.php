<?php

namespace Tests;

use App\Models\Organization;
use App\Models\User;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Facades\Schema;

abstract class TestCase extends BaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        if (Schema::hasTable('organizations')) {
            $organization = Organization::create(['name' => 'Test Organization', 'website' => 'https://organization.example.org']);
            $this->actingAs(User::factory()->create(['organization_id' => $organization->id]));
        }
    }
}
