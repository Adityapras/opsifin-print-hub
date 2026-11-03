<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('documents', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained()->cascadeOnDelete();
            $table->string('disk', 50)->default('local');
            $table->string('path');
            $table->char('sha256', 64);
            $table->unsignedInteger('pages');
            $table->unsignedBigInteger('size_bytes');
            $table->timestamps(6);

            $table->index(['tenant_id', 'sha256']);
        });

        Schema::create('print_jobs', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('printer_id')->constrained()->restrictOnDelete();
            $table->foreignUuid('document_id')->nullable()->constrained()->restrictOnDelete();
            $table->string('inv_no', 100);
            $table->string('item_type', 50);
            $table->unsignedTinyInteger('version_inv');
            $table->string('state', 16);
            $table->unsignedTinyInteger('attempt_count')->default(0);
            $table->foreignUuid('lease_agent_id')->nullable()->constrained('agents')->nullOnDelete();
            $table->dateTime('lease_until', 6)->nullable();
            $table->dateTime('available_at', 6);
            $table->dateTime('received_at', 6);
            $table->dateTime('printed_at', 6)->nullable();
            $table->dateTime('reported_at', 6)->nullable();
            $table->timestamps(6);

            $table->unique(['tenant_id', 'inv_no', 'item_type', 'version_inv']);
            $table->index(['tenant_id', 'printer_id', 'state', 'available_at', 'received_at'], 'print_jobs_claim_index');
            $table->index(['tenant_id', 'lease_agent_id', 'lease_until']);
        });

        Schema::create('print_job_attempts', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('job_id')->constrained('print_jobs')->cascadeOnDelete();
            $table->foreignUuid('agent_id')->constrained()->restrictOnDelete();
            $table->unsignedTinyInteger('attempt_no');
            $table->unsignedBigInteger('spooler_job_id')->nullable();
            $table->dateTime('started_at', 6);
            $table->dateTime('spooled_at', 6)->nullable();
            $table->dateTime('finished_at', 6)->nullable();
            $table->unsignedInteger('pages_printed')->nullable();
            $table->string('outcome', 32)->nullable();
            $table->timestamps(6);

            $table->unique(['tenant_id', 'job_id', 'attempt_no']);
            $table->index(['tenant_id', 'agent_id', 'started_at']);
        });

        Schema::create('print_job_events', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('job_id')->constrained('print_jobs')->cascadeOnDelete();
            $table->foreignUuid('attempt_id')->nullable()->constrained('print_job_attempts')->cascadeOnDelete();
            $table->string('actor_type', 20);
            $table->uuid('actor_id')->nullable();
            $table->string('type', 32);
            $table->string('from_state', 16)->nullable();
            $table->string('to_state', 16)->nullable();
            $table->dateTime('occurred_at', 6);
            $table->dateTime('recorded_at', 6);
            $table->json('data')->nullable();
            $table->timestamps(6);

            $table->index(['tenant_id', 'job_id', 'recorded_at']);
            $table->index(['tenant_id', 'type', 'recorded_at']);
        });

        Schema::create('agent_commands', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('agent_id')->constrained()->cascadeOnDelete();
            $table->string('type', 32);
            $table->json('args')->nullable();
            $table->string('status', 20)->default('pending');
            $table->dateTime('delivered_at', 6)->nullable();
            $table->dateTime('finished_at', 6)->nullable();
            $table->text('result_message')->nullable();
            $table->string('attachment_disk', 50)->nullable();
            $table->string('attachment_path')->nullable();
            $table->timestamps(6);

            $table->index(['tenant_id', 'agent_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('agent_commands');
        Schema::dropIfExists('print_job_events');
        Schema::dropIfExists('print_job_attempts');
        Schema::dropIfExists('print_jobs');
        Schema::dropIfExists('documents');
    }
};
