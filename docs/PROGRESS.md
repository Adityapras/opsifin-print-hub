# Opsifin Print Hub — Progress Memory

Last updated: 2026-11-03 (Asia/Jakarta)

Dokumen ini menjadi handoff ringkas untuk Codex/agent berikutnya. Baca `AGENTS.md`,
`docs/PRD.md`, dan `docs/PRD-ringkasan-keputusan.md` sebelum mengubah perilaku domain.

## Preferensi user

- Percakapan dengan user menggunakan Bahasa Indonesia.
- Bahasa default aplikasi dan seluruh UI adalah English.
- Komentar kode tetap Bahasa Indonesia dan menjelaskan alasan, sesuai `AGENTS.md`.

## Status aplikasi

- Docker Compose aktif: app, Horizon, scheduler, MySQL, dan Redis.
- Admin Panel internal tersedia di `/admin`; halaman awal adalah Global Dashboard,
  sedangkan master client berada di `/admin/tenants`.
- Resource operasional selalu berada di bawah tenant master, misalnya
  `/admin/tenants/{tenant:code}/sites`, `/agents`, dan `/source-connections`.
- Health endpoint terakhir terverifikasi HTTP 200.
- Migrasi MySQL sudah berjalan sampai
  `2026_11_03_000200_add_soft_deletes_to_tenants`.
- Validasi terakhir: 59 test lulus (308 assertions), PHPStan bersih, Pint bersih.

## Fitur yang sudah selesai

### Agent API Hub

- `POST /agent/v1/enroll`
- `POST /agent/v1/heartbeat`
- `POST /agent/v1/jobs/claim`
- `GET /agent/v1/documents/{id}` melalui signed URL
- `POST /agent/v1/jobs/{id}/events`
- `POST /agent/v1/commands/{id}/result`
- Bearer token berbentuk `<agent_id>.<device_secret>`; Hub hanya menyimpan hash secret.
- Enrollment memakai pairing code delapan karakter, berlaku 15 menit, sekali pakai.

### Source, job, dan callback

- Source Connection CRUD dengan credential terenkripsi.
- Aksi Test connection dengan diagnosis auth, endpoint, HTTP, dan jaringan.
- Create/edit Printer Binding otomatis disinkronkan ke endpoint legacy `/printerName`.
- Sinkronisasi mengirim nama/alias lama dan baru sesuai kontrak autoprint lama; binding
  lokal tidak disimpan bila Opsifin menolak request atau tidak dapat dijangkau.
- Perubahan status Active/Inactive tidak memanggil `/printerName` karena tidak mengubah
  identitas remote. Source Connection tidak dapat dipindah setelah binding dibuat karena
  API legacy tidak menyediakan penghapusan record pada koneksi lama.
- Tabel Printer Bindings menyediakan aksi `Sync to Opsifin` untuk menyinkronkan ulang
  binding lama tanpa mengubah datanya.
- Pull `/getData`, dedupe job, validasi PDF, snapshot, SHA-256, dan page count.
- Claim dengan lease, signed document download, event idempoten, dan state machine job.
- Callback outbox dengan retry/backoff ke opsifin-main.

### Admin Panel

- Hub menjadi satu control plane mandiri untuk tim internal; tenant/client tidak memiliki
  panel terpisah dan tidak ada self-registration untuk client.
- Navigasi control plane sekarang terdiri dari Global Dashboard, Tenants, Global
  Monitoring, Internal Team & Access, dan Platform Settings.
- Global Dashboard menampilkan ringkasan tenant aktif, Agent online/offline, printer
  aktif/bermasalah, failed job, dan callback yang jatuh tempo. Global Monitoring
  menyediakan matriks health lintas tenant sesuai assignment user internal.
- Tenant menjadi master data di `/admin/tenants`. Platform Admin dapat membuat tenant,
  sedangkan user internal non-platform hanya melihat tenant yang ditugaskan kepadanya.
