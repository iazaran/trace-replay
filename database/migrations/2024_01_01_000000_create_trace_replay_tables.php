<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tr_workspaces', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('name');
            $table->timestamps();
        });

        Schema::create('tr_projects', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('workspace_id')->index();
            $table->string('name');
            $table->timestamps();

            $table->foreign('workspace_id')->references('id')->on('tr_workspaces')->onDelete('cascade');
        });

        Schema::create('tr_traces', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('project_id')->nullable()->index();
            $table->string('name')->nullable();
            $table->string('type', 20)->default('http'); // http, job, command, livewire
            $table->json('tags')->nullable();
            $table->string('trace_parent')->nullable()->index(); // W3C trace context
            $table->decimal('duration_ms', 12, 2)->nullable();
            $table->unsignedBigInteger('peak_memory_usage')->nullable();
            $table->string('status')->default('processing'); // processing, success, error
            $table->unsignedSmallInteger('http_status')->nullable();
            $table->text('error_reason')->nullable();
            $table->string('user_id')->nullable()->index();
            $table->string('user_type')->nullable();
            $table->string('ip_address', 45)->nullable();
            $table->text('user_agent')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();

            $table->foreign('project_id')->references('id')->on('tr_projects')->onDelete('set null');
            $table->index('status');
            $table->index('started_at');
            $table->index(['type', 'started_at']);
        });

        Schema::create('tr_trace_steps', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('trace_id')->index();
            $table->string('label');
            $table->string('type')->default('step'); // step, checkpoint, http, job, command
            $table->integer('step_order')->default(0);

            $table->json('request_payload')->nullable();
            $table->json('response_payload')->nullable();
            $table->json('state_snapshot')->nullable();

            $table->decimal('duration_ms', 12, 2)->nullable();
            $table->unsignedBigInteger('memory_usage')->nullable(); // bytes
            $table->unsignedInteger('db_query_count')->nullable();
            $table->decimal('db_query_time_ms', 12, 2)->nullable();
            $table->json('db_queries')->nullable(); // Detailed SQL tracking
            $table->json('cache_calls')->nullable();
            $table->unsignedInteger('cache_hit_count')->default(0);
            $table->unsignedInteger('cache_miss_count')->default(0);
            $table->json('http_calls')->nullable();
            $table->json('mail_calls')->nullable();
            $table->json('log_calls')->nullable();
            $table->string('status')->default('success'); // success, error, checkpoint
            $table->text('error_reason')->nullable();

            $table->timestamps();

            $table->foreign('trace_id')->references('id')->on('tr_traces')->onDelete('cascade');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tr_trace_steps');
        Schema::dropIfExists('tr_traces');
        Schema::dropIfExists('tr_projects');
        Schema::dropIfExists('tr_workspaces');
    }
};
