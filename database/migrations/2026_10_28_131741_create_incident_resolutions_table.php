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

        Schema::create('incident_resolutions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('incident_id')->unique()->constrained()->cascadeOnDelete();
            $table->text('summary');
            $table->text('workaround')->nullable();
            $table->text('permanent_fix')->nullable();
            $table->string('fix_type', 32);
            $table->json('steps')->nullable();
            $table->json('linked_prs')->nullable();
            $table->timestamp('deployed_at')->nullable();
            $table->text('verification')->nullable();
            $table->boolean('follow_up_needed')->default(false);
            $table->boolean('is_published')->default(false);
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {

        Schema::dropIfExists('incident_resolutions');
    }
};
