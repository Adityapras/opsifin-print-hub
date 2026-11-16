<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('printer_bindings', function (Blueprint $table): void {
            $table->string('last_synced_printer_status', 32)->nullable()->after('last_error');
            $table->unsignedSmallInteger('status_sync_attempts')->default(0)->after('last_synced_printer_status');
            $table->dateTime('next_status_sync_at', 6)->nullable()->after('status_sync_attempts');
            $table->dateTime('last_status_synced_at', 6)->nullable()->after('next_status_sync_at');
            $table->text('last_status_sync_error')->nullable()->after('last_status_synced_at');

            $table->index(
                ['tenant_id', 'is_active', 'next_status_sync_at'],
                'printer_bindings_status_sync_due_index',
            );
        });
    }

    public function down(): void
    {
        Schema::table('printer_bindings', function (Blueprint $table): void {
            $table->dropIndex('printer_bindings_status_sync_due_index');
            $table->dropColumn([
                'last_synced_printer_status',
                'status_sync_attempts',
                'next_status_sync_at',
                'last_status_synced_at',
                'last_status_sync_error',
            ]);
        });
    }
};
