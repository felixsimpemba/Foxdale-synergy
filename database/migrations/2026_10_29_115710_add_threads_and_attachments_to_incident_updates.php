<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Conversation threads on incident comments (one level of replies), edits and deletions by
 * the author, and files attached to a comment.
 */
return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('incident_updates', function (Blueprint $table) {
            // Replies point at the top-level comment of their thread.
            $table->foreignId('parent_id')->nullable()->after('incident_id')->constrained('incident_updates')->cascadeOnDelete();
            $table->timestamp('edited_at')->nullable()->after('notified_channels');
            // A deleted comment that has replies stays as a placeholder so the thread reads.
            $table->softDeletes();

            $table->index(['parent_id', 'created_at']);
        });

        Schema::table('documents', function (Blueprint $table) {
            $table->foreignId('incident_update_id')->nullable()->after('incident_id')->constrained()->nullOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('documents', function (Blueprint $table) {
            $table->dropConstrainedForeignId('incident_update_id');
        });

        Schema::table('incident_updates', function (Blueprint $table) {
            $table->dropIndex(['parent_id', 'created_at']);
            $table->dropConstrainedForeignId('parent_id');
            $table->dropColumn(['edited_at', 'deleted_at']);
        });
    }
};
