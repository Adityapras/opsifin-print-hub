<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('agent_releases', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('version', 50)->unique();
            $table->string('channel', 32)->default('stable');
            $table->string('disk', 50)->default('local');
            $table->string('path');
            $table->string('original_name');
            $table->unsignedBigInteger('size_bytes');
            $table->char('sha256', 64);
            $table->string('signature_type', 32)->default('authenticode');
            $table->string('signature_subject')->nullable();
            $table->dateTime('signature_verified_at', 6);
            $table->text('release_notes')->nullable();
            $table->string('status', 20)->default('draft');
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('published_by')->nullable()->constrained('users')->nullOnDelete();
            $table->dateTime('published_at', 6)->nullable();
            $table->dateTime('retired_at', 6)->nullable();
            $table->timestamps(6);

            $table->index(['status', 'channel', 'published_at']);
        });

        Schema::create('agent_release_events', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('agent_release_id')->constrained('agent_releases')->cascadeOnDelete();
            $table->foreignId('actor_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('type', 50);
            $table->json('data')->nullable();
            $table->dateTime('occurred_at', 6);
            $table->timestamps(6);

            $table->index(['agent_release_id', 'occurred_at']);
        });

        Schema::create('agent_update_deployments', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('agent_release_id')->constrained('agent_releases')->restrictOnDelete();
            $table->foreignUuid('agent_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('agent_command_id')->nullable()->constrained('agent_commands')->nullOnDelete();
            $table->foreignId('scheduled_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('install_policy', 20)->default('automatic');
            $table->string('status', 24)->default('pending');
            $table->unsignedTinyInteger('progress_percent')->default(0);
            $table->text('message')->nullable();
            $table->dateTime('scheduled_at', 6);
            $table->dateTime('delivered_at', 6)->nullable();
            $table->dateTime('accepted_at', 6)->nullable();
            $table->dateTime('installed_at', 6)->nullable();
            $table->dateTime('failed_at', 6)->nullable();
            $table->dateTime('last_observed_at', 6)->nullable();
            $table->timestamps(6);

            $table->unique(['tenant_id', 'agent_release_id', 'agent_id'], 'agent_update_release_agent_unique');
            $table->index(['tenant_id', 'status', 'scheduled_at']);
            $table->index(['tenant_id', 'agent_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('agent_update_deployments');
        Schema::dropIfExists('agent_release_events');
        Schema::dropIfExists('agent_releases');
    }
};
