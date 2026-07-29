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

#### Respon JSON (200 OK):
```json
{
  "success": true,
  "data": {
    "current_page": 1,
    "data": [
      {
        "id": 15,
        "clickup_task_id": "86eyd1yk8",
        "tiket_id": "714815",
        "name": "#714815 Mohon Sync Chatbot",
        "tipe_aplikasi": "EBESHA",
        "aplikasi": "Royal Safari Garden",
        "status": "Open",
        "technician": "LMD - Louis",
        "requestor_name": "RSG",
        "created_time": "Jun 13, 2026 09:41 PM",
        "resolved_time": "Jul 29, 2026 12:00 AM",
        "updated_at": "2026-07-29T07:34:39.000000Z"
      }
    ],
    "first_page_url": "http://127.0.0.1:8000/api/clickup/tasks?page=1",
    "last_page": 98,
    "total": 1955
  }
}
```

---

### B. Detail Lengkap Tiket (`GET /api/clickup/tasks/{id}`)

Mengambil seluruh field detail tiket berdasarkan **ID Database** (misal `15`), **ClickUp Task ID** (misal `86eyd1yk8`), atau **Nomor Tiket** (misal `714815`).

#### Contoh Request cURL:
```bash
curl -X GET "http://127.0.0.1:8000/api/clickup/tasks/714815" \
  -H "Authorization: Bearer YOUR_API_TOKEN"
```

#### Respon JSON (200 OK):
```json
{
  "success": true,
  "data": {
    "id": 15,
    "clickup_task_id": "86eyd1yk8",
    "custom_id": null,
    "tiket_id": "714815",
    "name": "#714815 Mohon Sync Chatbot",
    "tipe_aplikasi": "EBESHA",
    "aplikasi": "Royal Safari Garden",
    "status": "Open",
    "description": "selamat malam tim boleh di cek chatbot auto resolvednya...",
    "requestor_name": "RSG",
    "resolution": "Sudah dibantu agar ter resolve otomatis",
    "technician": "LMD - Louis",
    "category": "Service Error -> Web Service",
    "subcategory": "Royal Safari Garden",
    "item": "eBesha -> Ebesha CRM -> Service Error",
    "priority": "MEDIUM",
    "due_by_time": "Jun 14, 2026 09:41 AM",
    "created_time": "Jun 13, 2026 09:41 PM",
    "resolved_time": "Jul 29, 2026 12:00 AM",
    "completed_time": null,
    "overdue_status": "false",
    "resolved_overdue": "false",
    "time_elapsed": "00:00:00",
    "hold_time": "00:00:00",
    "response_date": "Jun 13, 2026 09:42 PM",
    "response_due_date": "Jun 13, 2026 09:51 PM",
    "sla_response_time": "10",
    "resolved_due_date": "Jun 14, 2026 09:41 AM",
    "generate": "EBESHA",
    "created_at": "2026-07-29T07:34:39.000000Z",
    "updated_at": "2026-07-29T07:34:39.000000Z"
  }
}
```

---

### C. Export Semua Data Tiket (`GET /api/clickup/tasks/all`)

Mengambil seluruh baris data cache tiket (format JSON array) untuk kebutuhan dump data atau synchronizing ke database aplikasi lain.

#### Contoh Request cURL:
```bash
curl -X GET "http://127.0.0.1:8000/api/clickup/tasks/all" \
  -H "Authorization: Bearer YOUR_API_TOKEN"
```

---

## 🔄 4. Synchronize Data ClickUp API

### A. Jalankan Sync (`POST /api/clickup/sync`)

Memicu sinkronisasi data terbaru dari ClickUp Views ke database lokal.

#### Request Body (JSON):
```json
{
  "sync_token": "a30d8584-4aea-4df8-b012-65cb1dbc7a4c"
}
```
*(Jika `sync_token` tidak dikirim, backend akan meng-generate token secara otomatis).*

#### Respon JSON (200 OK):
```json
{
  "success": true,
  "status": "started",
  "sync_token": "sync-unique-uuid",
  "message": "Proses sinkronisasi telah dimulai di latar belakang.",
  "tasks": [
    {
      "id": 1,
      "clickup_task_id": "86eyex29k",
      "custom_id": null,
      "tiket_id": "LMD/2026/7/6925",
      "name": "LMD/2026/7/6925 BSB - Perubahan data akun karyawan cabang",
      "tipe_aplikasi": "EBESHA",
      "aplikasi": "BANK SUMSEL BABEL",
      "status": "Closed",
      "created_time": "Jul 29, 2026 02:04 PM",
      "resolved_time": "Jul 29, 2026 12:00 AM",
      "created_at": "2026-07-29T12:57:15.000000Z",
      "updated_at": "2026-07-29T13:45:42.000000Z",
      "description": "Technician: LMD - Louis...",
      "requestor_name": "BANK SUMSEL BABEL",
      "resolution": "Sudah disesuaikan nama data karyawan cabang",
      "technician": "LMD - Louis",
      "category": "Check Request",
      "subcategory": "BANK SUMSEL BABEL",
      "item": "eBesha -> Ebesha CRM",
      "priority": "MEDIUM",
      "request_type": "Check Request",
      "request_status": "Closed",
      "due_by_time": "Jul 29, 2026 05:00 PM",
      "completed_time": "Jul 29, 2026 12:00 AM",
      "overdue_status": "false",
      "resolved_overdue": "false",
      "resolved_due_date": "Jul 29, 2026 05:00 PM",
      "group": "L1 Group",
      "generate": "EBESHA",
      "time_elapsed": "00:00:00",
      "hold_time": "00:00:00",
      "actual_time": "00:00:00",
      "response_overdue": "false",
      "response_date": "Jul 29, 2026 02:05 PM",
      "response_due_date": "Jul 29, 2026 02:14 PM",
      "sla_response_time": "10",
      "sla_resolved_time": "54:00:00"
    }
  ]
}
```

