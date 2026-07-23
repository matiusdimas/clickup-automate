# Dokumentasi REST API - ClickUp Automation System

Dokumentasi ini menjelaskan secara rinci tata cara penggunaan REST API untuk integrasi dan pengawasan sistem otomatisasi ClickUp dari aplikasi lain, mencakup **Dashboard Analytics**, **Daftar & Detail Tiket (Filter Teknisi/Aplikasi)**, **Import Excel & Progress Tracking**, **Aturan Routing Field Excel**, **Pengaturan Modul (View & List ID)**, serta **Pemetaan Teknisi (Technician Mappings)**.

---

## 🔑 Autentikasi API

Semua request ke API ini dilindungi oleh middleware `CheckApiAuth`. Anda dapat menyertakan API Key / Token pada header HTTP dengan salah satu opsi berikut:

### Header Opsi 1: Authorization Bearer Token
```http
Authorization: Bearer <API_BEARER_TOKEN>
```

### Header Opsi 2: Custom Header X-Api-Key
```http
X-Api-Key: <API_BEARER_TOKEN>
```

---

## 📊 1. Dashboard Analytics API

API ini menyediakan metrik analitik lengkap, statistik modul, performa teknisi, distribusi status/prioritas, dan feed tiket terbaru secara modern & *real-time*.

### `GET /api/clickup/dashboard`

#### Query Parameters (Opsional)
| Parameter | Tipe Data | Deskripsi | Contoh |
| :--- | :--- | :--- | :--- |
| `module` | `string` | Filter berdasarkan Tipe Aplikasi / Modul | `?module=EBESHA` |
| `aplikasi` | `string` | Filter berdasarkan Detail Aplikasi Spesifik | `?aplikasi=RSG` |
| `status` | `string` | Filter berdasarkan Status Tiket | `?status=closed` |
| `technician` | `string` | Filter berdasarkan Nama/Inisial Teknisi | `?technician=LMD - Yana` |

---

## 📋 2. Task List & Detail API (Filter Teknisi, Modul, & Aplikasi)

Digunakan oleh aplikasi lain untuk mengambil daftar tiket dengan filter fleksibel atau melihat detail lengkap sebuah tiket berdasarkan ID/Nomor Tiket.

### A. List Tiket dengan Filter (`GET /api/clickup/tasks`)

#### Query Parameters (Opsional)
| Parameter | Tipe Data | Deskripsi | Contoh |
| :--- | :--- | :--- | :--- |
| `module` | `string` | Filter berdasarkan Modul / Tipe Aplikasi Utama | `?module=PSA PCA` |
| `aplikasi` | `string` | Filter berdasarkan Detail Aplikasi Spesifik | `?aplikasi=RSG` |
| `technician` | `string` | Filter tiket yang dikerjakan oleh teknisi/inisial tertentu | `?technician=LMD - Aldi` |
| `status` | `string` | Filter status tiket (`open`, `in progress`, `on hold`, `closed`) | `?status=open` |
| `search` | `string` | Pencarian kata kunci pada Judul, Nomor Tiket, atau Custom ID | `?search=717863` |
| `per_page` | `integer` | Jumlah tiket per halaman (default: 15, max: 100) | `?per_page=25` |

#### Contoh Request List Tiket Berdasarkan Teknisi & Aplikasi
```bash
curl -X GET "http://localhost:8000/api/clickup/tasks?technician=LMD%20-%20Aldi&aplikasi=PSA%20PCA" \
  -H "Authorization: Bearer YOUR_API_TOKEN" \
  -H "Accept: application/json"
```

#### Respon JSON (Success 200 OK)
```json
{
  "success": true,
  "data": {
    "current_page": 1,
    "data": [
      {
        "id": 5,
        "clickup_task_id": "86eyd1yk8",
        "tiket_id": "717863",
        "name": "#717863 Tidak muncul di PSA",
        "tipe_aplikasi": "PSA PCA",
        "aplikasi": "PSA PCA",
        "status": "open",
        "technician": "LMD - Aldi",
        "requestor_name": "Silvia Irene",
        "created_time": "Jul 23, 2026 01:54 PM",
        "resolved_time": "Not Assigned",
        "updated_at": "2026-07-23T14:18:58.000000Z"
      }
    ],
    "total": 1
  }
}
```

