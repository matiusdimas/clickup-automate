import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Head } from '@inertiajs/react';
import { useEffect, useMemo, useState } from 'react';
import * as XLSX from 'xlsx';

const apiBase = typeof window !== 'undefined' && window.location.pathname.startsWith('/clickup')
    ? '/clickup/api/clickup'
    : '/api/clickup';

const apiFetch = async (url, options = {}) => {
    const getXsrfToken = () => {
        if (typeof document === 'undefined') return null;
        const match = document.cookie.match(new RegExp('(^| )XSRF-TOKEN=([^;]+)'));
        return match ? decodeURIComponent(match[2]) : null;
    };

    const headers = {
        ...options.headers,
        'X-Requested-With': 'XMLHttpRequest',
    };

    const xsrfToken = getXsrfToken();
    if (xsrfToken) {
        headers['X-XSRF-TOKEN'] = xsrfToken;
    }

    return fetch(url, {
        ...options,
        headers,
        credentials: 'same-origin',
    });
};

const initialModuleForm = {
    module_name: '',
    clickup_view_id: '',
    clickup_list_id: '',
    is_active: true,
};

const initialRuleForm = {
    excel_field: '',
    excel_value: '',
    target_module: '',
    source_format: 'ebesha',
};

const formatDateTime = (value) => {
    if (!value) {
        return '-';
    }

    return new Intl.DateTimeFormat('id-ID', {
        dateStyle: 'medium',
        timeStyle: 'short',
    }).format(new Date(value));
};

const normalizeHeader = (header) =>
    String(header ?? '')
        .trim()
        .toLowerCase()
        .replace(/[_-]+/g, ' ')
        .replace(/\s+/g, ' ');

const pickValue = (row, aliases) => {
    for (const alias of aliases) {
        if (row[alias] !== undefined && row[alias] !== null && row[alias] !== '') {
            const val = String(row[alias]).trim();
            const lowerVal = val.toLowerCase();
            if (lowerVal !== 'null' && val !== '-' && val !== '') {
                return row[alias];
            }
        }
    }

    return '';
};

const parseSdpDate = (val) => {
    if (!val || val === '-') return val;
    let cleanVal = String(val).trim();
    // Truncate microseconds like .230077 to 3 digits .230 for Date parsing compatibility
    cleanVal = cleanVal.replace(/(\.\d{3})\d+/, '$1');
    if (cleanVal.includes('-') && cleanVal.includes(':') && !cleanVal.includes('T')) {
        cleanVal = cleanVal.replace(' ', 'T');
    }
    const date = new Date(cleanVal);
    if (isNaN(date.getTime())) return val;
    const months = ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'];
    const month = months[date.getMonth()];
    const day = String(date.getDate()).padStart(2, '0');
    const year = date.getFullYear();
    let hours = date.getHours();
    const minutes = String(date.getMinutes()).padStart(2, '0');
    const ampm = hours >= 12 ? 'PM' : 'AM';
    hours = hours % 12;
    hours = hours ? hours : 12;
    const strHours = String(hours).padStart(2, '0');
    return `${month} ${day}, ${year} ${strHours}:${minutes} ${ampm}`;
};

const parseOverdueStatus = (val) => {
    if (!val || val === '-' || val.toLowerCase() === 'false' || val.toLowerCase() === 'no' || val.toLowerCase() === 'null') return 'false';
    return 'true';
};

const normalizeRow = (row, rules = [], techMappings = []) => {
    const normalized = Object.entries(row).reduce((carry, [key, value]) => {
        carry[normalizeHeader(key)] = typeof value === 'string' ? value.trim() : value;
        return carry;
    }, {});

    let aplikasi = String(pickValue(normalized, ['aplikasi', 'module', 'tipe aplikasi', 'subcategory', 'category'])).trim().toUpperCase();

    // Apply dynamic routing rules: newer rules overwrite older ones
    for (const rule of rules) {
        const ruleField = normalizeHeader(rule.excel_field);
        const rowVal = normalized[ruleField];
        if (rowVal !== undefined && rowVal !== null && rowVal !== '') {
            if (String(rowVal).trim().toLowerCase() === String(rule.excel_value).trim().toLowerCase()) {
                aplikasi = String(rule.target_module).trim().toUpperCase();
                // Do not break here to allow newer rules to act as exceptions
            }
        }
    }

    const rawStatus = String(pickValue(normalized, ['request status', 'status'])).trim();
    const statusLower = rawStatus.toLowerCase();
    let mappedStatus = rawStatus || 'open';
    if (statusLower === 'resolved') {
        mappedStatus = 'closed';
    } else if (statusLower === 'stopclock' || statusLower === 'stop clock' || statusLower === 'on-hold' || statusLower === 'on_hold' || statusLower === 'on hold') {
        mappedStatus = 'on hold';
    } else if (statusLower === 'in-progress' || statusLower === 'in_progress' || statusLower === 'in progress') {
        mappedStatus = 'in progress';
    }

    let tech = pickValue(normalized, ['inisial time', 'initial time', 'inisial', 'initial', 'inisial teknisi', 'technician initial', 'technician', 'nama teknisi', 'created_by', 'created by']) || '';
    if (techMappings.length > 0 && tech) {
        const mapping = techMappings.find(m => m.original_name.toLowerCase() === tech.toLowerCase());
        if (mapping) {
            tech = mapping.mapped_name;
        }
    }

    return {
        ...row, // Preserve all original unmapped fields for the backend
        nomor_tiket: String(
            pickValue(normalized, [
                'nomor tiket',
                'ticket number',
                'ticket',
                'no tiket',
                'request id',
                'tiket id',
            ]),
        ).trim(),
        subject: String(pickValue(normalized, ['subject', 'judul', 'title'])).trim(),
        status: mappedStatus,
        aplikasi,
        aplikasi_detail: pickValue(normalized, ['account', 'tenant', 'origin', 'aplikasi_detail', 'detail_aplikasi']) || aplikasi,
        description: pickValue(normalized, ['description', 'deskripsi']) || '',
        requestor_name: pickValue(normalized, ['requestor name', 'requestor', 'requester name', 'requester', 'nama requestor']) || '',
        resolution: pickValue(normalized, ['resolution', 'resolusi', 'solution']) || '',
        created_time: parseSdpDate(pickValue(normalized, ['created time', 'created date', 'created at', 'waktu dibuat']) || ''),
        resolved_time: parseSdpDate(pickValue(normalized, ['resolved time', 'resolved date', 'solved time', 'solved date', 'waktu selesai']) || ''),

        // Expose new fields for preview
        technician: tech,
        category: pickValue(normalized, ['request type', 'category']) || '',
        subcategory: pickValue(normalized, ['subcategory', 'subkategori', 'account']) || '',
        item: pickValue(normalized, ['item', 'service category']) || '',
        priority: pickValue(normalized, ['priority', 'prioritas']) || '',
        due_by_time: parseSdpDate(pickValue(normalized, ['due by time', 'dueby time', 'resolved due date', 'tanggal jatuh tempo']) || ''),
        overdue_status: parseOverdueStatus(pickValue(normalized, ['overdue status', 'resolved overdue', 'status overdue']) || ''),
        actual_time: pickValue(normalized, ['actual time', 'time elapsed']) || '',
        hold_time: pickValue(normalized, ['hold time', 'onhold time']) || '00:00:00',
        response_date: parseSdpDate(pickValue(normalized, ['response date', 'responded date']) || ''),
        response_due_date: parseSdpDate(pickValue(normalized, ['response dueby time', 'response due date']) || ''),
        response_overdue: parseOverdueStatus(pickValue(normalized, ['first response overdue status', 'response overdue']) || ''),
        overdue_by: ((val) => (!val || val === '-' || val.toLowerCase() === 'null' ? 'false' : val))(pickValue(normalized, ['overdue by', 'sla violated technician', 'fr sla violated technician'])),
    };
};

