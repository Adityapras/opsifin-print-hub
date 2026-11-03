<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('source_connections', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->string('base_url', 2048);
            $table->string('username');
            $table->text('password_enc');
            $table->unsignedTinyInteger('api_version')->default(2);
            $table->unsignedSmallInteger('pull_interval_s')->default(30);
            $table->time('active_from')->default('05:00:00');
            $table->time('active_until')->default('22:00:00');
            $table->unsignedSmallInteger('connect_timeout_s')->default(5);
            $table->unsignedSmallInteger('timeout_s')->default(30);
            $table->boolean('is_active')->default(true);
            $table->dateTime('last_ok_at', 6)->nullable();
            $table->text('last_error')->nullable();
            $table->timestamps(6);

            $table->unique(['tenant_id', 'name']);
            $table->index(['tenant_id', 'is_active']);
        });

        Schema::create('printer_bindings', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('source_connection_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('printer_id')->constrained()->restrictOnDelete();
            $table->string('source_printer_name');
            $table->string('source_identifier');
            $table->unsignedTinyInteger('printer_version')->default(1);
            $table->boolean('is_active')->default(true);
            $table->dateTime('next_pull_at', 6)->nullable();
            $table->dateTime('last_pulled_at', 6)->nullable();
            $table->text('last_error')->nullable();
            $table->timestamps(6);

            $table->unique(
                ['tenant_id', 'source_connection_id', 'source_printer_name', 'source_identifier', 'printer_version'],
                'printer_bindings_source_identity_unique',
            );
            $table->index(['tenant_id', 'is_active', 'next_pull_at']);
            $table->index(['tenant_id', 'printer_id']);
        });

        Schema::table('documents', function (Blueprint $table): void {
            $table->string('source_url', 2048)->nullable();
            $table->dateTime('fetched_at', 6)->nullable();
            $table->dateTime('expires_at', 6)->nullable();
        });

        Schema::table('print_jobs', function (Blueprint $table): void {
            $table->foreignUuid('binding_id')->nullable()->constrained('printer_bindings')->nullOnDelete();
            $table->boolean('is_hardcopy')->default(true);
            $table->string('customer_id', 100)->nullable();
            $table->string('customer_name')->nullable();
            $table->string('branch_id', 100)->nullable();
            $table->string('implant_code', 100)->nullable();
            $table->string('failure_code', 64)->nullable();
            $table->json('source_payload')->nullable();

            $table->index(['tenant_id', 'binding_id', 'state'], 'print_jobs_binding_state_index');
        });

        Schema::create('callback_outbox', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('job_id')->constrained('print_jobs')->cascadeOnDelete();
            $table->foreignUuid('source_connection_id')->constrained()->cascadeOnDelete();
            $table->string('endpoint', 2048);
            $table->json('payload');
            $table->string('status', 16)->default('pending');
            $table->unsignedSmallInteger('attempts')->default(0);
            $table->dateTime('next_attempt_at', 6);
            $table->dateTime('expires_at', 6);
            $table->unsignedSmallInteger('last_http_status')->nullable();
            $table->text('last_response')->nullable();
            $table->dateTime('sent_at', 6)->nullable();
            $table->timestamps(6);

            $table->unique(['tenant_id', 'job_id']);
            $table->index(['tenant_id', 'status', 'next_attempt_at'], 'callback_outbox_due_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('callback_outbox');

        Schema::table('print_jobs', function (Blueprint $table): void {
            $table->dropIndex('print_jobs_binding_state_index');
            $table->dropConstrainedForeignId('binding_id');
            $table->dropColumn([
                'is_hardcopy',
                'customer_id',
                'customer_name',
                'branch_id',
                'implant_code',
                'failure_code',
                'source_payload',
            ]);
        });

        Schema::table('documents', function (Blueprint $table): void {
            $table->dropColumn(['source_url', 'fetched_at', 'expires_at']);
        });

        Schema::dropIfExists('printer_bindings');
        Schema::dropIfExists('source_connections');
    }
};
