{{--
  Fuente ÚNICA de tokens de marca MCA (variables CSS globales). Se incluye UNA sola
  vez en el <head> del panel. Valores APROBADOS del prototipo shell_dashboard_v4.
  UN solo azul institucional (#1E5AA8) y dorado tenue de acento (#C9A84C).

  Los consume <x-ui.shell-styles />, <x-ui.home-styles />, <x-ui.styles /> (panel)
  y los componentes de gráfica x-ui (chart-bars, funnel). El acento dorado está
  unificado en --mca-gold (#C9A84C) en todo el panel.
--}}
<style>
@import url("https://fonts.bunny.net/css?family=dm-sans:400,500,600,700");
:root{
  --mca-font:"DM Sans", system-ui, -apple-system, Arial, sans-serif;

  /* Azul institucional único + dorado tenue */
  --mca-blue:#1E5AA8; --mca-blue-deep:#13253D; --mca-blue-soft:#EAF1FA; --mca-blue-hover:#194E93;
  --mca-gold:#C9A84C; --mca-gold-soft:#FBF4DE;

  /* Sidebar claro */
  --mca-nav-bg-1:#EEF2F7; --mca-nav-bg-2:#E7ECF4; --mca-nav-border:#DCE4EF; --mca-nav-label:#8494AB;

  /* Superficies e ink */
  --mca-page-bg:#F1F4F9; --mca-card:#FFFFFF; --mca-card-border:#E5EAF2;
  --mca-ink:#13253D; --mca-ink-2:#5A6B84; --mca-ink-3:#8A99B2;

  /* Estados */
  --mca-ok:#1F9D6B; --mca-ok-soft:#E5F4EE;
  --mca-warn:#C9871F; --mca-warn-soft:#FBF0DC;
  --mca-info:#2C7BC4; --mca-info-soft:#E5F0FA;

  /* Radios y sombras */
  --mca-radius:16px; --mca-radius-sm:11px;
  --mca-shadow:0 1px 2px rgba(19,37,61,.04), 0 6px 20px rgba(19,37,61,.06);
  --mca-shadow-sm:0 1px 2px rgba(19,37,61,.05);
}
</style>
