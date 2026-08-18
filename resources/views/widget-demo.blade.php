<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>MCA — Microcredenciales (demo del widget)</title>
    <style>
        body { font-family: system-ui, Arial, sans-serif; margin: 0; background: #F7F5EF; color: #0b2545; }
        .hero { max-width: 720px; margin: 0 auto; padding: 80px 24px; }
        h1 { color: #0C447C; font-size: 34px; }
        p { line-height: 1.6; font-size: 17px; }
        .note { margin-top: 40px; padding: 16px; background: #fff; border-radius: 12px; font-size: 14px; color: #555; }
        /* Control de PRUEBAS: solo existe en esta pagina de demo, no en el widget embebible. */
        .devreset { position: fixed; top: 16px; right: 16px; z-index: 2147483001;
            background: #0b2545; color: #fff; border: none; border-radius: 999px;
            padding: 10px 16px; font-size: 13px; font-weight: 700; cursor: pointer;
            box-shadow: 0 6px 18px rgba(11,37,69,.3); font-family: system-ui, Arial, sans-serif; }
        .devreset:hover { background: #0C447C; }
        .devreset small { display: block; font-weight: 400; font-size: 10px; opacity: .8; }
    </style>
</head>
<body>
    <div class="hero">
        <h1>Microcredenciales MCA</h1>
        <p>Esta es una landing de demostración de la web anfitriona. El widget de Celia
           aparece abajo a la derecha, aislado con Shadow DOM para no chocar con estos estilos.</p>
        <p>Haz clic en «Habla con Celia» para probar la captura de lead, la navegación guiada
           y el emparejador.</p>
        <div class="note">Demo local. En producción el widget se embebe en la web real de MCA
            con un simple &lt;script&gt; y la <code>public_key</code> del bot.</div>
    </div>

    {{-- Botón de reinicio SOLO de pruebas: borra la sesión local del navegador y recarga
         como visitante nuevo. Vive en la página de demo, nunca en el widget embebible real. --}}
    <button class="devreset" id="dev-reset" title="Solo demo: empezar como visitante nuevo">
        ↺ Reiniciar sesión<small>solo demo · borra la sesión de este navegador</small>
    </button>
    <script>
        (function () {
            document.getElementById('dev-reset').addEventListener('click', function () {
                // Borra toda sesión local del widget (session_id por bot) en este navegador.
                try {
                    Object.keys(localStorage)
                        .filter(function (k) { return k.indexOf('celia_session_') === 0; })
                        .forEach(function (k) { localStorage.removeItem(k); });
                } catch (e) { /* almacenamiento no disponible: se ignora */ }
                // Recarga: el widget arranca sin sesión -> captura de "primera vez".
                location.reload();
            });
        })();
    </script>

    {{-- Embebido del widget: solo public_key (nunca institution_id). --}}
    <script src="{{ url('/widget/celia.js') }}"
            data-bot-key="{{ $botKey }}"
            data-api-base="{{ url('') }}"></script>
</body>
</html>
