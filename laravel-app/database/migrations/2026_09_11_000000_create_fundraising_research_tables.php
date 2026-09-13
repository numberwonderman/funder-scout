<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('organizations', function (Blueprint $table) {
            $table->id();
            $table->string('name')->nullable();
            $table->string('website');
            $table->string('geography')->nullable();
            $table->timestamps();
        });

        Schema::create('campaigns', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->string('title');
            $table->text('description');
            $table->unsignedBigInteger('goal_amount');
            $table->json('board_members')->nullable();
            $table->timestamps();
        });

        Schema::create('research_runs', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('campaign_id')->constrained()->cascadeOnDelete();
            $table->string('status')->default('queued');
            $table->boolean('is_demo')->default(false);
            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->text('failure_message')->nullable();
            $table->json('raw_agent_payload')->nullable();
            $table->timestamps();
        });

        Schema::create('prospects', function (Blueprint $table) {
            $table->id();
            $table->foreignId('research_run_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->string('funder_type');
            $table->string('ein')->nullable();
            $table->text('summary');
            $table->string('confidence');
            $table->unsignedTinyInteger('fit_score');
            $table->unsignedBigInteger('recommended_ask_min')->nullable();
            $table->unsignedBigInteger('recommended_ask_max')->nullable();
            $table->text('ask_rationale');
            $table->timestamps();
        });

        Schema::create('score_components', function (Blueprint $table) {
            $table->id();
            $table->foreignId('prospect_id')->constrained()->cascadeOnDelete();
            $table->string('key');
            $table->string('label');
            $table->decimal('signal', 5, 4);
            $table->unsignedTinyInteger('weight');
            $table->unsignedTinyInteger('points');
            $table->unique(['prospect_id', 'key']);
        });

        Schema::create('claims', function (Blueprint $table) {
            $table->id();
            $table->foreignId('prospect_id')->constrained()->cascadeOnDelete();
            $table->string('key');
            $table->text('claim');
            $table->json('value')->nullable();
            $table->string('confidence');
            $table->timestamps();
        });

        Schema::create('evidence_sources', function (Blueprint $table) {
            $table->id();
            $table->foreignId('claim_id')->nullable()->constrained()->cascadeOnDelete();
            $table->string('title');
            $table->text('url');
            $table->string('source_type');
            $table->timestampTz('retrieved_at');
            $table->text('excerpt_or_locator');
            $table->timestamps();
        });

        Schema::create('grants', function (Blueprint $table) {
            $table->id();
            $table->foreignId('prospect_id')->constrained()->cascadeOnDelete();
            $table->string('recipient');
            $table->text('purpose');
            $table->unsignedBigInteger('amount');
            $table->unsignedSmallInteger('year');
            $table->json('source');
            $table->timestamps();
        });

        Schema::create('prospect_people', function (Blueprint $table) {
            $table->id();
            $table->foreignId('prospect_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->string('role');
            $table->string('confidence');
            $table->json('sources');
            $table->timestamps();
        });

        Schema::create('relationships', function (Blueprint $table) {
            $table->id();
            $table->foreignId('prospect_id')->constrained()->cascadeOnDelete();
            $table->string('from_name');
            $table->string('relationship');
            $table->string('to_name');
            $table->json('sources');
            $table->timestamps();
        });

        Schema::create('agent_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('research_run_id')->constrained()->cascadeOnDelete();
            $table->string('node');
            $table->string('status');
            $table->text('message');
            $table->json('metadata')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('agent_events');
        Schema::dropIfExists('relationships');
        Schema::dropIfExists('prospect_people');
        Schema::dropIfExists('grants');
        Schema::dropIfExists('evidence_sources');
        Schema::dropIfExists('claims');
        Schema::dropIfExists('score_components');
        Schema::dropIfExists('prospects');
        Schema::dropIfExists('research_runs');
        Schema::dropIfExists('campaigns');
        Schema::dropIfExists('organizations');
    }
};
