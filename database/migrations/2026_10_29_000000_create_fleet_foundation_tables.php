<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tenants', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('code', 50)->unique();
            $table->string('name');
            $table->string('timezone', 64)->default('Asia/Jakarta');
            $table->string('status', 16)->default('active');
            $table->timestamps(6);
        });

        Schema::create('sites', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->text('address')->nullable();
            $table->string('pic_name')->nullable();
            $table->string('pic_phone', 50)->nullable();
            $table->string('timezone', 64)->nullable();
            $table->timestamps(6);

            $table->unique(['tenant_id', 'name']);
        });

        Schema::create('agent_pairing_codes', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('site_id')->constrained()->cascadeOnDelete();
            $table->char('code_hash', 64)->unique();
            $table->dateTime('expires_at', 6);
            $table->dateTime('used_at', 6)->nullable();
            $table->timestamps(6);

            $table->index(['tenant_id', 'site_id', 'expires_at']);
        });

        Schema::create('agents', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('site_id')->constrained()->cascadeOnDelete();
            $table->string('hostname');
            $table->string('os');
            $table->string('version', 50);
            $table->string('machine_id');
            $table->char('secret_hash', 64);
            $table->unsignedBigInteger('config_version')->default(1);
            $table->json('capabilities')->nullable();
            $table->unsignedBigInteger('uptime_s')->nullable();
            $table->dateTime('agent_time', 6)->nullable();
            $table->dateTime('last_seen_at', 6)->nullable();
            $table->string('status', 16)->default('offline');
            $table->dateTime('revoked_at', 6)->nullable();
            $table->timestamps(6);

            $table->index(['tenant_id', 'site_id', 'machine_id']);
            $table->index(['tenant_id', 'status', 'last_seen_at']);
        });

        Schema::create('printers', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('agent_id')->constrained()->cascadeOnDelete();
            $table->string('windows_name');
            $table->string('alias')->nullable();
            $table->string('driver')->nullable();
            $table->string('port')->nullable();
            $table->boolean('is_default')->default(false);
            $table->string('status', 24)->default('UNKNOWN');
            $table->unsignedBigInteger('raw_status')->nullable();
            $table->unsignedInteger('queue_length')->default(0);
            $table->boolean('is_active')->default(false);
            $table->boolean('is_paused')->default(false);
            $table->json('print_settings')->nullable();
            $table->dateTime('last_seen_at', 6)->nullable();
            $table->timestamps(6);

            $table->unique(['tenant_id', 'agent_id', 'windows_name']);
            $table->index(['tenant_id', 'status', 'is_active']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('printers');
        Schema::dropIfExists('agents');
        Schema::dropIfExists('agent_pairing_codes');
        Schema::dropIfExists('sites');
        Schema::dropIfExists('tenants');
    }
};
