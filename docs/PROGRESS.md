# Opsifin Print Hub — Progress Memory

Last updated: 2026-11-17 (Asia/Jakarta)

Dokumen ini menjadi handoff ringkas untuk Codex/agent berikutnya. Baca `AGENTS.md`,
`docs/PRD.md`, dan `docs/PRD-ringkasan-keputusan.md` sebelum mengubah perilaku domain.

## Preferensi user

- Percakapan dengan user menggunakan Bahasa Indonesia.
- Bahasa default aplikasi dan seluruh UI adalah English.
- Komentar kode tetap Bahasa Indonesia dan menjelaskan alasan, sesuai `AGENTS.md`.

## Status aplikasi

- Docker Compose memakai app, database queue worker, dan scheduler. Database memakai
  schema `print_hub` pada MySQL 5.7 di external network `opsifin-main-networks`.
- Admin Panel internal tersedia di `/admin`; halaman awal adalah Global Dashboard,
  sedangkan master client berada di `/admin/tenants`.
- Resource operasional selalu berada di bawah tenant master, misalnya
  `/admin/tenants/{tenant:code}/sites`, `/agents`, dan `/source-connections`.
- Health endpoint terakhir terverifikasi HTTP 200.
- Migrasi MySQL source sudah tersedia sampai
  `2026_11_13_000000_add_printer_status_sync_support` dan sudah diterapkan pada database
  pengembangan lokal.
- Validasi terakhir: 98 test lulus (584 assertions), PHPStan bersih, Pint bersih,
  Composer audit tanpa security advisory.
- Background PDF refetch diproses database queue worker. Source pull dan callback MVP
  tetap dijalankan langsung oleh scheduler tiap 10 detik; cache lock database mencegah
  task yang sama overlap.

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
- Respons claim membawa object opsional `display` berisi nomor dokumen, jenis dokumen,
  dan waktu diterima agar Agent Control dapat menampilkan pekerjaan aktif tanpa membawa
  customer, nilai finansial, atau source payload.
- Remote config membawa `source_poll_interval_s` sebagai informasi. Nilainya hanya dikirim
  bila seluruh Source Connection aktif untuk Agent memakai interval yang sama; tanpa source
  aktif atau ketika interval berbeda, nilainya `null` agar UI tidak menyesatkan.
- Perubahan interval/status Source Connection dan topology Printer Binding menaikkan
  `config_version` Agent terdampak, sehingga interval terbaru dikirim pada heartbeat berikutnya.

### Agent release dan OTA

- Platform Admin memiliki resource global **Agent Releases** untuk upload satu MSI, validasi
  ProductVersion, perhitungan SHA-256 server-side, pemilihan policy manual/automatic, publish,
  pilot 10%, rollout tenant, retire, audit event, dan monitoring deployment per Agent.
- Tidak ada Authenticode, manifest release, private signing key, key ID, channel, atau migration
  bridge Agent lama. Baseline OTA ini khusus fresh install sesuai handoff Agent 12 November 2026.
- Paket disimpan private dan hanya diunduh dengan signed URL yang belum kedaluwarsa serta Bearer
  token Agent pemilik deployment. URL dibuat ulang saat command benar-benar dikirim.
- Aksi **Schedule update** tersedia pada daftar Agent dan selalu memakai policy milik release.
  Default policy adalah `manual`; policy `automatic` tetap menunggu Agent idle, menghentikan claim
  baru, menyelesaikan print aktif, dan flush outbox sebelum instalasi.
- Lifecycle heartbeat opsional mencatat `downloading`, `ready`, `waiting_for_idle`, `installing`,
  dan `failed`. Command `succeeded` hanya berarti diterima; status internal `installed` baru ditulis
  ketika heartbeat berikutnya melaporkan versi target dan ditampilkan sebagai **Succeeded** di UI.
- Scheduler menandai deployment tanpa progres sebagai `stalled` setelah batas konfigurasi.
- Command update hanya membawa `version`, `url`, `sha256`, `install_policy`, dan `release_notes`
  opsional. Command pending dari baseline signing lama dinormalisasi sebelum dikirim agar field
  obsolete tidak pernah mencapai Agent baru.
