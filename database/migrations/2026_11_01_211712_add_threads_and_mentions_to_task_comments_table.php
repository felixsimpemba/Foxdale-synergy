<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Replies are one level deep (a reply's parent is a top-level comment), and mentions are
     * kept as rows so the timeline can return the names to highlight.
     */
    public function up(): void
    {
        Schema::table('task_comments', function (Blueprint $table) {
            $table->foreignId('parent_id')->nullable()->after('task_id')
                ->constrained('task_comments')->nullOnDelete();
            $table->index(['task_id', 'parent_id', 'created_at']);
        });

        Schema::create('task_comment_mentions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('task_comment_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->timestamps();

            $table->unique(['task_comment_id', 'user_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('task_comment_mentions');

        Schema::table('task_comments', function (Blueprint $table) {
            $table->dropForeign(['parent_id']);
            $table->dropIndex(['task_id', 'parent_id', 'created_at']);
            $table->dropColumn('parent_id');
        });
    }
};
