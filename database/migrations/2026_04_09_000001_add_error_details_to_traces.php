<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tr_traces', function (Blueprint $table) {
            $table->text('error_reason')->nullable()->after('http_status');
        });
    }

    public function down(): void
    {
        Schema::table('tr_traces', function (Blueprint $table) {
            $table->dropColumn('error_reason');
        });
    }
};

