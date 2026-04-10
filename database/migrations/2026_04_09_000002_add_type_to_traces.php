<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tr_traces', function (Blueprint $table) {
            $table->string('type', 20)->default('http')->after('name');
        });

        // Add index for filtering by type
        Schema::table('tr_traces', function (Blueprint $table) {
            $table->index(['type', 'started_at']);
        });
    }

    public function down(): void
    {
        Schema::table('tr_traces', function (Blueprint $table) {
            $table->dropIndex(['type', 'started_at']);
            $table->dropColumn('type');
        });
    }
};

