{{--
  Estilos del SHELL autenticado (sidebar claro + topbar limpia + área central).
  Patrón x-ui: bloque <style> autocontenido, consume var(--mca-*) de <x-ui.tokens />.
  Traduce shell_dashboard_v4 (grupos de navegación delimitados, switcher como
  tarjeta, brand-gap, item activo con barra dorada). Interactividad (colapso móvil,
  menú de perfil hacia arriba) la da Alpine en el layout.
--}}
<style>
.mca-shell{display:flex;min-height:100vh;font-family:var(--mca-font);color:var(--mca-ink);background:var(--mca-page-bg);-webkit-font-smoothing:antialiased}
.mca-shell *{box-sizing:border-box}
.mca-shell .ic{width:18px;height:18px;flex:0 0 auto;vertical-align:middle}

/* ===== SIDEBAR (claro) ===== */
.mca-sidebar{width:264px;flex-shrink:0;background:linear-gradient(180deg,var(--mca-nav-bg-1),var(--mca-nav-bg-2));
  border-right:1px solid var(--mca-nav-border);display:flex;flex-direction:column;padding:18px 14px;position:sticky;top:0;height:100vh;z-index:40}

/* logo */
.mca-brand{display:flex;align-items:center;gap:11px;padding:4px 8px}
.mca-brand .brand-mark{width:38px;height:38px;border-radius:11px;flex-shrink:0;background:linear-gradient(150deg,#20456e,var(--mca-blue-deep));
  display:grid;place-items:center;color:var(--mca-gold);box-shadow:0 4px 10px rgba(19,37,61,.18)}
.mca-brand .brand-mark .ic{width:20px;height:20px}
.mca-brand .brand-name{font-weight:700;font-size:16px;letter-spacing:-.01em;color:var(--mca-ink);line-height:1.1}
.mca-brand .brand-sub{font-size:10.5px;font-weight:600;letter-spacing:.14em;color:var(--mca-nav-label);margin-top:1px}

/* separación clara logo -> switcher */
.mca-brand-gap{height:22px}

/* switcher de línea de producto (tarjeta delimitada; preparada para más líneas) */
.mca-switcher{background:var(--mca-card);border:1px solid var(--mca-nav-border);border-radius:var(--mca-radius-sm);
  padding:11px 12px;display:flex;align-items:center;gap:10px;cursor:default;box-shadow:var(--mca-shadow-sm)}
.mca-switcher .sic{width:32px;height:32px;border-radius:9px;background:var(--mca-blue-soft);color:var(--mca-blue);display:grid;place-items:center;flex-shrink:0}
.mca-switcher .tx{flex:1;min-width:0}
.mca-switcher .t1{font-size:13.5px;font-weight:600;color:var(--mca-ink)}
.mca-switcher .t2{font-size:11px;color:var(--mca-ink-3);margin-top:1px}
.mca-switcher .chev{color:var(--mca-ink-3);flex-shrink:0}

/* grupos de navegación delimitados */
.mca-nav{margin-top:20px;display:flex;flex-direction:column;gap:6px;flex:1;overflow-y:auto}
.mca-nav::-webkit-scrollbar{width:6px}
.mca-nav::-webkit-scrollbar-thumb{background:rgba(19,37,61,.12);border-radius:3px}
.mca-nav-group{background:rgba(255,255,255,.45);border:1px solid rgba(220,228,239,.7);border-radius:var(--mca-radius-sm);padding:8px 7px 9px}
.mca-nav-group + .mca-nav-group{margin-top:4px}
.mca-nav-label{font-size:10.5px;font-weight:700;letter-spacing:.13em;color:var(--mca-nav-label);padding:2px 9px 7px}
.mca-nav-item{display:flex;align-items:center;gap:11px;padding:9px 10px;border-radius:9px;font-size:14px;font-weight:500;color:var(--mca-ink-2);
  cursor:pointer;transition:background .12s,color .12s;position:relative;text-decoration:none}
.mca-nav-item .ic{color:var(--mca-ink-3);transition:color .12s}
.mca-nav-item:hover{background:rgba(30,90,168,.07);color:var(--mca-blue)}
.mca-nav-item:hover .ic{color:var(--mca-blue)}
.mca-nav-item.on{background:var(--mca-card);color:var(--mca-blue);font-weight:600;box-shadow:var(--mca-shadow-sm)}
.mca-nav-item.on .ic{color:var(--mca-blue)}
.mca-nav-item.on::before{content:"";position:absolute;left:0;top:9px;bottom:9px;width:3px;border-radius:3px;background:var(--mca-gold)}
.mca-nav-item .badge{margin-left:auto;background:var(--mca-gold);color:#fff;font-size:10px;font-weight:700;border-radius:999px;padding:1px 7px}

/* perfil abajo + menú (Alpine, abre hacia arriba) */
.mca-sidebar-foot{margin-top:10px;padding-top:2px;border-top:1px solid var(--mca-nav-border);position:relative}
.mca-user{margin-top:8px;display:flex;align-items:center;gap:10px;padding:8px;border-radius:10px;cursor:pointer;width:100%;border:none;background:none;font-family:inherit;text-align:left}
.mca-user:hover{background:rgba(30,90,168,.06)}
.mca-user .av{width:34px;height:34px;border-radius:9px;background:linear-gradient(150deg,#2f8f6b,#1f6d51);color:#fff;display:grid;place-items:center;font-weight:600;font-size:13px;flex-shrink:0;overflow:hidden}
.mca-user .av img{width:100%;height:100%;object-fit:cover}
.mca-user .t1{font-size:13.5px;font-weight:600;color:var(--mca-ink);line-height:1.1}
.mca-user .t2{font-size:11px;color:var(--mca-ink-3)}
.mca-user .chev{margin-left:auto;color:var(--mca-ink-3)}
.mca-user-menu{position:absolute;left:0;right:0;bottom:calc(100% - 2px);background:#fff;border:1px solid var(--mca-card-border);
  border-radius:12px;box-shadow:var(--mca-shadow);padding:6px;z-index:50}
.mca-user-menu a,.mca-user-menu button{display:flex;align-items:center;gap:9px;width:100%;text-align:left;border:none;background:none;
  font-family:inherit;font-size:13.5px;color:var(--mca-ink);padding:9px 10px;border-radius:8px;cursor:pointer;text-decoration:none}
.mca-user-menu a:hover,.mca-user-menu button:hover{background:var(--mca-page-bg)}
.mca-user-menu .ic{color:var(--mca-ink-2)}
.mca-user-menu .sep{height:1px;background:var(--mca-card-border);margin:4px 2px}

/* ===== MAIN ===== */
.mca-main{flex:1;min-width:0;display:flex;flex-direction:column}
.mca-topbar{height:60px;background:rgba(255,255,255,.85);backdrop-filter:blur(6px);border-bottom:1px solid var(--mca-card-border);
  display:flex;align-items:center;gap:14px;padding:0 26px;position:sticky;top:0;z-index:30}
.mca-topbar-title{font-size:16px;font-weight:600;letter-spacing:-.01em;color:var(--mca-ink);margin:0;display:flex;align-items:center;gap:8px}
.mca-topbar-title .tico{width:17px;height:17px;color:var(--mca-ink-3)}
.mca-topbar .sp{flex:1}
.mca-topbar .icon-btn{width:38px;height:38px;border-radius:10px;border:1px solid var(--mca-card-border);background:#fff;display:grid;place-items:center;color:var(--mca-ink-2);cursor:pointer;position:relative}
.mca-topbar .icon-btn .dot{position:absolute;top:9px;right:10px;width:6px;height:6px;border-radius:50%;background:#E5484D}
.mca-topbar .lang{height:38px;padding:0 13px;border-radius:10px;border:1px solid var(--mca-card-border);background:#fff;display:flex;align-items:center;gap:6px;font-size:13px;font-weight:600;color:var(--mca-ink-2)}
.mca-inst{display:inline-flex;align-items:center;gap:6px;height:38px;padding:0 13px;border-radius:10px;border:1px solid var(--mca-card-border);background:#fff;font-size:13px;font-weight:600;color:var(--mca-ink-2);cursor:pointer;font-family:inherit}

/* área de contenido: cada página trae su propio padding/fondo. El respiro derecho
   lo da un GUTTER fijo (padding-right), no un max-width: así el contenido se estira
   a lo ancho de forma consistente (y en Inicio el carril queda a ras del gutter,
   sin hueco extra a su derecha). */
.mca-content{flex:1;min-width:0;padding-right:20px}
@media(max-width:640px){.mca-content{padding-right:0}}

/* hamburguesa + backdrop (solo móvil) */
.mca-hamburger{display:none;background:none;border:none;color:var(--mca-ink);cursor:pointer;padding:6px;margin-left:-6px}
.mca-backdrop{display:none}

/* ===== responsive: sidebar off-canvas < 1024px ===== */
@media(max-width:1023px){
  .mca-sidebar{position:fixed;top:0;left:0;bottom:0;height:100vh;transform:translateX(-100%);transition:transform .22s ease;box-shadow:0 0 40px rgba(19,37,61,.18)}
  .mca-sidebar.open{transform:translateX(0)}
  .mca-hamburger{display:inline-flex}
  .mca-backdrop.show{display:block;position:fixed;inset:0;background:rgba(19,30,45,.42);z-index:35}
}
@media (prefers-reduced-motion: reduce){ .mca-shell *{transition:none !important} }
</style>
