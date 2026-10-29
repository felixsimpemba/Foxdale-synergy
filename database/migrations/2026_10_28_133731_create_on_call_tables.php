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
        Schema::create('on_call_schedules', function (Blueprint $table) {
            $table->id();
            $table->foreignId('team_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->string('rotation_type', 16);
            $table->string('timezone', 64)->default('UTC');
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('on_call_shifts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('on_call_schedule_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->timestamp('starts_at');
            $table->timestamp('ends_at');
            $table->boolean('is_override')->default(false);
            $table->timestamps();

            $table->index(['on_call_schedule_id', 'starts_at', 'ends_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('on_call_shifts');
        Schema::dropIfExists('on_call_schedules');
    }
};
