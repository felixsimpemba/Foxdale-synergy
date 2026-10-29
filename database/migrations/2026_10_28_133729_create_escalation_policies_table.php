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
        Schema::create('escalation_policies', function (Blueprint $table) {
            $table->id();
            $table->string('name')->unique();
            $table->text('description')->nullable();
            $table->timestamps();
        });

        Schema::create('escalation_levels', function (Blueprint $table) {
            $table->id();
            $table->foreignId('escalation_policy_id')->constrained()->cascadeOnDelete();
            $table->unsignedTinyInteger('level');
            // Minutes after the incident was reported, if it is still unacknowledged.
            $table->unsignedInteger('after_minutes');
            $table->string('target_type', 16);
            $table->unsignedBigInteger('target_id')->nullable();
            // Only applies to incidents at this severity or more severe (e.g. 1 = SEV1 only).
            $table->unsignedTinyInteger('max_severity')->nullable();
            $table->timestamps();

            $table->unique(['escalation_policy_id', 'level']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('escalation_levels');
        Schema::dropIfExists('escalation_policies');
    }
};
