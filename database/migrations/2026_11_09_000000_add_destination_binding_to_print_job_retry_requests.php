<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('print_job_retry_requests', function (Blueprint $table): void {
            $table->foreignUuid('destination_binding_id')
                ->nullable()
                ->after('job_id')
                ->constrained('printer_bindings')
                ->restrictOnDelete();

            $table->index(
                ['tenant_id', 'destination_binding_id'],
                'print_job_retry_requests_destination_index',
            );
        });
    }

    public function down(): void
    {
        Schema::table('print_job_retry_requests', function (Blueprint $table): void {
            $table->dropIndex('print_job_retry_requests_destination_index');
            $table->dropConstrainedForeignId('destination_binding_id');
        });
    }
};