---

### B. Progress Monitoring Sync (`GET /api/clickup/sync/{syncToken}/progress`)

Memantau status dan persentase progress sync yang sedang berjalan.

#### Contoh Request cURL:
```bash
curl -X GET "http://127.0.0.1:8000/api/clickup/sync/sync-unique-uuid/progress" \
  -H "Authorization: Bearer YOUR_API_TOKEN"
```

#### Respon JSON (200 OK):
```json
{
  "success": true,
  "data": {
    "sync_token": "sync-unique-uuid",
    "status": "running",
    "summary": {
      "total_modules": 1,
      "completed_modules": 0,
      "fetched_tasks": 500,
      "cached_tasks": 500,
      "progress_percent": 50
    },
    "modules": [
      {
        "module_name": "IT TICKET LIST",
        "page": 5,
        "pages": 20,
        "fetched": 500,
        "cached": 500,
        "status": "running"
      }
    ]
  }
}
```

---

## 📥 5. Import Excel File & Monitoring Progress API

### A. Preview File Excel (`POST /api/clickup/import/upload-preview`)

Mengunggah file Excel/CSV untuk di-parse dan dicek status duplikatnya sebelum di-submit.

#### Request Body (multipart/form-data):
- `file`: File Excel (`.xlsx`, `.xls`, `.csv`)
- `source_format`: `ebesha` atau `sdp`

#### Contoh Request cURL:
```bash
curl -X POST "http://127.0.0.1:8000/api/clickup/import/upload-preview" \
  -H "Authorization: Bearer YOUR_API_TOKEN" \
  -F "file=@/path/to/tiket_import.xlsx" \
  -F "source_format=ebesha"
```

#### Respon JSON (200 OK):
```json
{
  "success": true,
  "data": {
    "source_format": "ebesha",
    "detected_headers": ["Nomor Tiket", "Judul Tiket", "Account", "Status"],
    "total_rows": 434,
    "ready_rows": 300,
    "duplicate_rows": 134,
    "rows": [
      {
        "nomor_tiket": "714815",
        "review_status": "duplicate",
        "review_reason": "Tiket sudah ada di cache lokal (akan di-update ke ClickUp)"
      }
    ]
  }
}
```

---

### B. Submit Import Data (`POST /api/clickup/import`)

Mengirim data tiket hasil preview ke ClickUp API dan menyimpan cache ke DB lokal.

#### Request Body (JSON):
```json
{
  "source_format": "ebesha",
  "import_token": "import-token-uuid-1234",
  "rows": [
    {
      "nomor_tiket": "714815",
      "name": "Mohon Sync Chatbot",
      "status": "Open",
      "account": "Royal Safari Garden",
      "technician": "LMD - Louis"
    }
  ]
}
```

#### Contoh Request cURL:
```bash
curl -X POST "http://127.0.0.1:8000/api/clickup/import" \
  -H "Authorization: Bearer YOUR_API_TOKEN" \
  -H "Content-Type: application/json" \
  -d '{
    "source_format": "ebesha",
    "import_token": "import-token-uuid-1234",
    "rows": []
  }'
```

---

### C. Monitoring Progress Loading Bar Import (`GET /api/clickup/import/{importToken}/progress`)

Endpoint real-time untuk memantau progress persentase loading bar dan jumlah baris tiket yang sudah berhasil di-import.

#### Contoh Request cURL:
```bash
curl -X GET "http://127.0.0.1:8000/api/clickup/import/import-token-uuid-1234/progress" \
  -H "Authorization: Bearer YOUR_API_TOKEN"
```

#### Respon JSON (200 OK):
```json
{
  "success": true,
  "data": {
    "import_token": "import-token-uuid-1234",
    "status": "running",
    "processed_rows": 145,
    "total_rows": 434,
    "progress_percent": 33
  }
}
```

---

## 🔀 6. Import Routing Rules API

Digunakan untuk mengelola aturan routing kolom Excel ke modul target ClickUp.

- `GET /api/clickup/rules` - Ambil daftar semua aturan routing.
- `POST /api/clickup/rules` - Tambah aturan baru.
  - **Body JSON**: `{"source_format": "ebesha", "excel_field": "Account", "excel_value": "Royal safari garden", "target_module": "CAFEINS"}`
- `DELETE /api/clickup/rules/{id}` - Hapus aturan routing berdasarkan ID.

---

## ⚙️ 7. Module Settings API (ClickUp Views & List IDs)

Digunakan untuk menambah/mengubah target ClickUp View ID & List ID.

- `GET /api/clickup/modules` - Ambil daftar modul.
- `POST /api/clickup/modules` - Tambah modul baru.
  - **Body JSON**: `{"module_name": "CAFEINS", "clickup_view_id": "view_123", "clickup_list_id": "list_123", "is_active": true}`
- `PUT /api/clickup/modules/{id}` - Update modul.
- `DELETE /api/clickup/modules/{id}` - Hapus modul.

---

## 👤 8. Technician Mappings API

Digunakan untuk memetakan nama/inisial teknisi mentah dari Excel/ClickUp ke nama standar resmi.

- `GET /api/clickup/technician-mappings` - Ambil daftar pemetaan teknisi.
- `POST /api/clickup/technician-mappings` - Tambah pemetaan teknisi.
  - **Body JSON**: `{"original_name": "Louis", "mapped_name": "LMD - Louis"}`
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
