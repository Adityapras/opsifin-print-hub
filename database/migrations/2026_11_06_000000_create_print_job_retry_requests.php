<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('print_job_retry_requests', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('job_id')->constrained('print_jobs')->cascadeOnDelete();
            $table->foreignId('requested_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('mode', 24);
            $table->string('status', 16)->default('pending');
            $table->text('reason');
            $table->text('error_message')->nullable();
            $table->dateTime('requested_at', 6);
            $table->dateTime('started_at', 6)->nullable();
            $table->dateTime('completed_at', 6)->nullable();
            $table->timestamps(6);

            $table->index(
                ['tenant_id', 'job_id', 'status'],
                'print_job_retry_requests_job_status_index',
            );
            $table->index(
                ['tenant_id', 'status', 'requested_at'],
                'print_job_retry_requests_status_index',
            );
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('print_job_retry_requests');
    }
};