const readExcelFile = async (file, rules = [], techMappings = []) => {
    const buffer = await file.arrayBuffer();
    const workbook = XLSX.read(buffer, { type: 'array' });
    const sheetName = workbook.SheetNames[0];

    if (!sheetName) {
        return { rows: [], headers: [] };
    }

    const worksheet = workbook.Sheets[sheetName];
    const rawArray = XLSX.utils.sheet_to_json(worksheet, { header: 1, defval: '' });

    let headerRowIndex = 0;
    for (let i = 0; i < Math.min(20, rawArray.length); i++) {
        const rowString = rawArray[i].map(String).join(' ').toLowerCase();
        if (rowString.includes('request id') || rowString.includes('nomor tiket') || rowString.includes('ticket number') || rowString.includes('ticket_number') || rowString.includes('subject')) {
            headerRowIndex = i;
            break;
        }
    }

    const rawRows = XLSX.utils.sheet_to_json(worksheet, { defval: '', range: headerRowIndex });

    // Extract original header names from first row
    const headers = rawRows.length > 0
        ? Object.keys(rawRows[0]).map((h) => String(h).trim()).filter(Boolean)
        : [];

    const rows = rawRows
        .map((row) => normalizeRow(row, rules, techMappings))
        .filter((row) => row.nomor_tiket && row.aplikasi);

    return { rows, headers };
};

