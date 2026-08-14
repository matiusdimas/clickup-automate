# 📘 Dokumentasi Lengkap REST API - ClickUp Automation System

Dokumentasi ini berisi panduan teknis dan spesifikasi lengkap REST API untuk integrasi aplikasi eksternal (third-party apps, dashboard internal, webhook, atau script otomatisasi) dengan **ClickUp Automation System**.

---

## 🔑 1. Autentikasi API

Semua request ke API dilindungi oleh middleware autentikasi token. Anda wajib mengirimkan Token API pada header setiap request HTTP.

### Opsi Header Autentikasi:

#### Opsi A (Rekomendasi Standard):
```http
Authorization: Bearer YOUR_API_TOKEN
```

#### Opsi B (Custom Header):
```http
X-Api-Key: YOUR_API_TOKEN
```

> ℹ️ **Catatan**: `YOUR_API_TOKEN` diambil dari konfigurasi `.env` (`API_BEARER_TOKEN`).

---

## 🌐 Base URL

- **Local Development**: `http://127.0.0.1:8000/api/clickup`
- **Staging / Production**: `http://support-portal.lmd.co.id/clickup/api/clickup` (sesuai domain server)

---

## 📊 2. Dashboard Analytics & Overview API

### A. Dashboard Analytics (`GET /api/clickup/dashboard`)

Mengambil data statistik analitik, performa teknisi, distribusi status/prioritas tiket, serta feed tiket terbaru.

#### Request Header:
```http
Authorization: Bearer YOUR_API_TOKEN
Accept: application/json
```

#### Query Parameters (Opsional):
| Parameter | Tipe | Deskripsi | Contoh |
| :--- | :--- | :--- | :--- |
| `module` | `string` | Filter Tipe Aplikasi / Modul | `?module=EBESHA` |
| `aplikasi` | `string` | Filter Detail Aplikasi Spesifik | `?aplikasi=RSG` |
| `status` | `string` | Filter Status Tiket (`Open`, `In Progress`, `Closed`) | `?status=open` |
| `technician` | `string` | Filter Nama Teknisi | `?technician=LMD - Yana` |

#### Contoh Request cURL:
```bash
curl -X GET "http://127.0.0.1:8000/api/clickup/dashboard?module=EBESHA&status=open" \
  -H "Authorization: Bearer YOUR_API_TOKEN" \
  -H "Accept: application/json"
```

#### Respon JSON (200 OK):
```json
{
  "success": true,
  "data": {
    "metrics": {
      "total_tasks": 1955,
      "open_tasks": 120,
      "closed_tasks": 1835,
      "active_technicians": 12,
      "overall_sla_compliance_rate": 96.5
    },
    "by_status": [
      { "status": "open", "total": 120 },
      { "status": "closed", "total": 1835 }
    ],
    "by_module": [
      { "tipe_aplikasi": "EBESHA", "total": 1200 },
      { "tipe_aplikasi": "CAFEINS", "total": 755 }
    ],
    "top_technicians": [
      { "technician": "LMD - Louis", "total_assigned": 450, "resolved": 440 }
    ],
    "latest_tasks": []
  }
}
```

---

### B. System Overview (`GET /api/clickup/overview`)

Mengambil rangkuman status cache lokal, jumlah modul aktif, dan timestamp terakhir kali sync dijalankan.

#### Contoh Request cURL:
```bash
curl -X GET "http://127.0.0.1:8000/api/clickup/overview" \
  -H "Authorization: Bearer YOUR_API_TOKEN"
```

#### Respon JSON (200 OK):
```json
{
  "success": true,
  "data": {
    "active_modules": 1,
    "total_cache": 1955,
    "last_synced_at": "2026-07-29T14:33:00.000000Z",
    "modules": [
      {
        "id": 1,
        "module_name": "IT TICKET LIST",
        "clickup_view_id": "a30d8584-4aea",
        "clickup_list_id": "90130000",
        "is_active": true,
        "tasks_count": 1955
      }
    ]
  }
}
```

---

## 📋 3. Task List & Detail API (Filter Teknisi, Modul, & Aplikasi)

### A. List Tiket Terfilter (`GET /api/clickup/tasks`)

