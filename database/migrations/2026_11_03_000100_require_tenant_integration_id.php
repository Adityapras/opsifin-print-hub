<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tenants', function (Blueprint $table): void {
            // Backfill dilakukan pada migrasi sebelumnya; NOT NULL menutup celah
            // tenant tanpa identitas bila data masuk di luar model Eloquent.
            $table->uuid('integration_id')->nullable(false)->change();
        });
    }

    public function down(): void
    {
        Schema::table('tenants', function (Blueprint $table): void {
            $table->uuid('integration_id')->nullable()->change();
        });
    }
};
