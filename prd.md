Sip, ini logika alurnya makin matang dan rapi. Jadi sistem kita gak cuma jadi jembatan numpang lewat, tapi punya database lokal sendiri buat nge-cache dan nge-sync data dari ClickUp.

Dengan cara ini, pas lu import file Excel, sistem gak perlu capek-capek nembak API ClickUp berulang kali buat ngecek data. Cukup *query* ke database lokal aplikasi lu. Cepet, hemat limit API, dan anti-telat.

Berikut adalah update **Product Requirement Document (PRD)** khusus untuk menambahkan **Fitur Tombol Sync Manual** dan **Logika Impor Excel (Upsert: Update/Insert)**.

---

# REVISED PRODUCT REQUIREMENT DOCUMENT (PRD)

**Feature Addendum:** Master Data Sync Button & Smart Excel Import (Upsert Engine)

---

## 1. Updated System Workflow

### Alur Kerja 1: Sinkronisasi Master Data (Tombol "Sync ClickUp")

Sebelum lu melakukan import Excel, lu pencet tombol **"Sync Data"** di UI Aplikasi.

1. Sistem akan membaca daftar modul aktif (Cafeins, CMMS, Account Planning) dari tabel setting UI.
2. Sistem menjalankan looping halaman ($n+1$) ke API ClickUp untuk menarik **semua** tiket aktif dari setiap View.
3. Setiap task yang ditarik akan langsung disimpan ke database lokal aplikasi lu (`clickup_tasks_cache`).
* Jika Task ID **belum ada** di DB lokal $\rightarrow$ **Simpan baru (Insert).**
* Jika Task ID **sudah ada** di DB lokal $\rightarrow$ **Perbarui data terbaru (Update).**



### Alur Kerja 2: Proses Import Excel (Smart Upsert)

Setelah DB aplikasi lu terisi data hasil sync terbaru, baru lu upload file Excel (Nomor Tiket, Subject, Status, Aplikasi). Sistem akan memprosesnya baris demi baris:

1. **Pencarian Lokal:** Sistem mencari nomor tiket di database aplikasi lu:
```sql
SELECT id, clickup_task_id FROM clickup_tasks_cache WHERE name LIKE '%#708074%' AND tipe_aplikasi = 'CAFEINS';

```


2. **Kondisi A (Data SUDAH ADA):**
Sistem langsung mengambil `clickup_task_id` dari DB lokal, lalu mengirim request `PUT` ke ClickUp untuk meng-update status/datanya secara presisi.
3. **Kondisi B (Data BELUM ADA):**
Sistem akan mengirim request `POST` ke API ClickUp untuk **membuat tiket baru** di dalam List tersebut. Setelah tiket berhasil dibuat di ClickUp, API ClickUp akan mengembalikan ID Task baru. Sistem lu langsung menyimpan data tiket baru tersebut ke DB lokal aplikasi lu agar sinkron.

---

## 2. Database Schema Requirements (Tambahan Tabel Cache)

Lu perlu satu tabel tambahan untuk menampung hasil sinkronisasi dari tombol tersebut.

### Tabel `clickup_tasks_cache`

```php
Schema::create('clickup_tasks_cache', function (Blueprint $table) {
    $table->id();
    $table->string('clickup_task_id')->unique(); // ID internal ClickUp (misal: 86ey2ydhx)
    $table->string('custom_id')->nullable();     // Custom ID project (misal: SPRT-864)
    $table->string('name');                      // Judul lengkap (misal: #708074 CAFEINS - Nojar tidak muncul)
    $table->string('tipe_aplikasi');             // Mapping modul (CAFEINS, CMMS, dll)
    $table->string('status');                    // Status tiket di ClickUp (Open, Closed, dll)
    $table->timestamps();
    
    // Indexing biar query nyari nomor tiket secepat kilat
    $table->index(['name', 'tipe_aplikasi']);
});

```

---

## 3. Implementasi Kode Laravel 12

### Driver 1: Logika Tombol Sync (Menyedot data ClickUp ke DB Lokal)

