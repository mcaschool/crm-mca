{{--
  Página de acceso al CRM (rediseño v4, fiel al mockup aprobado). SOLO capa visual:
  el formulario POSTea al MISMO endpoint de Breeze (route('login')) con @csrf, los
  campos name="email"/"password"/"remember" que el backend espera, los errores reales
  de Laravel estilizados, y el enlace a la ruta real de reset. El paso 2FA posterior
  no se toca. El toggle ES/EN es visual (client-side): el idioma real del panel se
  fija tras el login; aquí no hay mecanismo de locale para invitados.
--}}
<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>MCA · {{ __('Acceso al CRM') }}</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=DM+Sans:opsz,wght@9..40,400;9..40,500;9..40,600;9..40,700&display=swap" rel="stylesheet">
    <style>
        :root{
            --brand:#1E5AA8; --brand-glow:#2E74C9;
            --gold:#C9A84C; --gold-hi:#E4CB84;
            --ink:#EAF1FB; --muted:#9FB4D6;
            --glass:rgba(255,255,255,.07); --glass-brd:rgba(255,255,255,.16);
            --field:rgba(6,18,38,.42); --field-brd:rgba(255,255,255,.14);
        }
        *{box-sizing:border-box;margin:0;padding:0}
        html,body{height:100%}
        body{
            font-family:"DM Sans",system-ui,sans-serif; color:var(--ink);
            min-height:100vh; display:grid; place-items:center; position:relative; overflow:hidden;
            background:
                radial-gradient(120% 70% at 50% -8%, rgba(201,168,76,.12), transparent 45%),
                linear-gradient(180deg, rgba(6,18,38,.45) 0%, rgba(5,15,30,.72) 60%, rgba(3,11,22,.9) 100%),
                url('{{ asset('images/auth/bg-login.jpg') }}') center/cover no-repeat fixed,
                #061630;
        }
        .scene{position:fixed;inset:0;z-index:0;pointer-events:none;overflow:hidden}
        .scene .vignette{position:absolute;inset:0;background:radial-gradient(120% 90% at 50% 42%, transparent 52%, rgba(2,8,18,.72) 100%)}

        .wrap{position:relative;z-index:2;width:100%;display:grid;place-items:center;padding:32px 20px}
        .card{
            width:100%;max-width:430px; background:var(--glass);
            border:1px solid var(--glass-brd); border-radius:24px;
            padding:38px 38px 30px;
            backdrop-filter:blur(26px) saturate(120%); -webkit-backdrop-filter:blur(26px) saturate(120%);
            box-shadow:0 30px 80px -20px rgba(0,0,0,.62), inset 0 1px 0 rgba(255,255,255,.22);
            position:relative; overflow:hidden;
            animation:rise .7s cubic-bezier(.16,1,.3,1) both;
        }
        .card::before{content:"";position:absolute;top:0;left:24px;right:24px;height:2px;border-radius:2px;
            background:linear-gradient(90deg,transparent,var(--gold),transparent);opacity:.85}
        @keyframes rise{from{opacity:0;transform:translateY(14px) scale(.985)}to{opacity:1;transform:none}}
        @media (prefers-reduced-motion:reduce){.card{animation:none}}

        .lang{position:absolute;top:16px;right:20px;display:flex;gap:2px;font-size:12.5px;font-weight:600;z-index:3}
        .lang button{background:none;border:0;color:var(--muted);cursor:pointer;padding:4px 6px;border-radius:6px;font-family:inherit;font-weight:600;font-size:12.5px;transition:color .2s,background .2s}
        .lang button.on{color:var(--ink)}
        .lang button:hover{color:var(--ink);background:rgba(255,255,255,.06)}
        .lang .sep{color:rgba(159,180,214,.45);align-self:center}

        .brand{display:flex;justify-content:center;margin:6px 0 24px}
        .brand img{height:52px;width:auto;max-width:230px;object-fit:contain;filter:drop-shadow(0 2px 10px rgba(0,0,0,.35))}

        .head{text-align:center;margin-bottom:24px}
        .head h1{font-size:23px;font-weight:700;letter-spacing:-.01em}
        .head p{font-size:14px;color:var(--muted);margin-top:6px}

        .alert{display:flex;align-items:flex-start;gap:9px;border-radius:12px;padding:11px 13px;font-size:13px;line-height:1.4;margin-bottom:18px}
        .alert svg{width:16px;height:16px;flex:0 0 auto;margin-top:1px}
        .alert-danger{background:rgba(222,70,70,.14);border:1px solid rgba(233,96,96,.42);color:#ffd9d9}
        .alert-ok{background:rgba(64,190,124,.13);border:1px solid rgba(96,212,146,.4);color:#c9f6db}

        .field{margin-bottom:15px}
        .field label{display:block;font-size:12.5px;font-weight:600;color:var(--muted);margin-bottom:7px;padding-left:2px}
        .input{display:flex;align-items:center;gap:10px;background:var(--field);border:1px solid var(--field-brd);
            border-radius:13px;padding:0 14px;height:50px;transition:border-color .2s,box-shadow .2s,background .2s}
        .input:focus-within{border-color:var(--brand-glow);box-shadow:0 0 0 3px rgba(46,116,201,.28);background:rgba(6,18,38,.55)}
        .input.has-error{border-color:rgba(233,96,96,.65);box-shadow:0 0 0 3px rgba(222,70,70,.18)}
        .input svg{width:18px;height:18px;color:var(--muted);flex:0 0 auto}
        .input input{flex:1;background:none;border:0;outline:0;color:var(--ink);font-family:inherit;font-size:15px;height:100%}
        .input input::placeholder{color:rgba(159,180,214,.7)}
        .eye{background:none;border:0;cursor:pointer;color:var(--muted);display:grid;place-items:center;padding:4px;border-radius:6px;transition:color .2s}
        .eye:hover{color:var(--ink)}
        .err{display:block;font-size:12px;color:#ffb4b4;margin-top:6px;padding-left:2px}

        .row{display:flex;align-items:center;justify-content:space-between;margin:16px 0 22px}
        .remember{display:flex;align-items:center;gap:9px;cursor:pointer;font-size:13.5px;color:var(--muted);user-select:none}
        .remember input{position:absolute;opacity:0;width:0;height:0}
        .box{width:18px;height:18px;border-radius:6px;border:1.5px solid rgba(255,255,255,.3);background:rgba(255,255,255,.04);display:grid;place-items:center;transition:all .18s}
        .box svg{width:12px;height:12px;color:#1a1205;opacity:0;transform:scale(.6);transition:all .18s}
        .remember input:checked + .box{background:var(--gold);border-color:var(--gold)}
        .remember input:checked + .box svg{opacity:1;transform:scale(1)}
        .remember input:focus-visible + .box{box-shadow:0 0 0 3px rgba(201,168,76,.35)}
        .forgot{font-size:13.5px;color:#bcd0ee;text-decoration:none;font-weight:500;transition:color .2s}
        .forgot:hover{color:#fff}

        .btn{width:100%;height:52px;border:0;border-radius:13px;cursor:pointer;font-family:inherit;font-size:15.5px;font-weight:700;letter-spacing:.01em;color:#241a05;
            background:linear-gradient(180deg,var(--gold-hi),var(--gold));
            box-shadow:0 12px 26px -10px rgba(201,168,76,.6),inset 0 1px 0 rgba(255,255,255,.5);
            transition:transform .15s,box-shadow .2s,filter .2s}
        .btn:hover{transform:translateY(-1px);box-shadow:0 16px 30px -10px rgba(201,168,76,.7),inset 0 1px 0 rgba(255,255,255,.55);filter:brightness(1.03)}
        .btn:active{transform:translateY(0)}
        .btn:focus-visible{outline:0;box-shadow:0 0 0 3px rgba(228,203,132,.5)}

        .note{display:flex;align-items:center;justify-content:center;gap:7px;margin-top:18px;font-size:12.5px;color:var(--muted)}
        .note svg{width:14px;height:14px;opacity:.8}
        .foot{position:relative;z-index:2;text-align:center;font-size:12px;color:rgba(159,180,214,.6);padding:0 20px 26px;margin-top:-6px}

        @media (max-width:480px){.card{padding:32px 24px 26px;border-radius:20px}.head h1{font-size:21px}.brand img{height:46px}}
    </style>
</head>
<body>
    <div class="scene" aria-hidden="true"><div class="vignette"></div></div>

    <div class="wrap">
        <form class="card" method="POST" action="{{ route('login') }}">
            @csrf

            <div class="lang">
                <button type="button" class="on" data-lang="es">ES</button>
                <span class="sep">·</span>
                <button type="button" data-lang="en">EN</button>
            </div>

            <div class="brand">
                <img src="{{ asset('images/logo.png') }}" alt="MCA School of Business and Postgraduate">
            </div>

            <div class="head">
                <h1 data-t="title">{{ __('Acceso al CRM') }}</h1>
                <p data-t="subtitle">{{ __('Introduce tus credenciales para continuar') }}</p>
            </div>

            @if (session('status'))
                <div class="alert alert-ok" role="status">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M20 6 9 17l-5-5"/></svg>
                    <span>{{ session('status') }}</span>
                </div>
            @endif

            @if ($errors->any())
                <div class="alert alert-danger" role="alert">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><path d="M12 8v4"/><path d="M12 16h.01"/></svg>
                    <span>{{ $errors->first() }}</span>
                </div>
            @endif

            <div class="field">
                <label for="email" data-t="emailLbl">{{ __('Correo electrónico') }}</label>
                <div class="input @error('email') has-error @enderror">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><rect x="2" y="4" width="20" height="16" rx="2"/><path d="m22 7-10 6L2 7"/></svg>
                    <input id="email" type="email" name="email" value="{{ old('email') }}" required autofocus autocomplete="username" placeholder="nombre@mcaschool.education">
                </div>
                @error('email') <span class="err">{{ $message }}</span> @enderror
            </div>

            <div class="field">
                <label for="password" data-t="passLbl">{{ __('Contraseña') }}</label>
                <div class="input @error('password') has-error @enderror">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="11" width="18" height="10" rx="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/></svg>
                    <input id="password" type="password" name="password" required autocomplete="current-password" placeholder="••••••••">
                    <button type="button" class="eye" id="eye" aria-label="{{ __('Mostrar contraseña') }}">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M2 12s3.5-7 10-7 10 7 10 7-3.5 7-10 7-10-7-10-7z"/><circle cx="12" cy="12" r="3"/></svg>
                    </button>
                </div>
                @error('password') <span class="err">{{ $message }}</span> @enderror
            </div>

            <div class="row">
                <label class="remember">
                    <input type="checkbox" name="remember" checked>
                    <span class="box"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3.5" stroke-linecap="round" stroke-linejoin="round"><path d="M20 6 9 17l-5-5"/></svg></span>
                    <span data-t="remember">{{ __('Recordarme') }}</span>
                </label>
                @if (Route::has('password.request'))
                    <a href="{{ route('password.request') }}" class="forgot" data-t="forgot">{{ __('¿Olvidaste tu contraseña?') }}</a>
                @endif
            </div>

            <button class="btn" type="submit" data-t="submit">{{ __('Ingresar') }}</button>

            <div class="note">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/></svg>
                <span data-t="note">{{ __('Acceso exclusivo para personal autorizado') }}</span>
            </div>
        </form>
    </div>

    <footer class="foot" id="foot">Customer Relationship Management | MCA School of Business | {{ date('Y') }} · {{ __('Derechos reservados') }}</footer>

    <script>
        const YEAR = "{{ date('Y') }}";
        const eye = document.getElementById('eye'), pass = document.getElementById('password'), foot = document.getElementById('foot');
        eye.addEventListener('click', () => {
            const show = pass.type === 'password';
            pass.type = show ? 'text' : 'password';
            eye.setAttribute('aria-label', show ? 'Ocultar contraseña' : 'Mostrar contraseña');
        });

        // Toggle ES/EN: solo visual (client-side). No persiste el idioma ni envía nada.
        const T = {
            es: {title:'Acceso al CRM', subtitle:'Introduce tus credenciales para continuar', emailLbl:'Correo electrónico', passLbl:'Contraseña', remember:'Recordarme', forgot:'¿Olvidaste tu contraseña?', submit:'Ingresar', note:'Acceso exclusivo para personal autorizado', footTail:'Derechos reservados'},
            en: {title:'CRM Access', subtitle:'Enter your credentials to continue', emailLbl:'Email address', passLbl:'Password', remember:'Remember me', forgot:'Forgot your password?', submit:'Sign in', note:'Authorized personnel only', footTail:'All rights reserved'}
        };
        function render(lang) {
            const t = T[lang];
            document.querySelectorAll('[data-t]').forEach(el => { if (t[el.dataset.t]) el.textContent = t[el.dataset.t]; });
            foot.textContent = 'Customer Relationship Management | MCA School of Business | ' + YEAR + ' · ' + t.footTail;
            document.documentElement.lang = lang;
        }
        document.querySelectorAll('.lang button').forEach(b => {
            b.addEventListener('click', () => {
                document.querySelectorAll('.lang button').forEach(x => x.classList.remove('on'));
                b.classList.add('on');
                render(b.dataset.lang);
            });
        });
    </script>
</body>
</html>
