<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * One row per person's survey. The answers stay in one JSON document because the
     * dashboard reads and exports the whole response; department and identity are separate
     * columns so the list can filter and count them cheaply.
     */
    public function up(): void
    {
        Schema::create('pulse_responses', function (Blueprint $table) {
            $table->id();
            $table->string('department', 32)->index();
            $table->string('name', 120);
            $table->string('role', 120);
            $table->json('answers');
            // The plain token only ever exists in the respondent's browser; this is its
            // SHA-256 hash, so a leaked database does not hand out edit access.
            $table->char('edit_token_hash', 64)->unique();
            $table->timestamps();

            $table->index(['department', 'updated_at']);
            $table->index('updated_at');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('pulse_responses');
    }
};
