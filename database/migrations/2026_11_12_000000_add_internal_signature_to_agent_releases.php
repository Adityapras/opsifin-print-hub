<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('agent_releases', function (Blueprint $table): void {
            $table->text('signature_value')->nullable()->after('signature_type');
            $table->string('signing_key_id', 100)->nullable()->after('signature_value');
            $table->string('manifest_path')->nullable()->after('signing_key_id');
        });
    }

    public function down(): void
    {
        Schema::table('agent_releases', function (Blueprint $table): void {
            $table->dropColumn(['signature_value', 'signing_key_id', 'manifest_path']);
        });
    }
};
