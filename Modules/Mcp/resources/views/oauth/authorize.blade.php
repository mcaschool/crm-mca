<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex,nofollow">
    <title>{{ __('Autorizar conexión') }} · MCA CRM</title>
    <style>
        :root { color-scheme: light; }
        body { margin:0; font-family:system-ui,-apple-system,Segoe UI,Roboto,sans-serif; background:#F4F6F9; color:#1E2430; display:flex; min-height:100vh; align-items:center; justify-content:center; padding:16px; }
        .card { background:#fff; border:1px solid #E5E9F0; border-radius:16px; box-shadow:0 8px 30px rgba(20,40,80,.08); width:min(440px,100%); padding:28px 26px; }
        h1 { font-size:18px; margin:0 0 4px; }
        .sub { color:#6B7686; font-size:13.5px; margin:0 0 18px; }
        .app { display:flex; align-items:center; gap:10px; padding:12px 14px; background:#F4F6F9; border-radius:12px; margin-bottom:16px; font-weight:600; }
        ul { list-style:none; padding:0; margin:0 0 18px; }
        li { display:flex; align-items:center; gap:8px; padding:7px 0; font-size:13.5px; border-bottom:1px solid #EEF1F6; }
        .ok { color:#1E7A46; font-weight:700; }
        .tag { font-size:11px; background:#EAF1FB; color:#1E5AA8; padding:2px 8px; border-radius:999px; font-weight:600; }
        .actions { display:flex; gap:10px; margin-top:6px; }
        button { flex:1; padding:11px 14px; border-radius:10px; font-size:14px; font-weight:600; cursor:pointer; border:1px solid transparent; font-family:inherit; }
        .approve { background:#1E5AA8; color:#fff; }
        .deny { background:#fff; border-color:#D8DEE8; color:#3B4453; }
        .note { font-size:11.5px; color:#8A93A2; margin-top:14px; text-align:center; }
    </style>
</head>
<body>
    <div class="card">
        <h1>{{ __('Autorizar acceso a MCA CRM') }}</h1>
        <p class="sub">{{ __('Una aplicación externa solicita conectarse a tu MCP.') }}</p>

        <div class="app">🔌 {{ $app_name }}</div>

        <p class="sub" style="margin-bottom:8px">{{ __('Acceso solicitado:') }}</p>
        <ul>
            <li><span class="ok">✓</span> {{ __('Inspección de solo lectura del CRM') }} <span class="tag">read-only</span></li>
            <li><span class="ok">✓</span> {{ __('Ámbito') }}: <strong>Global</strong> <span class="tag">todas las instituciones</span></li>
            @foreach ($scopes as $s)
                <li><span class="ok">✓</span> <code>{{ $s }}</code></li>
            @endforeach
        </ul>

        <form method="POST" action="{{ route('mcp.oauth.authorize.approve') }}">
            @csrf
            @foreach (['client_id','redirect_uri','response_type','code_challenge','code_challenge_method','scope','state','resource'] as $k)
                <input type="hidden" name="{{ $k }}" value="{{ $params[$k] ?? '' }}">
            @endforeach
            <div class="actions">
                <button class="deny" type="submit" name="decision" value="deny">{{ __('Denegar') }}</button>
                <button class="approve" type="submit" name="decision" value="approve">{{ __('Autorizar') }}</button>
            </div>
        </form>

        <p class="note">{{ __('La escritura permanece desactivada. Podrás elevar permisos técnicos más adelante desde el panel.') }}</p>
    </div>
</body>
</html>