- Download atau SHA-256 yang gagal tidak mengubah versi terpasang. Manual maupun automatic harus
  menghentikan claim baru dan menunggu print aktif serta event delivery selesai sebelum instalasi.

### Source, job, dan callback

- Source Connection CRUD dengan credential terenkripsi.
- Source Connection baru selalu inactive dengan identity status `pending`. Aksi `Verify
  identity` memanggil `GET {base_url}/identity` memakai Basic Auth dan hanya menghasilkan
  status `verified` bila `integration_id` remote sama persis dengan tenant Hub.
- Identity verification bersifat fail-closed: `pending`, `mismatch`, `unavailable`, atau
  perubahan URL/credential memblokir create/sync/activation binding, source pull, claim
  queued job, status printer, dan callback. Binding terkait ikut dinonaktifkan.
- Respons `/identity` yang wajib adalah JSON `{"integration_id":"<UUID Hub>"}`. HTTP
  401/403, 404, respons tanpa UUID valid, jaringan gagal, dan UUID tenant lain mendapat
  diagnosis berbeda dan tidak dapat dipakai secara operasional.
- Create/edit Printer Binding otomatis disinkronkan ke endpoint legacy `/printerName`.
- Sinkronisasi mengirim nama/alias lama dan baru sesuai kontrak autoprint lama; binding
  lokal tidak disimpan bila Opsifin menolak request atau tidak dapat dijangkau.
- Perubahan status Active/Inactive tidak memanggil `/printerName` karena tidak mengubah
  identitas remote. Source Connection tidak dapat dipindah setelah binding dibuat karena
  API legacy tidak menyediakan penghapusan record pada koneksi lama.
- Tabel Printer Bindings menyediakan aksi `Sync to Opsifin` untuk menyinkronkan ulang
  binding lama tanpa mengubah datanya.
- Pull `/getData`, dedupe job, validasi PDF, snapshot, SHA-256, dan page count.
- Satu tenant dapat memiliki beberapa Source Connection yang mewakili instance Opsifin
  dalam batas bisnis yang sama, misalnya Production dan Cabang. Setiap job menyimpan
  `source_connection_id` asal secara immutable dan memakai dedupe key per source, sehingga
  nomor invoice yang sama dari dua instance tetap menjadi dua job yang valid.
- Callback dan refetch mengambil connection dari snapshot source pada Print Job, bukan
  dari binding yang mutable. Binding yang sudah dibuat tidak dapat dipindahkan ke Source
  Connection lain; perubahan route lintas source harus menggunakan binding baru.
- Source pull sekarang busy-aware per Printer Binding. Binding yang masih memiliki job
  `received`/`queued`/`claimed`/`printing` atau callback `pending` tidak memanggil
  `/getData` lagi; binding dan tenant lain tetap diproses. Setelah batch terminal dan
  callback terkirim, binding yang masih due langsung dapat ditarik pada tick scheduler
  berikutnya. Ini mencegah payload `Process` legacy berulang memenuhi limit 50 dan menambah
  event duplicate tanpa meningkatkan throughput printer.
- Download PDF menormalkan hanya UTF-8 BOM/whitespace aman maksimal 16 byte sebelum
  `%PDF-`. Snapshot, ukuran, dan SHA-256 dihitung dari dokumen yang sudah kanonis agar
  Agent tetap dapat memakai validasi header ketat. HTML atau prefix non-whitespace tetap
  ditolak sebagai `PDF_INVALID`; tidak ada request, query, atau worker tambahan.
- Scheduler `hub:pull-sources` dapat menemukan binding jatuh tempo tanpa TenantContext,
  tetapi tetap mencocokkan `tenant_id` binding dan Source Connection secara eksplisit
  sebelum memasang context. Regression test mencakup pull dua tenant dalam satu proses.
- Claim dengan lease, signed document download, event idempoten, dan state machine job.
- Hub tidak pernah menjalankan retry otomatis setelah Agent melaporkan `spooled`. Error pada
  tahap itu bersifat ambigu karena kertas mungkin sudah keluar; job dihentikan sebagai `failed`
  untuk pemeriksaan operator. Guard ini mencegah invoice tercetak dua kali walaupun Agent lama
  salah mengklasifikasikan race pembacaan JobId spooler.
