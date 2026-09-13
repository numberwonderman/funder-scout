<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('organizations', function (Blueprint $table) {
            $table->string('ein')->nullable();
            $table->string('nonprofit_status')->default('unknown');
            $table->string('fiscal_sponsorship_status')->default('unknown');
            $table->text('mission')->nullable();
            $table->json('program_areas')->nullable();
            $table->json('populations_served')->nullable();
            $table->unsignedSmallInteger('organization_age')->nullable();
            $table->unsignedBigInteger('annual_budget')->nullable();
            $table->unsignedInteger('staff_size')->nullable();
            $table->json('desired_funding_categories')->nullable();
            $table->unsignedBigInteger('desired_grant_min')->nullable();
            $table->unsignedBigInteger('desired_grant_max')->nullable();
            $table->json('needs')->nullable();
            $table->boolean('prefers_unrestricted')->default(false);
            $table->json('keywords')->nullable();
            $table->json('exclusions')->nullable();
        });
        Schema::table('prospects', function (Blueprint $table) {
            $table->string('grant_title')->nullable();
            $table->text('canonical_application_url')->nullable();
            $table->unsignedBigInteger('amount_min')->nullable();
            $table->unsignedBigInteger('amount_max')->nullable();
            $table->date('deadline')->nullable();
            $table->boolean('rolling_deadline')->default(false);
            $table->string('grant_status')->default('uncertain');
            $table->string('application_effort')->default('unknown');
            $table->json('possible_disqualifiers')->nullable();
            $table->timestampTz('last_verified_at')->nullable();
        });
        Schema::create('prospect_feedback', function (Blueprint $table) {
            $table->id();
            $table->foreignId('prospect_id')->constrained()->cascadeOnDelete();
            $table->string('status');
            $table->text('note')->nullable();
            $table->timestamps();
        });
        Schema::create('application_workspaces', function (Blueprint $table) {
            $table->id();
            $table->foreignId('prospect_id')->unique()->constrained()->cascadeOnDelete();
            $table->json('content')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('application_workspaces');
        Schema::dropIfExists('prospect_feedback');
        Schema::table('prospects', fn (Blueprint $table) => $table->dropColumn(['grant_title', 'canonical_application_url', 'amount_min', 'amount_max', 'deadline', 'rolling_deadline', 'grant_status', 'application_effort', 'possible_disqualifiers', 'last_verified_at']));
        Schema::table('organizations', fn (Blueprint $table) => $table->dropColumn(['ein', 'nonprofit_status', 'fiscal_sponsorship_status', 'mission', 'program_areas', 'populations_served', 'organization_age', 'annual_budget', 'staff_size', 'desired_funding_categories', 'desired_grant_min', 'desired_grant_max', 'needs', 'prefers_unrestricted', 'keywords', 'exclusions']));
    }
};
