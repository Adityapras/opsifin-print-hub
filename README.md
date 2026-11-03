# Opsifin Print Hub

Pusat autoprint invoice multi-tenant untuk Opsifin. Hub menarik antrean cetak dari opsifin-main, membagikan job ke Print Agent di PC klien, dan mencatat setiap langkah dari hulu ke hilir.

- PRD: [`docs/PRD.md`](docs/PRD.md) · ringkasan keputusan: [`docs/PRD-ringkasan-keputusan.md`](docs/PRD-ringkasan-keputusan.md)
- Kontrak API agent: [`contracts/agent-v1.yaml`](contracts/agent-v1.yaml)
- Agent (.NET 10): repo `opsifin-print-agent`

## Mulai

```bash
cp .env.example .env
docker compose up -d --build
docker compose exec app php artisan key:generate
docker compose exec app php artisan migrate
docker compose exec app php artisan make:filament-user
```

Panel admin: http://localhost:8000/admin · Horizon: http://localhost:8000/horizon

Panduan pengembangan ada di [`CLAUDE.md`](CLAUDE.md).

## Integrasi agent (fondasi)

Endpoint yang sudah aktif:

- `POST /agent/v1/enroll` — menukar pairing code sekali pakai dengan device secret.
- `POST /agent/v1/heartbeat` — memperbarui kondisi agent dan inventaris printer.
- `POST /agent/v1/jobs/claim` — mengklaim maksimal satu job per printer dengan lease.
- `GET /agent/v1/documents/{id}` — mengunduh PDF lewat signed URL lima menit.
- `POST /agent/v1/jobs/{id}/events` — menerima event idempoten dan menggerakkan state job.
- `POST /agent/v1/commands/{id}/result` — mencatat hasil remote command.

Request selain enrollment memakai `Authorization: Bearer <agent_id>.<device_secret>`
dan header `X-Agent-Version`. Hub hanya menyimpan hash SHA-256 device secret.
Printer baru dari heartbeat disimpan nonaktif sampai Tenant Admin mengaktifkannya.

Untuk bootstrap integrasi sebelum resource admin tersedia:

```bash
docker compose exec app php artisan hub:bootstrap-agent ACME "PT ACME" "Kantor Jakarta"
```

Perintah tersebut menampilkan pairing code yang berlaku 15 menit dan hanya bisa dipakai sekali.

Setelah membuat user Filament, berikan akses ke tenant sebelum login:

```bash
docker compose exec app php artisan hub:grant-tenant-user admin@example.com ACME --role=tenant_admin
```

Panel memakai URL tenant seperti `/admin/ACME`. User hanya dapat membuka tenant yang
memiliki membership; role `viewer` dan `operator` tidak dapat mengubah koneksi atau binding.

Alur pemasangan dari panel:

1. Buat **Site** pada menu Armada.
2. Buka **Agents**, klik **Buat pairing code**, lalu pilih site.
3. Masukkan kode delapan karakter itu ke installer Print Agent dalam 15 menit.
4. Setelah heartbeat pertama, printer Windows muncul otomatis pada menu **Printers**.
5. Tenant Admin mengatur alias/print settings dan mengaktifkan printer.
6. Buat **Printer Binding**; binding baru tetap nonaktif sampai autoprint lama dihentikan.

Operator dapat pause/resume antrean printer tanpa memperoleh akses untuk mengubah koneksi,
aktivasi printer, atau binding. Perubahan konfigurasi printer otomatis menaikkan
`config_version` agar agent mengambil konfigurasi terbaru pada heartbeat berikutnya.

## Pull sumber dan callback

Scheduler menjalankan dua worker ringan setiap 10 detik:

- `hub:pull-sources` mengambil binding yang jatuh tempo, memanggil endpoint legacy
  `/getData` dengan Basic Auth, memvalidasi PDF, dan membuat job secara idempoten.
- `hub:process-callbacks` mengirim hasil terminal dari transactional outbox dan
  menjadwalkan retry dengan backoff jika sumber belum dapat dihubungi.

Credential sumber disimpan terenkripsi memakai `APP_KEY`. Ukuran PDF dibatasi oleh
`PRINT_HUB_MAX_PDF_BYTES` (default 20 MiB). Pull hanya berjalan pada jam aktif koneksi
dan setiap dokumen disimpan sebagai snapshot immutable beserta SHA-256 dan jumlah halaman.

Tenant Admin dapat menjalankan **Test connection** dari daftar **Source Connections**.
Hasilnya membedakan masalah autentikasi, endpoint tidak ditemukan, respons HTTP lain,
dan kegagalan koneksi tanpa menampilkan credential sumber.

Perubahan konfigurasi oleh user panel dicatat append-only pada menu **Governance → Audit
Logs**. Nilai password, token, secret, credential, dan authorization selalu disamarkan
sebelum disimpan. Pembaruan status runtime seperti heartbeat dan hasil pengecekan koneksi
tidak dimasukkan agar audit tetap fokus pada perubahan konfigurasi manusia.

Kedua worker juga dapat dijalankan manual untuk diagnosis:

```bash
docker compose exec app php artisan hub:pull-sources
docker compose exec app php artisan hub:pull-sources --binding=<binding-uuid>
docker compose exec app php artisan hub:process-callbacks
```
