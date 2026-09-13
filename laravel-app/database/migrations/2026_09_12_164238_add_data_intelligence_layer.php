<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('claims', function (Blueprint $table) {
            $table->string('predicate')->nullable()->after('key');
            $table->string('status')->default('supported')->after('confidence');
            $table->string('origin')->default('publicly_verified')->after('status');
            $table->string('extraction_method')->default('strands_structured_extraction')->after('origin');
            $table->timestampTz('verified_at')->nullable();
            $table->timestampTz('expires_at')->nullable();
        });
        Schema::table('evidence_sources', function (Blueprint $table) {
            $table->unsignedTinyInteger('source_tier')->default(3);
            $table->date('publication_date')->nullable();
            $table->timestampTz('source_last_modified')->nullable();
            $table->string('content_hash', 64)->nullable();
            $table->boolean('is_archived')->default(false);
        });
        Schema::table('prospects', function (Blueprint $table) {
            $table->string('path_to_money_rating')->default('unknown');
            $table->unsignedTinyInteger('path_completeness')->default(0);
            $table->decimal('opportunity_value', 8, 4)->nullable();
        });
        Schema::create('knowledge_entities', function (Blueprint $table) {
            $table->id();
            $table->string('entity_type');
            $table->string('name');
            $table->string('canonical_key')->unique();
            $table->json('attributes')->nullable();
            $table->timestamps();
            $table->index(['entity_type', 'name']);
        });
        Schema::create('knowledge_edges', function (Blueprint $table) {
            $table->id();
            $table->foreignId('from_entity_id')->constrained('knowledge_entities')->cascadeOnDelete();
            $table->string('predicate');
            $table->foreignId('to_entity_id')->constrained('knowledge_entities')->cascadeOnDelete();
            $table->foreignId('claim_id')->constrained()->cascadeOnDelete();
            $table->string('status')->default('supported');
            $table->timestamps();
            $table->unique(['from_entity_id', 'predicate', 'to_entity_id', 'claim_id'], 'knowledge_edge_unique');
        });
        Schema::create('path_to_money_steps', function (Blueprint $table) {
            $table->id();
            $table->foreignId('prospect_id')->constrained()->cascadeOnDelete();
            $table->unsignedTinyInteger('sequence');
            $table->string('step_key');
            $table->string('label');
            $table->string('status');
            $table->string('outcome')->nullable();
            $table->text('rationale');
            $table->foreignId('claim_id')->nullable()->constrained()->nullOnDelete();
            $table->boolean('is_hard_gate')->default(false);
            $table->boolean('is_disqualifying')->default(false);
            $table->timestamps();
            $table->unique(['prospect_id', 'step_key']);
        });
        Schema::create('research_tasks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('prospect_id')->constrained()->cascadeOnDelete();
            $table->foreignId('path_to_money_step_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('parent_id')->nullable()->constrained('research_tasks')->nullOnDelete();
            $table->text('question');
            $table->text('why_it_matters');
            $table->json('likely_sources');
            $table->json('search_queries');
            $table->string('confidence_required')->default('primary_or_strong_institutional');
            $table->json('stop_conditions');
            $table->unsignedTinyInteger('decision_impact')->default(1);
            $table->unsignedTinyInteger('uncertainty')->default(1);
            $table->unsignedTinyInteger('resolvability')->default(1);
            $table->unsignedTinyInteger('research_cost')->default(1);
            $table->decimal('information_value', 8, 4)->default(0);
            $table->unsignedTinyInteger('depth')->default(0);
            $table->string('status')->default('queued');
            $table->text('resolution')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('research_tasks');
        Schema::dropIfExists('path_to_money_steps');
        Schema::dropIfExists('knowledge_edges');
        Schema::dropIfExists('knowledge_entities');
        Schema::table('prospects', fn (Blueprint $table) => $table->dropColumn(['path_to_money_rating', 'path_completeness', 'opportunity_value']));
        Schema::table('evidence_sources', fn (Blueprint $table) => $table->dropColumn(['source_tier', 'publication_date', 'source_last_modified', 'content_hash', 'is_archived']));
        Schema::table('claims', fn (Blueprint $table) => $table->dropColumn(['predicate', 'status', 'origin', 'extraction_method', 'verified_at', 'expires_at']));
    }
};
