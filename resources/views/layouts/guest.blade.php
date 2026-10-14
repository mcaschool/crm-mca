{{--
  Layout de las pantallas de acceso secundarias (recuperar y restablecer contraseña, verificación
  en dos pasos). Mismo diseño que el login aprobado (auth.partials.styles): fondo institucional,
  logotipo y tarjeta. El slot es el contenido de la tarjeta.
--}}
<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <meta name="csrf-token" content="{{ csrf_token() }}">

        <title>MCA · {{ $title ?? __('Acceso al CRM') }}</title>

        @include('auth.partials.styles')
    </head>
    <body>
        <div class="scene" aria-hidden="true"><div class="vignette"></div></div>

        <main class="wrap">
            <div class="card">
                <div class="brand">
                    <img src="{{ asset('images/logo.png') }}" alt="MCA School of Business and Postgraduate">
                </div>

                {{ $slot }}
            </div>
        </main>

        <footer class="foot">Customer Relationship Management | MCA School of Business | {{ date('Y') }} · {{ __('Derechos reservados') }}</footer>
    </body>
</html>