- Scheduler melepaskan lease `claimed`/`printing` yang kedaluwarsa setiap 10 detik,
  menutup attempt lama dengan outcome `lease_expired`, lalu mengembalikan job ke `queued`
  agar satu printer tidak terkunci permanen.
- Callback outbox dengan retry/backoff ke opsifin-main.
- Scheduler `hub:sync-printer-statuses` mengirim availability efektif setiap Printer Binding
  ke endpoint Opsifin Main `/PrinterStatus`. Agent stale, offline, revoked, atau unknown selalu
  diproyeksikan sebagai `OFFLINE`, meskipun heartbeat printer terakhir masih `ONLINE`.
- Hasil sinkronisasi status disimpan per binding (`last_synced_printer_status`, waktu sukses,
  jumlah percobaan, jadwal retry, dan error terakhir) dengan retry backoff. Tabel Bindings
  menampilkan status yang terakhir dikirim agar perbedaan Hub dan Opsifin dapat diaudit.
- Job `failed` memiliki dua aksi manual dengan alasan wajib: `Retry printing` memakai
  snapshot PDF tersimpan, sedangkan `Refetch and retry` mengunduh dan memvalidasi PDF
  baru dari Opsifin. Keduanya mempertahankan job, dokumen lama, attempt, dan timeline.
- Setiap manual retry menaikkan print run, menulis event `reprint_requested` dan audit log,
  lalu kembali ke `queued`. Callback lama tidak ditimpa; setiap run memiliki callback
  terpisah dan callback pending dari run lama ditandai `superseded` agar tidak berlomba.
- Modal `Retry printing` dan `Refetch and retry` mewajibkan pemilihan destination binding
  aktif. Pilihan dibatasi ke tenant dan Source Connection asal yang sama; ketika retry
  berhasil, `binding_id` dan `printer_id` job dipindahkan bersama dalam satu transaksi.
  Event dan audit menyimpan binding/printer lama serta baru, sehingga job historis dari
  binding inactive dapat diarahkan ke printer pengganti tanpa mengaktifkan route lama.
- `Refetch and retry` tidak lagi menahan request Livewire selama Opsifin menghasilkan PDF.
  Submit membuat `print_job_retry_requests` persistent lalu background worker mengunduh, memvalidasi,
  menyimpan snapshot, dan mengantrekan job. Request aktif mencegah klik ganda; kegagalan
  background tersimpan dan job tetap `failed`, sedangkan sukses mengubahnya ke `queued`
  dalam transaksi yang sama dengan status request `succeeded`.
- Manual retry hanya tersedia untuk Platform Admin, Tenant Admin, dan Operator pada
  tenant yang sama, serta ditolak bila lease masih aktif, binding/source belum siap,
  atau printer tidak aktif.

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
- Navigasi workspace tenant berada di sisi kanan dan hanya menampilkan nama menu. Penjelasan,
  hal yang harus dikonfigurasi, dan hal yang harus dimonitor ditampilkan sebagai card pada
  halaman aktif agar navigasi tetap ringkas dan konteks kerja tetap jelas.
- Menu **Setup guide** menampilkan diagram onboarding dinamis delapan langkah: Integration
  Identity, Source Connection, Site, Agent, Printer, Binding, status sync, dan controlled E2E
  print. Setiap langkah memiliki indikator Ready/Action required, target hasil, dan tautan
  langsung ke halaman konfigurasi terkait.
- Platform Admin memiliki aksi **Reinitialize tenant** pada Tenant Overview. Aksi ini
  mempertahankan tenant, `integration_id`, user/membership, dan audit log, tetapi menghapus
  seluruh Source Connection, site, Agent, printer, binding, dokumen/job/callback, command,
  serta deployment update tenant sehingga onboarding dapat dimulai ulang dari nol.
- Reinitialize bersifat fail-closed: tenant harus bebas dari print/callback/refetch/update aktif,
  kode tenant dan alasan wajib dikonfirmasi, remote Opsifin Main dibersihkan lebih dahulu,
  dan kegagalan remote membuat tenant tetap Suspended tanpa menghapus data lokal.
