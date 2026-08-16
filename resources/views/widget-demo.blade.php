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

    {{-- Embebido del widget: solo public_key (nunca institution_id). --}}
    <script src="{{ url('/widget/celia.js') }}"
            data-bot-key="{{ $botKey }}"
            data-api-base="{{ url('') }}"></script>
</body>
</html>
