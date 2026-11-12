<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('source_connections', function (Blueprint $table): void {
            $table->string('identity_status', 16)->default('pending')->after('is_active');
            $table->uuid('remote_integration_id')->nullable()->after('identity_status');
            $table->dateTime('identity_verified_at', 6)->nullable()->after('remote_integration_id');
            $table->index(
                ['tenant_id', 'identity_status', 'is_active'],
                'source_connections_identity_active_index',
            );
        });

        // Koneksi lama belum pernah membuktikan identitas remote. Menonaktifkannya
        // mencegah request lintas client selama proses verifikasi pertama.
        DB::table('printer_bindings')->update(['is_active' => false]);
        DB::table('source_connections')->update([
            'is_active' => false,
            'identity_status' => 'pending',
        ]);

        Schema::table('source_connections', function (Blueprint $table): void {
            $table->boolean('is_active')->default(false)->change();
        });
    }

    public function down(): void
    {
        Schema::table('source_connections', function (Blueprint $table): void {
            $table->dropIndex('source_connections_identity_active_index');
            $table->dropColumn([
                'identity_status',
                'remote_integration_id',
                'identity_verified_at',
            ]);
            $table->boolean('is_active')->default(true)->change();
        });
    }
};
