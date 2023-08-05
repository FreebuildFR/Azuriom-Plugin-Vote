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
        Schema::table('vote_rewards', function (Blueprint $table) {
            $table->string('monthly_rewards')->nullable()->after('commands');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('vote_rewards', function (Blueprint $table) {
            $table->dropColumn('monthly_rewards');
        });
    }
};
