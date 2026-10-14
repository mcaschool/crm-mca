{{--
  Perfil heredado de Breeze (/profile): ruta conservada por compatibilidad, sin enlace en el
  menú (el perfil del panel es /mi-perfil). Solo presentación con el kit; formularios, rutas
  y nombres de campos intactos.
--}}
<x-app-layout>
    <div class="mca-page">
        <div class="ui-narrow">
            <x-ui.page-header :title="__('Datos de la cuenta')" :subtitle="__('Nombre, correo y contraseña de tu cuenta.')" />

            <div class="ui-stack">
                <section class="ui-card" aria-labelledby="pf-info-title">
                    @include('profile.partials.update-profile-information-form')
                </section>

                <section class="ui-card" aria-labelledby="pf-pass-title">
                    @include('profile.partials.update-password-form')
                </section>
            </div>
        </div>
    </div>
</x-app-layout>