```php
namespace App\Services;

use App\Models\ClickUpModule;
use App\Models\ClickUpTaskCache;
use Illuminate\Support\Facades\Http;

class ClickUpTaskSyncService
{
    /**
     * Fungsi yang dijalankan saat tombol "Sync ClickUp" diklik
     */
    public function syncAllViewsToLocal()
    {
        // 1. Ambil semua modul/view yang disetting dari UI UX
        $modules = ClickUpModule::all();
        $apiKey = config('services.clickup.api_key');

        foreach ($modules as $module) {
            $page = 0;
            $hasMoreData = true;

            while ($hasMoreData) {
                $response = Http::withHeaders([
                    'Authorization' => $apiKey,
                    'Content-Type' => 'application/json',
                ])->get("https://api.clickup.com/api/v2/view/{$module->clickup_view_id}/task", [
                    'page' => $page
                ]);

                if ($response->failed()) {
                    break;
                }

                $tasks = $response->json()['tasks'] ?? [];

                if (empty($tasks)) {
                    $hasMoreData = false;
                    break;
                }

                // 2. Lakukan Upsert ke DB Lokal Aplikasi
                foreach ($tasks as $task) {
                    ClickUpTaskCache::updateOrCreate(
                        ['clickup_task_id' => $task['id']], // Key unik pembatas
                        [
                            'custom_id'     => $task['custom_id'] ?? null,
                            'name'          => $task['name'],
                            'tipe_aplikasi' => $module->module_name, // Contoh: CAFEINS
                            'status'        => $task['status']['status'] ?? 'Open',
                        ]
                    );
                }

                $page++;
            }
        }
    }
}

```

### Driver 2: Logika Eksekusi Baris Excel (Upsert ke ClickUp)

```php
namespace App\Jobs;

use App\Models\ClickUpTaskCache;
use App\Models\ClickUpModule;
use Illuminate\Support\Facades\Http;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class ProcessExcelRowJob implements ShouldQueue
{
    use Queueable;

    protected $row; // Isinya data per baris Excel: nomor_tiket, subject, status, aplikasi

    public function __construct($row)
    {
        $this->row = $row;
    }

    public function handle()
    {
        $apiKey = config('services.clickup.api_key');
        
        // 1. Cari di DB lokal aplikasi dulu
        $localTask = ClickUpTaskCache::where('tipe_aplikasi', $this->row['aplikasi'])
            ->where('name', 'like', '%' . $this->row['nomor_tiket'] . '%')
            ->first();

        if ($localTask) {
            // KONDISI A: JIKA SUDAH ADA -> TINGGAL UPDATE DATA/STATUS KE CLICKUP
            Http::withHeaders([
                'Authorization' => $apiKey,
                'Content-Type' => 'application/json',
            ])->put("https://api.clickup.com/api/v2/task/{$localTask->clickup_task_id}", [
                'status' => $this->row['status']
                // field tambahan lainnya bisa ditaruh di sini
            ]);
            
            // Update juga cache lokal biar tetap sinkron
            $localTask->update(['status' => $this->row['status']]);

        } else {
            // KONDISI B: JIKA BELUM ADA -> BUAT TIKET BARU DI CLICKUP
            // Cari tau List ID ClickUp berdasarkan nama aplikasi
            $moduleSetting = ClickUpModule::where('module_name', $this->row['aplikasi'])->first();
            
            if ($moduleSetting) {
                // Catatan: Membuat task baru membutuhkan List ID (bukan View ID). 
                // Pastikan tabel setting UI lu juga menyimpan List ID target pembuatannya.
                $listId = $moduleSetting->clickup_list_id; 

                $response = Http::withHeaders([
                    'Authorization' => $apiKey,
                    'Content-Type' => 'application/json',
                ])->post("https://api.clickup.com/api/v2/list/{$listId}/task", [
                    'name' => '#' . $this->row['nomor_tiket'] . ' ' . $this->row['subject'],
                    'status' => $this->row['status']
                ]);

                if ($response->successful()) {
                    $newTask = $response->json();
                    
                    // Simpan data tiket baru yang barusan sukses dibuat ke DB lokal aplikasi lu
                    ClickUpTaskCache::create([
                        'clickup_task_id' => $newTask['id'],
                        'custom_id'     => $newTask['custom_id'] ?? null,
                        'name'          => $newTask['name'],
                        'tipe_aplikasi' => $this->row['aplikasi'],
                        'status'        => $newTask['status']['status'] ?? 'Open',
                    ]);
                }
            }
        }
    }
}

```

---

## 4. UI/UX Flow Layout (Tombol Baru)

Pada halaman Dashboard / Import tempat lu mengunggah file Excel, sediakan komponen berikut:

1. **Card Status Cache:** Menampilkan informasi teks: *"Terakhir di-sync: 5 Menit yang lalu (Total 248 tiket tersimpan di DB)"*.
2. **Tombol Action Button:**
* `[ Button: Sync Data ClickUp Terbaru ]` $\rightarrow$ Berwarna biru dengan indikator *loading/spinning* ketika diklik. Menjalankan `syncAllViewsToLocal()`.


3. **Form Upload Excel:**
* Berada di bawah tombol sync, digunakan untuk memproses data sheet lu setelah memastikan database lokal aplikasi lu sudah paling *update*.



Dengan arsitektur *hybrid* (Cache Lokal + Event-Driven Request) seperti ini, lu gak bakal pusing lagi sama urusan pencarian eror atau kuota limit API jebol. Semua proses perbandingan data dilakukan super cepat di server lu sendiri!