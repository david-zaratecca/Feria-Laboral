/**
 * ============================================================
 * js/script.js
 * Lógica de la pantalla de login. Tras login exitoso:
 *  - Guarda el JWT y los datos del usuario en sessionStorage
 *  - Redirige según rol (Asistente / Empresa)
 * ============================================================
 */

(function () {
    'use strict';

    // Site key pública de reCAPTCHA v3 (se puede dejar visible).
    // Si la cambias, cámbiala también en el <script> de index.html.
    const RECAPTCHA_SITE_KEY = '6LcIqNAsAAAAAB8_NxqKz3aTJKx0sJDQgpglIsF0';

    const form       = document.getElementById('loginForm');
    const btnLogin   = document.getElementById('btnLogin');
    const loading    = document.getElementById('loading');
    const messageDiv = document.getElementById('message');

    if (!form) return;

    form.addEventListener('submit', async (e) => {
        e.preventDefault();

        const email    = document.getElementById('email').value.trim().toLowerCase();
        const password = document.getElementById('password').value;

        if (!email || !password) {
            showMessage('Email y contraseña son requeridos', 'error');
            return;
        }

        btnLogin.disabled = true;
        loading.classList.add('show');
        if (messageDiv) messageDiv.style.display = 'none';

        try {
            // reCAPTCHA v3
            if (typeof grecaptcha === 'undefined') {
                showMessage('No se pudo cargar la validación de seguridad', 'error');
                return;
            }
            const captchaToken = await grecaptcha.execute(
                RECAPTCHA_SITE_KEY,
                { action: 'login' }
            );

            const { ok, data, networkError } = await Api.post('api/login.php', {
                email,
                password,
                recaptchaToken: captchaToken,
            });

            if (networkError) {
                showMessage('Error al conectar con el servidor', 'error');
                return;
            }
            if (!ok || !data || !data.success) {
                showMessage((data && data.message) || 'Error de autenticación', 'error');
                return;
            }

            // Guardar token + datos NO sensibles del usuario
            Api.setSession(data.token, {
                nombre:    data.nombre,
                rol:       data.rol,
                companyId: data.companyId,
                companyName: data.companyName,
            });

            // Redirigir por rol
            if (data.rol === 'Asistente') {
                window.location.href = 'asistencia.html';
                return;
            }
            if (data.rol === 'Empresa') {
                window.location.href = 'empresa.html';
                return;
            }
            showMessage('Rol aún no implementado', 'error');
        } catch (err) {
            console.error(err);
            showMessage('Error al conectar con el servidor', 'error');
        } finally {
            btnLogin.disabled = false;
            loading.classList.remove('show');
        }
    });

    function showMessage(text, type) {
        if (!messageDiv) return;
        messageDiv.textContent = text;
        messageDiv.className = `message ${type}`;
        messageDiv.style.display = 'block';
    }
})();
