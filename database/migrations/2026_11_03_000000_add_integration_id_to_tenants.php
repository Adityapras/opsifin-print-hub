<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tenants', function (Blueprint $table): void {
            $table->uuid('integration_id')->nullable()->after('id');
        });

        DB::table('tenants')
            ->select('id')
            ->orderBy('id')
            ->eachById(function (object $tenant): void {
                DB::table('tenants')
                    ->where('id', $tenant->id)
                    ->update(['integration_id' => (string) Str::uuid7()]);
            }, column: 'id');

        Schema::table('tenants', function (Blueprint $table): void {
            $table->unique('integration_id', 'tenants_integration_id_unique');
        });
    }

    public function down(): void
    {
        Schema::table('tenants', function (Blueprint $table): void {
            $table->dropUnique('tenants_integration_id_unique');
            $table->dropColumn('integration_id');
        });
    }
};
