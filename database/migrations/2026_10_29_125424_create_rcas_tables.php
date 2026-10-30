<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Root cause analysis (specification section 8): one per incident, with its timeline
     * of events and the action items that follow from it.
     */
    public function up(): void
    {
        Schema::create('rcas', function (Blueprint $table) {
            $table->id();
            $table->foreignId('incident_id')->unique()->constrained()->cascadeOnDelete();
            $table->string('status', 16)->default('draft')->index();
            $table->text('summary')->nullable();
            $table->text('impact')->nullable();
            $table->text('detection')->nullable();
            $table->text('root_cause')->nullable();
            $table->string('root_cause_category', 32)->nullable()->index();
            $table->json('contributing_factors')->nullable();
            $table->json('five_whys')->nullable();
            $table->json('went_well')->nullable();
            $table->json('went_wrong')->nullable();
            $table->text('lessons_learned')->nullable();
            $table->string('recurrence_risk', 16)->nullable();
            $table->foreignId('author_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('reviewer_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('due_at')->nullable()->index();
            $table->timestamp('submitted_at')->nullable();
            $table->timestamp('approved_at')->nullable();
            $table->timestamp('rejected_at')->nullable();
            $table->text('rejection_reason')->nullable();
            $table->timestamp('last_reminded_at')->nullable();
            $table->timestamps();

            $table->fullText(['summary', 'root_cause', 'lessons_learned']);
        });

        Schema::create('rca_timeline_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('rca_id')->constrained()->cascadeOnDelete();
            $table->timestamp('at');
            $table->text('event');
            $table->string('actor', 150)->nullable();
            $table->timestamps();

            $table->index(['rca_id', 'at']);
        });

        Schema::create('rca_action_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('rca_id')->constrained()->cascadeOnDelete();
            $table->string('type', 16);
            $table->string('title', 300);
            $table->foreignId('owner_id')->nullable()->constrained('users')->nullOnDelete();
            $table->date('due_date')->nullable();
            $table->string('priority', 16)->default('medium');
            $table->string('status', 16)->default('open');
            $table->timestamp('completed_at')->nullable();
            $table->foreignId('completed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->text('note')->nullable();
            $table->timestamp('last_reminded_at')->nullable();
            $table->timestamps();

            $table->index(['status', 'due_date']);
            $table->index(['owner_id', 'status']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('rca_action_items');
        Schema::dropIfExists('rca_timeline_events');
        Schema::dropIfExists('rcas');
    }
};
