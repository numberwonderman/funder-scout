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
        Schema::table('organizations', function (Blueprint $table) {
            $table->string('enrichment_status')->default('idle');
            $table->text('enrichment_message')->nullable();
            $table->timestamp('enrichment_started_at')->nullable();
            $table->timestamp('enriched_at')->nullable();
            $table->json('enrichment_suggestions')->nullable();
            $table->json('enrichment_sources')->nullable();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('organizations', function (Blueprint $table) {
            $table->dropColumn([
                'enrichment_status',
                'enrichment_message',
                'enrichment_started_at',
                'enriched_at',
                'enrichment_suggestions',
                'enrichment_sources',
            ]);
        });
    }
};