- Service reinitialize memasang dan memulihkan `TenantContext` secara eksplisit agar action
  Livewire tetap dapat membaca relasi tenant dan membuat audit log tanpa bergantung pada
  lifecycle middleware halaman. Context aktif yang menunjuk tenant lain ditolak fail-closed.
- Menu global `Documentation` menjadi handbook internal di aplikasi. Isinya menjelaskan
  ownership setiap modul, model operasional sederhana Trust → Fleet → Route → Operate,
  flow end-to-end dan responsibility lanes, safety model tenant/routing, alasan tech stack,
  onboarding user guide, serta failure handling termasuk dua mode manual retry.
- Detail Print Job, Agent, Callback Outbox, dan Audit Log dibuka sebagai modal lebar dari
  tabel agar operator tidak kehilangan filter dan posisi daftar. Route halaman detail
  terpisah dihapus; Tenant Overview tetap halaman karena merupakan konteks induk.
- Job Summary untuk setiap status yang memiliki dokumen menampilkan link **Download PDF** menuju snapshot yang
  benar-benar dikirim ke Agent. URL berlaku lima menit, memerlukan sesi login, dan endpoint
  memverifikasi membership serta kecocokan tenant sebelum mengalirkan file tanpa cache.
- Job Summary juga menampilkan **Source document URL** asli dari Opsifin sebagai teks
  monospace yang dapat disalin dan dibuka. Hanya skema HTTP(S) yang dijadikan link aktif.
- Modal Print Job menampilkan timeline append-only dalam panel scroll berukuran tetap,
  urutan event terbaru di atas, reason/mode retry langsung terbaca, dan payload teknis
  collapsed. History tetap lengkap tanpa membuat modal memanjang tanpa batas.
- Tenant Admin dapat menghapus permanen job terminal beserta attempt, timeline, callback,
  retry history, metadata dokumen, dan seluruh snapshot PDF job. Job dengan lease Agent,
  callback pending, atau refetch aktif ditolak; nomor dokumen dan alasan wajib dikonfirmasi.
  Audit Log `print_job.deleted` tetap dipertahankan agar hard delete dapat ditelusuri.
- Internal Team & Access mengelola akun tim Opsifin, status Platform Admin, serta role
  tenant_admin/operator/viewer per tenant. Client tidak boleh dibuat sebagai user Hub.
- Platform Settings saat ini merupakan halaman read-only untuk fakta control plane dan
  safety boundary; secret environment tidak ditampilkan.
- Schema MySQL 5.7 yang baru dimulai tanpa user maupun data bisnis. Platform Admin pertama
  perlu dibuat dengan `make:filament-user`, lalu flag `is_platform_admin` diaktifkan sebelum
  tenant onboarding dimulai.
- Volume MySQL lokal lama tetap dipertahankan sebagai recovery source dan tidak ikut
  dipasang pada runtime baru. Tidak ada data bisnis lama yang disalin ke schema baru.
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
- Timestamp relatif pada tabel operasional selalu disertai jam lokal, misalnya
  `6 minutes ago · 18:00`; tanggal ikut ditampilkan untuk hari sebelumnya agar operator
  tidak perlu membuka tooltip untuk mengetahui waktu aktual.
- Seluruh row action pada 11 tabel admin kini diringkas ke satu tombol **Actions** dengan
  dropdown auto-placement. Aksi operasional, edit, detail, retry, sync, dan destructive
  action tetap mempertahankan permission serta confirmation masing-masing tanpa memperlebar
  tabel atau memaksa horizontal scroll hanya untuk mencapai CTA.

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

### Availability printer

- Availability printer di UI tidak lagi memakai nilai mentah heartbeat sebagai kebenaran
  tunggal. Status efektif mengikuti presence Agent: `Agent stale` setelah 90 detik tanpa
  heartbeat, `Agent offline` setelah 180 detik, dan `Agent revoked` setelah akses dicabut.
