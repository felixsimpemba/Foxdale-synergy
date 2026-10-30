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

        Schema::create('incidents', function (Blueprint $table) {
            $table->id();
            // Set right after insert from the id, e.g. INC-2026-00123.
            $table->string('ref', 32)->nullable()->unique();
            $table->string('title', 200);
            $table->text('description');
            $table->text('impact')->nullable();
            $table->unsignedTinyInteger('severity');
            $table->string('status', 16);
            $table->foreignId('category_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('service_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('team_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('reporter_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('assignee_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('source', 16);
            $table->string('fingerprint')->nullable();
            $table->unsignedInteger('occurrences')->default(1);
            $table->timestamp('started_at')->nullable();
            $table->timestamp('reported_at');
            $table->timestamp('acknowledged_at')->nullable();
            $table->timestamp('mitigated_at')->nullable();
            $table->timestamp('resolved_at')->nullable();
            $table->timestamp('closed_at')->nullable();
            $table->timestamp('ack_due_at')->nullable();
            $table->timestamp('mitigate_due_at')->nullable();
            $table->timestamp('resolve_due_at')->nullable();
            $table->boolean('sla_breached')->default(false);
            $table->unsignedInteger('reopened_count')->default(0);
            $table->string('mattermost_post_id')->nullable();
            $table->string('deletion_reason', 500)->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['status', 'severity']);
            $table->index(['service_id', 'status']);
            $table->index(['team_id', 'status']);
            $table->index(['assignee_id', 'status']);
            $table->index('reported_at');
            $table->index(['fingerprint', 'status']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {

        Schema::dropIfExists('incidents');
    }
};
