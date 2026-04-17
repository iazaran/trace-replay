<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('tr_traces', 'workspace_id')) {
            return;
        }

        Schema::table('tr_traces', function (Blueprint $table) {
            $table->uuid('workspace_id')->nullable()->after('id')->index();
        });
    }

    public function down(): void
    {
        if (! Schema::hasColumn('tr_traces', 'workspace_id')) {
            return;
        }

        Schema::table('tr_traces', function (Blueprint $table) {
            $table->dropIndex(['workspace_id']);
            $table->dropColumn('workspace_id');
        });
    }
};