- Nilai mentah printer terakhir tetap disimpan dan ditampilkan sebagai `Last report` agar
  troubleshooting driver/spooler tetap memiliki bukti, tanpa menyesatkan operator bahwa
  printer masih dapat dijangkau.
- Daftar Printer, topology Tenant Overview, filter Availability, dan counter problem
  printer pada dashboard memakai availability efektif. Tabel Printer polling setiap 10
  detik sehingga perubahan waktu tampil tanpa mengubah histori heartbeat.
- Saat TenantContext tidak tersedia, availability menjadi `Unknown` (fail-safe), bukan
  menganggap laporan lama `ONLINE` masih valid atau melewati tenant scope.
- Simulation mode menyelesaikan spool sekitar 1–2 detik, tetapi tetap menjalankan jendela
  verifikasi pasca-spool 60 detik sebelum event `completed`. Delay ini disengaja dan sama
  dengan printer fisik untuk menghindari status sukses palsu akibat error sesaat setelah spool.

### Pairing ulang dan deduplikasi

- `(tenant_id, machine_id)` memiliki unique constraint.
- Pairing ulang PC yang sama memakai Agent ID lama, mengganti secret, membuka revoke,
  mempertahankan printer/history, dan menunggu heartbeat baru.
- Migrasi `000400` menggabungkan Agent dan printer duplikat beserta referensinya.
- Cleanup deduplikasi berikut adalah catatan historis sebelum data onboarding lokal
  dikosongkan pada 2026-11-04:
  - Agent lama yang dihapus: `01a19b4c-c1a3-7106-933a-8858f30ff74d`
  - Agent aktif yang dipertahankan: `01a19baf-2b6f-7398-a076-e40d7f86199f`
  - Hostname: `ADITYA-PRASETYO`
  - Machine ID: `66cbd80b-5b2e-48f9-9f5f-c06960d071ec`
  - Tujuh printer sekarang menunjuk ke Agent aktif; satu printer lama dipertahankan Offline.
- User sebelumnya mengonfirmasi koneksi Agent ↔ Hub berhasil; Agent harus pairing ulang
  setelah tenant baru dibuat.

## Alur operasional berikutnya

1. Buat Tenant, lalu salin Integration ID Hub ke opsifin-main client yang tepat.
2. Buat Source Connection dalam keadaan inactive dan jalankan `Verify identity`.
3. Setelah status `Verified`, aktifkan Source Connection.
4. Buat Site dan pairing Agent; pastikan Agent Online dan printer fisik muncul.
5. Aktifkan hanya printer fisik yang akan dipakai dan atur alias/print settings.
6. Buat Printer Binding menggunakan Source Connection, destination printer,
   source PrinterName, source Identifier, dan Printer Version.
7. Simpan binding dalam keadaan Inactive.
8. Hentikan autoprint lama untuk kombinasi tersebut agar tidak terjadi cetak ganda.
9. Aktifkan binding dan pantau Operations → Print Jobs serta Callback Outbox.

## Pekerjaan penting yang belum selesai

- Endpoint `/identity` lokal sudah dapat diverifikasi oleh Hub. Implementasi pada setiap
  instance opsifin-main tenant tetap menjadi prasyarat onboarding.
- User invitation melalui email dan mandatory 2FA untuk admin.
- Rotasi secret Agent terjadwal; pairing ulang baru menangani penggantian secret manual.
- Cancel job dari panel.
- Alert operasional Agent offline/printer error/job failed/callback failed.
- Laporan dan ekspor CSV (P1).
- Verifikasi end-to-end cetak PDF dan spooler pada printer fisik nyata.
- Uji instalasi manual pertama pada clean Windows VM untuk mendokumentasikan alur
  `More info → Run anyway` dan kebutuhan whitelist pada policy perusahaan yang ketat.
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
- Endpoint legacy `/getData` opsifin-main saat ini hanya memproses `VersionAPI=2`.
  Source Connection lokal harus memakai API version 2; version 1 menghasilkan array
  kosong walaupun terdapat invoice yang cocok.
- Raw query hanya boleh berada di repository domain atau migration sesuai aturan arsitektur.
- Baca PRD sebelum mengubah state machine, lease, callback, atau tenancy.
