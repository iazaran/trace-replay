<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up()
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
            $table->json('tags')->nullable();
            $table->float('duration_ms')->nullable();
            $table->string('status')->default('processing'); // processing, success, error
            $table->unsignedSmallInteger('http_status')->nullable();
            $table->string('user_id')->nullable()->index();
            $table->string('user_type')->nullable();
            $table->string('ip_address', 45)->nullable();
            $table->text('user_agent')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();

            $table->foreign('project_id')->references('id')->on('tr_projects')->onDelete('set null');
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

            $table->float('duration_ms')->nullable();
            $table->unsignedBigInteger('memory_usage')->nullable(); // bytes
            $table->unsignedInteger('db_query_count')->nullable();
            $table->float('db_query_time_ms')->nullable();
            $table->string('status')->default('success'); // success, error, checkpoint
            $table->text('error_reason')->nullable();

            $table->timestamps();

            $table->foreign('trace_id')->references('id')->on('tr_traces')->onDelete('cascade');
        });
    }

    public function down()
    {
        Schema::dropIfExists('tr_trace_steps');
        Schema::dropIfExists('tr_traces');
        Schema::dropIfExists('tr_projects');
        Schema::dropIfExists('tr_workspaces');
    }
};