export default function Dashboard() {
    const [overview, setOverview] = useState(null);
    const [loading, setLoading] = useState(true);
    const [pageError, setPageError] = useState('');
    const [actionMessage, setActionMessage] = useState('');
    const [syncing, setSyncing] = useState(false);
    const [moduleSaving, setModuleSaving] = useState(false);
    const [moduleForm, setModuleForm] = useState(initialModuleForm);
    const [editingModuleId, setEditingModuleId] = useState(null);
    const [importing, setImporting] = useState(false);
    const [importFileName, setImportFileName] = useState('');
    const [importPreview, setImportPreview] = useState([]);
    const [importResult, setImportResult] = useState(null);
    const [selectedModule, setSelectedModule] = useState('all');
    const [syncProgress, setSyncProgress] = useState(null);
    const [rules, setRules] = useState([]);
    const [techMappings, setTechMappings] = useState([]);
    const [detectedHeaders, setDetectedHeaders] = useState([]);
    const [ruleForm, setRuleForm] = useState(initialRuleForm);
    const [techMappingForm, setTechMappingForm] = useState({ original_name: '', mapped_name: '' });
    const [ruleSaving, setRuleSaving] = useState(false);
    const [techMappingSaving, setTechMappingSaving] = useState(false);
    const [importSource, setImportSource] = useState('ebesha');

    const loadOverview = async () => {
        setLoading(true);
        setPageError('');

        try {
            const response = await apiFetch(`${apiBase}/overview`);
            const payload = await response.json();

            if (!response.ok || !payload.success) {
                throw new Error(payload.message || 'Gagal memuat data ClickUp.');
            }

            setOverview(payload);
        } catch (error) {
            setPageError(error.message);
        } finally {
            setLoading(false);
        }
    };

    const loadRules = async () => {
        try {
            const response = await apiFetch(`${apiBase}/rules`);
            const payload = await response.json();
            if (response.ok && payload.success) {
                setRules(payload.data ?? []);
            }
        } catch {
            // Silently ignore rule load errors
        }
    };

    const loadTechMappings = async () => {
        try {
            const response = await apiFetch(`${apiBase}/technician-mappings`);
            const payload = await response.json();
            if (response.ok) {
                setTechMappings(payload ?? []);
            }
        } catch {
            // Silently ignore errors
        }
    };

    useEffect(() => {
        loadOverview();
        loadRules();
        loadTechMappings();
    }, []);

    const modules = overview?.modules ?? [];
    const recentTasks = overview?.recent_tasks ?? [];

    const filteredTasks = useMemo(
        () =>
            selectedModule === 'all'
                ? recentTasks
                : recentTasks.filter((task) => task.tipe_aplikasi === selectedModule),
        [recentTasks, selectedModule],
    );

    const resetModuleForm = () => {
        setModuleForm(initialModuleForm);
        setEditingModuleId(null);
    };

    const resetImportState = () => {
        setImportFileName('');
        setImportPreview([]);
        setImportResult(null);
    };

    const mapAppCategory = (appName) => {
        const map = {
            'cafeins': 'bbe04f86-d669-4216-9d74-50b06d57c920',
            'sales mastes': '66596674-9673-4b1a-be26-6192038774dc',
            'cmms': 'ed3788b1-277c-4c32-b130-9379356ee3e0',
            'myla': 'f80b800d-54aa-4389-b2fb-cdf4c623f72a',
            'psa pca': 'd053f47c-d816-4caf-b91c-88372f9d3b27',
            'pmois': 'cfb962e5-71f3-4609-9b56-c8b784ccb325',
            'doc tracking': '655932d5-1747-442e-9619-e74f44592cc2',
            'starla': 'b015af83-26e5-48eb-bedc-d326c9145ab8',
            'ebesha': '730a53d7-3658-4fd6-aa4e-89fa91bf3a1b',
            'ultima & starlink': 'acc57591-7221-4118-8e03-d366d9a76be4',
            'gntu': '099e4653-4973-4da1-8cb4-ec709af6f812',
            'jarin': 'd225ea0a-7258-4d94-b952-6dc73b33dc01',
        };
        return map[String(appName || '').toLowerCase().trim()] || null;
    };

    const reviewImportRow = (row, moduleLookup, cachedTiketIds) => {
        const issues = [];
        const isDuplicate = row.nomor_tiket && row.aplikasi && cachedTiketIds.has(`${row.aplikasi}::${row.nomor_tiket}`);
        const primaryModule = Object.values(moduleLookup)[0];

        if (!row.nomor_tiket) {
            issues.push('Nomor tiket kosong');
        }

        if (row.aplikasi && !mapAppCategory(row.aplikasi)) {
            issues.push(`Nama aplikasi (${row.aplikasi}) tidak ditemukan di daftar dropdown Apps`);
        }

        if (!primaryModule) {
            issues.push('Belum ada Module aktif yang terkonfigurasi di sistem (harap buat 1 module)');
        } else if (!primaryModule.clickup_list_id) {
            issues.push('List ID module belum tersimpan, akan di-resolve otomatis saat submit');
        }

        if (isDuplicate) {
            issues.push('Tiket sudah ada di cache (akan di-update)');
        }

        const status = !primaryModule || (row.aplikasi && !mapAppCategory(row.aplikasi))
            ? 'skip'
            : isDuplicate
                ? 'duplicate'
                : primaryModule.clickup_list_id
                    ? 'ready'
                    : 'warn';

        return {
            ...row,
            is_duplicate: isDuplicate,
            review_status: status,
            review_reason: issues.length > 0 ? issues.join(', ') : 'Siap di-submit (baru)',
        };
    };

    const fetchSyncProgress = async (syncToken) => {
        const response = await apiFetch(`${apiBase}/sync/${syncToken}/progress`);
        const payload = await response.json();

        if (!response.ok || !payload.success) {
            throw new Error(payload.message || 'Gagal mengambil progress sync.');
        }

        setSyncProgress(payload.data);
        return payload.data;
    };

    const handleImportFile = async (event) => {
        const file = event.target.files?.[0];

        if (!file) {
            return;
        }

        setImporting(true);
        setActionMessage('');
        setImportResult(null);

        try {
            const rulesToApply = rules.filter((r) => r.source_format === importSource);
            const { rows, headers } = await readExcelFile(file, rulesToApply, techMappings);
            const moduleLookup = modules.reduce((carry, module) => {
                carry[String(module.module_name).trim().toUpperCase()] = {
                    clickup_list_id: module.clickup_list_id,
                };

                return carry;
            }, {});

            // Build a set of existing tiket_id+tipe_aplikasi from cached tasks for duplicate detection
            const cachedTiketIds = new Set(
                recentTasks
                    .filter((t) => t.tiket_id)
                    .map((t) => `${t.tipe_aplikasi}::${t.tiket_id}`),
            );

            const reviewedRows = rows.map((row) => reviewImportRow(row, moduleLookup, cachedTiketIds));

            if (reviewedRows.length === 0) {
                throw new Error('File Excel tidak berisi baris tiket yang valid.');
            }

            // Store detected column headers for use in rule creation dropdown
            if (headers.length > 0) {
                setDetectedHeaders(headers);
            }

            setImportFileName(file.name);
            setImportPreview(reviewedRows);
            setActionMessage(`File siap direview. Total ${reviewedRows.length} baris ditemukan. Kolom terdeteksi: ${headers.join(', ')}.`);
        } catch (error) {
            resetImportState();
            setActionMessage(error.message);
        } finally {
            setImporting(false);
            event.target.value = '';
        }
    };

    const addRule = async (event) => {
        event.preventDefault();
        if (!ruleForm.excel_field || !ruleForm.excel_value || !ruleForm.target_module) {
            return;
        }
        setRuleSaving(true);
        try {
            const response = await apiFetch(`${apiBase}/rules`, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify(ruleForm),
            });
            const payload = await response.json();
            if (!response.ok || !payload.success) {
                throw new Error(payload.message || 'Gagal menyimpan rule.');
            }
            setRuleForm(initialRuleForm);
            await loadRules();
        } catch (error) {
            setActionMessage(error.message);
        } finally {
            setRuleSaving(false);
        }
    };

    const deleteRule = async (ruleId) => {
        if (!window.confirm('Hapus rule ini?')) return;
        try {
            const response = await apiFetch(`${apiBase}/rules/${ruleId}`, { method: 'DELETE' });
            const payload = await response.json();
            if (!response.ok || !payload.success) {
                throw new Error(payload.message || 'Gagal menghapus rule.');
            }
            await loadRules();
        } catch (error) {
            setActionMessage(error.message);
        }
    };

    const addTechMapping = async (e) => {
        e.preventDefault();
        setTechMappingSaving(true);
        try {
            const response = await apiFetch(`${apiBase}/technician-mappings`, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify(techMappingForm),
            });

            if (response.ok) {
                setTechMappingForm({ original_name: '', mapped_name: '' });
                loadTechMappings();
                showActionMessage('Technician mapping berhasil ditambahkan.');
            } else {
                const errorData = await response.json();
                showActionMessage(`Gagal menambahkan mapping: ${errorData.message}`);
            }
        } catch (error) {
            showActionMessage('Gagal menghubungi server.');
        } finally {
            setTechMappingSaving(false);
        }
    };

    const deleteTechMapping = async (id) => {
        if (!confirm('Hapus mapping ini?')) return;
        try {
            const response = await apiFetch(`${apiBase}/technician-mappings/${id}`, { method: 'DELETE' });
            if (response.ok) {
                loadTechMappings();
                showActionMessage('Mapping berhasil dihapus.');
            }
        } catch {
            showActionMessage('Gagal menghapus mapping.');
        }
    };

    const submitImport = async () => {
        if (importPreview.length === 0) {
            setActionMessage('Upload file dulu untuk review sebelum submit.');
            return;
        }

        setImporting(true);

        try {
            await syncClickUp(true);
        } catch (error) {
            setImporting(false);
            return;
        }

        setActionMessage('Memproses import...');

        try {
            const payloadRows = importPreview.map(({ review_status, review_reason, ...row }) => row);
            const response = await apiFetch(`${apiBase}/import`, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                },
                body: JSON.stringify({
                    rows: payloadRows,
                    source_format: importSource
                }),
            });

            const payload = await response.json();

            if (!response.ok || !payload.success) {
                throw new Error(payload.message || 'Gagal memproses import.');
            }

            setImportResult(payload.data ?? null);
            const summary = payload.data ?? {};
            setActionMessage(
                `Import selesai. Created: ${summary.created ?? 0}, Updated: ${summary.updated ?? 0}, Skipped: ${summary.skipped ?? 0}, Failed: ${summary.failed ?? 0}`,
            );
            await loadOverview();
        } catch (error) {
            setActionMessage(error.message);
        } finally {
            setImporting(false);
        }
    };

    const saveModule = async (event) => {
        event.preventDefault();
        setModuleSaving(true);
        setActionMessage('');

        const endpoint = editingModuleId
            ? `${apiBase}/modules/${editingModuleId}`
            : `${apiBase}/modules`;
        const method = editingModuleId ? 'PUT' : 'POST';

        try {
            const response = await apiFetch(endpoint, {
                method,
                headers: {
                    'Content-Type': 'application/json',
                },
                body: JSON.stringify({
                    ...moduleForm,
                    module_name: moduleForm.module_name.toUpperCase().trim(),
                    clickup_view_id: moduleForm.clickup_view_id.trim(),
                    clickup_list_id: moduleForm.clickup_list_id.trim(),
                }),
            });

            const payload = await response.json();

            if (!response.ok || !payload.success) {
                throw new Error(payload.message || 'Gagal menyimpan module.');
            }

            setActionMessage(payload.message || 'Module tersimpan.');
            resetModuleForm();
            await loadOverview();
        } catch (error) {
            setActionMessage(error.message);
        } finally {
            setModuleSaving(false);
        }
    };

    const editModule = (module) => {
        setEditingModuleId(module.id);
        setModuleForm({
            module_name: module.module_name,
            clickup_view_id: module.clickup_view_id ?? '',
            clickup_list_id: module.clickup_list_id ?? '',
            is_active: Boolean(module.is_active),
        });
    };

    const deleteModule = async (moduleId) => {
        if (!window.confirm('Hapus module ini?')) {
            return;
        }

        try {
            const response = await apiFetch(`${apiBase}/modules/${moduleId}`, {
                method: 'DELETE',
            });

            const payload = await response.json();

            if (!response.ok || !payload.success) {
                throw new Error(payload.message || 'Gagal menghapus module.');
            }

            setActionMessage(payload.message || 'Module dihapus.');
            if (editingModuleId === moduleId) {
                resetModuleForm();
            }
            await loadOverview();
        } catch (error) {
            setActionMessage(error.message);
        }
    };

    const syncClickUp = async (throwOnError = false) => {
        const syncToken = crypto.randomUUID();
        setSyncing(true);
        if (!throwOnError) {
            setActionMessage('');
        } else {
            setActionMessage('Menyinkronkan data dari ClickUp sebelum import...');
        }
        setSyncProgress({
            sync_token: syncToken,
            status: 'running',
            summary: {
                total_modules: 0,
                completed_modules: 0,
                fetched_tasks: 0,
                cached_tasks: 0,
                progress_percent: 0,
            },
            modules: [],
        });

        let polling = true;
        const timer = window.setInterval(async () => {
            if (!polling) {
                return;
            }

            try {
                const data = await fetchSyncProgress(syncToken);

                if (data?.status === 'done' || data?.status === 'failed' || data?.status === 'missing') {
                    window.clearInterval(timer);
                    polling = false;
                }
            } catch (error) {
                window.clearInterval(timer);
                polling = false;
            }
        }, 1000);

        try {
            const syncRequest = apiFetch(`${apiBase}/sync`, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                },
                body: JSON.stringify({ sync_token: syncToken }),
            });

            await fetchSyncProgress(syncToken);

            const response = await syncRequest;
            const payload = await response.json();

            if (!response.ok || !payload.success) {
                throw new Error(payload.message || 'Gagal sinkronisasi ClickUp.');
            }

            setActionMessage(payload.message || 'Sinkronisasi selesai.');
            setSyncProgress(payload.progress ?? null);
            await loadOverview();
            return true;
        } catch (error) {
            setActionMessage(error.message);
            if (throwOnError) throw error;
            return false;
        } finally {
            polling = false;
            window.clearInterval(timer);
            setSyncing(false);
        }
    };

    const summary = overview?.summary ?? {};

    return (
        <AuthenticatedLayout
            header={
                <div className="flex flex-col gap-2 md:flex-row md:items-end md:justify-between">
                    <div>
                        <p className="text-sm uppercase tracking-[0.3em] text-slate-500">
                            ClickUp Bridge
                        </p>
                        <h2 className="text-2xl font-semibold leading-tight text-slate-900">
                            REST API cache + React dashboard
                        </h2>
                    </div>
                    <p className="max-w-2xl text-sm text-slate-500">
                        Sinkronisasi data ClickUp ke cache lokal, lalu pakai endpoint JSON ini dari aplikasi lain tanpa perlu ubah workflow inti.
                    </p>
                </div>
            }
        >
            <Head title="ClickUp Bridge" />

            <div className="relative overflow-hidden bg-slate-950 text-slate-100">
                <div className="absolute inset-0 bg-[radial-gradient(circle_at_top_right,_rgba(34,197,94,0.24),_transparent_30%),radial-gradient(circle_at_bottom_left,_rgba(14,165,233,0.18),_transparent_28%)]" />
                <div className="relative mx-auto max-w-7xl space-y-6 px-4 py-10 sm:px-6 lg:px-8">
                    <section className="grid gap-4 lg:grid-cols-[1.15fr_0.85fr]">
                        <div className="rounded-3xl border border-white/10 bg-white/5 p-6 shadow-2xl shadow-black/20 backdrop-blur-xl">
                            <div className="flex flex-col gap-6 md:flex-row md:items-start md:justify-between">
                                <div className="space-y-3">
                                    <span className="inline-flex rounded-full border border-emerald-400/30 bg-emerald-400/10 px-3 py-1 text-xs font-semibold uppercase tracking-[0.28em] text-emerald-200">
                                        Local cache ready
                                    </span>
                                    <h1 className="max-w-xl text-3xl font-semibold tracking-tight text-white md:text-5xl">
                                        Sync ClickUp, import Excel, dan expose semua data lewat REST API.
                                    </h1>
                                    <p className="max-w-2xl text-sm leading-6 text-slate-300 md:text-base">
                                        Dashboard ini memantau cache lokal, menambah module target, lalu mengeksekusi sync serta upsert tiket langsung dari file Excel.
                                    </p>
                                </div>

                                <button
                                    type="button"
                                    onClick={syncClickUp}
                                    disabled={syncing}
                                    className="inline-flex items-center justify-center rounded-2xl bg-gradient-to-r from-cyan-400 to-emerald-400 px-5 py-3 text-sm font-semibold text-slate-950 transition hover:scale-[1.01] disabled:cursor-not-allowed disabled:opacity-60"
                                >
                                    {syncing ? 'Menyinkronkan...' : 'Sync Data ClickUp Terbaru'}
                                </button>
                            </div>

                            <div className="mt-6 grid gap-3 sm:grid-cols-3">
                                <div className="rounded-2xl border border-white/10 bg-slate-900/70 p-4">
                                    <p className="text-xs uppercase tracking-[0.28em] text-slate-400">Module aktif</p>
                                    <p className="mt-2 text-2xl font-semibold text-white">{summary.active_module_count ?? 0}</p>
                                </div>
                                <div className="rounded-2xl border border-white/10 bg-slate-900/70 p-4">
                                    <p className="text-xs uppercase tracking-[0.28em] text-slate-400">Total cache</p>
                                    <p className="mt-2 text-2xl font-semibold text-white">{summary.task_count ?? 0}</p>
                                </div>
                                <div className="rounded-2xl border border-white/10 bg-slate-900/70 p-4">
                                    <p className="text-xs uppercase tracking-[0.28em] text-slate-400">Terakhir sync</p>
                                    <p className="mt-2 text-sm font-medium text-white">
                                        {formatDateTime(summary.last_synced_at)}
                                    </p>
                                </div>
                            </div>
                        </div>

                        <div className="rounded-3xl border border-white/10 bg-slate-900/80 p-6 shadow-2xl shadow-black/20 backdrop-blur-xl">
                            <p className="text-xs uppercase tracking-[0.3em] text-slate-400">API status</p>
                            <div className="mt-3 space-y-3 text-sm text-slate-300">
                                <div className="flex items-center justify-between gap-4 rounded-2xl bg-white/5 px-4 py-3">
                                    <span>GET /api/clickup/overview</span>
                                    <span className="text-emerald-300">online</span>
                                </div>
                                <div className="flex items-center justify-between gap-4 rounded-2xl bg-white/5 px-4 py-3">
                                    <span>POST /api/clickup/sync</span>
                                    <span className="text-cyan-300">ready</span>
                                </div>
                                <div className="flex items-center justify-between gap-4 rounded-2xl bg-white/5 px-4 py-3">
                                    <span>POST /api/clickup/import</span>
                                    <span className="text-amber-300">ready</span>
                                </div>
                            </div>
                            <div className="mt-6 rounded-2xl border border-cyan-400/20 bg-cyan-400/10 p-4 text-sm text-cyan-100">
                                Endpoint ini bisa dipakai aplikasi lain sebagai sumber data cache lokal atau jalur import otomatis.
                            </div>
                        </div>
                    </section>

                    {syncProgress ? (
                        <section className="rounded-3xl border border-white/10 bg-white/5 p-6 shadow-xl shadow-black/20 backdrop-blur-xl">
                            <div className="flex flex-col gap-3 md:flex-row md:items-center md:justify-between">
                                <div>
                                    <p className="text-xs uppercase tracking-[0.28em] text-slate-400">
                                        Sync progress
                                    </p>
                                    <h3 className="mt-1 text-lg font-semibold text-white">
                                        {syncProgress.status === 'done' ? 'Sinkronisasi selesai' : 'Sinkronisasi berjalan'}
                                    </h3>
                                </div>
                                <div className="text-sm text-slate-300">
                                    {syncProgress.summary?.completed_modules ?? 0}/{syncProgress.summary?.total_modules ?? 0} module selesai
                                </div>
                            </div>

                            <div className="mt-4 h-3 overflow-hidden rounded-full bg-slate-900/80">
                                <div
                                    className="h-full rounded-full bg-gradient-to-r from-cyan-400 via-emerald-400 to-lime-300 transition-all duration-500"
                                    style={{ width: `${syncProgress.summary?.progress_percent ?? 0}%` }}
                                />
                            </div>

                            <div className="mt-3 flex items-center justify-between text-xs uppercase tracking-[0.24em] text-slate-400">
                                <span>
                                    {syncProgress.summary?.progress_percent ?? 0}%
                                </span>
                                <span>
                                    {syncProgress.summary?.fetched_tasks ?? 0} fetched / {syncProgress.summary?.cached_tasks ?? 0} cached
                                </span>
                            </div>

                            <div className="mt-6 grid gap-3 md:grid-cols-2 xl:grid-cols-3">
                                {(syncProgress.modules ?? []).map((module) => (
                                    <div key={module.module_name} className="rounded-2xl border border-white/10 bg-slate-950/60 p-4">
                                        <div className="flex items-start justify-between gap-3">
                                            <div>
                                                <p className="font-semibold text-white">{module.module_name}</p>
                                                <p className="mt-1 text-xs text-slate-500">page {module.page ?? 0}</p>
                                            </div>
                                            <span className="rounded-full bg-white/5 px-2 py-1 text-[11px] uppercase tracking-[0.2em] text-slate-300">
                                                {module.status}
                                            </span>
                                        </div>
                                        <div className="mt-4 grid grid-cols-3 gap-2 text-xs text-slate-400">
                                            <div>
                                                <div className="text-slate-500">pages</div>
                                                <div className="mt-1 text-white">{module.pages ?? 0}</div>
                                            </div>
                                            <div>
                                                <div className="text-slate-500">fetched</div>
                                                <div className="mt-1 text-white">{module.fetched ?? 0}</div>
                                            </div>
                                            <div>
                                                <div className="text-slate-500">cached</div>
                                                <div className="mt-1 text-white">{module.cached ?? 0}</div>
                                            </div>
                                        </div>
                                        {module.error ? (
                                            <p className="mt-3 text-xs text-rose-200">
                                                {module.error}
                                            </p>
                                        ) : null}
                                    </div>
                                ))}
                            </div>
                        </section>
                    ) : null}

                    {pageError ? (
                        <div className="rounded-2xl border border-rose-400/30 bg-rose-500/10 px-4 py-3 text-sm text-rose-100">
                            {pageError}
                        </div>
                    ) : null}

                    {actionMessage ? (
                        <div className="rounded-2xl border border-emerald-400/30 bg-emerald-500/10 px-4 py-3 text-sm text-emerald-100">
                            {actionMessage}
                        </div>
                    ) : null}

                    <section className="grid gap-6 xl:grid-cols-[0.9fr_1.1fr]">
                        <div className="rounded-3xl border border-white/10 bg-white/5 p-6 shadow-xl shadow-black/20 backdrop-blur-xl">
                            <div className="flex items-center justify-between gap-4">
                                <div>
                                    <p className="text-xs uppercase tracking-[0.28em] text-slate-400">
                                        Module setting
                                    </p>
                                    <h3 className="mt-1 text-xl font-semibold text-white">
                                        Simpan View dan List ID
                                    </h3>
                                </div>
                                <button
                                    type="button"
                                    onClick={resetModuleForm}
                                    className="rounded-full border border-white/10 px-4 py-2 text-xs font-semibold uppercase tracking-[0.22em] text-slate-300 transition hover:bg-white/10"
                                >
                                    Reset
                                </button>
                            </div>

                            <form className="mt-5 space-y-4" onSubmit={saveModule}>
                                <label className="block space-y-2">
                                    <span className="text-sm text-slate-300">Module name</span>
                                    <input
                                        value={moduleForm.module_name}
                                        onChange={(event) =>
                                            setModuleForm((previous) => ({
                                                ...previous,
                                                module_name: event.target.value.toUpperCase(),
                                            }))
                                        }
                                        className="w-full rounded-2xl border border-white/10 bg-slate-950/70 px-4 py-3 text-white outline-none ring-0 placeholder:text-slate-500 focus:border-cyan-400"
                                        placeholder="CAFEINS"
                                        required
                                    />
                                </label>

                                <label className="block space-y-2">
                                    <span className="text-sm text-slate-300">ClickUp view ID</span>
                                    <input
                                        value={moduleForm.clickup_view_id}
                                        onChange={(event) =>
                                            setModuleForm((previous) => ({
                                                ...previous,
                                                clickup_view_id: event.target.value,
                                            }))
                                        }
                                        className="w-full rounded-2xl border border-white/10 bg-slate-950/70 px-4 py-3 text-white outline-none ring-0 placeholder:text-slate-500 focus:border-cyan-400"
                                        placeholder="view_123456"
                                        required
                                    />
                                </label>

                                <label className="block space-y-2">
                                    <span className="text-sm text-slate-300">ClickUp list ID</span>
                                    <input
                                        value={moduleForm.clickup_list_id}
                                        onChange={(event) =>
                                            setModuleForm((previous) => ({
                                                ...previous,
                                                clickup_list_id: event.target.value,
                                            }))
                                        }
                                        className="w-full rounded-2xl border border-white/10 bg-slate-950/70 px-4 py-3 text-white outline-none ring-0 placeholder:text-slate-500 focus:border-cyan-400"
                                        placeholder="list_123456"
                                    />
                                </label>

                                <label className="flex items-center gap-3 rounded-2xl border border-white/10 bg-slate-950/50 px-4 py-3">
                                    <input
                                        type="checkbox"
                                        checked={moduleForm.is_active}
                                        onChange={(event) =>
                                            setModuleForm((previous) => ({
                                                ...previous,
                                                is_active: event.target.checked,
                                            }))
                                        }
                                        className="size-4 rounded border-slate-500 bg-slate-900 text-cyan-400 focus:ring-cyan-400"
                                    />
                                    <span className="text-sm text-slate-300">Module aktif</span>
                                </label>

                                <button
                                    type="submit"
                                    disabled={moduleSaving}
                                    className="w-full rounded-2xl bg-white px-4 py-3 text-sm font-semibold text-slate-950 transition hover:bg-cyan-200 disabled:cursor-not-allowed disabled:opacity-60"
                                >
                                    {moduleSaving
                                        ? 'Menyimpan...'
                                        : editingModuleId
                                            ? 'Update module'
                                            : 'Save module'}
                                </button>
                            </form>

                            <div className="mt-6 space-y-3">
                                {modules.length === 0 ? (
                                    <div className="rounded-2xl border border-dashed border-white/10 bg-slate-950/40 px-4 py-5 text-sm text-slate-400">
                                        Belum ada module. Tambahkan view dan list ID terlebih dulu supaya sync bisa berjalan.
                                    </div>
                                ) : (
                                    modules.map((module) => (
                                        <div
                                            key={module.id}
                                            className="rounded-2xl border border-white/10 bg-slate-950/50 p-4"
                                        >
                                            <div className="flex items-start justify-between gap-3">
                                                <div>
                                                    <p className="text-base font-semibold text-white">
                                                        {module.module_name}
                                                    </p>
                                                    <p className="mt-1 text-xs uppercase tracking-[0.25em] text-slate-500">
                                                        {module.clickup_view_id}
                                                    </p>
                                                </div>
                                                <span
                                                    className={`rounded-full px-3 py-1 text-xs font-semibold uppercase tracking-[0.2em] ${module.is_active ? 'bg-emerald-400/10 text-emerald-200' : 'bg-slate-400/10 text-slate-300'}`}
                                                >
                                                    {module.is_active ? 'active' : 'paused'}
                                                </span>
                                            </div>

                                            <div className="mt-3 grid gap-2 text-sm text-slate-300">
                                                <div className="flex items-center justify-between gap-3 rounded-xl bg-white/5 px-3 py-2">
                                                    <span>List ID</span>
                                                    <span className="font-medium text-white">
                                                        {module.clickup_list_id || '-'}
                                                    </span>
                                                </div>
                                                <div className="flex items-center justify-between gap-3 rounded-xl bg-white/5 px-3 py-2">
                                                    <span>Cache tiket</span>
                                                    <span className="font-medium text-white">
                                                        {module.tasks_count ?? 0}
                                                    </span>
                                                </div>
                                                <div className="flex items-center justify-between gap-3 rounded-xl bg-white/5 px-3 py-2">
                                                    <span>Last sync</span>
                                                    <span className="font-medium text-white">
                                                        {formatDateTime(module.last_synced_at)}
                                                    </span>
                                                </div>
                                            </div>

                                            <div className="mt-4 flex gap-2">
                                                <button
                                                    type="button"
                                                    onClick={() => editModule(module)}
                                                    className="rounded-full border border-cyan-400/30 bg-cyan-400/10 px-3 py-2 text-xs font-semibold uppercase tracking-[0.2em] text-cyan-100"
                                                >
                                                    Edit
                                                </button>
                                                <button
                                                    type="button"
                                                    onClick={() => deleteModule(module.id)}
                                                    className="rounded-full border border-rose-400/30 bg-rose-400/10 px-3 py-2 text-xs font-semibold uppercase tracking-[0.2em] text-rose-100"
                                                >
                                                    Delete
                                                </button>
                                            </div>
                                        </div>
                                    ))
                                )}
                            </div>
                        </div>

                        <div className="space-y-6">

                            {/* === IMPORT ROUTING RULES CARD === */}
                            <div className="rounded-3xl border border-violet-400/20 bg-violet-400/5 p-6 shadow-xl shadow-black/20 backdrop-blur-xl">
                                <div className="flex items-center justify-between gap-4">
                                    <div>
                                        <p className="text-xs uppercase tracking-[0.28em] text-violet-300">
                                            Import routing rules
                                        </p>
                                        <h3 className="mt-1 text-xl font-semibold text-white">
                                            Aturan routing field Excel
                                        </h3>
                                    </div>
                                </div>
                                <p className="mt-2 text-sm leading-6 text-slate-300">
                                    Buat aturan: jika kolom <span className="text-violet-300 font-medium">[Field]</span> berisi nilai <span className="text-violet-300 font-medium">[Value]</span>, maka baris tersebut diarahkan ke module <span className="text-violet-300 font-medium">[Module]</span>. Upload Excel dahulu agar kolom terdeteksi otomatis.
                                </p>

                                {/* Add Rule Form */}
                                <form onSubmit={addRule} className="mt-5 grid grid-cols-1 gap-3 sm:grid-cols-[1fr_1fr_1fr_auto]">
                                    <div className="space-y-1">
                                        <label className="text-xs text-slate-400">Sumber (SDP/Ebesha)</label>
                                        <select
                                            value={ruleForm.source_format}
                                            onChange={(e) => setRuleForm((prev) => ({ ...prev, source_format: e.target.value }))}
                                            className="w-full rounded-2xl border border-white/10 bg-slate-950/70 px-4 py-3 text-white outline-none focus:border-violet-400"
                                            required
                                        >
                                            <option value="ebesha">Ebesha</option>
                                            <option value="sdp">SDP</option>
                                        </select>
                                    </div>
                                    <div className="space-y-1">
                                        <label className="text-xs text-slate-400">Field (kolom Excel)</label>
                                        {detectedHeaders.length > 0 ? (
                                            <select
                                                value={ruleForm.excel_field}
                                                onChange={(e) => setRuleForm((prev) => ({ ...prev, excel_field: e.target.value }))}
                                                className="w-full rounded-2xl border border-white/10 bg-slate-950/70 px-4 py-3 text-white outline-none focus:border-violet-400"
                                                required
                                            >
                                                <option value="">-- Pilih kolom --</option>
                                                {detectedHeaders.map((h) => (
                                                    <option key={h} value={h}>{h}</option>
                                                ))}
                                            </select>
                                        ) : (
                                            <input
                                                value={ruleForm.excel_field}
                                                onChange={(e) => setRuleForm((prev) => ({ ...prev, excel_field: e.target.value }))}
                                                className="w-full rounded-2xl border border-white/10 bg-slate-950/70 px-4 py-3 text-white outline-none placeholder:text-slate-500 focus:border-violet-400"
                                                placeholder="misal: Account"
                                                required
                                            />
                                        )}
                                    </div>
                                    <div className="space-y-1">
                                        <label className="text-xs text-slate-400">Value</label>
                                        <input
                                            value={ruleForm.excel_value}
                                            onChange={(e) => setRuleForm((prev) => ({ ...prev, excel_value: e.target.value }))}
                                            className="w-full rounded-2xl border border-white/10 bg-slate-950/70 px-4 py-3 text-white outline-none placeholder:text-slate-500 focus:border-violet-400"
                                            placeholder="misal: Royal safari garden"
                                            required
                                        />
                                    </div>
                                    <div className="space-y-1">
                                        <label className="text-xs text-slate-400">Target Apps</label>
                                        <select
                                            value={ruleForm.target_module}
                                            onChange={(e) => setRuleForm((prev) => ({ ...prev, target_module: e.target.value }))}
                                            className="w-full rounded-2xl border border-white/10 bg-slate-950/70 px-4 py-3 text-white outline-none focus:border-violet-400"
                                            required
                                        >
                                            <option value="">-- Pilih Target Apps --</option>
                                            <option value="SKIP">❌ JANGAN MASUKKAN (SKIP)</option>
                                            <optgroup label="Apps List">
                                                <option value="Cafeins">Cafeins</option>
                                                <option value="Sales Mastes">Sales Mastes</option>
                                                <option value="CMMS">CMMS</option>
                                                <option value="MyLA">MyLA</option>
                                                <option value="PSA PCA">PSA PCA</option>
                                                <option value="PMOIS">PMOIS</option>
                                                <option value="Doc Tracking">Doc Tracking</option>
                                                <option value="Starla">Starla</option>
                                                <option value="eBesha">eBesha</option>
                                                <option value="Ultima & Starlink">Ultima & Starlink</option>
                                                <option value="GNTU">GNTU</option>
                                                <option value="Jarin">Jarin</option>
                                            </optgroup>
                                        </select>
                                    </div>
                                    <div className="flex items-end">
                                        <button
                                            type="submit"
                                            disabled={ruleSaving}
                                            className="w-full rounded-2xl bg-violet-500 px-4 py-3 text-sm font-semibold text-white transition hover:bg-violet-400 disabled:cursor-not-allowed disabled:opacity-60"
                                        >
                                            {ruleSaving ? 'Menyimpan...' : '+ Tambah'}
                                        </button>
                                    </div>
                                </form>

                                {/* Existing Rules List */}
                                {rules.length > 0 ? (
                                    <div className="mt-5 overflow-hidden rounded-2xl border border-white/10">
                                        <table className="min-w-full divide-y divide-white/10 text-sm">
                                            <thead className="bg-slate-950/80 text-slate-400">
                                                <tr>
                                                    <th className="px-4 py-3 text-left font-medium">Sumber</th>
                                                    <th className="px-4 py-3 text-left font-medium">Field</th>
                                                    <th className="px-4 py-3 text-left font-medium">Value</th>
                                                    <th className="px-4 py-3 text-left font-medium">→ Apps</th>
                                                    <th className="px-4 py-3 text-left font-medium"></th>
                                                </tr>
                                            </thead>
                                            <tbody className="divide-y divide-white/10 bg-slate-950/50">
                                                {rules.map((rule) => (
                                                    <tr key={rule.id} className="hover:bg-white/5">
                                                        <td className="px-4 py-3">
                                                            <span className="rounded-full bg-slate-800 px-3 py-1 text-xs font-semibold uppercase tracking-[0.1em] text-slate-300">
                                                                {rule.source_format}
                                                            </span>
                                                        </td>
                                                        <td className="px-4 py-3 text-violet-200 font-medium">{rule.excel_field}</td>
                                                        <td className="px-4 py-3 text-slate-200">{rule.excel_value}</td>
                                                        <td className="px-4 py-3">
                                                            <span className="rounded-full bg-violet-400/10 px-3 py-1 text-xs font-semibold uppercase tracking-[0.18em] text-violet-200">
                                                                {rule.target_module}
                                                            </span>
                                                        </td>
                                                        <td className="px-4 py-3">
                                                            <button
                                                                type="button"
                                                                onClick={() => deleteRule(rule.id)}
                                                                className="rounded-full border border-rose-400/30 bg-rose-400/10 px-3 py-1 text-xs font-semibold text-rose-200 hover:bg-rose-400/20"
                                                            >
                                                                Hapus
                                                            </button>
                                                        </td>
                                                    </tr>
                                                ))}
                                            </tbody>
                                        </table>
                                    </div>
                                ) : (
                                    <div className="mt-4 rounded-2xl border border-dashed border-white/10 bg-slate-950/40 px-4 py-4 text-sm text-slate-400">
                                        Belum ada rule. Tambahkan rule di atas, atau upload Excel dahulu agar kolom terdeteksi otomatis di dropdown Field.
                                    </div>
                                )}

                                {detectedHeaders.length > 0 && (
                                    <div className="mt-4 flex flex-wrap gap-2">
                                        <span className="text-xs text-slate-500">Kolom terdeteksi dari Excel:</span>
                                        {detectedHeaders.map((h) => (
                                            <span
                                                key={h}
                                                onClick={() => setRuleForm((prev) => ({ ...prev, excel_field: h }))}
                                                className="cursor-pointer rounded-full border border-violet-400/20 bg-violet-400/10 px-3 py-1 text-xs text-violet-200 transition hover:bg-violet-400/20"
                                                title={`Klik untuk isi Field dengan '${h}'`}
                                            >
                                                {h}
                                            </span>
                                        ))}
                                    </div>
                                )}
                            </div>

                            {/* === TECHNICIAN MAPPING CARD === */}
                            <div className="mt-6 rounded-3xl border border-white/10 bg-slate-900/80 p-6 shadow-xl shadow-black/20 backdrop-blur-xl">
                                <div>
                                    <p className="text-xs uppercase tracking-[0.28em] text-slate-400">
                                        Technician mappings
                                    </p>
                                    <h3 className="mt-1 text-xl font-semibold text-white">
                                        Ganti nama teknisi kotor menjadi nama valid
                                    </h3>
                                    <p className="mt-2 text-sm text-slate-300">
                                        Atur agar email atau nama acak di "Created By" pada Excel langsung dipetakan ke nama yang benar saat import.
                                    </p>
                                </div>
                                <form onSubmit={addTechMapping} className="mt-6 grid items-start gap-4 sm:grid-cols-3">
                                    <div className="space-y-1">
                                        <label className="text-xs text-slate-400">Original Name (di Excel)</label>
                                        <input
                                            value={techMappingForm.original_name}
                                            onChange={(e) => setTechMappingForm((prev) => ({ ...prev, original_name: e.target.value }))}
                                            className="w-full rounded-2xl border border-white/10 bg-slate-950/70 px-4 py-3 text-white outline-none placeholder:text-slate-500 focus:border-cyan-400"
                                            placeholder="misal: yana.nurrohman@..."
                                            required
                                        />
                                    </div>
                                    <div className="space-y-1">
                                        <label className="text-xs text-slate-400">Mapped Name (dikirim ke ClickUp)</label>
                                        <input
                                            value={techMappingForm.mapped_name}
                                            onChange={(e) => setTechMappingForm((prev) => ({ ...prev, mapped_name: e.target.value }))}
                                            className="w-full rounded-2xl border border-white/10 bg-slate-950/70 px-4 py-3 text-white outline-none placeholder:text-slate-500 focus:border-cyan-400"
                                            placeholder="misal: LMD - Yana"
                                            required
                                        />
                                    </div>
                                    <div className="flex items-end h-[74px]">
                                        <button
                                            type="submit"
                                            disabled={techMappingSaving}
                                            className="w-full rounded-2xl bg-cyan-500 px-4 py-3 text-sm font-semibold text-slate-950 transition hover:bg-cyan-400 disabled:cursor-not-allowed disabled:opacity-60"
                                        >
                                            {techMappingSaving ? 'Menyimpan...' : '+ Tambah Mapping'}
                                        </button>
                                    </div>
                                </form>

                                {techMappings.length > 0 ? (
                                    <div className="mt-5 overflow-hidden rounded-2xl border border-white/10">
                                        <table className="min-w-full divide-y divide-white/10 text-sm">
                                            <thead className="bg-slate-950/80 text-slate-400">
                                                <tr>
                                                    <th className="px-4 py-3 text-left font-medium">Original Name</th>
                                                    <th className="px-4 py-3 text-left font-medium">Mapped Name</th>
                                                    <th className="px-4 py-3 text-left font-medium"></th>
                                                </tr>
                                            </thead>
                                            <tbody className="divide-y divide-white/10 bg-slate-950/50">
                                                {techMappings.map((mapping) => (
                                                    <tr key={mapping.id} className="hover:bg-white/5">
                                                        <td className="px-4 py-3 text-slate-200">{mapping.original_name}</td>
                                                        <td className="px-4 py-3 text-cyan-200 font-medium">→ {mapping.mapped_name}</td>
                                                        <td className="px-4 py-3 text-right">
                                                            <button
                                                                type="button"
                                                                onClick={() => deleteTechMapping(mapping.id)}
                                                                className="rounded-full border border-rose-400/30 bg-rose-400/10 px-3 py-1 text-xs font-semibold text-rose-200 hover:bg-rose-400/20"
                                                            >
                                                                Hapus
                                                            </button>
                                                        </td>
                                                    </tr>
                                                ))}
                                            </tbody>
                                        </table>
                                    </div>
                                ) : (
                                    <div className="mt-4 rounded-2xl border border-dashed border-white/10 bg-slate-950/40 px-4 py-4 text-sm text-slate-400">
                                        Belum ada technician mapping.
                                    </div>
                                )}
                            </div>

                            {/* === SMART IMPORT CARD === */}
                            <div className="rounded-3xl border border-white/10 bg-slate-900/80 p-6 shadow-xl shadow-black/20 backdrop-blur-xl">
                                <div className="flex flex-col gap-4 md:flex-row md:items-center md:justify-between">
                                    <div>
                                        <p className="text-xs uppercase tracking-[0.28em] text-slate-400">
                                            Smart import
                                        </p>
                                        <h3 className="mt-1 text-xl font-semibold text-white">
                                            Upload Excel dan kirim rows ke REST API
                                        </h3>
                                    </div>
                                    <label className="inline-flex cursor-pointer items-center justify-center rounded-2xl border border-cyan-400/30 bg-cyan-400/10 px-4 py-3 text-sm font-semibold text-cyan-100">
                                        <input
                                            type="file"
                                            accept=".xlsx,.xls,.csv"
                                            onChange={handleImportFile}
                                            className="hidden"
                                            disabled={importing}
                                        />
                                        {importing ? 'Memproses file...' : 'Upload Excel untuk review'}
                                    </label>
                                </div>
                                <p className="mt-4 text-sm leading-6 text-slate-300">
                                    Pilih sumber tiket sebelum mengupload Excel. Routing Rules akan diterapkan sesuai dengan sumber (SDP / Ebesha) yang dipilih.
                                </p>
                                <div className="mt-5 flex gap-6">
                                    <label className="flex items-center gap-2 text-sm text-white cursor-pointer">
                                        <input
                                            type="radio"
                                            name="importSource"
                                            value="ebesha"
                                            checked={importSource === 'ebesha'}
                                            onChange={(e) => {
                                                setImportSource(e.target.value);
                                                resetImportState();
                                            }}
                                            className="size-4 text-cyan-400 bg-slate-900 border-slate-500 focus:ring-cyan-400"
                                        />
                                        Ebesha
                                    </label>
                                    <label className="flex items-center gap-2 text-sm text-white cursor-pointer">
                                        <input
                                            type="radio"
                                            name="importSource"
                                            value="sdp"
                                            checked={importSource === 'sdp'}
                                            onChange={(e) => {
                                                setImportSource(e.target.value);
                                                resetImportState();
                                            }}
                                            className="size-4 text-cyan-400 bg-slate-900 border-slate-500 focus:ring-cyan-400"
                                        />
                                        SDP
                                    </label>
                                </div>
                            </div>

                            {importFileName ? (
                                <div className="mt-5 rounded-3xl border border-white/10 bg-slate-950/60 p-5">
                                    <div className="flex flex-col gap-3 md:flex-row md:items-center md:justify-between">
                                        <div>
                                            <p className="text-xs uppercase tracking-[0.28em] text-slate-400">
                                                Review import
                                            </p>
                                            <h4 className="mt-1 text-lg font-semibold text-white">
                                                {importFileName}
                                            </h4>
                                        </div>
                                        <div className="flex gap-2">
                                            <button
                                                type="button"
                                                onClick={resetImportState}
                                                className="rounded-2xl border border-white/10 px-4 py-2 text-xs font-semibold uppercase tracking-[0.22em] text-slate-300 transition hover:bg-white/10"
                                            >
                                                Reset
                                            </button>
                                            <button
                                                type="button"
                                                onClick={submitImport}
                                                disabled={importing || importPreview.length === 0}
                                                className="rounded-2xl bg-white px-4 py-2 text-xs font-semibold uppercase tracking-[0.22em] text-slate-950 transition hover:bg-cyan-200 disabled:cursor-not-allowed disabled:opacity-60"
                                            >
                                                {importing ? 'Submit...' : 'Submit Import'}
                                            </button>
                                        </div>
                                    </div>

                                    <div className="mt-4 grid gap-3 sm:grid-cols-4">
                                        <div className="rounded-2xl border border-white/10 bg-white/5 p-3">
                                            <p className="text-xs uppercase tracking-[0.24em] text-slate-400">Total baris</p>
                                            <p className="mt-2 text-2xl font-semibold text-white">{importPreview.length}</p>
                                        </div>
                                        <div className="rounded-2xl border border-emerald-400/20 bg-emerald-400/10 p-3">
                                            <p className="text-xs uppercase tracking-[0.24em] text-emerald-200">Baru</p>
                                            <p className="mt-2 text-2xl font-semibold text-white">
                                                {importPreview.filter((row) => row.review_status === 'ready').length}
                                            </p>
                                        </div>
                                        <div className="rounded-2xl border border-cyan-400/20 bg-cyan-400/10 p-3">
                                            <p className="text-xs uppercase tracking-[0.24em] text-cyan-200">Duplikat (update)</p>
                                            <p className="mt-2 text-2xl font-semibold text-white">
                                                {importPreview.filter((row) => row.review_status === 'duplicate').length}
                                            </p>
                                        </div>
                                        <div className="rounded-2xl border border-amber-400/20 bg-amber-400/10 p-3">
                                            <p className="text-xs uppercase tracking-[0.24em] text-amber-200">Warning</p>
                                            <p className="mt-2 text-2xl font-semibold text-white">
                                                {importPreview.filter((row) => row.review_status === 'warn').length}
                                            </p>
                                        </div>
                                    </div>

                                    <div className="mt-5 overflow-auto rounded-2xl border border-white/10" style={{ maxHeight: '600px' }}>
                                        <table className="min-w-full divide-y divide-white/10 text-sm">
                                            <thead className="bg-slate-950/80 text-slate-400 sticky top-0">
                                                <tr>
                                                    <th className="px-4 py-3 text-left font-medium whitespace-nowrap">Nomor Tiket</th>
                                                    <th className="px-4 py-3 text-left font-medium min-w-[200px]">Subject</th>
                                                    <th className="px-4 py-3 text-left font-medium">Status</th>
                                                    <th className="px-4 py-3 text-left font-medium">Aplikasi</th>
                                                    <th className="px-4 py-3 text-left font-medium">Technician</th>
                                                    {/* <th className="px-4 py-3 text-left font-medium min-w-[200px]">Resolution</th>
                                                    <th className="px-4 py-3 text-left font-medium">Category</th>
                                                    <th className="px-4 py-3 text-left font-medium">Subcategory</th>
                                                    <th className="px-4 py-3 text-left font-medium">Item</th>
                                                    <th className="px-4 py-3 text-left font-medium">Priority</th>
                                                    <th className="px-4 py-3 text-left font-medium whitespace-nowrap">Due By Time</th>
                                                    <th className="px-4 py-3 text-left font-medium whitespace-nowrap">Overdue Status</th>
                                                    <th className="px-4 py-3 text-left font-medium whitespace-nowrap">Response Due By</th>
                                                    <th className="px-4 py-3 text-left font-medium whitespace-nowrap">Response Overdue</th>
                                                    <th className="px-4 py-3 text-left font-medium whitespace-nowrap">Actual Time</th> */}
                                                    <th className="px-4 py-3 text-left font-medium">Review</th>
                                                </tr>
                                            </thead>
                                            <tbody className="divide-y divide-white/10 bg-slate-950/50">
                                                {importPreview.map((row, index) => (
                                                    <tr key={`${row.nomor_tiket || 'row'}-${index}`} className="hover:bg-white/5">
                                                        <td className="px-4 py-3 text-white whitespace-nowrap">{row.nomor_tiket || '-'}</td>
                                                        <td className="px-4 py-3 text-slate-200">{row.subject || '-'}</td>
                                                        <td className="px-4 py-3 text-slate-200">{row.status || '-'}</td>
                                                        <td className="px-4 py-3 text-slate-200">{row.aplikasi || '-'}</td>
                                                        <td className="px-4 py-3 text-slate-200">{row.technician || '-'}</td>
                                                        {/* <td className="px-4 py-3 text-slate-200 truncate max-w-[200px]" title={row.resolution}>{row.resolution || '-'}</td>
                                                        <td className="px-4 py-3 text-slate-200">{row.category || '-'}</td>
                                                        <td className="px-4 py-3 text-slate-200">{row.subcategory || '-'}</td>
                                                        <td className="px-4 py-3 text-slate-200">{row.item || '-'}</td>
                                                        <td className="px-4 py-3 text-slate-200">{row.priority || '-'}</td>
                                                        <td className="px-4 py-3 text-slate-200 whitespace-nowrap">{row.due_by_time || '-'}</td>
                                                        <td className="px-4 py-3 text-slate-200 whitespace-nowrap">{row.overdue_status || '-'}</td>
                                                        <td className="px-4 py-3 text-slate-200 whitespace-nowrap">{row.response_due_date || '-'}</td>
                                                        <td className="px-4 py-3 text-slate-200 whitespace-nowrap">{row.response_overdue || '-'}</td>
                                                        <td className="px-4 py-3 text-slate-200 whitespace-nowrap">{row.actual_time || '-'}</td> */}
                                                        <td className="px-4 py-3 whitespace-nowrap">
                                                            <span className={`rounded-full px-3 py-1 text-xs font-semibold uppercase tracking-[0.18em] ${row.review_status === 'ready' ? 'bg-emerald-400/10 text-emerald-200' : row.review_status === 'duplicate' ? 'bg-cyan-400/10 text-cyan-200' : row.review_status === 'warn' ? 'bg-amber-400/10 text-amber-200' : 'bg-rose-400/10 text-rose-200'}`}>
                                                                {row.review_reason}
                                                            </span>
                                                        </td>
                                                    </tr>
                                                ))}
                                            </tbody>
                                        </table>
                                    </div>

                                    {importResult ? (
                                        <div className="mt-5 space-y-4">
                                            <div className="rounded-2xl border border-cyan-400/20 bg-cyan-400/10 p-4 text-sm text-cyan-100">
                                                Import result: Created {importResult.created ?? 0}, Updated {importResult.updated ?? 0}, Skipped {importResult.skipped ?? 0}, Failed {importResult.failed ?? 0}
                                            </div>

                                            {Array.isArray(importResult.details) && importResult.details.length > 0 ? (
                                                <div className="overflow-hidden rounded-2xl border border-white/10">
                                                    <table className="min-w-full divide-y divide-white/10 text-sm">
                                                        <thead className="bg-slate-950/80 text-slate-400">
                                                            <tr>
                                                                <th className="px-4 py-3 text-left font-medium">Nomor Tiket</th>
                                                                <th className="px-4 py-3 text-left font-medium">Aplikasi</th>
                                                                <th className="px-4 py-3 text-left font-medium">Status</th>
                                                                <th className="px-4 py-3 text-left font-medium">Message</th>
                                                            </tr>
                                                        </thead>
                                                        <tbody className="divide-y divide-white/10 bg-slate-950/50">
                                                            {importResult.details.map((detail, index) => (
                                                                <tr key={`${detail.nomor_tiket || 'detail'}-${index}`} className="hover:bg-white/5">
                                                                    <td className="px-4 py-3 text-white">{detail.nomor_tiket || '-'}</td>
                                                                    <td className="px-4 py-3 text-slate-200">{detail.aplikasi || '-'}</td>
                                                                    <td className="px-4 py-3 text-slate-200">{detail.status || '-'}</td>
                                                                    <td className="px-4 py-3 text-slate-300">{detail.message || '-'}</td>
                                                                </tr>
                                                            ))}
                                                        </tbody>
                                                    </table>
                                                </div>
                                            ) : null}
                                        </div>
                                    ) : null}
                                </div>
                            ) : null}

                            <div className="rounded-3xl border border-white/10 bg-white/5 p-6 shadow-xl shadow-black/20 backdrop-blur-xl">
                                <div className="flex items-center justify-between gap-4">
                                    <div>
                                        <p className="text-xs uppercase tracking-[0.28em] text-slate-400">
                                            Recent cache
                                        </p>
                                        <h3 className="mt-1 text-xl font-semibold text-white">
                                            Tiket terbaru di database lokal
                                        </h3>
                                    </div>
                                    <select
                                        value={selectedModule}
                                        onChange={(event) => setSelectedModule(event.target.value)}
                                        className="rounded-2xl border border-white/10 bg-slate-950/70 px-3 py-2 text-sm text-white outline-none focus:border-cyan-400"
                                    >
                                        <option value="all">Semua module</option>
                                        {modules.map((module) => (
                                            <option key={module.id} value={module.module_name}>
                                                {module.module_name}
                                            </option>
                                        ))}
                                    </select>
                                </div>

                                <div className="mt-5 overflow-hidden rounded-2xl border border-white/10">
                                    <table className="min-w-full divide-y divide-white/10 text-sm">
                                        <thead className="bg-slate-950/80 text-slate-400">
                                            <tr>
                                                <th className="px-4 py-3 text-left font-medium">Tiket ID</th>
                                                <th className="px-4 py-3 text-left font-medium">Tiket</th>
                                                <th className="px-4 py-3 text-left font-medium">Module</th>
                                                <th className="px-4 py-3 text-left font-medium">Status</th>
                                                <th className="px-4 py-3 text-left font-medium">Updated</th>
                                            </tr>
                                        </thead>
                                        <tbody className="divide-y divide-white/10 bg-slate-950/50">
                                            {loading ? (
                                                <tr>
                                                    <td className="px-4 py-5 text-slate-400" colSpan={5}>
                                                        Loading cache...
                                                    </td>
                                                </tr>
                                            ) : filteredTasks.length === 0 ? (
                                                <tr>
                                                    <td className="px-4 py-5 text-slate-400" colSpan={5}>
                                                        Belum ada tiket di cache.
                                                    </td>
                                                </tr>
                                            ) : (
                                                filteredTasks.map((task) => (
                                                    <tr key={task.id} className="hover:bg-white/5">
                                                        <td className="px-4 py-3">
                                                            <span className="rounded-full bg-violet-400/10 px-3 py-1 text-xs font-semibold text-violet-200">
                                                                {task.tiket_id || '-'}
                                                            </span>
                                                        </td>
                                                        <td className="px-4 py-3 text-white">
                                                            <div className="font-medium">{task.name}</div>
                                                            <div className="text-xs text-slate-500">{task.clickup_task_id}</div>
                                                        </td>
                                                        <td className="px-4 py-3 text-slate-200">{task.tipe_aplikasi}</td>
                                                        <td className="px-4 py-3 text-slate-200">{task.status}</td>
                                                        <td className="px-4 py-3 text-slate-400">{formatDateTime(task.updated_at)}</td>
                                                    </tr>
                                                ))
                                            )}
                                        </tbody>
                                    </table>
                                </div>
                            </div>
                        </div>
                    </section>
                </div>
            </div>
        </AuthenticatedLayout>
    );
}
