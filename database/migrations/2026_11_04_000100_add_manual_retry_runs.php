<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('print_jobs', function (Blueprint $table): void {
            $table->unsignedSmallInteger('run_number')->default(1)->after('attempt_count');
        });

        Schema::table('callback_outbox', function (Blueprint $table): void {
            $table->dropUnique('callback_outbox_tenant_id_job_id_unique');
            $table->unsignedSmallInteger('job_run')->default(1)->after('job_id');
            $table->unique(['tenant_id', 'job_id', 'job_run'], 'callback_outbox_job_run_unique');
        });
    }

    public function down(): void
    {
        // Schema lama hanya mengizinkan satu callback per job. Saat rollback,
        // callback run pertama dipertahankan agar unique key lama dapat dipulihkan.
        DB::table('callback_outbox')->where('job_run', '>', 1)->delete();

        Schema::table('callback_outbox', function (Blueprint $table): void {
            $table->dropUnique('callback_outbox_job_run_unique');
            $table->dropColumn('job_run');
            $table->unique(['tenant_id', 'job_id']);
        });

        Schema::table('print_jobs', function (Blueprint $table): void {
            $table->dropColumn('run_number');
        });
    }
};
