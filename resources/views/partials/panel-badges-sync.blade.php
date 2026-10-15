{{--
  Sincronizador ÚNICO de los indicadores del menú lateral (Leads, Contactos, Bandeja social).
  Consulta GET {{ route('panel.badges') }} (solo números, por permisos e institución) y emite los
  mismos eventos de navegador que ya escucha el menú (crm-badges-updated, social-unread-updated),
  así que el menú no cambia. El número sale siempre del servidor, nunca de sumas en el navegador.

  - Cada ~8 s mientras la pestaña está visible; en segundo plano se detiene y, al volver (visible,
    foco o conexión recuperada), consulta en el acto.
  - Errores transitorios: espera creciente (8 → 16 → 32 → 60 s) y vuelta al ritmo normal al
    primer acierto. Sesión caducada o sin permiso (401/403/419): deja de consultar.
  - Varias pestañas: comparten cada resultado por BroadcastChannel, también los que emiten las
    propias pantallas (p. ej. leer una conversación en la Bandeja), para que todas coincidan.
--}}
<script>
    (() => {
        if (window.__mcaPanelBadges) return; // una sola instancia por página
        window.__mcaPanelBadges = true;

        const URL_BADGES = @js(route('panel.badges'));
        const EVERY = 8000, MAX = 60000;
        let wait = EVERY, timer = null, busy = false, stopped = false;
        const channel = 'BroadcastChannel' in window ? new BroadcastChannel('mca-panel-badges') : null;

        // Emite los eventos que ya oye el menú; marca los propios para no reenviarlos en bucle.
        const apply = (d, share) => {
            if (!d || typeof d !== 'object') return;
            if ('leads' in d || 'contacts' in d) {
                window.dispatchEvent(new CustomEvent('crm-badges-updated', { detail: { leads: d.leads, contacts: d.contacts, __sync: true } }));
            }
            if ('social' in d) {
                window.dispatchEvent(new CustomEvent('social-unread-updated', { detail: { total: d.social, __sync: true } }));
            }
            if (share && channel) channel.postMessage(d);
        };
        if (channel) channel.onmessage = (e) => apply(e.data, false);

        // Lo que emiten las pantallas (Livewire) también llega a las demás pestañas.
        window.addEventListener('crm-badges-updated', (e) => {
            const d = e.detail || {};
            if (!d.__sync && channel) channel.postMessage({ leads: d.leads, contacts: d.contacts });
        });
        window.addEventListener('social-unread-updated', (e) => {
            const d = e.detail || {};
            if (!d.__sync && channel && typeof d.total === 'number') channel.postMessage({ social: d.total });
        });

        const schedule = () => { if (!stopped && timer === null) timer = setTimeout(tick, wait); };
        const now = () => { if (timer !== null) { clearTimeout(timer); timer = null; } tick(); };

        async function tick() {
            timer = null;
            if (stopped || busy) return;
            if (document.visibilityState !== 'visible') return; // se reanuda al volver a la pestaña
            busy = true;
            try {
                const r = await fetch(URL_BADGES, { credentials: 'same-origin', cache: 'no-store', headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' } });
                if ([401, 403, 419].includes(r.status)) { stopped = true; return; }
                if (!r.ok) throw new Error(String(r.status));
                apply(await r.json(), true);
                wait = EVERY;
            } catch (e) {
                wait = Math.min(MAX, wait * 2);
            } finally {
                busy = false;
                schedule();
            }
        }

        document.addEventListener('visibilitychange', () => { if (document.visibilityState === 'visible') now(); });
        window.addEventListener('focus', now);
        window.addEventListener('online', () => { wait = EVERY; now(); });
        schedule();
    })();
</script>
