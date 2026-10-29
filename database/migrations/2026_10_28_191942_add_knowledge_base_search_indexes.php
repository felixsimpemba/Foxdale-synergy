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
        Schema::table('incidents', function (Blueprint $table) {
            $table->fullText(['title', 'description']);
        });

        Schema::table('incident_resolutions', function (Blueprint $table) {
            $table->fullText(['summary', 'permanent_fix', 'workaround']);
            $table->timestamp('published_at')->nullable()->after('is_published');
            $table->foreignId('published_by')->nullable()->after('published_at')->constrained('users')->nullOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('incident_resolutions', function (Blueprint $table) {
            $table->dropConstrainedForeignId('published_by');
            $table->dropColumn('published_at');
            $table->dropFullText(['summary', 'permanent_fix', 'workaround']);
        });

        Schema::table('incidents', function (Blueprint $table) {
            $table->dropFullText(['title', 'description']);
        });
    }
};
