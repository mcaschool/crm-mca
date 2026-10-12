<!doctype html>
<html lang="{{ app()->getLocale() }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>{{ $pageTitle ?? __('Reservar una reunión') }} · {{ $institution->name ?? config('app.name') }}</title>
    <link rel="preconnect" href="https://fonts.bunny.net">
    <link href="https://fonts.bunny.net/css?family=dm-sans:400,500,600,700&display=swap" rel="stylesheet">
    <style>
        :root{--ink:#13253D;--muted:#61748F;--blue:#1E5AA8;--blue-d:#143B6B;--soft:#F1F6FC;--line:#E3EAF3;--paper:#F4F7FB;--ok:#1E8E5A;--warn:#B7791F;--err:#B23B3B}
        *{box-sizing:border-box}
        body{margin:0;background:var(--paper);color:var(--ink);font-family:"DM Sans",system-ui,-apple-system,"Segoe UI",Arial,sans-serif;font-size:15px;line-height:1.5}
        a{color:var(--blue)}
        .bk-top{background:#fff;border-bottom:1px solid var(--line)}
        .bk-top .in{max-width:1040px;margin:0 auto;padding:14px 20px;display:flex;align-items:center;gap:12px}
        .bk-top img{height:34px;width:auto}
        .bk-top .nm{font-weight:700;font-size:16px}
        .bk-top .sp{flex:1}
        .bk-lang a{font-size:13px;font-weight:600;text-decoration:none;color:var(--muted);padding:4px 8px;border-radius:6px}
        .bk-lang a.on{color:var(--blue);background:var(--soft)}
        .bk-main{max-width:1040px;margin:0 auto;padding:28px 20px 48px}
        .bk-card{background:#fff;border:1px solid var(--line);border-radius:16px;box-shadow:0 12px 32px -22px rgba(19,37,61,.35)}
        .bk-h1{font-size:24px;font-weight:700;margin:0 0 6px}
        .bk-sub{color:var(--muted);margin:0 0 20px}
        .bk-btn{display:inline-flex;align-items:center;justify-content:center;gap:8px;border:1px solid var(--blue);background:var(--blue);color:#fff;border-radius:10px;padding:11px 18px;font:inherit;font-weight:600;cursor:pointer;text-decoration:none}
        .bk-btn:hover{background:var(--blue-d)}
        .bk-btn.ghost{background:#fff;color:var(--blue);border-color:#CFE0F5}
        .bk-btn.ghost:hover{background:var(--soft)}
        .bk-btn.danger{background:#fff;color:var(--err);border-color:#F1C7C7}
        .bk-btn[disabled]{opacity:.55;cursor:not-allowed}
        .bk-err{background:#FDECEC;border:1px solid #F5C6C6;color:var(--err);border-radius:10px;padding:10px 14px;margin:0 0 16px;font-size:14px}
        .bk-ok{background:#E8F6EE;border:1px solid #BEE7CD;color:#17643F;border-radius:10px;padding:12px 16px;margin:0 0 18px;font-size:14px}
        .bk-meta{display:flex;flex-direction:column;gap:6px;font-size:14px;color:var(--muted)}
        .bk-meta b{color:var(--ink);font-weight:600}
        .bk-foot{max-width:1040px;margin:0 auto;padding:0 20px 28px;color:var(--muted);font-size:12px;text-align:center}
        @media(max-width:640px){.bk-main{padding:18px 14px 36px}.bk-h1{font-size:20px}}
    </style>
    @stack('head')
</head>
<body>
    <header class="bk-top">
        <div class="in">
            @if ($institution?->logoUrl())
                <img src="{{ $institution->logoUrl() }}" alt="{{ $institution->name }}">
            @endif
            <span class="nm">{{ $institution->name ?? config('app.name') }}</span>
            <span class="sp"></span>
            <nav class="bk-lang" aria-label="{{ __('Idioma') }}">
                <a href="{{ request()->fullUrlWithQuery(['lang' => 'es']) }}" class="{{ app()->getLocale() === 'es' ? 'on' : '' }}" hreflang="es">ES</a>
                <a href="{{ request()->fullUrlWithQuery(['lang' => 'en']) }}" class="{{ app()->getLocale() === 'en' ? 'on' : '' }}" hreflang="en">EN</a>
            </nav>
        </div>
    </header>
    <main class="bk-main">
        @yield('content')
    </main>
    <footer class="bk-foot">{{ $institution->name ?? config('app.name') }} · {{ __('Reservas en línea') }}</footer>
    @stack('scripts')
</body>
</html>
