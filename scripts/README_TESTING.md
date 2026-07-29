# 🛡️ Dokumentasi Pentest & Stress Test - ClickUp Automate System

Folder ini berisi skrip pengujian keamanan (**Penetration Testing**) dan performa (**Stress Testing**) otomatis untuk REST API ClickUp Automate. 

Skrip ini dibuat menggunakan **Python 3 Standard Library**, sehingga **tidak memerlukan install modul external (pip)** dan dapat langsung dijalankan di sistem operasi Windows, Linux, maupun macOS.

---

## 🔒 1. Penetration Testing Script (`pentest.py`)

Skrip ini secara otomatis memindai dan menguji celah keamanan pada endpoint REST API.

### Fitur Kategori Pengujian:
1. **Authentication & Authorization**: Memeriksa penolakan request tanpa token, token palsu, serta pengujian header `X-Api-Key` & `Authorization: Bearer`.
2. **Injection Attacks (SQLi & XSS)**: Pengujian payload SQL Injection pada query parameters (`?search=`, `?technician=`) dan pengecekan proteksi Reflected XSS.
3. **Path Traversal & BOLA**: Pengujian ekstraksi file sensitif (`/../.env`, `/../../etc/passwd`) dan parameter ID tidak valid.
4. **Security Headers Audit**: Verifikasi keberadaan header `X-Frame-Options`, `X-Content-Type-Options`, dan pencegahan *Information Disclosure* versi server.
5. **HTTP Verb Tampering & Payload Limit**: Memeriksa pembatasan method HTTP (seperti `TRACE`) serta ketahanan terhadap payload berukuran besar (2MB+ DoS protection).

### Cara Menjalankan Pentest:

```bash
# 1. Menjalankan pentest ke localhost (Token otomatis dibaca dari file .env jika ada)
python scripts/pentest.py

# 2. Menjalankan dengan URL target & Token spesifik
python scripts/pentest.py --url http://localhost:8000 --token "YOUR_API_BEARER_TOKEN"

# 3. Menjalankan dengan mode verbose (log detail debug)
python scripts/pentest.py --url http://localhost:8000 --verbose
```

---

## ⚡ 2. Stress & Load Testing Script (`stresstest.py`)

Skrip ini mensimulasikan beban kerja tinggi dengan mengirimkan ratusan hingga ribuan request secara bersamaan (*multi-threaded concurrency*) untuk mengukur performa server (RPS, Latensi Percentile P50/P90/P95/P99, & Error Rate).

### Fitur Metrik Performa:
- **Requests Per Second (RPS / Throughput)**.
- **Breakdown HTTP Status Code** (distribusi 2xx, 4xx, 5xx).
- **Latensi Detail**: Minimum, Maximum, Average, Median (P50), P90, P95, dan P99 Percentiles.
- **Penilaian Kesehatan Server (Health Assessment)**.

### Cara Menjalankan Stress Test:

```bash
# 1. Stress test default (20 pekerja bersamaan, total 200 request ke endpoint task list)
python scripts/stresstest.py --url http://localhost:8000/api/clickup/tasks

# 2. Stress test skala sedang (50 pekerja bersamaan, total 1,000 request)
python scripts/stresstest.py --url http://localhost:8000/api/clickup/dashboard -c 50 -n 1000

# 3. Stress test skala tinggi (100 pekerja bersamaan, total 2,000 request)
python scripts/stresstest.py --url http://localhost:8000/api/clickup/overview -c 100 -n 2000

# 4. Stress test POST Endpoint dengan Body JSON
python scripts/stresstest.py --url http://localhost:8000/api/clickup/modules --method POST --body '{"module_name":"TEST_LOAD"}' -c 10 -n 100
```

---

## 💡 Rekomendasi & Best Practices

1. **Lingkungan Pengujian**: Jalankan Stress Test **HANYA** di server lokal, staging, atau lingkungan sandbox development untuk menghindari mengganggu operasional live production.
2. **Evaluasi Pentest**: Jika skrip Pentest menemukan status `[VULN: HIGH]` atau `[VULN: MED]`, segera periksa middleware `CheckApiAuth` dan sanitasi input query pada Controller terkait.
3. **Analisis Latensi Stress Test**:
   - **P95 < 500ms**: Performa Server Optimal.
   - **P95 > 2000ms**: Server mulai mengalami kemacetan (*bottleneck* query database atau keterbatasan worker PHP-FPM / Laravel).
