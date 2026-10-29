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

        Schema::create('incident_updates', function (Blueprint $table) {
            $table->id();
            $table->foreignId('incident_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('type', 16);
            $table->text('body');
            $table->string('visibility', 16)->default('internal');
            $table->json('notified_channels')->nullable();
            $table->timestamps();

            $table->index(['incident_id', 'created_at']);
        });

        Schema::create('status_history', function (Blueprint $table) {
            $table->id();
            $table->foreignId('incident_id')->constrained()->cascadeOnDelete();
            $table->string('from_status', 16)->nullable();
            $table->string('to_status', 16);
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->text('note')->nullable();
            $table->timestamp('changed_at');
            $table->unsignedInteger('seconds_in_previous')->nullable();
            $table->timestamps();

            $table->index(['incident_id', 'changed_at']);
        });

        Schema::create('incident_assignments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('incident_id')->constrained()->cascadeOnDelete();
            $table->foreignId('from_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('to_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('team_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('assigned_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('reason', 500)->nullable();
            $table->timestamps();

            $table->index(['incident_id', 'created_at']);
        });

        Schema::create('incident_links', function (Blueprint $table) {
            $table->id();
            $table->foreignId('incident_id')->constrained()->cascadeOnDelete();
            $table->foreignId('related_incident_id')->constrained('incidents')->cascadeOnDelete();
            $table->string('type', 16);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['incident_id', 'related_incident_id', 'type']);
        });

        Schema::create('incident_watchers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('incident_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->timestamps();

            $table->unique(['incident_id', 'user_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {

        Schema::dropIfExists('incident_watchers');
        Schema::dropIfExists('incident_links');
        Schema::dropIfExists('incident_assignments');
        Schema::dropIfExists('status_history');
        Schema::dropIfExists('incident_updates');
    }
};
