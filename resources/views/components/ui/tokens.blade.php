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

  /* ===== Escala del sistema de componentes (etapa 2) =====
     La consumen el kit de componentes (x-ui.kit) y el shell. Los tokens de arriba siguen vigentes para las
     pantallas aún no migradas; se reasignarán a esta escala al extender el sistema.
     Todos los pares texto/fondo cumplen WCAG AA (≥4.5:1). */

  /* Texto: principal 15.4:1 · secundario 7.5:1 · terciario 5.4:1 (4.8:1 sobre el menú) */
  --mca-text:#13253D; --mca-text-2:#46566E; --mca-text-3:#5A6B83; --mca-text-dis:#8593A8;

  /* Superficies y bordes */
  --mca-surface:#FFFFFF; --mca-surface-2:#F8FAFC; --mca-surface-hover:#F5F8FC;
  --mca-line:#E3E8EF; --mca-line-strong:#D2DAE5;
  --mca-focus:0 0 0 3px rgba(30,90,168,.22);

  /* Tonos semánticos: texto (≥5:1 sobre su fondo suave) + fondo suave */
  --mca-success:#1E6B45; --mca-success-soft:#E7F4EC;
  --mca-warning:#8A5A0C; --mca-warning-soft:#FBF1DC;
  --mca-danger:#B42318;  --mca-danger-soft:#FDECEC;
  --mca-teal:#0E6570;    --mca-teal-soft:#E0F3F4;
  --mca-neutral:#55657C; --mca-neutral-soft:#EEF1F5;
  --mca-gold-ink:#7A5B12; --mca-gold-line:#EBDDB2;

  /* Tipografía: 12 · 13 · 14 · 16 · 22 · 28 */
  --mca-fs-xs:12px; --mca-fs-sm:13px; --mca-fs-md:14px; --mca-fs-lg:16px; --mca-fs-xl:22px; --mca-fs-2xl:28px;

  /* Controles: 32 compacto · 36 estándar (barras y tablas) · 40 formularios */
  --mca-h-sm:32px; --mca-h-md:36px; --mca-h-lg:40px;
  --mca-r-ctl:8px; --mca-r-card:12px;
  --mca-shadow-xs:0 1px 2px rgba(16,24,40,.05);
  --mca-shadow-card:0 1px 2px rgba(16,24,40,.04), 0 1px 3px rgba(16,24,40,.04);
  --mca-shadow-pop:0 12px 32px -8px rgba(19,37,61,.20), 0 2px 6px rgba(19,37,61,.06);
}
</style>