- Platform Admin dapat menambah dan mengedit tenant. Penghapusan di UI sengaja berupa
  **Archive**, bukan hard delete: tenant di-soft-delete, status menjadi `suspended`,
  Agent API langsung ditolak, source pull berhenti, tetapi seluruh konfigurasi dan
  histori tetap disimpan. Tenant dapat ditampilkan melalui filter Archived lalu Restore;
  hasil restore tetap `suspended` sampai direview dan diaktifkan manual.
- Setiap tenant memiliki `integration_id` UUIDv7 yang dibuat Hub, unik, permanen, dapat
  disalin dari halaman Integration Identity, tidak dapat diubah lewat form, dan
  diwajibkan oleh constraint database.
- Source Connections, Sites, Agents, Printers, Printer Bindings, Print Jobs, Callback
  Outbox, dan Audit Logs menggunakan route bertingkat di bawah tenant master.
- Tenant Overview menjadi pintu masuk menuju seluruh resource tenant dan menampilkan
  jumlah estate yang dikelola serta topology Site → Agent PC → Windows Printer.
- Internal Team & Access mengelola akun tim Opsifin, status Platform Admin, serta role
  tenant_admin/operator/viewer per tenant. Client tidak boleh dibuat sebagai user Hub.
- Platform Settings saat ini merupakan halaman read-only untuk fakta control plane dan
  safety boundary; secret environment tidak ditampilkan.
- Akun internal lokal `admin@opsifin.com` sudah ditetapkan sebagai Platform Admin agar
  menu tenant management, Internal Team & Access, dan Platform Settings dapat digunakan.
- Model Tenant memakai `code` sebagai route key. Tombol Open tenant sekarang menghasilkan
  URL `/admin/tenants/ACME`, bukan UUID yang sebelumnya menyebabkan halaman 404.
- Tombol Tenant Overview menyebut halaman child `index` secara eksplisit. Connections,
  Sites, Agents, Printers, Bindings, Jobs, Callbacks, dan Audit Log sekarang menuju URL
  nested sebenarnya, bukan query `?relation=...` yang hanya memuat ulang halaman tenant.
- Tenant membership dan role tenant_admin/operator/viewer.
- Print Jobs dan Callback Outbox untuk observabilitas operasional.
- Sites, Agents, Printers, dan Printer Bindings.
- Audit Log append-only untuk perubahan konfigurasi; secret selalu disamarkan.
- Seluruh label, notifikasi, empty state, API error, dan output command menggunakan English.

### Perbaikan tenancy Livewire

- `SetFilamentTenantContext` membaca tenant dari parameter route master dan dijalankan
  sebagai persistent middleware.
- Middleware memeriksa akses user internal sebelum memasang TenantContext dan mengembalikan
  404 untuk tenant yang tidak ditugaskan, sehingga konteks client yang salah tidak sempat
  dipakai query bisnis.
- TenantContext dipertahankan sampai aksi komponen Livewire selesai.
- Error `A tenant context is required when creating business data` pada form create sudah
  diperbaiki dan dilindungi regression test.

### Pengamanan database test dan recovery lokal

- `phpunit.xml` memakai `APP_CONFIG_CACHE` khusus testing agar config cache container tidak
  dapat mengalahkan konfigurasi SQLite in-memory.
- `tests/TestCase.php` menghentikan test sebelum `RefreshDatabase` bila koneksi aktif bukan
  SQLite. Guard ini mencegah test pernah menjalankan refresh schema pada MySQL dev.
- Pada 2026-11-03, config cache sempat membuat satu test men-refresh database MySQL lokal.
  Data dipulihkan point-in-time dari binary log tepat sebelum DROP TABLE dan diverifikasi:
  1 user, 1 tenant, 1 membership, 1 site, 1 agent, 7 printer, 1 Source Connection,
  1 Printer Binding, dan 13 Audit Log.
- Seluruh test suite kemudian dijalankan ulang dan jumlah data MySQL tetap tidak berubah.
- Config, route, dan event cache PHPUnit memakai file `/tmp` terpisah sehingga test tidak
  membaca cache runtime container. Route cache aplikasi dibangun ulang setelah penambahan
  halaman global dan Integration Identity.

### Lifecycle Agent

