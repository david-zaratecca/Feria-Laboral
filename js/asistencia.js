/**
 * ============================================================
 * js/asistencia.js
 * Pantalla de control de asistencia (rol Asistente).
 *  - Carga la lista de asistentes existentes
 *  - Permite escanear QR e ingresar nuevos
 *  - Exporta a CSV (con sanitización anti CSV-injection)
 * Sin innerHTML para datos provenientes de HubSpot.
 * ============================================================
 */

document.addEventListener('DOMContentLoaded', () => {
    'use strict';

    const user = Api.requireRole('Asistente');
    if (!user) return;

    let attendanceData = [];

    const startBtn   = document.getElementById('startScanBtn');
    const reader     = document.getElementById('reader');
    const tableBody  = document.getElementById('attendanceTable');
    const messageDiv = document.getElementById('message');
    const counterEl  = document.getElementById('attendanceCounter');
    const exportBtn  = document.getElementById('exportBtn');

    let scanning = false;
    let qr = null;
    let messageTimeout = null;

    /* ---------- UI helpers ---------- */
    function showMessage(text, type = 'warning', duration = 5000) {
        if (!messageDiv) return;
        if (messageTimeout) clearTimeout(messageTimeout);

        messageDiv.textContent = text;
        messageDiv.className = `message ${type}`;
        messageDiv.style.display = 'block';

        messageTimeout = setTimeout(() => {
            messageDiv.style.display = 'none';
            messageDiv.textContent = '';
        }, duration);
    }

    function updateCounter() {
        if (counterEl) {
            counterEl.textContent = `Asistentes ingresados: ${attendanceData.length}`;
        }
    }

    /**
     * Inserta una fila en la tabla usando textContent (anti-XSS).
     */
    function addToTable(c) {
        const row = document.createElement('tr');
        const fields = [
            'firstname',
            'lastname',
            'email',
            'phone',
            'numero_de_identificacion',
            'fl___nivel_de_estudios',
        ];
        for (const key of fields) {
            const td = document.createElement('td');
            td.textContent = c[key] ?? '';
            row.appendChild(td);
        }
        tableBody.prepend(row);
    }

    /* ---------- Carga inicial ---------- */
    async function loadExistingAttendance() {
        try {
            const { ok, data } = await Api.get('api/asistencia-list.php');
            if (!ok || !data || !data.success) {
                console.warn('No se pudieron cargar asistencias previas');
                return;
            }
            attendanceData = data.contacts || [];
            attendanceData.forEach(addToTable);
            updateCounter();
        } catch (err) {
            console.error('Error cargando asistencia inicial', err);
        }
    }
    loadExistingAttendance();

    /* ---------- Escaneo QR ---------- */
    if (startBtn) {
        startBtn.addEventListener('click', async () => {
            if (scanning) return;
            scanning = true;
            if (reader) reader.style.display = 'block';

            qr = new Html5Qrcode('reader');
            try {
                await qr.start(
                    { facingMode: 'environment' },
                    { fps: 10, qrbox: 250 },
                    onScanSuccess
                );
            } catch (err) {
                scanning = false;
                if (reader) reader.style.display = 'none';
                showMessage('No se pudo acceder a la cámara', 'error');
                console.error(err);
            }
        });
    }

    async function onScanSuccess(decodedText) {
        try { await qr.stop(); } catch { /* ignore */ }
        scanning = false;
        if (reader) reader.style.display = 'none';

        try {
            const { ok, data } = await Api.post('api/asistencia-scan.php', {
                email: (decodedText || '').trim(),
            });

            if (data && data.alreadyScanned) {
                showMessage(data.message, 'warning');
                return;
            }
            if (!ok || !data || !data.success) {
                showMessage((data && data.message) || 'Error al registrar asistencia', 'error');
                return;
            }

            showMessage(data.message, 'success');
            addToTable(data.contact);
            attendanceData.unshift(data.contact);
            updateCounter();
        } catch (err) {
            console.error(err);
            showMessage('Error al conectar con el servidor', 'error');
        }
    }

    /* ---------- Exportar CSV ---------- */
    if (exportBtn) {
        exportBtn.addEventListener('click', () => {
            if (!attendanceData.length) {
                showMessage('No hay datos para exportar', 'warning');
                return;
            }

            const headers = [
                'Nombre',
                'Apellido',
                'Correo',
                'Teléfono',
                'Identificación',
                'Nivel de estudios',
            ];

            // Defensa contra CSV injection: si arranca con =, +, -, @ se prefija con apóstrofe
            function safeCell(v) {
                const str = (v == null) ? '' : String(v);
                const escaped = /^[=+\-@]/.test(str) ? "'" + str : str;
                return `"${escaped.replace(/"/g, '""')}"`;
            }

            let csv = '\uFEFF' + headers.join(';') + '\n';
            for (const c of attendanceData) {
                csv += [
                    c.firstname,
                    c.lastname,
                    c.email,
                    c.phone,
                    c.numero_de_identificacion,
                    c.fl___nivel_de_estudios,
                ].map(safeCell).join(';') + '\n';
            }

            const blob = new Blob([csv], { type: 'text/csv;charset=utf-8;' });
            const url  = URL.createObjectURL(blob);
            const link = document.createElement('a');
            link.href = url;
            link.download = `asistencia_feria_${new Date().toISOString().slice(0,10)}.csv`;
            document.body.appendChild(link);
            link.click();
            document.body.removeChild(link);
            URL.revokeObjectURL(url);
        });
    }
});
