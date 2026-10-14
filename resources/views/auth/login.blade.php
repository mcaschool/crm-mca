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
    @include("auth.partials.styles")
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
                <div class="alert alert-danger" role="alert" id="login-error">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><path d="M12 8v4"/><path d="M12 16h.01"/></svg>
                    <span>{{ $errors->first() }}</span>
                </div>
            @endif

            <div class="field">
                <label for="email" data-t="emailLbl">{{ __('Correo electrónico') }}</label>
                <div class="input @error('email') has-error @enderror">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><rect x="2" y="4" width="20" height="16" rx="2"/><path d="m22 7-10 6L2 7"/></svg>
                    <input id="email" type="email" name="email" value="{{ old('email') }}" required autofocus autocomplete="username" placeholder="nombre@mcaschool.education" @if ($errors->any()) aria-invalid="true" aria-describedby="login-error" @endif>
                </div>
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
            eye.setAttribute('aria-label', T[document.documentElement.lang === 'en' ? 'en' : 'es'][show ? 'hide' : 'show']);
        });

        // Toggle ES/EN: solo visual (client-side). No persiste el idioma ni envía nada.
        const T = {
            es: {title:'Acceso al CRM', subtitle:'Introduce tus credenciales para continuar', emailLbl:'Correo electrónico', passLbl:'Contraseña', remember:'Recordarme', forgot:'¿Olvidaste tu contraseña?', submit:'Ingresar', note:'Acceso exclusivo para personal autorizado', footTail:'Derechos reservados', show:'Mostrar contraseña', hide:'Ocultar contraseña'},
            en: {title:'CRM Access', subtitle:'Enter your credentials to continue', emailLbl:'Email address', passLbl:'Password', remember:'Remember me', forgot:'Forgot your password?', submit:'Sign in', note:'Authorized personnel only', footTail:'All rights reserved', show:'Show password', hide:'Hide password'}
        };
        function render(lang) {
            const t = T[lang];
            document.querySelectorAll('[data-t]').forEach(el => { if (t[el.dataset.t]) el.textContent = t[el.dataset.t]; });
            foot.textContent = 'Customer Relationship Management | MCA School of Business | ' + YEAR + ' · ' + t.footTail;
            document.documentElement.lang = lang;
            eye.setAttribute('aria-label', t[pass.type === 'password' ? 'show' : 'hide']);
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
