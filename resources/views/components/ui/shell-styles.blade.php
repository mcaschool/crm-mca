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
  display:flex;align-items:center;gap:14px;padding:0 var(--mca-gutter);position:sticky;top:0;z-index:30}
.mca-topbar .sp{flex:1}

/* migas de pan (sección › página). En móvil solo la página actual, en una línea. */
.mca-crumbs{display:flex;align-items:center;gap:8px;min-width:0;font-size:14px}
.mca-crumbs .tico{width:17px;height:17px;color:var(--mca-ink-3);flex:0 0 auto}
.mca-crumbs ol{display:flex;align-items:center;min-width:0;margin:0;padding:0;list-style:none}
.mca-crumbs li{display:flex;align-items:center;min-width:0;white-space:nowrap}
.mca-crumbs li + li::before{content:"";width:6px;height:6px;margin:0 10px 0 9px;border-right:1.5px solid var(--mca-ink-3);border-top:1.5px solid var(--mca-ink-3);transform:rotate(45deg);flex:0 0 auto}
.mca-crumbs a{color:var(--mca-ink-2);text-decoration:none;font-weight:500;border-radius:6px}
.mca-crumbs a:hover{color:var(--mca-blue)}
.mca-crumbs [aria-current]{color:var(--mca-ink);font-weight:600;overflow:hidden;text-overflow:ellipsis}
.mca-topbar .icon-btn{width:38px;height:38px;border-radius:10px;border:1px solid var(--mca-card-border);background:#fff;display:grid;place-items:center;color:var(--mca-ink-2);cursor:pointer;position:relative}
.mca-topbar .icon-btn .dot{position:absolute;top:9px;right:10px;width:6px;height:6px;border-radius:50%;background:#E5484D}
.mca-topbar .lang{height:38px;padding:0 13px;border-radius:10px;border:1px solid var(--mca-card-border);background:#fff;display:flex;align-items:center;gap:6px;font-size:13px;font-weight:600;color:var(--mca-ink-2)}
.mca-inst{display:inline-flex;align-items:center;gap:6px;height:38px;padding:0 13px;border-radius:10px;border:1px solid var(--mca-card-border);background:#fff;font-size:13px;font-weight:600;color:var(--mca-ink-2);cursor:pointer;font-family:inherit}

/* área de contenido: MARCO COMÚN de todas las páginas internas. El cuerpo ocupa todo
   el ancho disponible junto al menú (sin carriles reservados), con un máximo legible en
   pantallas muy anchas. El margen interior lo da .mca-page, igual en todas las páginas
   y alineado con la barra superior (--mca-gutter). */
.mca-shell{--mca-gutter:28px}
.mca-content{flex:1;min-width:0}
.mca-canvas{min-width:0}
.mca-canvas-body{min-width:0;max-width:1680px}
.mca-page{padding:24px var(--mca-gutter) 40px;min-width:0}
@media(max-width:1023px){.mca-shell{--mca-gutter:20px}.mca-page{padding-top:20px;padding-bottom:32px}}
@media(max-width:640px){
  .mca-shell{--mca-gutter:16px}
  .mca-page{padding-top:16px;padding-bottom:28px}
  /* barra superior compacta: más espacio para el nombre de la página */
  .mca-topbar{gap:6px}
  .mca-topbar .icon-btn{width:34px;height:34px}
  .mca-topbar .lang{height:34px;padding:0 8px}
  .mca-crumbs .tico,.mca-crumbs li:not(:last-child){display:none}
  .mca-crumbs{font-size:15px}
}

/* cabecera de página: título (y contador) + descripción + acciones a la derecha */
.mca-pagehead{display:flex;align-items:flex-start;justify-content:space-between;gap:12px 16px;flex-wrap:wrap;margin:0 0 20px}
.mca-pagehead .ph-text{min-width:0;flex:1 1 280px}
.mca-pagehead .ph-back{display:inline-flex;align-items:center;gap:4px;font-size:13px;font-weight:500;color:var(--mca-ink-2);text-decoration:none;margin-bottom:6px;border-radius:6px}
.mca-pagehead .ph-back:hover{color:var(--mca-blue)}
.mca-pagehead .ph-back .ic{width:16px;height:16px}
.mca-pagehead h1{display:flex;align-items:center;gap:10px;flex-wrap:wrap;margin:0;font-size:20px;line-height:1.25;font-weight:700;letter-spacing:-.01em;color:var(--mca-ink)}
.mca-pagehead .ph-count{font-size:12.5px;font-weight:600;color:var(--mca-ink-2);background:var(--mca-card);border:1px solid var(--mca-card-border);border-radius:999px;padding:1px 9px;font-variant-numeric:tabular-nums}
.mca-pagehead .ph-sub{margin:4px 0 0;font-size:14px;color:var(--mca-ink-2);max-width:72ch}
.mca-pagehead .ph-actions{display:flex;align-items:center;gap:8px;flex-wrap:wrap;max-width:100%}

/* tablas anchas: desplazamiento horizontal propio (nunca recorte), primera columna fija y
   sombras laterales que avisan de que hay más contenido a izquierda o derecha */
.mca-table-scroll{overflow-x:auto;-webkit-overflow-scrolling:touch;overscroll-behavior-x:contain;
  background:linear-gradient(90deg,var(--mca-card) 30%,rgba(255,255,255,0)) left center/40px 100% no-repeat local,
             linear-gradient(270deg,var(--mca-card) 30%,rgba(255,255,255,0)) right center/40px 100% no-repeat local,
             radial-gradient(farthest-side at 0 50%,rgba(19,37,61,.14),rgba(19,37,61,0)) left center/12px 100% no-repeat scroll,
             radial-gradient(farthest-side at 100% 50%,rgba(19,37,61,.14),rgba(19,37,61,0)) right center/12px 100% no-repeat scroll}
.mca-table-scroll > table{min-width:100%;width:max-content}
.mca-table-scroll > table th,.mca-table-scroll > table td{white-space:nowrap}
.mca-table-scroll > table .rt-wrap{white-space:normal}
.mca-table-scroll > table .rt-w-sm,.mca-table-scroll > table .rt-w-md{white-space:normal;overflow-wrap:anywhere}
.mca-table-scroll > table .rt-w-sm{max-width:180px}
.mca-table-scroll > table .rt-w-md{max-width:240px}
.mca-table-scroll .rt-sticky{position:sticky;left:0;z-index:1}
.mca-table-scroll td.rt-sticky{background:var(--mca-card)}
.mca-table-scroll tbody tr:hover > td.rt-sticky{background:#F7FAFE}
.mca-table-scroll th.rt-sticky{background:#FBFCFE}

/* listados en móvil: cada fila pasa a ser una tarjeta con etiqueta + valor; nada se oculta.
   La primera celda (.rt-primary) encabeza la tarjeta y las acciones (.rt-actions) quedan al pie. */
@media(max-width:767px){
  table.mca-rtable{width:100%;min-width:0}
  table.mca-rtable thead{position:absolute;width:1px;height:1px;overflow:hidden;clip:rect(0 0 0 0);white-space:nowrap}
  table.mca-rtable,table.mca-rtable tbody{display:block}
  table.mca-rtable tr{display:block;padding:14px 16px;border-top:1px solid var(--mca-card-border)}
  table.mca-rtable tbody tr:first-child{border-top:0}
  table.mca-rtable td{display:flex;align-items:baseline;gap:12px;padding:3px 0 !important;border:0 !important;white-space:normal;min-width:0;text-align:left !important;background:none}
  table.mca-rtable td[data-label]::before{content:attr(data-label);flex:0 0 96px;font-size:11px;font-weight:600;letter-spacing:.05em;text-transform:uppercase;color:var(--mca-ink-3);padding-top:2px}
  /* «anywhere» solo parte una palabra si no cabe en la línea (correos largos) */
  table.mca-rtable td,table.mca-rtable td > *{min-width:0;overflow-wrap:anywhere}
  .mca-table-scroll > table.mca-rtable .rt-w-sm,.mca-table-scroll > table.mca-rtable .rt-w-md{max-width:none}
  table.mca-rtable td.rt-primary{padding-bottom:8px !important}
  table.mca-rtable td.rt-actions{justify-content:flex-start;flex-wrap:wrap;padding-top:10px !important}
  table.mca-rtable td.rt-empty{display:block;text-align:center !important;padding:28px 8px !important}
  .mca-table-scroll:has(> table.mca-rtable){overflow-x:visible;background:none}
  .mca-table-scroll > table.mca-rtable{width:100%}
  .mca-table-scroll .rt-sticky{position:static}
}

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
/* foco de teclado visible en la navegación y la barra superior */
.mca-nav-item:focus-visible,.mca-crumbs a:focus-visible,.mca-topbar button:focus-visible,.mca-user:focus-visible,.mca-pagehead a:focus-visible{outline:2px solid var(--mca-blue);outline-offset:2px}
@media (prefers-reduced-motion: reduce){ .mca-shell *{transition:none !important} }
</style>
