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

        Schema::create('sla_policies', function (Blueprint $table) {
            $table->id();
            $table->unsignedTinyInteger('severity')->unique();
            $table->unsignedInteger('ack_minutes');
            $table->unsignedInteger('mitigate_minutes');
            $table->unsignedInteger('resolve_minutes');
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {

        Schema::dropIfExists('sla_policies');
    }
};
