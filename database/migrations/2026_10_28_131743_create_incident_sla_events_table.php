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

        // One row per warning or breach, so the every-minute SLA check raises each only once.
        Schema::create('incident_sla_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('incident_id')->constrained()->cascadeOnDelete();
            $table->string('metric', 16);
            $table->string('type', 16);
            $table->timestamp('due_at');
            $table->timestamp('occurred_at');
            $table->timestamps();

            $table->unique(['incident_id', 'metric', 'type']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {

        Schema::dropIfExists('incident_sla_events');
    }
};