Mengambil daftar tiket dari database cache lokal dengan dukungan filter pencarian, teknisi, aplikasi, status, dan pagination.

#### Query Parameters:
| Parameter | Tipe | Deskripsi | Contoh |
| :--- | :--- | :--- | :--- |
| `module` | `string` | Filter Modul Utama | `?module=EBESHA` |
| `aplikasi` | `string` | Filter Detail Nama Aplikasi | `?aplikasi=Royal Safari Garden` |
| `technician` | `string` | Filter Teknisi | `?technician=LMD - Louis` |
| `status` | `string` | Filter Status Tiket | `?status=Open` |
| `search` | `string` | Pencarian Kata Kunci (Judul, Tiket ID) | `?search=714815` |
| `per_page` | `integer` | Jumlah Data per Halaman (Default: 15, Max: 100) | `?per_page=20` |
| `page` | `integer` | Nomor Halaman | `?page=1` |

#### Contoh Request cURL:
```bash
curl -X GET "http://127.0.0.1:8000/api/clickup/tasks?technician=LMD%20-%20Louis&per_page=10" \
  -H "Authorization: Bearer YOUR_API_TOKEN"
```

---

### B. Detail Lengkap Tiket (`GET /api/clickup/tasks/{id}`)

Mengambil seluruh field detail tiket berdasarkan **ID Database** (misal `15`), **ClickUp Task ID** (misal `86eyd1yk8`), atau **Nomor Tiket** (misal `714815`).

---

### C. Export Semua Data Tiket (`GET /api/clickup/tasks/all`)

Mengambil seluruh baris data cache tiket (format JSON array) untuk kebutuhan dump data atau synchronizing ke database aplikasi lain.

---

## 🔄 4. Synchronize Data ClickUp API

### A. Jalankan Sync (`POST /api/clickup/sync`)

Memicu sinkronisasi data terbaru dari ClickUp Views ke database lokal.

### B. Progress Monitoring Sync (`GET /api/clickup/sync/{syncToken}/progress`)

Memantau status dan persentase progress sync yang sedang berjalan.

### C. Hentikan / Cancel Sync (`POST /api/clickup/sync/cancel`)

Menghentikan proses sinkronisasi data yang sedang berjalan.

---

## 📥 5. Import Excel File & Monitoring Progress API

### A. Preview File Excel (`POST /api/clickup/import/upload-preview`)
### B. Submit Import Data (`POST /api/clickup/import`)
### C. Monitoring Progress Loading Bar Import (`GET /api/clickup/import/{importToken}/progress`)

---

## 🔀 6. Advanced Import Routing Rules API (Multi-Condition AND/OR)

Digunakan untuk mengelola aturan routing dinamis berbasis kombinasi perkondisian `AND` / `OR` untuk menentukan modul/aplikasi target tiket saat import Excel.

### A. Fetch List Aturan (`GET /api/clickup/rules`)
```bash
curl -X GET "http://127.0.0.1:8000/api/clickup/rules" \
  -H "Authorization: Bearer YOUR_API_TOKEN"
```

### B. Tambah / Simpan Aturan Advanced (`POST /api/clickup/rules`)
Mengirim payload aturan tunggal atau multi-kondisi dengan operator logika `AND` / `OR`.

#### Body JSON Example (Multi-Condition AND/OR):
```json
{
  "source_format": "ebesha",
  "logical_operator": "AND",
  "target_module": "APPS ULTIMA INFRA",
  "conditions": [
    {
      "field": "Account",
      "operator": "=",
      "value": "abc"
    },
    {
      "field": "Service Category",
      "operator": "=",
      "value": "db"
    }
  ]
}
```

#### Supported Operators:
- `=` (Exact Match)
- `!=` (Not Equal)
- `CONTAINS` (Contains Substring)

### C. Hapus Aturan Routing (`DELETE /api/clickup/rules/{id}`)

---

## 👥 7. ClickUp Auto-Assignee Rules & Sync API

Endpoint ini digunakan untuk mengelola aturan penugasan tim/teknisi ClickUp (Assignees) secara otomatis berdasarkan kategori aplikasi (Main Apps, Infrastructure Apps, atau Spesifik Apps).

