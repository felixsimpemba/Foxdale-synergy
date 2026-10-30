<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * People mentioned with @name in a comment or reply. Kept as rows so the timeline can
     * return their names for highlighting and notifications can be traced.
     */
    public function up(): void
    {
        Schema::create('incident_update_mentions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('incident_update_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->timestamps();

            $table->unique(['incident_update_id', 'user_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('incident_update_mentions');
    }
};
