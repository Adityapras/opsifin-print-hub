<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('agent_releases', function (Blueprint $table): void {
            $table->dropIndex(['status', 'channel', 'published_at']);
            $table->string('install_policy', 20)->default('manual')->after('sha256');
            $table->dropColumn([
                'channel',
                'signature_type',
                'signature_value',
                'signing_key_id',
                'manifest_path',
                'signature_subject',
                'signature_verified_at',
            ]);
            $table->index(['status', 'published_at']);
        });

        Schema::table('agent_update_deployments', function (Blueprint $table): void {
            $table->string('install_policy', 20)->default('manual')->change();
        });
    }

    public function down(): void
    {
        Schema::table('agent_releases', function (Blueprint $table): void {
            $table->dropIndex(['status', 'published_at']);
            $table->string('channel', 32)->default('stable')->after('version');
            $table->string('signature_type', 32)->default('authenticode')->after('sha256');
            $table->text('signature_value')->nullable()->after('signature_type');
            $table->string('signing_key_id', 100)->nullable()->after('signature_value');
            $table->string('manifest_path')->nullable()->after('signing_key_id');
            $table->string('signature_subject')->nullable()->after('manifest_path');
            $table->dateTime('signature_verified_at', 6)->nullable()->after('signature_subject');
            $table->dropColumn('install_policy');
            $table->index(['status', 'channel', 'published_at']);
        });

        Schema::table('agent_update_deployments', function (Blueprint $table): void {
            $table->string('install_policy', 20)->default('automatic')->change();
        });
    }
};
