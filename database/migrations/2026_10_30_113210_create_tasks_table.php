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
        Schema::create('tasks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('project_id')->constrained()->cascadeOnDelete();
            // Deleting a sprint returns its tasks to the backlog instead of deleting them.
            $table->foreignId('sprint_id')->nullable()->constrained()->nullOnDelete();
            // One level of subtasks; deleting a parent leaves its children unparented.
            $table->foreignId('parent_id')->nullable()->constrained('tasks')->nullOnDelete();

            // Nullable only for the instant between insert and the numbering step, like
            // incidents.ref. TaskService and the factory always fill them immediately.
            $table->unsignedInteger('number')->nullable();
            // Denormalised "KEY-number" so lists and searches never need the project join.
            $table->string('ref', 24)->nullable()->unique();
            $table->string('title', 200);
            $table->text('description')->nullable();
            $table->string('type', 16)->default('task');
            $table->string('status', 16)->default('todo');
            $table->string('priority', 16)->default('medium');
            $table->foreignId('assignee_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('reporter_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('reviewer_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('review_state', 24)->default('none');
            $table->unsignedTinyInteger('story_points')->nullable();
            $table->date('due_date')->nullable();
            $table->boolean('is_blocked')->default(false);
            $table->string('blocked_reason', 500)->nullable();
            // Order within a board column; gaps of 1024 leave room for midpoint inserts.
            $table->unsignedInteger('position')->default(0);
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['project_id', 'number']);
            $table->index(['project_id', 'status', 'position']);
            $table->index(['sprint_id', 'status', 'position']);
            $table->index(['assignee_id', 'status']);
            $table->index(['reviewer_id', 'review_state']);
            $table->index('parent_id');
            $table->index('due_date');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('tasks');
    }
};
