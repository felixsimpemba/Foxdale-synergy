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
            $table->foreignId('routing_rule_id')->nullable()->after('assignee_id')->constrained()->nullOnDelete();
            $table->foreignId('escalation_policy_id')->nullable()->after('routing_rule_id')->constrained()->nullOnDelete();
            // The highest escalation level reached so far (0 = none yet).
            $table->unsignedTinyInteger('escalation_level')->default(0)->after('escalation_policy_id');
            $table->timestamp('next_escalation_at')->nullable()->after('escalation_level');

            $table->index(['status', 'next_escalation_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('incidents', function (Blueprint $table) {
            $table->dropIndex(['status', 'next_escalation_at']);
            $table->dropConstrainedForeignId('escalation_policy_id');
            $table->dropConstrainedForeignId('routing_rule_id');
            $table->dropColumn(['escalation_level', 'next_escalation_at']);
        });
    }
};