---

### B. Detail Lengkap Tiket (`GET /api/clickup/tasks/{id}`)

Melihat detail lengkap satu tiket. `{id}` dapat berupa **ID Database** (misal `5`), **ClickUp Task ID** (misal `86eyd1yk8`), atau **Nomor Tiket** (misal `717863`).

#### Contoh Request
```bash
curl -X GET "http://localhost:8000/api/clickup/tasks/717863" \
  -H "Authorization: Bearer YOUR_API_TOKEN" \
  -H "Accept: application/json"
```

#### Respon JSON Detail Lengkap (Success 200 OK)
```json
{
  "success": true,
  "data": {
    "id": 5,
    "clickup_task_id": "86eyd1yk8",
    "custom_id": null,
    "tiket_id": "717863",
    "name": "#717863 Tidak muncul di PSA",
    "tipe_aplikasi": "PSA PCA",
    "aplikasi": "PSA PCA",
    "status": "open",
    "description": "Mohon bantuannya untuk pengecekan data PSA di account saya...",
    "requestor_name": "Silvia Irene",
    "resolution": "Not Assigned",
    "technician": "LMD - Aldi",
    "category": "Customer Complaint",
    "subcategory": "PSA-PCA",
    "item": "Not Assigned",
    "priority": "MEDIUM",
    "due_by_time": "Jul 28, 2026 01:54 PM",
    "created_time": "Jul 23, 2026 01:54 PM",
    "resolved_time": "Not Assigned",
    "completed_time": "Not Assigned",
    "overdue_status": "false",
    "resolved_overdue": "false",
    "group": "L1 Group",
    "generate": "SDP",
    "time_elapsed": "00:00:00",
    "hold_time": "00:00:00",
    "actual_time": "00:00:00",
    "response_date": "Jul 23, 2026 02:03 PM",
    "response_due_date": "Jul 23, 2026 02:24 PM",
    "sla_response_time": "00:30:00",
    "sla_resolved_time": "27:00:00",
    "created_at": "2026-07-23T12:57:47.000000Z",
    "updated_at": "2026-07-23T14:18:58.000000Z"
  }
}
```

---

## 📂 3. Import Excel File & Progress Bar API

API untuk mengunggah file Excel, mengecek preview, melakukan submit import, serta memantau persentase loading bar secara real-time.

### A. Preview Excel File (`POST /api/clickup/import/upload-preview`)
### B. Submit Import (`POST /api/clickup/import`)
### C. Cek Progress Loading Bar (`GET /api/clickup/import/{importToken}/progress`)

---

## 🔀 4. Import Routing Rules API (Aturan Routing Field Excel)

- `GET /api/clickup/rules` - Get list aturan routing
- `POST /api/clickup/rules` - Tambah aturan routing baru
- `DELETE /api/clickup/rules/{id}` - Hapus aturan routing

---

## ⚙️ 5. Module Settings API (Simpan View & List ID)

- `GET /api/clickup/modules` - Get list modul
- `POST /api/clickup/modules` - Tambah modul baru (`module_name`, `clickup_view_id`, `clickup_list_id`)
- `PUT /api/clickup/modules/{id}` - Update modul
- `DELETE /api/clickup/modules/{id}` - Hapus modul

---

## 👤 6. Technician Mappings API (Pemetaan Nama Teknisi Kotor -> Nama Valid)

- `GET /api/clickup/technician-mappings` - Get list pemetaan teknisi
- `POST /api/clickup/technician-mappings` - Tambah/update pemetaan teknisi (`original_name` -> `mapped_name`)
- `GET /api/clickup/technician-mappings/{id}` - Detail pemetaan
- `PUT /api/clickup/technician-mappings/{id}` - Update pemetaan
- `DELETE /api/clickup/technician-mappings/{id}` - Hapus pemetaan

---

*Dokumentasi ini diperbarui secara otomatis untuk ClickUp Automate System 2026.*
