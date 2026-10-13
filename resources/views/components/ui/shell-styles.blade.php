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
.mca-sidebar{width:256px;flex-shrink:0;background:var(--mca-nav-bg-1);
  border-right:1px solid var(--mca-nav-border);display:flex;flex-direction:column;padding:16px 12px;position:sticky;top:0;height:100vh;z-index:40}

/* logo */
.mca-brand{display:flex;align-items:center;gap:11px;padding:4px 8px}
.mca-brand .brand-mark{width:38px;height:38px;border-radius:11px;flex-shrink:0;background:linear-gradient(150deg,#20456e,var(--mca-blue-deep));
  display:grid;place-items:center;color:var(--mca-gold);box-shadow:0 4px 10px rgba(19,37,61,.18)}
.mca-brand .brand-mark .ic{width:20px;height:20px}
.mca-brand .brand-name{font-weight:700;font-size:16px;letter-spacing:-.01em;color:var(--mca-ink);line-height:1.1}
.mca-brand .brand-sub{font-size:10.5px;font-weight:600;letter-spacing:.12em;color:var(--mca-text-3);margin-top:2px}

/* separación clara logo -> switcher */
.mca-brand-gap{height:12px}

/* grupos de navegación delimitados */
.mca-nav{margin-top:12px;display:flex;flex-direction:column;gap:2px;flex:1;overflow-y:auto}
.mca-nav::-webkit-scrollbar{width:6px}
.mca-nav::-webkit-scrollbar-thumb{background:rgba(19,37,61,.12);border-radius:3px}
/* grupos planos, separados por aire y una línea fina; etiquetas AA */
.mca-nav-group{display:flex;flex-direction:column;gap:1px;padding:4px 0}
.mca-nav-group + .mca-nav-group{margin-top:10px;padding-top:12px;border-top:1px solid var(--mca-nav-border)}
.mca-nav-label{font-size:11px;font-weight:600;letter-spacing:.08em;color:var(--mca-text-3);padding:0 10px 6px}
.mca-nav-item{display:flex;align-items:center;gap:10px;min-height:36px;padding:0 10px;border-radius:var(--mca-r-ctl);font-size:13.5px;font-weight:500;color:var(--mca-text-2);
  cursor:pointer;transition:background .12s,color .12s;position:relative;text-decoration:none}
.mca-nav-item .ic{width:17px;height:17px;color:var(--mca-text-3);transition:color .12s}
.mca-nav-item:hover{background:rgba(19,37,61,.055);color:var(--mca-text)}
.mca-nav-item:hover .ic{color:var(--mca-text-2)}
.mca-nav-item.on{background:var(--mca-surface);color:var(--mca-blue);font-weight:600;box-shadow:0 0 0 1px var(--mca-line),var(--mca-shadow-xs)}
.mca-nav-item.on .ic{color:var(--mca-blue)}
.mca-nav-item.on::before{content:"";position:absolute;left:0;top:8px;bottom:8px;width:3px;border-radius:0 3px 3px 0;background:var(--mca-gold)}
.mca-nav-item .badge{margin-left:auto;background:var(--mca-gold);color:var(--mca-blue-deep);font-size:11px;font-weight:700;border-radius:999px;padding:1px 7px;font-variant-numeric:tabular-nums}

/* perfil abajo + menú (Alpine, abre hacia arriba) */
.mca-sidebar-foot{margin-top:10px;padding-top:2px;border-top:1px solid var(--mca-nav-border);position:relative}
.mca-user{margin-top:8px;display:flex;align-items:center;gap:10px;padding:8px;border-radius:10px;cursor:pointer;width:100%;border:none;background:none;font-family:inherit;text-align:left}
.mca-user:hover{background:rgba(30,90,168,.06)}
.mca-user .av{width:34px;height:34px;border-radius:9px;background:linear-gradient(150deg,#2f8f6b,#1f6d51);color:#fff;display:grid;place-items:center;font-weight:600;font-size:13px;flex-shrink:0;overflow:hidden}
.mca-user .av img{width:100%;height:100%;object-fit:cover}
.mca-user .t1{font-size:13.5px;font-weight:600;color:var(--mca-ink);line-height:1.1}
.mca-user .t2{font-size:11.5px;color:var(--mca-text-3)}
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
.mca-topbar{height:56px;background:rgba(255,255,255,.92);backdrop-filter:saturate(1.4) blur(8px);border-bottom:1px solid var(--mca-line);
  display:flex;align-items:center;gap:6px;padding:0 var(--mca-gutter);position:sticky;top:0;z-index:30}
.mca-topbar .sp{flex:1}

/* migas de pan (sección › página). En móvil solo la página actual, en una línea. */
.mca-crumbs{display:flex;align-items:center;gap:8px;min-width:0;font-size:13.5px;margin-right:8px}
.mca-crumbs .tico{width:16px;height:16px;color:var(--mca-text-3);flex:0 0 auto}
.mca-crumbs ol{display:flex;align-items:center;min-width:0;margin:0;padding:0;list-style:none}
.mca-crumbs li{display:flex;align-items:center;min-width:0;white-space:nowrap}
.mca-crumbs li + li::before{content:"";width:6px;height:6px;margin:0 10px 0 9px;border-right:1.5px solid var(--mca-text-3);border-top:1.5px solid var(--mca-text-3);transform:rotate(45deg);flex:0 0 auto}
.mca-crumbs a{color:var(--mca-text-2);text-decoration:none;font-weight:500;border-radius:6px}
.mca-crumbs a:hover{color:var(--mca-blue)}
.mca-crumbs [aria-current]{color:var(--mca-text);font-weight:600;overflow:hidden;text-overflow:ellipsis}
/* acciones de la barra: botones de icono sin caja, con fondo solo al pasar */
.mca-topbar .icon-btn{width:36px;height:36px;border-radius:var(--mca-r-ctl);border:1px solid transparent;background:transparent;display:grid;place-items:center;color:var(--mca-text-2);cursor:pointer;position:relative;transition:background-color .12s,color .12s}
.mca-topbar .icon-btn:hover{background:rgba(19,37,61,.055);color:var(--mca-text)}
.mca-topbar .icon-btn .dot{position:absolute;top:8px;right:9px;width:7px;height:7px;border-radius:50%;background:var(--mca-danger);box-shadow:0 0 0 2px #fff}
.mca-topbar .lang{height:36px;padding:0 10px;margin-left:4px;border-radius:var(--mca-r-ctl);border:1px solid var(--mca-line-strong);background:var(--mca-surface);display:flex;align-items:center;gap:5px;font-size:13px;font-weight:600;color:var(--mca-text-2)}
.mca-topbar .lang:hover{background:var(--mca-surface-2)}
.mca-inst{display:inline-flex;align-items:center;gap:6px;height:36px;padding:0 12px;border-radius:var(--mca-r-ctl);border:1px solid var(--mca-line-strong);background:var(--mca-surface);font-size:13px;font-weight:600;color:var(--mca-text-2);cursor:pointer;font-family:inherit}

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
  .mca-topbar{gap:2px}
  .mca-topbar .icon-btn{width:34px;height:34px}
  .mca-topbar .lang{height:34px;padding:0 8px}
  .mca-crumbs .tico,.mca-crumbs li:not(:last-child){display:none}
  .mca-crumbs{font-size:15px}
}

/* cabecera de página: título (y contador) + descripción + acciones a la derecha */
.mca-pagehead{display:flex;align-items:flex-end;justify-content:space-between;gap:12px 16px;flex-wrap:wrap;margin:0 0 20px}
.mca-pagehead .ph-text{min-width:0;flex:1 1 280px}
.mca-pagehead .ph-back{display:inline-flex;align-items:center;gap:4px;font-size:13px;font-weight:500;color:var(--mca-text-2);text-decoration:none;margin-bottom:6px;border-radius:6px}
.mca-pagehead .ph-back:hover{color:var(--mca-blue)}
.mca-pagehead .ph-back .ic{width:16px;height:16px}
.mca-pagehead h1{display:flex;align-items:center;gap:10px;flex-wrap:wrap;margin:0;font-size:var(--mca-fs-xl);line-height:1.2;font-weight:700;letter-spacing:-.02em;color:var(--mca-text)}
.mca-pagehead .ph-count{display:inline-flex;align-items:center;height:22px;font-size:12.5px;font-weight:600;letter-spacing:0;color:var(--mca-text-2);background:var(--mca-neutral-soft);border-radius:999px;padding:0 8px;font-variant-numeric:tabular-nums}
.mca-pagehead .ph-sub{margin:6px 0 0;font-size:14px;line-height:1.45;color:var(--mca-text-2);max-width:72ch}
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

/* listados en móvil: el patrón de tarjetas vive en x-ui.kit (.ui-table). Aquí solo se libera
   la columna fija, que en tarjetas no tiene sentido. */
@media(max-width:767px){
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
