<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('prospects', function (Blueprint $table) {
            $table->string('opportunity_status')->default('worth_investigating');
            $table->string('opportunity_type')->default('mission_match');
            $table->string('eligibility_status')->default('not_verified');
            $table->json('evidence_gaps')->nullable();
            $table->json('next_actions')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('prospects', function (Blueprint $table) {
            $table->dropColumn(['opportunity_status', 'opportunity_type', 'eligibility_status', 'evidence_gaps', 'next_actions']);
        });
    }
};
