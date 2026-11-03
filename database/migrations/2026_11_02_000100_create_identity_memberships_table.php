<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->boolean('is_platform_admin')->default(false)->after('password');
        });

        Schema::create('memberships', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('role', 32)->default('viewer');
            $table->json('site_ids')->nullable();
            $table->timestamps(6);

            $table->unique(['tenant_id', 'user_id']);
            $table->index(['tenant_id', 'role']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('memberships');

        Schema::table('users', function (Blueprint $table): void {
            $table->dropColumn('is_platform_admin');
        });
    }
};
