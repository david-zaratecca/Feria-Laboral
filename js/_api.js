/**
 * ============================================================
 * js/_api.js
 * Cliente HTTP compartido. Maneja:
 *   - Almacenamiento del token JWT y los datos del usuario
 *   - Inyección automática del header Authorization
 *   - Manejo unificado de errores 401 (sesión expirada -> login)
 *   - Redirección por rol
 *   - Helper escapeHtml (defensa anti-XSS)
 * ============================================================
 */

(function () {
    'use strict';

    const TOKEN_KEY = 'feria_token';
    const USER_KEY = 'feria_user';

    const Api = {
        /* ---------- Sesión ---------- */
        setSession(token, user) {
            sessionStorage.setItem(TOKEN_KEY, token);
            sessionStorage.setItem(USER_KEY, JSON.stringify(user));
        },

        getToken() {
            return sessionStorage.getItem(TOKEN_KEY);
        },

        getUser() {
            try {
                const raw = sessionStorage.getItem(USER_KEY);
                return raw ? JSON.parse(raw) : null;
            } catch {
                return null;
            }
        },

        clearSession() {
            sessionStorage.removeItem(TOKEN_KEY);
            sessionStorage.removeItem(USER_KEY);
        },

        /**
         * Si no hay sesión válida o el rol no coincide, redirige al login.
         * Devuelve el user si todo está OK, o null si redirigió.
         */
        requireRole(rol) {
            const token = Api.getToken();
            const user = Api.getUser();
            if (!token || !user || user.rol !== rol) {
                Api.clearSession();
                window.location.href = 'index.html';
                return null;
            }
            return user;
        },

        /* ---------- Petición HTTP ---------- */
        async request(path, { method = 'GET', body = null } = {}) {
            const headers = { 'Accept': 'application/json' };
            const token = Api.getToken();
            if (token) headers['Authorization'] = `Bearer ${token}`;
            if (body !== null) headers['Content-Type'] = 'application/json';

            let res;
            try {
                res = await fetch(path, {
                    method,
                    headers,
                    body: body !== null ? JSON.stringify(body) : null,
                    credentials: 'same-origin',
                });
            } catch (err) {
                console.error('Network error', err);
                return { ok: false, status: 0, data: null, networkError: true };
            }

            let data = null;
            const text = await res.text();
            if (text) {
                try { data = JSON.parse(text); } catch { /* texto no JSON */ }
            }


            if (res.status === 401) {
                if (!path.includes('login.php')) {
                    Api.clearSession();
                    window.location.href = 'index.html';
                }
                return { ok: false, status: 401, data };
            }


            return { ok: res.ok, status: res.status, data };
        },

        get(path) { return Api.request(path, { method: 'GET' }); },
        post(path, body) { return Api.request(path, { method: 'POST', body }); },
    };

    /**
     * Escapa HTML para inserción segura como texto.
     * Úsalo cuando absolutamente necesites construir HTML;
     * preferir siempre `element.textContent = value`.
     */
    function escapeHtml(value) {
        const div = document.createElement('div');
        div.textContent = value ?? '';
        return div.innerHTML;
    }

    // Exponer globalmente
    window.Api = Api;
    window.escapeHtml = escapeHtml;
})();
