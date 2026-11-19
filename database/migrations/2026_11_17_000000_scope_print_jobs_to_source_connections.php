<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('print_jobs', function (Blueprint $table): void {
            $table->foreignUuid('source_connection_id')
                ->nullable()
                ->after('binding_id')
                ->constrained('source_connections')
                ->restrictOnDelete();
            $table->char('dedupe_key', 64)->nullable()->after('source_connection_id');
        });

        // Job lama belum menyimpan source secara langsung. Backfill dilakukan sebelum
        // unique key baru dibuat agar histori tetap memiliki route Opsifin asal.
        DB::table('print_jobs')
            ->whereNotNull('binding_id')
            ->orderBy('id')
            ->select(['id', 'binding_id', 'inv_no', 'item_type', 'version_inv'])
            ->chunk(500, function ($jobs): void {
                $connectionIds = DB::table('printer_bindings')
                    ->whereIn('id', $jobs->pluck('binding_id')->filter()->unique()->all())
                    ->pluck('source_connection_id', 'id');

                foreach ($jobs as $job) {
                    $sourceConnectionId = $connectionIds->get($job->binding_id);

                    if (! is_string($sourceConnectionId)) {
                        continue;
                    }

                    DB::table('print_jobs')->where('id', $job->id)->update([
                        'source_connection_id' => $sourceConnectionId,
                        'dedupe_key' => hash('sha256', implode('|', [
                            (string) $job->inv_no,
                            (string) $job->item_type,
                            (string) $job->version_inv,
                        ])),
                    ]);
                }
            });

        Schema::table('print_jobs', function (Blueprint $table): void {
            $table->dropUnique(['tenant_id', 'inv_no', 'item_type', 'version_inv']);
            // Hash berukuran tetap menjaga index jauh di bawah batas MySQL 5.7,
            // sementara source_connection_id memisahkan nomor invoice antar-instance.
            $table->unique(
                ['tenant_id', 'source_connection_id', 'dedupe_key'],
                'print_jobs_source_dedupe_unique',
            );
        });
    }

    public function down(): void
    {
        $hasCrossSourceCollisions = DB::table('print_jobs')
            ->select(['tenant_id', 'inv_no', 'item_type', 'version_inv'])
            ->groupBy(['tenant_id', 'inv_no', 'item_type', 'version_inv'])
            ->havingRaw('COUNT(*) > 1')
            ->exists();

        if ($hasCrossSourceCollisions) {
            // Rollback tidak boleh diam-diam menghapus salah satu invoice valid dari
            // dua source hanya untuk memulihkan unique key lama.
            throw new RuntimeException(
                'Cannot roll back source-scoped job identity while cross-source invoice collisions exist.',
            );
        }

        Schema::table('print_jobs', function (Blueprint $table): void {
            $table->dropUnique('print_jobs_source_dedupe_unique');
            $table->unique(['tenant_id', 'inv_no', 'item_type', 'version_inv']);
            $table->dropConstrainedForeignId('source_connection_id');
            $table->dropColumn('dedupe_key');
        });
    }
};