- Status Agent dihitung dinamis dari `last_seen_at`:
  - `< 90 detik`: Online
  - `90–180 detik`: Stale
  - `> 180 detik`: Offline
  - `revoked_at` terisi: Revoked
- Tabel Agents polling setiap 10 detik.
- Tenant Admin dapat menjalankan Revoke access; token langsung ditolak dengan HTTP 401.
- Revoke menandai printer Offline dan menggagalkan remote command yang belum selesai.
- Delete permanen hanya diizinkan untuk Agent revoked yang belum memiliki printer.
- Agent revoked disembunyikan secara default; tersedia melalui filter Revoked/All statuses.

### Pairing ulang dan deduplikasi

- `(tenant_id, machine_id)` memiliki unique constraint.
- Pairing ulang PC yang sama memakai Agent ID lama, mengganti secret, membuka revoke,
  mempertahankan printer/history, dan menunggu heartbeat baru.
- Migrasi `000400` menggabungkan Agent dan printer duplikat beserta referensinya.
- Cleanup database user sudah dijalankan:
  - Agent lama yang dihapus: `01a19b4c-c1a3-7106-933a-8858f30ff74d`
  - Agent aktif yang dipertahankan: `01a19baf-2b6f-7398-a076-e40d7f86199f`
  - Hostname: `ADITYA-PRASETYO`
  - Machine ID: `66cbd80b-5b2e-48f9-9f5f-c06960d071ec`
  - Tujuh printer sekarang menunjuk ke Agent aktif; satu printer lama dipertahankan Offline.
- User mengonfirmasi koneksi Agent ↔ Hub berhasil.

## Alur operasional berikutnya

1. Pastikan Agent tampil Online dan printer fisik muncul di Fleet → Printers.
2. Aktifkan hanya printer fisik yang akan dipakai dan atur alias/print settings.
3. Buat Printer Binding menggunakan Source Connection, destination printer,
   source PrinterName, source Identifier, dan Printer Version.
4. Simpan binding dalam keadaan Inactive.
5. Hentikan autoprint lama untuk kombinasi tersebut agar tidak terjadi cetak ganda.
6. Aktifkan binding dan pantau Operations → Print Jobs serta Callback Outbox.

## Pekerjaan penting yang belum selesai

- Endpoint `/identity` di opsifin-main dan verifikasi otomatis bahwa `integration_id`
  Source Connection cocok dengan tenant Hub. Untuk saat ini UUID dari Hub disalin secara
  manual ketika endpoint tersebut sudah ditambahkan di opsifin-main.
- User invitation melalui email dan mandatory 2FA untuk admin.
- Rotasi secret Agent terjadwal; pairing ulang baru menangani penggantian secret manual.
- Manual reprint dan cancel job dari panel.
- Recovery otomatis lease kedaluwarsa.
- Alert operasional Agent offline/printer error/job failed/callback failed.
- Laporan dan ekspor CSV (P1).
- Verifikasi end-to-end cetak PDF dan spooler pada printer fisik nyata.
- Benchmark/pilot produksi, backup-restore, TLS, dan runbook rollout.

## Catatan desain penting

- Hub adalah control plane tunggal. Jangan membuat prefix panel per client seperti
  `/admin/bravo`; gunakan `/admin/tenants/bravo/...` agar tenant selalu eksplisit sebagai
  master parent.
- `integration_id` adalah identitas publik untuk pencocokan Hub ↔ opsifin-main, bukan
  secret autentikasi. Credential Source Connection tetap terpisah dan terenkripsi.
- Semua resource operasional harus mendapat tenant dari parent route dan wajib menolak
  user internal yang tidak memiliki membership tenant tersebut.
- Jangan hard-delete Agent yang memiliki printer/history; gunakan revoke dan filter arsip.
- Pairing ulang harus selalu reuse berdasarkan tenant + machine_id.
- Jangan mengaktifkan binding sebelum autoprint lama dihentikan.
- Raw query hanya boleh berada di repository domain atau migration sesuai aturan arsitektur.
- Baca PRD sebelum mengubah state machine, lease, callback, atau tenancy.
