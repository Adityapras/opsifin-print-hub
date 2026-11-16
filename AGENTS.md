# AGENTS.md

Panduan untuk Claude Code (dan agent lain lewat `AGENTS.md`) saat bekerja di repo ini.

## Apa ini

**Opsifin Print Hub** adalah pusat (multi-tenant) pengganti `opsifin-autoprint`. Hub menarik antrean cetak dari setiap instance opsifin-main, memvalidasi PDF, membagi job ke Print Agent di PC klien, lalu melaporkan hasilnya balik ke opsifin-main. Agent (.NET 10) ada di repo terpisah `opsifin-print-agent`.

Spesifikasi lengkap ada di `docs/PRD.md` (detail) dan `docs/PRD-ringkasan-keputusan.md` (keputusan + alur). Keduanya ekspor dari dokumen Claude Docs:
https://claude.ai/artifact/Eg6irNPQwFVvrc2i1eB8zJ. Dokumen itu sumber kebenaran; diagram hanya ada di sana.
Baca PRD sebelum mengubah perilaku domain (state machine job, lease, callback, tenancy).
Status implementasi dan handoff terakhir ada di `docs/PROGRESS.md`; baca sebelum melanjutkan pekerjaan.

## Stack

Laravel 13 · PHP 8.5 · Filament 5 (panel `/admin`) · MySQL 5.7 · database queue/cache/locks · Pest 4 · Larastan.
Tidak ada SPA terpisah: semua UI admin lewat Filament.

## Menjalankan (Docker, tidak butuh PHP lokal)

```bash
docker compose up -d --build            # app :8000, database queue worker, scheduler
docker compose exec app php artisan migrate
docker compose exec app php artisan make:filament-user
```

Perintah sehari-hari selalu lewat container:

```bash
docker compose exec app vendor/bin/pest                       # semua test (Unit, Feature, Arch)
docker compose exec app vendor/bin/pest tests/Arch            # aturan arsitektur saja
docker compose exec app vendor/bin/phpstan analyse --memory-limit=1G
docker compose exec app vendor/bin/pint                       # format kode
docker compose exec app composer require <paket>
```

Container memakai UID/GID host (`UID`/`GID` env, default 1000) agar file hasil artisan tidak dimiliki root.

## Arsitektur

- Modul domain di `app/Domain/{Tenancy,Identity,Fleet,Sources,Jobs,Delivery,Notifications,Reporting,Audit}`. Modul lain hanya boleh memanggil service class publik sebuah modul.
- API untuk agent ada di `/agent/v1`. Kontraknya `contracts/agent-v1.yaml` (OpenAPI 3.1) dan dipakai juga oleh repo agent. Ubah kontrak dulu, baru implementasi; di v1 hanya boleh menambah field opsional.
- Job cetak: state machine `received → queued → claimed → printing → printed` (+ `skipped`, `failed`, `cancelled`). Setiap transisi menulis baris ke `print_job_events` (append-only, dipartisi per bulan).

## Isolasi tenant (wajib)

MySQL tidak punya Row Level Security, jadi isolasi dijaga di aplikasi + CI:

1. Setiap model bisnis memakai trait `BelongsToTenant` (global scope + isi `tenant_id` otomatis).
2. Query mentah (`DB::table`, `DB::select`) hanya boleh di `App\Domain\*\Repositories`; dicek oleh `tests/Arch/TenancyTest.php`.
3. Setiap tabel bisnis punya index komposit yang diawali `tenant_id`.
4. Setiap endpoint punya test lintas tenant (tenant A tidak bisa membaca/mengubah data tenant B).

Saat menambah modul baru dengan Repositories, tambahkan namespace-nya ke daftar `ignoring` di `tests/Arch/TenancyTest.php`.

## Konvensi

- Waktu disimpan UTC (`DATETIME(6)`); konversi ke timezone tenant hanya di tampilan.
- Primary key UUIDv7 (`HasUuids`).
- Klaim job: kunci baris printer lalu `SELECT ... FOR UPDATE` dalam satu transaksi. Lock per printer menjaga paralelisme antar-printer tanpa membutuhkan `SKIP LOCKED` yang belum tersedia di MySQL 5.7.
- Test saat ini memakai SQLite in-memory (`phpunit.xml`). Migration, locking, dan JSON khas MySQL harus diverifikasi juga pada MySQL 5.7.
- Komentar kode dalam Bahasa Indonesia, menjelaskan *kenapa*, bukan *apa*.
