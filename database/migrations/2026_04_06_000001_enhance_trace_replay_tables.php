<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // tr_traces enhancements
        Schema::table('tr_traces', function (Blueprint $table) {
            // Recommendation 17: Trace parent for W3C context
            if (! Schema::hasColumn('tr_traces', 'trace_parent')) {
                $table->string('trace_parent')->after('tags')->nullable()->index();
            }

            // Phase 2: Memory tracking
            if (! Schema::hasColumn('tr_traces', 'peak_memory_usage')) {
                $table->unsignedBigInteger('peak_memory_usage')->after('duration_ms')->nullable();
            }

            // Recommendation 30: Indexes for performance
            // We use try-catch or individual checks because some DBs might already have these
            try {
                $table->index('status');
                $table->index('started_at');
            } catch (Throwable $e) {
                // Ignore if index already exists
            }

            // Recommendation 29: Change precision of float to decimal
            $table->decimal('duration_ms', 12, 2)->nullable()->change();
        });

        // tr_trace_steps enhancements
        Schema::table('tr_trace_steps', function (Blueprint $table) {
            // Recommendation 13: Detailed SQL tracking
            if (! Schema::hasColumn('tr_trace_steps', 'db_queries')) {
                $table->json('db_queries')->after('db_query_time_ms')->nullable();
            }

            // Recommendation 6: Cache tracking
            if (! Schema::hasColumn('tr_trace_steps', 'cache_calls')) {
                $table->json('cache_calls')->after('db_queries')->nullable();
                $table->unsignedInteger('cache_hit_count')->after('cache_calls')->default(0);
                $table->unsignedInteger('cache_miss_count')->after('cache_hit_count')->default(0);
            }

            // Recommendation 7: HTTP tracking
            if (! Schema::hasColumn('tr_trace_steps', 'http_calls')) {
                $table->json('http_calls')->after('cache_miss_count')->nullable();
            }

            // Recommendation 14: Mail/Notification tracking
            if (! Schema::hasColumn('tr_trace_steps', 'mail_calls')) {
                $table->json('mail_calls')->after('http_calls')->nullable();
            }

            // Phase 3: Application logs
            if (! Schema::hasColumn('tr_trace_steps', 'log_calls')) {
                $table->json('log_calls')->after('mail_calls')->nullable();
            }

            // Recommendation 29: Change precision of float to decimal
            $table->decimal('duration_ms', 12, 2)->nullable()->change();
            $table->decimal('db_query_time_ms', 12, 2)->nullable()->change();
        });
    }

    public function down(): void
    {
        if (DB::getDriverName() !== 'sqlite') {
            Schema::table('tr_traces', function (Blueprint $table) {
                $table->dropColumn(['trace_parent', 'peak_memory_usage']);
                $table->dropIndex(['status']);
                $table->dropIndex(['started_at']);
            });

            Schema::table('tr_trace_steps', function (Blueprint $table) {
                $table->dropColumn(['db_queries', 'cache_calls', 'cache_hit_count', 'cache_miss_count', 'http_calls', 'mail_calls', 'log_calls']);
            });
        }
    }
};