### A. Fetch List Aturan Assignee (`GET /api/clickup/assignee-rules`)
```bash
curl -X GET "http://127.0.0.1:8000/api/clickup/assignee-rules" \
  -H "Authorization: Bearer YOUR_API_TOKEN"
```

### B. Tambah Aturan Penugasan Assignee Baru (`POST /api/clickup/assignee-rules`)

#### Payload Request JSON:
```json
{
  "target_app_type": "specific_apps",
  "specific_app_name": "Cafeins",
  "assignee_ids": [113406558, 95553944],
  "assignee_names": ["Muhammad Dzaka Murran", "Support LMD"]
}
```

#### Field `target_app_type`:
- `main_apps` (Aplikasi Utama)
- `infra_apps` (Aplikasi Infrastruktur)
- `specific_apps` (Aplikasi Spesifik misal `Cafeins`, `eCentrix`, dll.)

### C. Update / Edit Aturan Assignee (`PUT /api/clickup/assignee-rules/{id}`)

#### Contoh Request cURL:
```bash
curl -X PUT "http://127.0.0.1:8000/api/clickup/assignee-rules/1" \
  -H "Authorization: Bearer YOUR_API_TOKEN" \
  -H "Content-Type: application/json" \
  -d '{
    "target_app_type": "main_apps",
    "assignee_ids": [113406558, 95553944],
    "assignee_names": ["Muhammad Dzaka Murran", "Support LMD"]
  }'
```

### D. Hapus Aturan Assignee (`DELETE /api/clickup/assignee-rules/{id}`)

### E. Trigger Batch Push Assignees ke ClickUp API (`POST /api/clickup/sync-assignees`)

Memicu eksekusi otomatis untuk menugaskan teknisi di server ClickUp API untuk tiket yang masih belum ter-assign.

#### Contoh Request cURL:
```bash
curl -X POST "http://127.0.0.1:8000/api/clickup/sync-assignees" \
  -H "Authorization: Bearer YOUR_API_TOKEN"
```

#### Respon JSON (200 OK):
```json
{
  "success": true,
  "message": "Sinkronisasi assignees ke ClickUp API selesai. 1 task di-update."
}
```

---

## 📱 8. App Options API (`GET /api/clickup/app-options`)

Mengambil daftar seluruh nama aplikasi resmi beserta tipe kategori/kodenya yang terdaftar di sistem.

```bash
curl -X GET "http://127.0.0.1:8000/api/clickup/app-options" \
  -H "Authorization: Bearer YOUR_API_TOKEN"
```

---

## ⚙️ 9. Module Settings API (ClickUp Views & List IDs)

- `GET /api/clickup/modules` - Ambil daftar modul.
- `POST /api/clickup/modules` - Tambah modul baru.
- `PUT /api/clickup/modules/{id}` - Update modul.
- `DELETE /api/clickup/modules/{id}` - Hapus modul.

---

## 👤 10. Technician Mappings API

- `GET /api/clickup/technician-mappings` - Ambil daftar pemetaan teknisi.
- `POST /api/clickup/technician-mappings` - Tambah pemetaan teknisi.
- `GET /api/clickup/technician-mappings/{id}` - Detail pemetaan.
- `PUT /api/clickup/technician-mappings/{id}` - Update pemetaan.
- `DELETE /api/clickup/technician-mappings/{id}` - Hapus pemetaan.

---

## 🚨 Error Response Standard

Semua error pada API mengembalikan format JSON standar:

```json
{
  "success": false,
  "message": "Deskripsi error atau alasan penolakan request.",
  "errors": {}
}
```

#### HTTP Status Codes:
- `200 OK`: Request berhasil diselesaikan.
- `401 Unauthorized`: Token API tidak valid atau header autentikasi tidak disertakan.
- `422 Unprocessable Entity`: Input payload/validasi tidak sesuai parameter.
- `429 Too Many Requests`: Kena rate limit ClickUp API (sistem otomatis melakukan retry).
- `500 Internal Server Error`: Terjadi masalah server (error tertangkap dan dicatat di log).

---

*Dokumentasi ini lengkap & valid untuk versi ClickUp Automate System 2026.*
