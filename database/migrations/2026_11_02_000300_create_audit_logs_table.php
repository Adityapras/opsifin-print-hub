<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('audit_logs', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('actor_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('action', 64);
            $table->string('subject_type');
            $table->string('subject_id', 100);
            $table->json('before_data')->nullable();
            $table->json('after_data')->nullable();
            $table->ipAddress('ip_address')->nullable();
            $table->string('user_agent', 1024)->nullable();
            $table->dateTime('occurred_at', 6);
            $table->timestamps(6);

            $table->index(['tenant_id', 'occurred_at']);
            $table->index(['tenant_id', 'subject_type', 'subject_id']);
            $table->index(['tenant_id', 'actor_id', 'occurred_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('audit_logs');
    }
};
