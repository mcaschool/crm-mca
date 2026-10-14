{{--
  Capa de COMPONENTES del sistema de diseño MCA (etapa 2). Global y única: se carga una
  vez en el layout, justo después de <x-ui.tokens />, y solo consume var(--mca-*).

  No es otra familia de estilos: es la base común hacia la que migran las familias
  heredadas (.mca-panel de x-ui.styles y .crm-page de x-ui.crm-styles) pantalla a
  pantalla. Prefijo único `ui-`; las pantallas no definen estilos propios para botones,
  campos, insignias, tablas ni tarjetas. Componentes Blade que la usan: x-ui.button,
  x-ui.badge, x-ui.avatar, x-ui.search, x-ui.select, x-ui.filters, x-ui.kpi, x-ui.pager,
  x-ui.lead-status.

  Tonos: cada componente con color lee --tone/--tone-soft de una clase .ui-tone--*.
  Tabla en móvil (tarjetas): .rt-primary encabeza, .rt-aside (estado) arriba a la derecha,
  .rt-foot (controles secundarios) y .rt-actions al pie; variantes de tabla
  .ui-table--meta3 (tres datos cortos por fila) y .ui-table--stack-aside (insignias largas).
  Fichas en rejilla: .ui-grid > .ui-card.ui-entity (cabecera, .ui-meta y pie .ui-card-f).
  Ficha de un registro: .ui-record (cabecera .ui-record-h + rejilla .ui-record-grid con
  .ui-record-main / .ui-record-side), .ui-subnav por anclas, .ui-list, .ui-timeline,
  .ui-chat, .ui-callout, .ui-menu, .ui-modal. Formularios: x-ui.field + .ui-form-grid.
--}}
<style>
/* solo para lectores de pantalla */
.ui-sr{position:absolute;width:1px;height:1px;padding:0;margin:-1px;overflow:hidden;clip:rect(0,0,0,0);white-space:nowrap;border:0}
@media(max-width:640px){.ui-hide-sm{display:none}}

/* ---------- tonos (texto ≥5:1 sobre su fondo suave) ---------- */
.ui-tone--blue{--tone:var(--mca-blue);--tone-soft:var(--mca-blue-soft)}
.ui-tone--teal{--tone:var(--mca-teal);--tone-soft:var(--mca-teal-soft)}
.ui-tone--success{--tone:var(--mca-success);--tone-soft:var(--mca-success-soft)}
.ui-tone--warning{--tone:var(--mca-warning);--tone-soft:var(--mca-warning-soft)}
.ui-tone--danger{--tone:var(--mca-danger);--tone-soft:var(--mca-danger-soft)}
.ui-tone--neutral{--tone:var(--mca-neutral);--tone-soft:var(--mca-neutral-soft)}
.ui-tone--gold{--tone:var(--mca-gold-ink);--tone-soft:var(--mca-gold-soft)}
.ui-tone--deep{--tone:var(--mca-blue-deep);--tone-soft:#E3E9F2}

/* ---------- botones: primario (una sola acción principal por zona), secundario, ghost ---------- */
.ui-btn{--h:var(--mca-h-md);display:inline-flex;align-items:center;justify-content:center;gap:7px;height:var(--h);padding:0 14px;
  border-radius:var(--mca-r-ctl);border:1px solid transparent;font-family:var(--mca-font);font-size:13.5px;font-weight:600;line-height:1;
  letter-spacing:-.005em;white-space:nowrap;text-decoration:none;cursor:pointer;user-select:none;
  transition:background-color .14s,border-color .14s,color .14s,box-shadow .14s}
.ui-btn .ic{width:16px;height:16px;flex:0 0 auto}
.ui-btn:focus-visible{outline:none;box-shadow:var(--mca-focus)}
.ui-btn:disabled,.ui-btn[aria-disabled=true]{opacity:.5;cursor:default;pointer-events:none}
.ui-btn--primary{background:var(--mca-blue);border-color:var(--mca-blue-hover);color:#fff;
  box-shadow:inset 0 1px 0 rgba(255,255,255,.14),0 1px 2px rgba(19,37,61,.18)}
.ui-btn--primary:hover{background:var(--mca-blue-hover)}
.ui-btn--primary:focus-visible{box-shadow:var(--mca-focus),inset 0 1px 0 rgba(255,255,255,.14)}
.ui-btn--secondary{background:var(--mca-surface);border-color:var(--mca-line-strong);color:var(--mca-text);box-shadow:var(--mca-shadow-xs)}
.ui-btn--secondary .ic{color:var(--mca-text-2)}
.ui-btn--secondary:hover{background:var(--mca-surface-2);border-color:#C3CDDB}
.ui-btn--ghost{background:transparent;color:var(--mca-text-2)}
.ui-btn--ghost:hover{background:rgba(19,37,61,.055);color:var(--mca-text)}
.ui-btn--danger{background:var(--mca-surface);border-color:#F0C9C5;color:var(--mca-danger)}
.ui-btn--danger:hover{background:var(--mca-danger-soft)}
.ui-btn--sm{--h:var(--mca-h-sm);padding:0 11px;font-size:13px;gap:6px}
.ui-btn--sm .ic{width:15px;height:15px}
.ui-btn--icon{width:var(--h);padding:0}

/* ---------- campos: 36px en barras de filtros, 40px en formularios ---------- */
.ui-input,.ui-select{height:var(--mca-h-md);border:1px solid var(--mca-line-strong);border-radius:var(--mca-r-ctl);background:var(--mca-surface);
  font-family:var(--mca-font);font-size:13.5px;color:var(--mca-text);padding:0 12px;box-shadow:var(--mca-shadow-xs);
  transition:border-color .14s,box-shadow .14s,background-color .14s;min-width:0}
.ui-input::placeholder{color:var(--mca-text-3);opacity:1}
.ui-input:hover,.ui-select:hover{border-color:#C3CDDB}
.ui-input:focus,.ui-select:focus{outline:none;border-color:var(--mca-blue);box-shadow:var(--mca-focus)}
.ui-input--lg,.ui-select--lg{height:var(--mca-h-lg);font-size:14px}
.ui-select{appearance:none;-webkit-appearance:none;cursor:pointer;padding-right:32px;font-weight:500;
  background:var(--mca-surface) url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='16' height='16' viewBox='0 0 24 24' fill='none' stroke='%235A6B83' stroke-width='2' stroke-linecap='round' stroke-linejoin='round'%3E%3Cpath d='m6 9 6 6 6-6'/%3E%3C/svg%3E") no-repeat right 10px center}
/* filtro aplicado (x-ui.select :active): se distingue de un filtro en «todos» sin leer su texto */
.ui-select--filter.is-active,.ui-input--filter.is-active{border-color:#9DB8DD;background-color:var(--mca-blue-soft);color:var(--mca-blue-deep)}
.ui-search{position:relative;display:flex;align-items:center;min-width:0}
.ui-search .ic{position:absolute;left:11px;width:16px;height:16px;color:var(--mca-text-3);pointer-events:none}
.ui-search .ui-input{width:100%;padding-left:35px}
.ui-search .ui-input::-webkit-search-cancel-button{cursor:pointer}

/* ---------- insignias: estado (con punto), categoría y conteo ---------- */
.ui-badge{display:inline-flex;align-items:center;gap:6px;height:22px;padding:0 8px;border-radius:6px;font-size:12px;font-weight:600;line-height:1;
  white-space:nowrap;color:var(--tone,var(--mca-neutral));background:var(--tone-soft,var(--mca-neutral-soft));
  box-shadow:inset 0 0 0 1px color-mix(in srgb,var(--tone,var(--mca-neutral)) 14%,transparent)}
.ui-badge .ic{width:13px;height:13px}
.ui-badge .dot{width:6px;height:6px;border-radius:50%;background:currentColor;flex:0 0 auto}
.ui-badge--pulse .dot{animation:uiPulse 1.8s ease-in-out infinite}
.ui-badge--count{height:20px;padding:0 7px;border-radius:999px;font-variant-numeric:tabular-nums;box-shadow:none}
@keyframes uiPulse{0%,100%{opacity:1;transform:scale(1)}50%{opacity:.4;transform:scale(.7)}}

/* ---------- avatar con iniciales (tono estable por persona) ---------- */
.ui-avatar{--s:32px;width:var(--s);height:var(--s);border-radius:50%;flex:0 0 auto;display:grid;place-items:center;overflow:hidden;
  font-size:12px;font-weight:600;letter-spacing:.01em;color:var(--tone,var(--mca-blue));background:var(--tone-soft,var(--mca-blue-soft))}
.ui-avatar img{width:100%;height:100%;object-fit:cover}
.ui-avatar .ic{width:16px;height:16px}
.ui-avatar--lg{--s:40px;font-size:14px}
.ui-avatar--sq{border-radius:9px}

/* persona: avatar + nombre + dato secundario */
.ui-person{display:flex;align-items:center;gap:12px;min-width:0}
.ui-person-text{display:flex;flex-direction:column;gap:2px;min-width:0}
.ui-person-name{font-size:14px;font-weight:600;color:var(--mca-text);text-decoration:none;line-height:1.3}
a.ui-person-name:hover{color:var(--mca-blue)}
a.ui-person-name:focus-visible{outline:2px solid var(--mca-blue);outline-offset:2px;border-radius:3px}
.ui-person-mail{font-size:13px;color:var(--mca-text-3);line-height:1.3}
.ui-person-line{display:flex;align-items:center;gap:8px;flex-wrap:wrap;min-width:0}
/* celda con título y dato secundario (programas, entidades…) */
.ui-cell-title{font-size:14px;font-weight:600;color:var(--mca-text);line-height:1.3}
.ui-cell-sub{font-size:12.5px;color:var(--mca-text-3);line-height:1.3}

/* ---------- tarjeta / panel ---------- */
.ui-card{background:var(--mca-surface);border:1px solid var(--mca-line);border-radius:var(--mca-r-card);box-shadow:var(--mca-shadow-card);min-width:0}
.ui-card-h{display:flex;align-items:center;flex-wrap:wrap;gap:8px 10px;min-height:52px;padding:10px 18px;border-bottom:1px solid var(--mca-line)}
.ui-card-h h2,.ui-card-h h3{min-width:0;margin:0;font-size:14px;font-weight:600;color:var(--mca-text);letter-spacing:-.005em}
.ui-card-h .ui-card-ic{width:16px;height:16px;color:var(--mca-text-3)}
.ui-card-h .sp{flex:1}
.ui-card-b{padding:6px}
.ui-card--clip{overflow:hidden}
.ui-link{display:inline-flex;align-items:center;gap:4px;white-space:nowrap;font-size:13px;font-weight:600;color:var(--mca-blue);text-decoration:none;border-radius:4px}
.ui-link .ic{width:14px;height:14px}
.ui-link:hover{color:var(--mca-blue-hover);text-decoration:underline;text-underline-offset:3px}
.ui-link:focus-visible{outline:2px solid var(--mca-blue);outline-offset:2px}

/* ---------- barra de herramientas de un listado ---------- */
.ui-toolbar{display:flex;align-items:center;gap:8px;flex-wrap:wrap;padding:12px 16px;border-bottom:1px solid var(--mca-line)}
.ui-toolbar .ui-search{flex:1 1 260px;max-width:380px}
.ui-toolbar .sp{flex:1 1 0}
.ui-filter-note{display:flex;align-items:center;gap:8px;flex-wrap:wrap;padding:10px 16px;border-bottom:1px solid var(--mca-line);background:var(--mca-surface-2);font-size:13px;color:var(--mca-text-2)}
.ui-filter-note .ic{width:15px;height:15px;color:var(--mca-text-3)}

/* ---------- tabla de datos ---------- */
.ui-table{width:100%;border-collapse:separate;border-spacing:0;font-size:13.5px;color:var(--mca-text);font-variant-numeric:tabular-nums}
.ui-table thead th{position:relative;height:40px;padding:0 16px;text-align:left;font-size:12px;font-weight:600;color:var(--mca-text-3);background:var(--mca-surface-2);border-bottom:1px solid var(--mca-line);letter-spacing:.01em}
.ui-table td{padding:12px 16px;border-bottom:1px solid var(--mca-line);vertical-align:middle;height:60px}
.ui-table th:first-child,.ui-table td:first-child{padding-left:20px}
.ui-table th:last-child,.ui-table td:last-child{padding-right:20px}
.ui-table tbody tr:last-child td{border-bottom:0}
.ui-table tbody tr{transition:background-color .1s}
.ui-table tbody tr:hover > td{background:var(--mca-surface-hover)}
.ui-table tr.is-link{cursor:pointer}
.ui-table .t-2{color:var(--mca-text-2)}
.ui-table .t-3{color:var(--mca-text-3)}
.ui-table .t-end{text-align:right}
.ui-table .t-nil{color:var(--mca-text-3)}
.ui-table .t-sub{display:block;font-size:12.5px;color:var(--mca-text-3);margin-top:2px}
.ui-table .t-mono,.ui-mono{font-family:ui-monospace,SFMono-Regular,Menlo,Consolas,monospace;font-size:12.5px;letter-spacing:0}
.ui-table .t-src{display:inline-flex;align-items:center;gap:7px;color:var(--mca-text)}
.ui-table .t-src .ic{width:15px;height:15px;color:var(--mca-text-3)}
.ui-table .t-actions{display:flex;gap:6px;justify-content:flex-end}
.ui-table .t-gap{width:var(--mca-h-sm);flex:0 0 auto}
/* orden manual: posición + subir/bajar */
.ui-table .t-order{display:flex;align-items:center;gap:2px}
.ui-table .t-order-n{min-width:22px;font-weight:600;color:var(--mca-text)}
.ui-table .rt-empty{height:auto}
/* columna de texto largo (títulos de programa…): se parte en líneas a partir de 320 px */
.mca-table-scroll > table.ui-table .rt-w-lg{white-space:normal;max-width:320px}
/* columna fija (scroll horizontal): mismo fondo que su fila y su cabecera */
.mca-table-scroll > table.ui-table td.rt-sticky{background:var(--mca-surface)}
.mca-table-scroll > table.ui-table th.rt-sticky{background:var(--mca-surface-2)}
.mca-table-scroll > table.ui-table tbody tr:hover > td.rt-sticky{background:var(--mca-surface-hover)}

/* portátiles (<1440): celdas algo más juntas y las columnas de texto pueden partirse en
   dos líneas (.rt-shrink y la persona) antes de recurrir al desplazamiento horizontal */
@media(min-width:768px) and (max-width:1439px){
  .ui-table thead th,.ui-table td{padding-left:12px;padding-right:12px}
  .ui-table th:first-child,.ui-table td:first-child{padding-left:16px}
  .ui-table th:last-child,.ui-table td:last-child{padding-right:16px}
  .mca-table-scroll > table.ui-table .rt-shrink{white-space:normal;max-width:170px}
  .mca-table-scroll > table.ui-table td.rt-primary{white-space:normal;max-width:280px}
  .mca-table-scroll > table.ui-table td.rt-w-lg{max-width:240px}
  .mca-table-scroll > table.ui-table .rt-w-sm{max-width:150px}
}

/* estado vacío */
.ui-empty{padding:40px 20px;text-align:center;color:var(--mca-text-2);font-size:14px}
.ui-empty .ui-empty-ic{width:40px;height:40px;border-radius:50%;margin:0 auto 10px;display:grid;place-items:center;background:var(--mca-neutral-soft);color:var(--mca-text-3)}
.ui-empty .ui-empty-ic .ic{width:18px;height:18px}
.ui-empty b{display:block;color:var(--mca-text);font-weight:600;margin-bottom:2px}

/* paginación */
.ui-pager{display:flex;align-items:center;justify-content:space-between;gap:12px;flex-wrap:wrap;padding:10px 16px 10px 20px;border-top:1px solid var(--mca-line);font-size:13px;color:var(--mca-text-2)}
.ui-pager b{color:var(--mca-text);font-weight:600}
.ui-pager .ui-pager-nav{display:flex;align-items:center;gap:6px}
.ui-pager .ui-pager-pos{min-width:56px;text-align:center;font-variant-numeric:tabular-nums}

/* grupo de filtros (x-ui.filters): en línea en escritorio; en móvil, plegado tras «Filtros» */
.ui-filters,.ui-filters-body{display:contents}
.ui-filters-toggle{display:none}

/* rejilla de fichas (asesores, integraciones…): tarjeta con cabecera, datos y acciones al pie */
.ui-grid{display:grid;gap:16px;grid-template-columns:repeat(auto-fill,minmax(min(100%,300px),1fr));align-items:stretch}
.ui-entity{display:flex;flex-direction:column}
.ui-entity-h{display:flex;align-items:flex-start;gap:12px;padding:16px 18px 0}
.ui-entity-h .ui-person-text{flex:1;gap:6px}
.ui-entity-title{margin:0;font-size:15px;font-weight:600;color:var(--mca-text);line-height:1.3;letter-spacing:-.005em}
.ui-entity-b{flex:1;padding:12px 18px 16px;font-size:13px;color:var(--mca-text-2)}
.ui-card-f{display:flex;align-items:center;gap:8px;flex-wrap:wrap;padding:12px 18px;border-top:1px solid var(--mca-line);background:var(--mca-surface-2);border-radius:0 0 var(--mca-r-card) var(--mca-r-card)}
.ui-card-f .sp{flex:1}
/* lista de datos etiqueta → valor */
.ui-meta{display:grid;grid-template-columns:auto minmax(0,1fr);align-items:baseline;gap:6px 16px;margin:0;font-size:13px}
.ui-meta dt{color:var(--mca-text-3);font-weight:500}
.ui-meta dd{margin:0;color:var(--mca-text);min-width:0;overflow-wrap:anywhere}
.ui-meta .t-nil,.ui-entity-b .t-nil{color:var(--mca-text-3)}
.ui-note{margin:8px 0 0;font-size:12.5px;color:var(--mca-text-3);line-height:1.45}
@media(max-width:767px){
  .ui-entity-h{padding:14px 14px 0}
  .ui-entity-b{padding:12px 14px 14px}
  .ui-card-f{padding:10px 14px}
}

/* ---------- ficha de un registro (lead, contacto…) ----------
   Cabecera (identidad + acciones comerciales) y rejilla de dos columnas: a la izquierda el
   historial de interacciones; a la derecha los datos y el seguimiento. En <1180 px, una
   sola columna con los datos primero. */
.ui-record{display:flex;flex-direction:column;gap:16px;min-width:0}
.ui-back{display:inline-flex;align-items:center;gap:4px;align-self:flex-start;font-size:13px;font-weight:500;color:var(--mca-text-2);text-decoration:none;border-radius:6px}
.ui-back:hover{color:var(--mca-blue)}
.ui-back:focus-visible{outline:2px solid var(--mca-blue);outline-offset:2px}
.ui-back .ic{width:16px;height:16px}
.ui-record-h{display:flex;align-items:flex-start;gap:16px;flex-wrap:wrap;padding:20px 22px}
.ui-avatar--xl{--s:56px;font-size:19px}
.ui-record-id{flex:1 1 320px;min-width:0;display:flex;flex-direction:column;gap:8px}
.ui-record-title{margin:0;font-size:var(--mca-fs-xl);font-weight:700;letter-spacing:-.02em;line-height:1.2;color:var(--mca-text);overflow-wrap:anywhere}
.ui-record-meta{display:flex;flex-wrap:wrap;align-items:center;gap:6px 16px;font-size:13px;color:var(--mca-text-2)}
.ui-record-meta > span{display:inline;min-width:0;overflow-wrap:anywhere}
.ui-record-meta .ic{display:inline-block;width:15px;height:15px;margin-right:6px;vertical-align:-3px;color:var(--mca-text-3)}
.ui-record-meta b{color:var(--mca-text);font-weight:600}
.ui-record-actions{display:flex;align-items:center;gap:8px;flex-wrap:wrap}
.ui-record-grid{display:grid;gap:16px;grid-template-columns:minmax(0,1fr) 360px;align-items:start}
.ui-record-main,.ui-record-side{display:flex;flex-direction:column;gap:16px;min-width:0}
.ui-record [id]{scroll-margin-top:120px}
@media(max-width:1179px){
  .ui-record-grid{grid-template-columns:minmax(0,1fr)}
  .ui-record-side{order:-1}
  .ui-record--main-first .ui-record-side{order:0}
}
@media(max-width:640px){
  .ui-record-h{padding:16px;gap:12px}
  .ui-avatar--xl{--s:44px;font-size:16px}
  .ui-record-actions{width:100%}
  .ui-record-actions > .ui-btn,.ui-record-actions > .ui-dropdown{flex:1 1 calc(50% - 4px)}
  .ui-record-actions > .ui-dropdown > .ui-btn{width:100%}
  .ui-record-actions > .ui-dropdown > .ui-btn{justify-content:space-between}
  .ui-record-actions > .ui-btn--primary{order:-1;flex-basis:100%}
  .ui-record-id{flex:1 1 0}
}

/* navegación por secciones de la ficha (anclas: nada queda oculto) */
.ui-subnav{position:sticky;top:64px;z-index:5;display:flex;gap:4px;padding:4px;overflow-x:auto;background:var(--mca-surface);
  border:1px solid var(--mca-line);border-radius:var(--mca-r-card);box-shadow:var(--mca-shadow-card);scrollbar-width:none}
.ui-subnav::-webkit-scrollbar{display:none}
.ui-subnav a{display:inline-flex;align-items:center;gap:6px;flex:0 0 auto;height:32px;padding:0 12px;border-radius:var(--mca-r-ctl);font-size:13px;font-weight:600;color:var(--mca-text-2);text-decoration:none;white-space:nowrap}
.ui-subnav a:hover{background:var(--mca-surface-hover);color:var(--mca-text)}
.ui-subnav a:focus-visible{outline:none;box-shadow:var(--mca-focus)}
.ui-subnav .ic{width:15px;height:15px;color:var(--mca-text-3)}
.ui-subnav .ui-badge--count{height:18px;padding:0 6px;font-size:11.5px}

/* cuerpo de tarjeta con relleno estándar y lista de filas */
.ui-card-p{padding:16px 18px}
.ui-card-h .ui-card-sub{display:inline-flex;align-items:center;gap:6px;font-size:12.5px;color:var(--mca-text-3);font-weight:500}
.ui-list{list-style:none;margin:0;padding:0}
.ui-list-item{display:flex;align-items:flex-start;gap:12px;width:100%;padding:12px 18px;border:0;border-top:1px solid var(--mca-line);background:none;
  font:inherit;font-size:13.5px;color:var(--mca-text);text-align:left;text-decoration:none;min-width:0}
.ui-list > li:first-child > .ui-list-item,.ui-list > .ui-list-item:first-child{border-top:0}
a.ui-list-item,button.ui-list-item{cursor:pointer}
a.ui-list-item:hover,button.ui-list-item:hover{background:var(--mca-surface-hover)}
a.ui-list-item:focus-visible,button.ui-list-item:focus-visible{outline:none;box-shadow:inset var(--mca-focus)}
.ui-list-main{flex:1;min-width:0;display:flex;flex-direction:column;gap:3px;overflow-wrap:anywhere}
.ui-list-title{font-weight:600;color:var(--mca-text);line-height:1.35}
.ui-list-title .ui-link{display:inline;white-space:normal;overflow-wrap:anywhere}
.ui-list-title .ui-link .ic{display:inline-block;vertical-align:-2px;margin-left:2px}
.ui-list-meta{font-size:12.5px;color:var(--mca-text-3);line-height:1.4}
.ui-list-meta b{color:var(--mca-text-2);font-weight:600}
.ui-list-aside{display:flex;align-items:center;gap:8px;flex:0 0 auto}
.ui-list-empty{padding:18px;font-size:13.5px;color:var(--mca-text-3)}

/* línea de tiempo (eventos) */
.ui-timeline{list-style:none;margin:0;padding:4px 18px 10px}
.ui-timeline > li{display:flex;gap:12px;padding:10px 0;border-top:1px solid var(--mca-line)}
.ui-timeline > li:first-child{border-top:0}
.ui-tl-ic{width:28px;height:28px;border-radius:50%;display:grid;place-items:center;flex:0 0 auto;color:var(--tone,var(--mca-text-2));background:var(--tone-soft,var(--mca-neutral-soft))}
.ui-tl-ic .ic{width:14px;height:14px}
.ui-tl-body{min-width:0;font-size:13.5px;line-height:1.45;color:var(--mca-text);overflow-wrap:anywhere}
.ui-tl-detail{color:var(--mca-text-2)}
.ui-tl-time{font-size:12px;color:var(--mca-text-3);margin-top:2px}

/* conversación: el prospecto a la derecha (como siempre en el CRM), el asesor a la izquierda */
.ui-chat{display:flex;flex-direction:column;gap:12px;padding:16px 18px;max-height:70vh;overflow-y:auto;overscroll-behavior:contain}
.ui-msg{display:flex;flex-direction:column;gap:4px;max-width:min(82%,640px);align-items:flex-start}
.ui-msg-from{font-size:12px;font-weight:600;color:var(--mca-text-3);padding:0 4px}
.ui-msg-bubble{padding:10px 14px;border-radius:14px;border-bottom-left-radius:4px;font-size:14px;line-height:1.5;color:var(--mca-text);
  background:var(--mca-surface-2);box-shadow:inset 0 0 0 1px var(--mca-line);overflow-wrap:anywhere}
.ui-msg-bubble a{color:var(--mca-blue);font-weight:600;overflow-wrap:anywhere}
.ui-msg--in{align-self:flex-end;align-items:flex-end}
.ui-msg--in .ui-msg-bubble{background:var(--mca-gold-soft);box-shadow:inset 0 0 0 1px var(--mca-gold-line);border-bottom-left-radius:14px;border-bottom-right-radius:4px}

/* aviso dentro de una tarjeta o de la página */
.ui-callout{display:flex;align-items:flex-start;gap:10px;padding:11px 14px;border-radius:var(--mca-r-ctl);font-size:13px;line-height:1.45;color:var(--mca-text-2);
  background:var(--tone-soft,var(--mca-surface-2));box-shadow:inset 0 0 0 1px color-mix(in srgb,var(--tone,var(--mca-text-3)) 18%,transparent)}
.ui-callout .ic{width:16px;height:16px;flex:0 0 auto;margin-top:1px;color:var(--tone,var(--mca-text-3))}
.ui-callout b{color:var(--mca-text);font-weight:600}

/* menú desplegable (p. ej. cambio de estado) */
.ui-dropdown{position:relative;display:inline-flex}
.ui-menu{position:absolute;top:calc(100% + 6px);left:0;z-index:40;min-width:220px;padding:6px;background:var(--mca-surface);
  border:1px solid var(--mca-line);border-radius:var(--mca-r-card);box-shadow:var(--mca-shadow-pop)}
.ui-menu-item{display:flex;align-items:center;gap:8px;width:100%;padding:8px 10px;border:0;background:none;border-radius:var(--mca-r-ctl);
  font:inherit;font-size:13.5px;color:var(--mca-text);text-align:left;cursor:pointer}
.ui-menu-item:hover:not(:disabled){background:var(--mca-surface-hover)}
.ui-menu-item:focus-visible{outline:none;box-shadow:var(--mca-focus)}
.ui-menu-item:disabled{opacity:.55;cursor:default}

/* ventana modal */
.ui-modal-bg{position:fixed;inset:0;z-index:1000;display:flex;align-items:center;justify-content:center;padding:24px;background:rgba(19,37,61,.45)}
.ui-modal{--w:720px;width:100%;max-width:var(--w);max-height:92vh;display:flex;flex-direction:column;overflow:hidden;background:var(--mca-surface);
  border-radius:16px;box-shadow:0 24px 70px -20px rgba(0,0,0,.5)}
.ui-modal--lg{--w:990px}
.ui-modal-h{display:flex;align-items:flex-start;justify-content:space-between;gap:12px;padding:16px 22px;border-bottom:1px solid var(--mca-line)}
.ui-modal-title{margin:0;display:flex;align-items:center;gap:8px;font-size:17px;font-weight:700;color:var(--mca-text);overflow-wrap:anywhere}
.ui-modal-title .ic{width:18px;height:18px;color:var(--mca-text-3)}
.ui-modal-sub{margin:4px 0 0;font-size:12.5px;color:var(--mca-text-3);overflow-wrap:anywhere}
.ui-modal-sub b{color:var(--mca-text);font-weight:600}
.ui-modal-b{flex:1;min-height:0;overflow:auto;padding:18px 22px;display:flex;flex-direction:column;gap:16px}
.ui-modal-b > *{flex-shrink:0}
.ui-modal-f{display:flex;align-items:center;justify-content:flex-end;gap:8px;flex-wrap:wrap;padding:14px 22px;border-top:1px solid var(--mca-line);background:var(--mca-surface-2)}
@media(max-width:640px){
  .ui-modal-bg{padding:0;align-items:stretch}
  .ui-modal{max-height:none;height:100%;border-radius:0}
  .ui-modal-h,.ui-modal-b,.ui-modal-f{padding-left:16px;padding-right:16px}
}

/* piezas de ficha: datos espaciados, nota de tarjeta, paso numerado, notas internas */
.ui-meta--spaced{gap:10px 16px;margin-top:2px}
.ui-card-p > .ui-person-line + .ui-meta{margin-top:14px}
.ui-meta--muted dd{color:var(--mca-text-3)}
.ui-meta + .ui-callout{margin-top:14px}
.ui-callout--sm{padding:9px 12px;font-size:12.5px}
.ui-strong{font-weight:600;color:var(--mca-text)}
.ui-text-danger{color:var(--mca-danger) !important;font-weight:600}
.ui-card-kicker{margin:0;padding:12px 18px 4px;font-size:12px;font-weight:600;color:var(--mca-text-3)}
.ui-card-kicker + .ui-list > li:first-child > .ui-list-item{border-top:0}
.ui-card-note{margin:0;padding:10px 18px 14px;border-top:1px solid var(--mca-line);font-size:12.5px;color:var(--mca-text-3);font-style:italic}
.ui-step-n{width:26px;height:26px;border-radius:50%;flex:0 0 auto;display:grid;place-items:center;font-size:12.5px;font-weight:700;color:var(--mca-blue);background:var(--mca-blue-soft)}
.ui-note-body{white-space:pre-line;line-height:1.5;color:var(--mca-text)}
.ui-inline-form{display:flex;gap:8px;padding:12px 18px;border-top:1px solid var(--mca-line);background:var(--mca-surface-2);border-radius:0 0 var(--mca-r-card) var(--mca-r-card)}
.ui-inline-form .ui-input{flex:1;min-width:0}
.ui-list--boxed{border:1px solid var(--mca-line);border-radius:var(--mca-r-ctl);overflow:hidden}
.ui-list--boxed .ui-list-item{padding:9px 12px}
.ui-menu--scroll{min-width:240px;max-height:260px;overflow:auto}
.ui-menu-item--stack{flex-direction:column;align-items:flex-start;gap:1px}
.ui-modal--md{--w:800px}
.ui-mail-to{font-size:13.5px;color:var(--mca-text-2)}
.ui-mail-to b{color:var(--mca-text);font-weight:600}

/* calendario del lead (componente compartido de la agenda) */
.ui-lead-agenda{display:flex;flex-direction:column;gap:10px;min-width:0}
.ui-next-activity{display:flex;flex-direction:column;gap:2px;padding:10px 12px;border-radius:var(--mca-r-ctl);text-decoration:none;color:inherit;
  background:var(--mca-blue-soft);box-shadow:inset 0 0 0 1px #D3E1F3}
.ui-next-activity:hover{background:#E1ECF8}
.ui-next-activity:focus-visible{outline:none;box-shadow:inset 0 0 0 1px #D3E1F3,var(--mca-focus)}
.ui-next-kicker{margin:0;font-size:12px;font-weight:600;color:var(--mca-blue)}
.ui-next-kicker--muted{color:var(--mca-text-3)}
.ui-list--compact .ui-list-item{padding:8px 0}
.ui-lead-agenda-actions{display:flex;gap:6px;flex-wrap:wrap}
.ui-callout--stack{flex-direction:column;gap:6px}
.ui-break{overflow-wrap:anywhere;min-width:0}
.ui-link--btn{border:0;background:none;padding:0;font:inherit;font-size:12.5px;font-weight:600;cursor:pointer}

/* editor de correo: barra de formato, cuerpo editable y vista de código */
/* sin overflow:hidden en el contenedor: el menú «Etiqueta» debe poder salir del editor sin recortarse */
.ui-editor{border:1px solid var(--mca-line-strong);border-radius:var(--mca-r-ctl);background:var(--mca-surface)}
.ui-editor:focus-within{border-color:var(--mca-blue);box-shadow:var(--mca-focus)}
.ui-editor-tb{display:flex;flex-wrap:wrap;align-items:center;gap:6px;padding:8px 10px;border-bottom:1px solid var(--mca-line);background:var(--mca-surface-2);
  border-radius:calc(var(--mca-r-ctl) - 1px) calc(var(--mca-r-ctl) - 1px) 0 0}
.ui-tb-btn{height:30px;min-width:32px;padding:0 9px;display:inline-flex;align-items:center;justify-content:center;gap:4px;border:1px solid var(--mca-line-strong);
  border-radius:6px;background:var(--mca-surface);font:inherit;font-size:13px;color:var(--mca-text);cursor:pointer}
.ui-tb-btn:hover{background:var(--mca-surface-hover)}
.ui-tb-btn:focus-visible{outline:none;box-shadow:var(--mca-focus)}
.ui-tb-select{height:30px;padding:0 8px;border:1px solid var(--mca-line-strong);border-radius:6px;background:var(--mca-surface);font:inherit;font-size:12.5px;color:var(--mca-text);cursor:pointer;flex:0 0 auto}
.ui-tb-sep{width:1px;height:20px;background:var(--mca-line-strong)}
.ui-tb-group{display:flex;flex-wrap:wrap;align-items:center;gap:6px;min-width:0}
.ui-editor-body{min-height:240px;max-height:50vh;overflow:auto;padding:12px 14px;font-size:14px;line-height:1.55;outline:none;color:var(--mca-text)}
/* móvil: la barra pasa de 3 filas a 2 (mismas herramientas; los textos se ocultan y quedan en title/aria-label)
   y el cuerpo crece con el contenido para que solo desplace el modal (un único scroll con el teclado abierto) */
@media(max-width:640px){
  .ui-editor-tb,.ui-tb-group{gap:4px}
  .ui-editor-tb{padding:6px}
  .ui-editor-tb .ui-tb-sep,.ui-tb-label{display:none}
  .ui-tb-btn{height:32px;min-width:34px;padding:0 6px}
  .ui-tb-select{height:32px;max-width:92px;padding:0 4px}
  .ui-editor-body{min-height:180px;max-height:none}
  .ui-editor-tb .ui-menu{left:auto;right:0;max-width:calc(100vw - 32px)}
}
.ui-editor-codewrap{display:flex;flex-direction:column}
.ui-editor-code{width:100%;height:34vh;min-height:200px;box-sizing:border-box;border:0;border-bottom:1px solid var(--mca-line);padding:12px 14px;
  font-family:ui-monospace,Menlo,Consolas,monospace;font-size:12.5px;line-height:1.5;outline:none;resize:vertical;color:var(--mca-text);background:var(--mca-surface-2)}
.ui-editor-preview{display:flex;flex-direction:column;height:34vh;overflow:hidden;border-radius:0 0 calc(var(--mca-r-ctl) - 1px) calc(var(--mca-r-ctl) - 1px)}
.ui-editor-pvh{padding:6px 12px;border-bottom:1px solid var(--mca-line);background:var(--mca-surface-2);font-size:11.5px;color:var(--mca-text-3)}
.ui-editor-preview iframe{flex:1;width:100%;border:0;background:#fff}

/* ---------- formularios: 40 px, etiqueta encima, ayuda y error debajo ---------- */
.ui-form-grid{display:grid;gap:16px 20px;grid-template-columns:repeat(var(--cols,2),minmax(0,1fr))}
.ui-form-grid--3{--cols:3}
.ui-span-full{grid-column:1/-1}
.ui-field{display:flex;flex-direction:column;gap:6px;min-width:0}
.ui-label{font-size:13px;font-weight:600;color:var(--mca-text);line-height:1.3}
.ui-label .opt{font-weight:400;color:var(--mca-text-3)}
.ui-label .req{color:var(--mca-danger);margin-left:2px}
.ui-field .ui-input,.ui-field .ui-select{width:100%;height:var(--mca-h-lg);font-size:14px}
.ui-textarea{height:auto !important;min-height:96px;padding:10px 12px;line-height:1.5;resize:vertical}
.ui-help{margin:0;font-size:12.5px;line-height:1.4;color:var(--mca-text-3)}
.ui-error{margin:0;display:flex;align-items:flex-start;gap:6px;font-size:12.5px;font-weight:500;line-height:1.4;color:var(--mca-danger)}
.ui-error .ic{width:14px;height:14px;flex:0 0 auto;margin-top:1px}
.ui-input.is-invalid,.ui-select.is-invalid{border-color:var(--mca-danger)}
.ui-input.is-invalid:focus,.ui-select.is-invalid:focus{box-shadow:0 0 0 3px rgba(180,35,24,.16)}
.ui-form-actions{display:flex;align-items:center;gap:8px;flex-wrap:wrap}
.ui-form-grid + .ui-form-grid{margin-top:16px}
.ui-req{color:var(--mca-danger)}
.ui-narrow{max-width:880px}
.ui-card-h--sep{border-top:1px solid var(--mca-line)}
.ui-card-f--end{justify-content:flex-end}
.ui-btn-label{display:inline-flex;align-items:center;gap:7px}
.ui-spin{display:inline-block;width:15px;height:15px;border:2px solid currentColor;border-right-color:transparent;border-radius:50%;animation:uiSpin .7s linear infinite;vertical-align:middle}
@keyframes uiSpin{to{transform:rotate(360deg)}}
@media(max-width:767px){
  .ui-form-grid,.ui-form-grid--3{grid-template-columns:minmax(0,1fr)}
  .ui-form-actions > .ui-btn{flex:1 1 auto}
}

/* aviso */
.ui-alert{display:flex;align-items:center;gap:9px;padding:10px 14px;border-radius:var(--mca-r-ctl);font-size:13.5px;font-weight:500;
  color:var(--tone);background:var(--tone-soft);box-shadow:inset 0 0 0 1px color-mix(in srgb,var(--tone) 18%,transparent);margin:0 0 16px}
.ui-alert .ic{width:16px;height:16px}

/* ---------- indicador (KPI) ---------- */
.ui-kpi{position:relative;display:flex;flex-direction:column;gap:10px;padding:16px 18px;background:var(--mca-surface);border:1px solid var(--mca-line);
  border-radius:var(--mca-r-card);box-shadow:var(--mca-shadow-card);color:inherit;text-decoration:none;min-width:0;
  transition:border-color .14s,box-shadow .14s}
a.ui-kpi:hover{border-color:var(--mca-line-strong);box-shadow:0 1px 2px rgba(16,24,40,.05),0 4px 12px rgba(16,24,40,.06)}
a.ui-kpi:focus-visible{outline:none;box-shadow:var(--mca-focus)}
.ui-kpi-top{display:flex;align-items:center;gap:10px}
.ui-kpi-ic{width:32px;height:32px;border-radius:8px;display:grid;place-items:center;flex:0 0 auto;color:var(--tone);background:var(--tone-soft)}
.ui-kpi-ic .ic{width:17px;height:17px}
.ui-kpi-label{flex:1;min-width:0;font-size:13px;font-weight:500;color:var(--mca-text-2);line-height:1.3}
.ui-kpi-go{width:16px;height:16px;color:var(--mca-text-3);transition:transform .14s,color .14s}
a.ui-kpi:hover .ui-kpi-go{color:var(--mca-blue);transform:translateX(2px)}
.ui-kpi-value{display:flex;align-items:baseline;gap:8px;font-size:var(--mca-fs-2xl);font-weight:700;line-height:1;letter-spacing:-.025em;color:var(--mca-text);font-variant-numeric:tabular-nums}
.ui-kpi-trend{display:inline-flex;align-items:center;gap:2px;font-size:12px;font-weight:600;letter-spacing:0;color:var(--mca-success)}
.ui-kpi-trend.down{color:var(--mca-warning)}
.ui-kpi-trend .ic{width:13px;height:13px}
.ui-kpi--accent{border-color:var(--mca-gold-line);background:linear-gradient(160deg,var(--mca-surface) 40%,var(--mca-gold-soft))}

/* ---------- fila de lista (tarjetas del inicio) ---------- */
.ui-row{display:flex;align-items:center;gap:12px;padding:10px 12px;border-radius:var(--mca-r-ctl);color:inherit;text-decoration:none;min-width:0}
a.ui-row:hover{background:var(--mca-surface-hover)}
a.ui-row:focus-visible{outline:none;box-shadow:var(--mca-focus)}
.ui-row-main{flex:1;min-width:0}
.ui-row-title{font-size:14px;font-weight:600;color:var(--mca-text);line-height:1.3;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
.ui-row-meta{font-size:12.5px;color:var(--mca-text-3);margin-top:2px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
.ui-row-aside{display:flex;align-items:center;gap:6px;flex:0 0 auto}
/* en móvil las insignias bajan bajo el texto: el nombre nunca se recorta */
@media(max-width:640px){
  .ui-row{flex-wrap:wrap;row-gap:6px;align-items:flex-start}
  .ui-row-main{flex:1 1 calc(100% - 44px)}
  .ui-row-title,.ui-row-meta{white-space:normal}
  .ui-row-aside{margin-left:44px;flex-wrap:wrap}
}

/* ---------- móvil: la tabla pasa a tarjetas (nada se oculta) ----------
   Contrato de marcado: data-label en cada celda de dato,
   .rt-primary encabeza la tarjeta, .rt-aside (estado) queda arriba a la derecha y
   .rt-actions al pie a todo el ancho. Los datos van en una rejilla de dos columnas. */
@media(max-width:767px){
  .ui-card--list{background:none;border:0;box-shadow:none;border-radius:0}
  .ui-card--list > .ui-toolbar{padding:0 0 12px;border:0;gap:8px}
  .ui-card--list > .ui-toolbar .ui-search{flex:1 1 0;max-width:none}
  .ui-card--list > .ui-toolbar .ui-select{flex:1 1 auto;min-width:calc(50% - 4px)}
  .ui-card--list > .ui-toolbar .ui-btn:not(.ui-filters-toggle){flex:1 1 calc(50% - 4px)}
  /* filtros plegados: «Filtros (n)» junto al buscador; al abrir, rejilla de dos columnas */
  .ui-filters-toggle{display:inline-flex;flex:0 0 auto}
  .ui-toolbar > .ui-filters:first-child .ui-filters-toggle{flex:1 1 100%;justify-content:flex-start}
  .ui-toolbar > .ui-filters:first-child .ui-filters-chev{margin-left:auto}
  .ui-filters.is-open .ui-filters-chev{transform:rotate(180deg)}
  .ui-filters-body{display:none}
  .ui-filters.is-open .ui-filters-body{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:8px;flex:1 1 100%}
  .ui-filters-body > *{width:100%;min-width:0}
  .ui-filters-body > .ui-btn--ghost{grid-column:1/-1;justify-self:start;width:auto}
  .ui-card--list > .ui-toolbar .sp{display:none}
  .ui-card--list > .ui-filter-note{border:1px solid var(--mca-line);border-radius:var(--mca-r-ctl);margin:0 0 12px}
  .ui-card--list > .ui-pager{border:0;padding:12px 0 0}
  .ui-card--list .mca-table-scroll:has(> table.ui-table){overflow:visible;background:none}

  table.ui-table,.mca-table-scroll > table.ui-table{width:100% !important;min-width:0 !important}
  table.ui-table thead{position:absolute;width:1px;height:1px;overflow:hidden;clip:rect(0 0 0 0);white-space:nowrap}
  table.ui-table,table.ui-table tbody{display:block}
  table.ui-table tbody{display:flex;flex-direction:column;gap:10px}
  table.ui-table tr{display:flex;flex-wrap:wrap;gap:10px 12px;padding:12px 14px;background:var(--mca-surface);border:1px solid var(--mca-line);
    border-radius:var(--mca-r-card);box-shadow:var(--mca-shadow-card)}
  table.ui-table tbody tr:hover > td{background:none}
  table.ui-table td{display:flex;flex-direction:column;align-items:flex-start;gap:1px;line-height:1.4;height:auto;padding:0 !important;border:0;white-space:normal !important;
    min-width:0;max-width:none !important;background:none !important;position:static !important;font-size:14px;overflow-wrap:anywhere}
  table.ui-table td[data-label]{flex:1 1 calc(50% - 6px)}
  /* tres datos cortos (p. ej. país y contadores): una sola fila de tres columnas */
  table.ui-table.ui-table--meta3 td[data-label]{flex-basis:calc(33.333% - 8px)}
  table.ui-table td[data-label]::before{content:attr(data-label);font-family:var(--mca-font);letter-spacing:0;font-size:12px;font-weight:500;color:var(--mca-text-3);line-height:1.25}
  table.ui-table td.rt-primary{flex:1 1 55%;order:-2}
  table.ui-table td.rt-aside{flex:0 0 auto;order:-1;align-items:flex-end}
  table.ui-table tr:not(:has(> td.rt-aside)) > td.rt-primary{flex-basis:100%}
  /* insignias largas (p. ej. acciones de auditoría): bajo el encabezado, a la izquierda */
  table.ui-table.ui-table--stack-aside td.rt-primary{flex-basis:100%}
  table.ui-table.ui-table--stack-aside td.rt-aside{flex-basis:100%;align-items:flex-start}
  table.ui-table td.rt-aside::before{display:none}
  table.ui-table td.rt-actions{flex:1 1 100%;flex-direction:row;padding-top:12px !important;border-top:1px solid var(--mca-line)}
  table.ui-table td.rt-actions .t-actions{width:100%;justify-content:stretch}
  /* controles secundarios de la fila (p. ej. orden manual): al pie, junto a las acciones */
  table.ui-table td.rt-foot{order:9;flex:0 0 auto;flex-direction:row;align-items:center;gap:4px;padding:12px 12px 0 0 !important;margin-right:-12px;border-top:1px solid var(--mca-line)}
  table.ui-table td.rt-foot::before{margin-right:4px}
  table.ui-table td.rt-actions{order:10}
  table.ui-table tr:has(> td.rt-foot) > td.rt-actions{flex:1 1 0}
  table.ui-table .t-gap{display:none}
  table.ui-table td.rt-actions .ui-btn:not(.ui-btn--icon){flex:1}
  table.ui-table td.rt-empty{display:block;flex:1 1 100%}
  table.ui-table td:not([data-label]):not(.rt-primary):not(.rt-actions):not(.rt-empty){display:none}
  table.ui-table tr:has(> td.rt-empty){padding:0}
  .ui-table .ui-person-mail{font-size:13px}
}

/* cabecera de página con varias acciones: en móvil la principal ocupa la fila y las demás se reparten */
@media(max-width:640px){
  .mca-pagehead.ui-ph-stack .ph-actions{width:100%}
  .mca-pagehead.ui-ph-stack .ph-actions > *{flex:1 1 calc(50% - 4px)}
  .mca-pagehead.ui-ph-stack .ph-actions > .ui-btn--primary{order:-1;flex-basis:100%}
}

/* ---------- control segmentado (p. ej. vistas del Calendario) ---------- */
.ui-seg{display:inline-flex;gap:2px;padding:3px;background:var(--mca-surface-2);border:1px solid var(--mca-line);border-radius:var(--mca-r-ctl)}
.ui-seg button{height:28px;padding:0 12px;border:0;border-radius:6px;background:none;font:inherit;font-size:13px;font-weight:600;color:var(--mca-text-2);cursor:pointer;white-space:nowrap}
.ui-seg button:hover{color:var(--mca-text);background:rgba(19,37,61,.05)}
.ui-seg button[aria-pressed=true]{background:var(--mca-surface);color:var(--mca-blue-deep);box-shadow:var(--mca-shadow-xs),inset 0 0 0 1px var(--mca-line-strong)}
.ui-seg button:focus-visible{outline:none;box-shadow:var(--mca-focus)}

/* casillas, chips de opción y franjas horarias de formularios */
.ui-checks{display:flex;flex-wrap:wrap;gap:10px 20px}
.ui-check{display:inline-flex;align-items:center;gap:8px;font-size:13.5px;color:var(--mca-text);cursor:pointer;line-height:1.35}
.ui-check input{width:16px;height:16px;flex:0 0 auto;margin:0;accent-color:var(--mca-blue);cursor:pointer}
.ui-chips{display:flex;flex-wrap:wrap;gap:6px}
.ui-chip{height:30px;padding:0 11px;border:1px solid #C9D9EE;border-radius:999px;background:var(--mca-surface);font:inherit;font-size:13px;font-weight:600;
  color:var(--mca-blue);font-variant-numeric:tabular-nums;cursor:pointer}
.ui-chip:hover{background:var(--mca-blue-soft)}
.ui-chip:focus-visible{outline:none;box-shadow:var(--mca-focus)}
/* piezas de ficha y formularios del Calendario */
.ui-record-title.is-struck{text-decoration:line-through;text-decoration-thickness:2px;color:var(--mca-text-2)}
.ui-card--danger{border-color:#F0C9C5}
.ui-card--danger > .ui-card-h .ui-card-ic{color:var(--mca-danger)}
.ui-actions-row{display:flex;flex-wrap:wrap;gap:8px}
.ui-actions-row--top{margin-top:12px}
.ui-card-p--sep{border-top:1px solid var(--mca-line);display:flex;flex-direction:column;gap:12px}
.ui-btn--block{width:100%}
.ui-guest-form{display:flex;flex-direction:column;gap:12px;margin-top:4px;padding-top:12px;border-top:1px dashed var(--mca-line-strong)}
.ui-card-kicker--flush{padding:0}
.ui-list-go{width:16px;height:16px;flex:0 0 auto;margin-top:2px;color:var(--mca-blue)}
.ui-stack{display:flex;flex-direction:column;gap:16px;min-width:0}
.ui-alert--flush{margin:0}
.ui-callout-list{list-style:disc}
.ui-text-warning{color:var(--mca-warning) !important}
.ui-text-warning .ic{display:inline-block;width:13px;height:13px;vertical-align:-2px}
.ui-checks--field{min-height:var(--mca-h-lg);align-items:center}
.ui-form-actions--end{justify-content:flex-end}
.ui-owner-pick{display:flex;align-items:center;gap:8px;font-size:13px;font-weight:500;color:var(--mca-text-2)}
.ui-owner-pick .ui-select{min-width:200px}
.ui-weekrows{border-top:1px solid var(--mca-line)}
.ui-subform{border-bottom:1px solid var(--mca-line);background:var(--mca-surface-2)}
.ui-subform > .ui-card-f{border-radius:0}
.ui-list-item--wrap{flex-wrap:wrap}
/* texto copiable (enlaces públicos) */
.ui-copy{display:flex;align-items:center;gap:6px;min-height:var(--mca-h-lg);padding:3px 3px 3px 12px;border:1px solid var(--mca-line-strong);border-radius:var(--mca-r-ctl);background:var(--mca-surface-2)}
.ui-copy-text{flex:1;min-width:0;font-family:ui-monospace,SFMono-Regular,Menlo,Consolas,monospace;font-size:12.5px;color:var(--mca-text);overflow-wrap:anywhere}
.ui-copy--inline{min-height:0;padding:0;border:0;background:none}
.ui-copy--inline .ui-copy-text{flex:0 1 auto;color:var(--mca-text-3)}
/* salas institucionales (Meet / Zoom) */
.ui-vr-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(min(100%,320px),1fr))}
.ui-vr{display:flex;flex-direction:column;gap:12px;min-width:0;padding:16px 18px}
.ui-vr + .ui-vr{border-left:1px solid var(--mca-line)}
.ui-vr-title{margin:0;font-size:14px;font-weight:600;color:var(--mca-text)}
.ui-vr .ui-meta dd{display:flex;flex-wrap:wrap;align-items:center;gap:6px}
@media(max-width:767px){.ui-vr + .ui-vr{border-left:0;border-top:1px solid var(--mca-line)}}
/* plantillas de correo del Calendario: editor y vista previa */
.ui-tpl-key{min-width:260px}
.ui-tpl-grid{display:grid;grid-template-columns:minmax(0,1fr) 380px}
.ui-tpl-edit{display:flex;flex-direction:column;gap:16px;min-width:0;padding:18px;border-right:1px solid var(--mca-line)}
.ui-tpl-body{min-height:340px;font-family:ui-monospace,SFMono-Regular,Menlo,Consolas,monospace;font-size:13px}
.ui-tpl-side{display:flex;flex-direction:column;gap:10px;min-width:0;padding:18px;background:var(--mca-surface-2);border-radius:0 0 var(--mca-r-card) 0}
.ui-tpl-vars{display:flex;flex-wrap:wrap;gap:6px}
.ui-tpl-vars code{padding:2px 7px;border-radius:6px;background:var(--mca-blue-soft);color:var(--mca-blue-deep);font-size:12px}
.ui-tpl-preview{padding:14px;border:1px solid var(--mca-line);border-radius:var(--mca-r-ctl);background:#fff;font-size:13.5px;line-height:1.5;overflow-wrap:anywhere}
.ui-tpl-preview-subject{margin-bottom:10px;padding-bottom:10px;border-bottom:1px solid var(--mca-line);font-weight:700;color:var(--mca-text)}
.ui-tpl-preview a{color:var(--mca-blue)}
@media(max-width:1023px){
  .ui-tpl-grid{grid-template-columns:minmax(0,1fr)}
  .ui-tpl-edit{border-right:0;border-bottom:1px solid var(--mca-line)}
  .ui-tpl-side{border-radius:0 0 var(--mca-r-card) var(--mca-r-card)}
}
@media(max-width:640px){.ui-tpl-key{min-width:0;flex:1 1 100%}.ui-tpl-edit,.ui-tpl-side{padding:16px}}
/* campana de avisos del Calendario (barra superior) */
.ui-bell{position:relative;cursor:pointer}
.ui-bell .ic{width:18px;height:18px}
.ui-bell-n{position:absolute;top:-4px;right:-4px;min-width:16px;height:16px;padding:0 4px;border-radius:9px;background:var(--mca-blue);color:#fff;font-size:10px;line-height:16px;text-align:center;font-weight:700}
.ui-bell-h{display:flex;align-items:center;justify-content:space-between;gap:10px;padding:8px 8px 8px 14px;border-bottom:1px solid var(--mca-line);font-size:13px}
.ui-bell-list{max-height:360px;overflow:auto}
.ui-bell-list .ui-list-item{padding:10px 14px}
.ui-bell-list .ui-list-item.is-unread{background:var(--mca-blue-soft)}
.ui-bell-kind{font-size:11px;font-weight:700;letter-spacing:.05em;text-transform:uppercase;color:var(--mca-blue)}
.ui-chip[aria-pressed=true]{color:#fff;background:var(--mca-blue);border-color:var(--mca-blue)}
.ui-span-2{grid-column:span 2}
@media(max-width:767px){.ui-span-2{grid-column:auto}}
/* elemento elegido en un buscador (p. ej. contacto de una actividad) y resultados */
.ui-picked{display:flex;align-items:center;gap:10px;min-height:var(--mca-h-lg);padding:4px 4px 4px 12px;border:1px solid #C9D9EE;border-radius:var(--mca-r-ctl);background:var(--mca-blue-soft);font-size:13.5px}
.ui-picked > .ic{width:16px;height:16px;flex:0 0 auto;color:var(--mca-blue)}
.ui-picked-text{flex:1;min-width:0;display:flex;flex-direction:column;line-height:1.3;overflow-wrap:anywhere}
.ui-picked-text b{font-weight:600;color:var(--mca-text)}
.ui-picked-text span{font-size:12.5px;color:var(--mca-text-2)}
.ui-results{margin-top:6px;max-height:280px;overflow:auto}
.ui-callout-list{margin:0;padding-left:20px}
.ui-callout--stack > b{display:flex;align-items:center;gap:6px}
.ui-callout--stack .ui-input{width:100%;max-width:480px}
.ui-interval{display:flex;align-items:center;gap:8px;flex-wrap:wrap}
.ui-interval .ui-input{width:124px;height:var(--mca-h-md)}
.ui-interval .sep{color:var(--mca-text-3)}
.ui-weekrow{display:grid;grid-template-columns:150px minmax(0,1fr);gap:8px 16px;align-items:start;padding:12px 18px;border-top:1px solid var(--mca-line)}
.ui-weekrow:first-child{border-top:0}
.ui-weekrow-day{padding-top:8px;font-size:13.5px;font-weight:600;color:var(--mca-text)}
.ui-weekrow-body{display:flex;flex-direction:column;align-items:flex-start;gap:8px;min-width:0}
.ui-weekrow-off{padding-top:8px;font-size:13px;color:var(--mca-text-3)}
@media(max-width:640px){
  .ui-weekrow{grid-template-columns:minmax(0,1fr);padding:12px 16px}
  .ui-weekrow-day{padding-top:0}
  .ui-interval{flex-wrap:nowrap;width:100%}
  .ui-interval .ui-input{width:auto;flex:1 1 0;min-width:0;padding:0 8px}
}

/* ---------- Calendario ----------
   Una sola marca para todas las vistas: cada día es una sección (.ui-cal-day) con cabecera y
   actividades (.ui-cal-ev). Por defecto se pinta como AGENDA (filas legibles con hora, datos,
   estado y acceso): así se ven la vista Lista, las vencidas y la semana/día en tableta y móvil.
   Con ancho suficiente (contenedor ≥900 px), semana y día pasan a REJILLA HORARIA: escala de
   horas, posición y altura proporcionales a la hora y la duración, y las actividades simultáneas
   repartidas en carriles (CalendarGrid). El mes es una cuadrícula que en móvil se reduce a un
   mini-mes con puntos más la agenda del mes. El color es el estado; el icono, el tipo. */
.ui-cal{container:cal / inline-size;min-width:0}
.ui-cal-bar{display:flex;align-items:center;gap:10px 12px;flex-wrap:wrap;padding:12px 16px;border-bottom:1px solid var(--mca-line)}
.ui-cal-nav{display:inline-flex;align-items:center;gap:4px}
.ui-cal-title{margin:0;display:flex;align-items:center;gap:8px;min-width:0;font-size:17px;font-weight:700;letter-spacing:-.01em;color:var(--mca-text)}
.ui-cal-bar .sp{flex:1}
.ui-cal-body{transition:opacity .15s}
.ui-cal.is-loading .ui-cal-body{opacity:.5}
.ui-cal-foot{display:flex;align-items:center;flex-wrap:wrap;gap:8px 16px;padding:10px 16px;border-top:1px solid var(--mca-line);background:var(--mca-surface-2);
  border-radius:0 0 var(--mca-r-card) var(--mca-r-card);font-size:12.5px;color:var(--mca-text-3)}
.ui-cal-foot .sp{flex:1}
.ui-cal-legend{display:flex;flex-wrap:wrap;gap:6px 14px;margin:0;padding:0;list-style:none}
.ui-cal-legend li{display:inline-flex;align-items:center;gap:6px}
.ui-cal-legend i{width:12px;height:12px;border-radius:4px;background:var(--tone-soft);box-shadow:inset 0 0 0 1px color-mix(in srgb,var(--tone) 28%,transparent)}

/* sección de un día (cabecera + actividades) */
.ui-cal-day{min-width:0}
.ui-cal-day-h{display:flex;align-items:center;gap:10px;margin:0;padding:8px 16px;background:var(--mca-surface-2);border-bottom:1px solid var(--mca-line);font-size:13px;font-weight:500;color:var(--mca-text-3)}
.ui-cal-day + .ui-cal-day > .ui-cal-day-h{border-top:1px solid var(--mca-line)}
.ui-cal-daybtn{display:inline-flex;align-items:baseline;gap:6px;min-height:32px;padding:5px 6px;margin-left:-6px;border:0;border-radius:6px;background:none;font:inherit;color:var(--mca-text-2);cursor:pointer}
.ui-cal-daybtn:hover{background:rgba(19,37,61,.05);color:var(--mca-text)}
.ui-cal-daybtn:focus-visible{outline:none;box-shadow:var(--mca-focus)}
.ui-cal-daybtn .num{font-size:16px;font-weight:700;color:var(--mca-text);font-variant-numeric:tabular-nums}
.ui-cal-day.is-today .ui-cal-daybtn .num{min-width:28px;padding:1px 7px;border-radius:999px;text-align:center;color:#fff;background:var(--mca-blue)}
.ui-cal-daybtn .dow{text-transform:capitalize}
.ui-cal-daybtn .dow .s{display:none}
.ui-cal-daybtn .mon{color:var(--mca-text-2)}
.ui-cal-day-n{margin-left:auto;font-size:12.5px}
.ui-cal-foot .ic{display:inline-block;width:14px;height:14px;vertical-align:-2px;margin-right:4px}
.ui-cal-ev-meta .is-high{color:var(--mca-danger);font-weight:600}
.ui-cal-day-b{position:relative;min-width:0}
.ui-cal-free{margin:0;padding:10px 16px;font-size:13px;color:var(--mca-text-3)}
.ui-cal-now{display:none}
.ui-cal-gutter{display:none}
.ui-cal-cluster{display:none}

/* actividad: fila de agenda (por defecto) */
.ui-cal-ev{position:relative;display:grid;grid-template-columns:96px minmax(0,1fr) auto;align-items:start;gap:4px 14px;padding:12px 16px;
  border-top:1px solid var(--mca-line);font-size:13.5px;line-height:1.4;color:var(--mca-text)}
.ui-cal-day-b > .ui-cal-ev:first-child{border-top:0}
.ui-cal-ev:hover{background:var(--mca-surface-hover)}
.ui-cal-ev-time{font-weight:700;font-variant-numeric:tabular-nums;color:var(--mca-text);white-space:nowrap;padding-top:1px}
.ui-cal-ev-main{display:flex;flex-direction:column;gap:3px;min-width:0}
.ui-cal-ev-head{display:flex;align-items:flex-start;gap:8px;min-width:0}
.ui-cal-ev-ic{width:22px;height:22px;flex:0 0 auto;display:grid;place-items:center;border-radius:6px;color:var(--tone);background:var(--tone-soft)}
.ui-cal-ev-ic .ic{width:13px;height:13px}
.ui-cal-ev-title{display:block;min-width:0;font-weight:600;color:var(--mca-text);text-decoration:none;overflow-wrap:anywhere;padding-top:1px}
.ui-cal-ev-meta{display:flex;flex-wrap:wrap;gap:3px 14px;padding-left:30px;font-size:12.5px;color:var(--mca-text-3)}
.ui-cal-ev-meta > span{display:inline-flex;align-items:center;gap:5px;min-width:0;overflow-wrap:anywhere}
.ui-cal-ev-meta .ic{width:13px;height:13px;flex:0 0 auto}
.ui-cal-ev-side{display:flex;align-items:center;gap:8px;flex-wrap:wrap;justify-content:flex-end}
.ui-cal-ev.is-cancelled .ui-cal-ev-title{text-decoration:line-through;color:var(--mca-text-3)}
.ui-cal-ev.is-cancelled .ui-cal-ev-time{color:var(--mca-text-3)}
/* toda la tarjeta abre el detalle (enlace «estirado»); el acceso a la reunión queda por encima */
.ui-cal-open::after{content:"";position:absolute;inset:0;border-radius:inherit}
.ui-cal-open:focus-visible{outline:none}
.ui-cal-open:focus-visible::after{box-shadow:var(--mca-focus)}
.ui-cal .ag-join{position:relative;z-index:1;display:inline-flex;align-items:center;gap:6px;height:28px;padding:0 10px;border-radius:var(--mca-r-ctl);background:var(--mca-blue);
  color:#fff;font-size:12.5px;font-weight:600;text-decoration:none;white-space:nowrap}
.ui-cal .ag-join:hover{background:var(--mca-blue-hover)}
.ui-cal .ag-join:focus-visible{outline:none;box-shadow:var(--mca-focus)}
.ui-cal .ag-join .ic{width:14px;height:14px}
.ui-cal .ag-join.only-ic{width:24px;height:24px;padding:0;justify-content:center;border-radius:6px}
.ui-cal .ag-join.only-ic .ic{width:13px;height:13px}

/* agenda estrecha (móvil): hora encima, estado y acceso debajo */
@container cal (max-width:560px){
  .ui-cal-bar{padding:12px}
  .ui-cal-bar .sp{display:none}
  .ui-cal-title{font-size:16px;flex:1 1 auto}
  .ui-cal-bar .ui-seg{width:100%}
  .ui-cal-bar .ui-seg button{flex:1;height:32px}
  .ui-cal-bar .ui-btn--sm{--h:36px}
  .ui-cal-ev{grid-template-columns:minmax(0,1fr);gap:6px;padding:12px 14px}
  .ui-cal-ev-time{font-size:13px;color:var(--tone)}
  .ui-cal-ev-meta{padding-left:0}
  .ui-cal-ev-side{justify-content:flex-start}
  .ui-cal-ev-side .ag-join{height:32px}
  .ui-cal-day-h{padding:8px 14px}
}

/* rejilla horaria (semana y día con espacio) */
@container cal (min-width:900px){
  .ui-cal-grid{--ph:72px;display:grid;grid-template-columns:56px var(--tpl,repeat(var(--cols),minmax(0,1fr)))}
  .ui-cal-gutter{display:block;border-right:1px solid var(--mca-line)}
  .ui-cal-gutter-h{height:58px;border-bottom:1px solid var(--mca-line);background:var(--mca-surface-2)}
  .ui-cal-hours{position:relative;height:calc(var(--hours) * var(--ph));margin:0;padding:0;list-style:none}
  .ui-cal-hours li{position:absolute;right:8px;top:calc(var(--i) * var(--ph) - 7px);font-size:11.5px;font-weight:500;color:var(--mca-text-3);font-variant-numeric:tabular-nums}
  .ui-cal-hours li:first-child{top:4px}
  .ui-cal-grid > .ui-cal-day + .ui-cal-day{border-left:1px solid var(--mca-line)}
  .ui-cal-grid .ui-cal-day-h{position:sticky;top:64px;z-index:3;height:58px;justify-content:center;flex-direction:column;gap:0;padding:6px 4px;border-top:0 !important}
  .ui-cal-grid .ui-cal-daybtn{flex-direction:column;align-items:center;gap:1px;margin:0;padding:2px 10px}
  .ui-cal-grid .ui-cal-daybtn .dow{font-size:11.5px;font-weight:600;letter-spacing:.04em;text-transform:uppercase}
  .ui-cal-grid .ui-cal-daybtn .dow .s{display:inline}
  .ui-cal-grid .ui-cal-daybtn .dow .l,.ui-cal-grid .ui-cal-daybtn .mon{display:none}
  .ui-cal-grid .ui-cal-daybtn .num{font-size:18px;line-height:1.35}
  .ui-cal-grid .ui-cal-day-n{display:none}
  .ui-cal-grid .ui-cal-day-b{height:calc(var(--hours) * var(--ph));
    background:repeating-linear-gradient(to bottom,var(--mca-line) 0 1px,transparent 1px calc(var(--ph) / 2),#EEF2F7 calc(var(--ph) / 2) calc(var(--ph) / 2 + 1px),transparent calc(var(--ph) / 2 + 1px) var(--ph))}
  .ui-cal-grid .ui-cal-day.is-today .ui-cal-day-b{background-color:#F7FAFE}
  .ui-cal-grid .ui-cal-free{display:none}
  .ui-cal-grid .ui-cal-now{display:block;position:absolute;left:0;right:0;top:calc(var(--t) * var(--ph) / 60);z-index:2;height:2px;background:var(--mca-danger);pointer-events:none}
  .ui-cal-grid .ui-cal-now::before{content:"";position:absolute;left:-4px;top:-3px;width:8px;height:8px;border-radius:50%;background:var(--mca-danger)}

  .ui-cal-grid .ui-cal-ev{position:absolute;z-index:3;top:calc(var(--t) * var(--ph) / 60 + 1px);height:max(22px,calc(var(--d) * var(--ph) / 60 - 3px));
    left:calc(var(--l) / var(--n) * (100% - 6px) + 3px);width:calc((100% - 6px) / var(--n) - 2px);
    display:flex;flex-direction:column;gap:2px;padding:5px 9px;border:0;border-radius:7px;overflow:hidden;font-size:12px;line-height:1.3;
    background:var(--tone-soft);box-shadow:inset 0 0 0 1px color-mix(in srgb,var(--tone) 14%,transparent),0 0 0 1px var(--mca-surface)}
  .ui-cal-grid .ui-cal-ev > *,.ui-cal-grid .ui-cal-ev-main > *{flex-shrink:0}
  /* 3 o más a la vez en la semana: sus tarjetas se sustituyen por una tarjeta de grupo (.ui-cal-cluster) */
  .ui-cal-grid:not(.ui-cal-grid--day) .ui-cal-ev.is-stacked{display:none}
  .ui-cal-grid:not(.ui-cal-grid--day) .ui-cal-cluster{display:flex;flex-direction:column;gap:2px;position:absolute;z-index:4;left:3px;right:3px;top:calc(var(--t) * var(--ph) / 60 + 1px);
    min-height:calc(var(--d) * var(--ph) / 60 - 3px);padding:4px 6px 5px;border-radius:6px;background:var(--mca-surface);
    box-shadow:inset 0 0 0 1px var(--mca-line-strong),var(--mca-shadow-xs)}
  .ui-cal-cluster-h{display:flex;align-items:center;gap:5px;padding:0 2px;font-size:11px;font-weight:700;color:var(--mca-text-2)}
  .ui-cal-cluster-h .ic{width:12px;height:12px}
  .ui-cal-cluster ul{margin:0;padding:0;list-style:none}
  .ui-cal-cluster .ui-cal-chip{padding:1px 2px;font-size:11.5px;gap:5px}
  /* tarjetas estrechas: solo hora de inicio y sin botón de acceso (sigue en la ficha, la vista día y la lista) */
  .ui-cal-grid .ui-cal-ev{container:ev / inline-size}
  .ui-cal-grid .ui-cal-ev:hover,.ui-cal-grid .ui-cal-ev:focus-within{z-index:20;box-shadow:inset 0 0 0 1px color-mix(in srgb,var(--tone) 26%,transparent),0 0 0 1px var(--mca-surface),var(--mca-shadow-pop)}
  .ui-cal-grid .ui-cal-ev-time{padding:0;font-size:11.5px;font-weight:600;color:var(--tone);overflow:hidden;text-overflow:ellipsis}
  .ui-cal-grid .ui-cal-ev-ic{display:none}
  .ui-cal-grid .ui-cal-ev-title{padding:0;font-size:12.5px;line-height:1.3;display:-webkit-box;-webkit-box-orient:vertical;-webkit-line-clamp:var(--lines,1);overflow:hidden}
  .ui-cal-grid .ui-cal-ev-meta{display:none;padding:0;font-size:11.5px;gap:0 8px}
  .ui-cal-grid .ui-cal-ev-side .ui-badge{display:none}
  .ui-cal-grid .ui-cal-ev-side{position:absolute;top:3px;right:3px}
  .ui-cal-grid .ui-cal-ev:has(.ag-join) .ui-cal-ev-main,.ui-cal-grid .ui-cal-ev:has(.ag-join) .ui-cal-ev-time{padding-right:24px}
  .ui-cal-grid .ag-join{width:22px;height:22px;padding:0;justify-content:center;border-radius:6px}
  .ui-cal-grid .ag-join span{display:none}
  .ui-cal-grid .ui-cal-ev.is-cancelled{background:#F4F6F9}
  /* cuánto texto cabe según la duración (≤20 min: una línea; 30 min: hora y título; 1 h+: título en dos líneas y datos) */
  .ui-cal-grid .ui-cal-ev--xs{flex-direction:row;align-items:center;gap:6px;padding-top:0;padding-bottom:0}
  .ui-cal-grid .ui-cal-ev--sm{gap:0;padding-top:2px;padding-bottom:2px}
  .ui-cal-grid .ui-cal-ev--sm .ui-cal-ev-time{line-height:1.25}
  .ui-cal-grid .ui-cal-ev--xs .ui-cal-ev-main{flex:1;min-width:0}
  .ui-cal-grid .ui-cal-ev--md,.ui-cal-grid .ui-cal-ev--lg{--lines:2}
  .ui-cal-grid .ui-cal-ev--lg .ui-cal-ev-meta{display:flex;flex-direction:column}
  .ui-cal-grid .ui-cal-ev--lg .ui-cal-ev-meta > span{display:block;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;max-width:100%}
  .ui-cal-grid .ui-cal-ev--lg .ui-cal-ev-meta .ic{display:none}
  /* vista día: columna ancha; hora a la izquierda y los datos en línea */
  .ui-cal-grid--day .ui-cal-ev{flex-direction:row;align-items:flex-start;gap:14px;padding:7px 14px;font-size:12.5px}
  .ui-cal-grid--day .ui-cal-ev-time{flex:0 0 auto;min-width:84px;font-size:12px;padding-top:1px}
  .ui-cal-grid--day .ui-cal-ev-main{flex:1;min-width:0;gap:2px}
  .ui-cal-grid--day .ui-cal-ev-title{font-size:13px;--lines:1}
  .ui-cal-grid--day .ui-cal-ev--lg .ui-cal-ev-title{--lines:2}
  .ui-cal-grid--day .ui-cal-ev-meta,.ui-cal-grid--day .ui-cal-ev--lg .ui-cal-ev-meta{display:flex;flex-direction:row;flex-wrap:wrap;gap:0 14px;font-size:12px}
  .ui-cal-grid--day .ui-cal-ev-meta > span,.ui-cal-grid--day .ui-cal-ev--lg .ui-cal-ev-meta > span{display:inline-flex;flex:0 0 auto;white-space:nowrap}
  .ui-cal-grid--day .ui-cal-ev:not(.ui-cal-ev--lg) .ui-cal-ev-meta{flex-wrap:nowrap;overflow:hidden}
  .ui-cal-grid--day .ui-cal-ev-meta .ic,.ui-cal-grid--day .ui-cal-ev--lg .ui-cal-ev-meta .ic{display:inline-block}
  .ui-cal-grid--day .ui-cal-ev--xs,.ui-cal-grid--day .ui-cal-ev--sm{align-items:center;padding-top:0;padding-bottom:0}
  .ui-cal-grid--day .ui-cal-ev--xs .ui-cal-ev-main,.ui-cal-grid--day .ui-cal-ev--sm .ui-cal-ev-main{flex-direction:row;align-items:center;gap:14px}
  .ui-cal-grid--day .ui-cal-ev--xs .ui-cal-ev-head,.ui-cal-grid--day .ui-cal-ev--sm .ui-cal-ev-head{flex:0 1 auto;min-width:0}
  .ui-cal-grid--day .ui-cal-ev--xs .ui-cal-ev-meta,.ui-cal-grid--day .ui-cal-ev--sm .ui-cal-ev-meta{flex:1 1 0;min-width:0;flex-wrap:nowrap;overflow:hidden}
  .ui-cal-grid--day .ui-cal-ev-side{top:50%;transform:translateY(-50%);right:6px}
  .ui-cal-grid--day .ui-cal-ev--md .ui-cal-ev-side,.ui-cal-grid--day .ui-cal-ev--lg .ui-cal-ev-side{top:5px;transform:none}
  .ui-cal-grid--day .ag-join{width:auto;padding:0 9px}
  .ui-cal-grid--day .ag-join span{display:inline}
  .ui-cal-grid--day .ui-cal-ev:has(.ag-join) .ui-cal-ev-main{padding-right:88px}
  .ui-cal-grid--day .ui-cal-ev:has(.ag-join) .ui-cal-ev-time{padding-right:0}
}

@container ev (max-width:129px){
  .ui-cal-ev-end,.ui-cal-ev-side{display:none}
  .ui-cal-ev-main,.ui-cal-ev-time{padding-right:0 !important}
}
/* mes */
.ui-cal-month{display:grid;grid-template-columns:repeat(7,minmax(0,1fr))}
.ui-cal-mh{padding:8px 10px;font-size:12px;font-weight:600;color:var(--mca-text-3);background:var(--mca-surface-2);border-bottom:1px solid var(--mca-line)}
.ui-cal-cell{container:cell / inline-size;position:relative;display:flex;flex-direction:column;gap:2px;min-width:0;min-height:120px;padding:6px;border-bottom:1px solid var(--mca-line)}
.ui-cal-cell:not(:nth-child(7n)){border-right:1px solid var(--mca-line)}
.ui-cal-cell.is-out{background:var(--mca-surface-2)}
.ui-cal-cell.is-out .ui-cal-cell-n{color:var(--mca-text-dis)}
.ui-cal-cell-n{align-self:flex-start;min-width:26px;height:26px;padding:0 6px;border:0;border-radius:999px;background:none;font:inherit;font-size:13px;font-weight:600;
  color:var(--mca-text);font-variant-numeric:tabular-nums;cursor:pointer}
.ui-cal-cell-n:hover{background:rgba(19,37,61,.06)}
.ui-cal-cell-n:focus-visible{outline:none;box-shadow:var(--mca-focus)}
.ui-cal-cell.is-today .ui-cal-cell-n{color:#fff;background:var(--mca-blue)}
.ui-cal-chip{position:relative;display:flex;align-items:center;gap:6px;min-width:0;padding:2px 6px;border-radius:5px;font-size:12px;line-height:1.4;color:var(--mca-text)}
.ui-cal-chip:hover{background:var(--mca-surface-hover)}
.ui-cal-chip i{width:7px;height:7px;flex:0 0 auto;border-radius:50%;background:var(--tone)}
.ui-cal-chip time{flex:0 0 auto;color:var(--mca-text-3);font-variant-numeric:tabular-nums}
.ui-cal-chip a{min-width:0;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;color:inherit;text-decoration:none;font-weight:500}
.ui-cal-chip.is-cancelled a{text-decoration:line-through;color:var(--mca-text-3)}
.ui-cal-chip .ag-join{flex:0 0 auto;margin-left:auto}
.ui-cal-more{align-self:flex-start;padding:2px 6px;border:0;border-radius:5px;background:none;font:inherit;font-size:12px;font-weight:600;color:var(--mca-blue);cursor:pointer}
.ui-cal-more:hover{background:var(--mca-blue-soft)}
.ui-cal-more:focus-visible{outline:none;box-shadow:var(--mca-focus)}
.ui-cal-dots{display:none}
.ui-cal-month-agenda{display:none}
@container cell (max-width:149px){.ui-cal-chip .ag-join{display:none}}
@container cal (max-width:799px){
  .ui-cal-mh{padding:8px 0;text-align:center;font-size:11.5px}
  .ui-cal-cell{min-height:52px;align-items:center;gap:3px;padding:4px 2px;border-right:0 !important}
  .ui-cal-cell-n{align-self:center;min-width:32px;height:32px}
  .ui-cal-cell .ui-cal-chip,.ui-cal-cell .ui-cal-more{display:none}
  .ui-cal-dots{display:flex;gap:3px;height:6px}
  .ui-cal-dots i{width:6px;height:6px;border-radius:50%;background:var(--tone)}
  .ui-cal-month-agenda{display:block;border-top:1px solid var(--mca-line)}
}

/* ---------- Bandeja social ----------
   Pantalla tipo aplicación que llena el alto: conversaciones, hilo y datos del contacto.
   ≥1280: tres columnas (los datos se pliegan con su botón); 768–1279: lista + hilo y los datos
   como panel lateral superpuesto; <768: una sola vista (lista → conversación → volver).
   Sin franjas laterales: la conversación activa se marca con fondo; los colores solo comunican
   estados reales (no leídos, espera de una persona, error, envío fallido). */
.mca-canvas-body:has(.ui-inbox){padding:18px var(--mca-gutter)}
.ui-inbox{--list:340px;--info:320px;display:grid;grid-template-columns:var(--list) minmax(0,1fr);gap:16px;height:calc(100vh - 96px);height:calc(100dvh - 96px);min-height:480px}
.ui-inbox > .ui-card{display:flex;flex-direction:column;min-height:0;overflow:hidden}
.ui-inbox-list-h{padding:16px 18px 12px;border-bottom:1px solid var(--mca-line)}
.ui-inbox-list-h h1{margin:0;display:flex;align-items:center;gap:8px;font-size:17px;font-weight:700;letter-spacing:-.01em;color:var(--mca-text)}
.ui-inbox-list-h p{margin:3px 0 0;font-size:12.5px;color:var(--mca-text-3)}
/* búsqueda y filtros de la lista: buscador a todo el ancho, dos selectores y «solo no leídas» */
.ui-inbox-filters{display:flex;flex-direction:column;gap:8px;padding:10px 14px 12px;border-bottom:1px solid var(--mca-line)}
.ui-inbox-filters .ui-search{width:100%}
.ui-inbox-filters-row{display:flex;align-items:center;gap:8px;min-width:0}
.ui-inbox-filters-row > .ui-select{flex:1 1 0;min-width:0;width:100%}
.ui-inbox-filters-n{margin-left:auto;font-size:12.5px;color:var(--mca-text-3);font-variant-numeric:tabular-nums}
/* ficha comercial vinculada en el panel de datos de la bandeja */
.ui-inbox-crm{display:flex;flex-direction:column;gap:8px;padding:14px 18px 18px;border-top:1px solid var(--mca-line)}
.ui-inbox-crm h3{margin:0;font-size:13px;font-weight:600;color:var(--mca-text)}
.ui-inbox-crm-contact{display:flex;align-items:center;gap:8px;min-height:40px;padding:6px 10px;border:1px solid var(--mca-line);border-radius:var(--mca-r-ctl);color:var(--mca-text);font-size:13.5px;font-weight:600;text-decoration:none;overflow-wrap:anywhere}
.ui-inbox-crm-contact:hover{background:var(--mca-surface-hover)}
.ui-inbox-crm-contact:focus-visible{outline:none;box-shadow:var(--mca-focus)}
.ui-inbox-crm-contact span{flex:1;min-width:0}
.ui-inbox-crm-contact .ic{width:16px;height:16px;flex:0 0 auto;color:var(--mca-text-3)}
.ui-inbox-list-h p b{color:var(--mca-blue);font-weight:600}
.ui-inbox-convs{flex:1;min-height:0;overflow-y:auto;margin:0;padding:6px;list-style:none;overscroll-behavior:contain}
.ui-conv{display:flex;align-items:flex-start;gap:12px;width:100%;padding:10px 10px;border:0;border-radius:var(--mca-r-ctl);background:none;font:inherit;text-align:left;color:var(--mca-text);cursor:pointer}
.ui-conv:hover{background:var(--mca-surface-hover)}
.ui-conv:focus-visible{outline:none;box-shadow:var(--mca-focus)}
.ui-conv.is-active{background:var(--mca-blue-soft)}
.ui-conv-av{position:relative;flex:0 0 auto}
.ui-conv-src{position:absolute;right:-3px;bottom:-3px;width:19px;height:19px;border-radius:50%;display:grid;place-items:center;background:var(--mca-surface);box-shadow:0 0 0 1.5px var(--mca-surface)}
.ui-conv-main{flex:1;min-width:0;display:flex;flex-direction:column;gap:2px}
.ui-conv-top,.ui-conv-bottom{display:flex;align-items:center;gap:8px;min-width:0}
.ui-conv-name{flex:1;min-width:0;font-size:14px;font-weight:500;line-height:1.3;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.ui-conv-time{flex:0 0 auto;font-size:11.5px;color:var(--mca-text-3);font-variant-numeric:tabular-nums}
.ui-conv-preview{flex:1;min-width:0;font-size:13px;line-height:1.35;color:var(--mca-text-3);white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.ui-conv.is-unread .ui-conv-name{font-weight:700}
.ui-conv.is-unread .ui-conv-preview{color:var(--mca-text);font-weight:500}
.ui-conv.is-unread .ui-conv-time{color:var(--mca-blue);font-weight:600}
.ui-conv-unread{flex:0 0 auto}
.ui-conv-flag{display:inline-flex;align-items:center;gap:5px;align-self:flex-start;margin-top:3px;padding:1px 7px;border-radius:5px;font-size:11.5px;font-weight:600;color:var(--tone);background:var(--tone-soft)}
.ui-conv-flag .ic{width:12px;height:12px}
.ui-inbox-empty-list{list-style:none}

/* conversación */
.ui-inbox-head{display:flex;align-items:center;gap:12px;flex-wrap:wrap;padding:12px 16px 12px 18px;border-bottom:1px solid var(--mca-line)}
.ui-inbox-back{display:none}
.ui-inbox-who{flex:1 1 200px;min-width:0;display:flex;flex-direction:column;gap:2px}
.ui-inbox-who strong{font-size:15px;font-weight:700;color:var(--mca-text);white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.ui-inbox-via{display:flex;align-items:center;gap:6px;min-width:0;font-size:12.5px;color:var(--mca-text-3);overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
.ui-inbox-ico{display:inline-flex;flex:0 0 auto}
.ui-inbox-ico .ic{width:14px;height:14px}
.ui-inbox-auto{order:9;flex-basis:100%;display:flex;align-items:center;gap:6px 8px;flex-wrap:wrap;padding-left:52px}
.ui-inbox-agent{display:inline-flex;align-items:center;gap:5px;font-size:12.5px;color:var(--mca-text-2);white-space:nowrap}
.ui-inbox-agent .ic{width:14px;height:14px;color:var(--mca-text-3)}
.ui-inbox-band{display:flex;flex-wrap:wrap;align-items:center;gap:4px 12px;margin:0;padding:8px 18px;border-bottom:1px solid var(--mca-line);font-size:12.5px;line-height:1.45}
.ui-inbox-note{background:var(--mca-surface-2);color:var(--mca-text-2)}
.ui-inbox-note b{color:var(--mca-text);font-weight:600}
.ui-inbox-note.is-attention{background:var(--mca-warning-soft);color:var(--mca-warning)}
.ui-inbox-note.is-attention b{color:var(--mca-warning)}
.ui-inbox-note.is-error{background:var(--mca-danger-soft);color:var(--mca-danger)}
.ui-inbox-note.is-error b{color:var(--mca-danger)}
.ui-inbox-note .ic{width:15px;height:15px;flex:0 0 auto}
.ui-inbox-msgs{flex:1;min-height:0;overflow-y:auto;display:flex;flex-direction:column;gap:4px;padding:16px 22px 18px;background:var(--mca-surface-2);overscroll-behavior:contain}
.ui-inbox-msgs:focus-visible{outline:none;box-shadow:inset var(--mca-focus)}
.ui-inbox-day{display:flex;align-items:center;gap:12px;margin:14px 0 8px;font-size:12px;font-weight:600;color:var(--mca-text-3)}
.ui-inbox-day::before,.ui-inbox-day::after{content:"";flex:1;height:1px;background:var(--mca-line)}
.ui-inbox-msgs > .ui-inbox-day:first-child{margin-top:0}
.ui-inbox-empty-thread{margin:auto}
/* burbujas: entrante a la izquierda (blanco), saliente a la derecha (azul suave); texto oscuro en ambas */
.ui-bubble{position:relative;display:flex;flex-direction:column;gap:6px;max-width:min(72%,640px);margin-top:10px;padding:9px 13px 7px;border-radius:14px;
  font-size:14px;line-height:1.5;color:var(--mca-text);overflow-wrap:anywhere}
.ui-bubble.is-follow{margin-top:0}
.ui-bubble--in{align-self:flex-start;background:var(--mca-surface);box-shadow:inset 0 0 0 1px var(--mca-line);border-bottom-left-radius:5px}
.ui-bubble--out{align-self:flex-end;background:var(--mca-blue-soft);box-shadow:inset 0 0 0 1px #D3E1F3;border-bottom-right-radius:5px}
.ui-bubble.is-failed{background:var(--mca-danger-soft);box-shadow:inset 0 0 0 1px #F3CBC7}
.ui-bubble-tag{display:inline-flex;align-items:center;gap:5px;font-size:11.5px;font-weight:600;color:var(--mca-teal)}
.ui-bubble-tag .ic{width:13px;height:13px}
.ui-bubble-meta{align-self:flex-end;font-size:11px;color:var(--mca-text-3);font-variant-numeric:tabular-nums;white-space:nowrap}
.ui-bubble.is-failed .ui-bubble-meta{color:var(--mca-danger)}
.ui-bubble-warn{display:flex;align-items:flex-start;gap:6px;padding:7px 9px;border-radius:8px;background:var(--mca-surface);font-size:12px;line-height:1.4;color:var(--mca-danger)}
.ui-bubble-warn .ic{width:14px;height:14px;flex:0 0 auto;margin-top:1px}
.ui-bubble-media{display:block}
.ui-bubble-media img{display:block;max-width:min(300px,100%);max-height:300px;border-radius:9px}
.ui-bubble-media video{display:block;max-width:min(320px,100%);max-height:320px;border-radius:9px}
.ui-bubble-media audio{display:block;max-width:100%}
.ui-bubble-doc{display:inline-flex;align-items:center;gap:8px;max-width:100%;padding:8px 10px;border-radius:9px;background:var(--mca-surface);box-shadow:inset 0 0 0 1px var(--mca-line);
  font-size:13px;font-weight:600;color:var(--mca-text);text-decoration:none}
.ui-bubble-doc span{min-width:0;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
.ui-bubble-doc .ic{width:16px;height:16px;flex:0 0 auto;color:var(--mca-blue)}
.ui-bubble-doc:hover{box-shadow:inset 0 0 0 1px var(--mca-line-strong)}
.ui-bubble-doc:focus-visible{outline:none;box-shadow:var(--mca-focus)}
.ui-bubble-doc.is-pending{font-weight:500;color:var(--mca-text-3)}
/* respuesta */
.ui-inbox-compose{display:flex;flex-direction:column;gap:8px;padding:12px 16px 12px;border-top:1px solid var(--mca-line);background:var(--mca-surface)}
.ui-inbox-compose-row{display:flex;align-items:flex-end;gap:8px}
.ui-inbox-attach{--h:40px;flex:0 0 auto;cursor:pointer}
.ui-inbox-attach input{display:none}
.ui-inbox-text{flex:1;height:40px;min-height:40px;max-height:144px;padding:9px 12px;line-height:1.45;font-size:14px;resize:none;overflow-y:auto}
.ui-inbox-text:disabled{background:var(--mca-surface-2);cursor:not-allowed}
.ui-inbox-send{--h:40px;flex:0 0 auto}
.ui-inbox-hint{display:flex;align-items:center;gap:6px;margin:0;font-size:12px;color:var(--mca-text-3)}
.ui-inbox-hint .ic{width:13px;height:13px}
.ui-inbox-keys{margin-left:auto;white-space:nowrap}
.ui-inbox-chip{display:inline-flex;align-items:center;gap:8px;align-self:flex-start;max-width:100%;padding:5px 6px 5px 10px;border-radius:var(--mca-r-ctl);background:var(--mca-surface-2);box-shadow:inset 0 0 0 1px var(--mca-line);font-size:12.5px;color:var(--mca-text)}
.ui-inbox-chip span{min-width:0;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
.ui-inbox-chip .ic{width:14px;height:14px;flex:0 0 auto;color:var(--mca-text-3)}
.ui-inbox-chip button{display:grid;place-items:center;width:24px;height:24px;border:0;border-radius:6px;background:none;color:var(--mca-text-3);cursor:pointer}
.ui-inbox-chip button:hover{background:var(--mca-danger-soft);color:var(--mca-danger)}
.ui-inbox-window{align-items:center;flex-wrap:wrap}
.ui-inbox-window > span{flex:1 1 260px}
/* datos del contacto */
.ui-inbox > .ui-inbox-info,.ui-inbox-scrim{display:none}
.ui-inbox-info-h{display:flex;align-items:center;justify-content:space-between;gap:8px;min-height:52px;padding:8px 10px 8px 18px;border-bottom:1px solid var(--mca-line)}
.ui-inbox-info-h h2{margin:0;font-size:14px;font-weight:600;color:var(--mca-text)}
.ui-inbox-info-id{display:flex;flex-direction:column;align-items:center;gap:6px;padding:20px 18px 14px;text-align:center}
.ui-inbox-info-id strong{font-size:15px;font-weight:700;color:var(--mca-text);overflow-wrap:anywhere}
/* etiqueta sobre valor: los datos largos (nombres, identificadores, fechas) se leen completos */
.ui-inbox-info-dl{display:flex;flex-direction:column;gap:12px;padding:16px 18px 18px;border-top:1px solid var(--mca-line);overflow-y:auto}
.ui-inbox-info-dl > div{display:flex;flex-direction:column;gap:2px;min-width:0}
.ui-inbox-info-dl dt{font-size:12px;font-weight:500;color:var(--mca-text-3)}
.ui-inbox-info-dl dd{margin:0;font-size:13.5px;color:var(--mca-text);overflow-wrap:anywhere}
/* plantillas de WhatsApp */
.ui-modal--sm{--w:580px}
.ui-tpl-pick{display:flex;flex-direction:column;align-items:stretch;gap:8px;width:100%;padding:12px 14px;border:0;border-radius:var(--mca-r-ctl);background:var(--mca-surface);
  box-shadow:inset 0 0 0 1px var(--mca-line);font:inherit;text-align:left;color:var(--mca-text);cursor:pointer}
.ui-tpl-pick:hover{box-shadow:inset 0 0 0 1px var(--mca-line-strong);background:var(--mca-surface-hover)}
.ui-tpl-pick:focus-visible{outline:none;box-shadow:var(--mca-focus)}
.ui-tpl-pick-body{display:block;padding:9px 11px;border-radius:8px;background:var(--mca-surface-2);font-size:13px;line-height:1.5;white-space:pre-wrap;color:var(--mca-text)}

@media(min-width:1280px){
  .ui-inbox.is-info:has(> .ui-inbox-info){grid-template-columns:var(--list) minmax(0,1fr) var(--info)}
  .ui-inbox.is-info > .ui-inbox-info{display:flex}
}
@media(min-width:1600px){.ui-inbox{--list:360px;--info:340px}}
@media(max-width:1279px){
  .ui-inbox{--list:300px}
  .ui-inbox.is-info:has(> .ui-inbox-info) > .ui-inbox-scrim{display:block;position:fixed;inset:0;z-index:59;background:rgba(19,37,61,.32)}
  .ui-inbox.is-info > .ui-inbox-info{display:flex;position:fixed;z-index:60;top:0;right:0;bottom:0;width:min(340px,100%);border-radius:0;box-shadow:var(--mca-shadow-pop)}
}
@media(max-width:767px){
  .mca-canvas-body:has(.ui-inbox){padding:12px}
  .ui-inbox{grid-template-columns:minmax(0,1fr);height:calc(100dvh - 88px);min-height:420px}
  .ui-inbox:has(.ui-inbox-thread[data-selected]) > .ui-inbox-list{display:none}
  .ui-inbox:not(:has(.ui-inbox-thread[data-selected])) > .ui-inbox-thread{display:none}
  .ui-inbox-back{display:inline-flex;margin-left:-6px}
  .ui-inbox-head{gap:8px 10px;padding:10px 12px}
  .ui-inbox-who{flex:1 1 0}
  .ui-inbox-send{width:var(--h);padding:0}
  .ui-inbox-send .ui-hide-sm{display:none}
  /* estado del asesor y acciones: en una línea si caben; si no, el estado arriba y las acciones a todo el ancho */
  .ui-inbox-auto{padding-left:0;gap:8px}
  .ui-inbox-agent{display:none}
  .ui-inbox-auto > .ui-badge{flex:0 0 auto;max-width:100%;white-space:normal;height:auto;min-height:24px;padding-top:3px;padding-bottom:3px;line-height:1.25}
  .ui-inbox-auto > .ui-btn{flex:1 1 150px;--h:36px}
  .ui-inbox-note{font-size:12px;gap:2px 10px}
  .ui-inbox-note-when{display:none}
  .ui-inbox-msgs{padding:12px 12px 14px}
  .ui-bubble{max-width:88%}
  .ui-inbox-band{padding:8px 12px}
  .ui-inbox-compose{padding:10px 12px}
  .ui-inbox-keys{display:none}
  .ui-inbox.is-info > .ui-inbox-info{width:100%}
}

/* ---------- Publicador ----------
   Editor por secciones (formato · archivo · descripción · destino) y, al lado, la publicación
   (resumen + acción principal) y el resultado por red. En <1024 una sola columna en ese orden. */
.ui-pub{display:grid;grid-template-columns:minmax(0,1fr) 380px;gap:16px;align-items:start}
.ui-pub-side{display:flex;flex-direction:column;gap:16px;position:sticky;top:80px;min-width:0}
.ui-pub-stack{display:flex;flex-direction:column;gap:14px}
.ui-seg--fill{display:flex;width:100%}
.ui-seg--fill button{flex:1;height:34px}
.ui-seg--icons button{display:inline-flex;align-items:center;justify-content:center;gap:7px}
.ui-seg--icons .ic{width:16px;height:16px}
/* zona de archivo: arrastrar/elegir, vista previa grande y datos del archivo */
.ui-drop{display:flex;flex-direction:column;align-items:center;justify-content:center;gap:6px;min-height:220px;padding:24px;border:1.5px dashed var(--mca-line-strong);
  border-radius:var(--mca-r-card);background:var(--mca-surface-2);text-align:center;color:var(--mca-text-2);cursor:pointer;transition:border-color .14s,background-color .14s}
.ui-drop:hover{border-color:#9DB8DD;background:var(--mca-blue-soft)}
.ui-drop:focus-within{border-color:var(--mca-blue);box-shadow:var(--mca-focus)}
.ui-drop.is-invalid{border-color:var(--mca-danger);background:var(--mca-danger-soft)}
.ui-drop.has-file{min-height:0;padding:12px;gap:12px;border-style:solid;background:var(--mca-surface);cursor:default}
.ui-drop.has-file:hover{background:var(--mca-surface);border-color:#9DB8DD}
.ui-drop-ic{width:44px;height:44px;flex:0 0 auto;display:grid;place-items:center;border-radius:12px;color:var(--tone,var(--mca-blue));background:var(--tone-soft,var(--mca-blue-soft))}
.ui-drop-ic .ic{width:20px;height:20px}
.ui-drop-title{font-size:14px;font-weight:600;color:var(--mca-text);overflow-wrap:anywhere}
.ui-drop-hint{max-width:460px;font-size:12.5px;line-height:1.45;color:var(--mca-text-3)}
.ui-drop-preview{display:grid;place-items:center;width:100%;padding:12px;border-radius:var(--mca-r-ctl);background:var(--mca-surface-2)}
.ui-drop-preview img{display:block;max-width:100%;max-height:440px;border-radius:6px;box-shadow:var(--mca-shadow-xs)}
.ui-drop.is-story .ui-drop-preview img{max-height:520px}
.ui-drop-file{display:flex;align-items:center;gap:12px;width:100%;text-align:left;cursor:pointer}
.ui-drop-meta{flex:1;min-width:0;display:flex;flex-direction:column;gap:2px}
.ui-drop-file .ui-btn{flex:0 0 auto;pointer-events:none}
.ui-pub-caption{min-height:150px}
.ui-pub-count{display:flex;align-items:flex-start;gap:8px;font-size:12px;color:var(--mca-text-3);font-variant-numeric:tabular-nums}
.ui-pub-count .sp{flex:1}
.ui-pub-count .is-near{color:var(--mca-warning);font-weight:600}
.ui-pub-nodesc{display:flex;align-items:center;gap:8px}
.ui-pub-nodesc .ic{width:15px;height:15px}
/* destinos: tarjetas seleccionables */
.ui-nets{display:grid;grid-template-columns:repeat(auto-fit,minmax(min(100%,220px),1fr));gap:10px}
.ui-net{display:flex;align-items:center;gap:12px;min-height:60px;padding:10px 14px;border:1px solid var(--mca-line-strong);border-radius:var(--mca-r-ctl);
  background:var(--mca-surface);cursor:pointer;transition:border-color .14s,background-color .14s}
.ui-net:hover{border-color:#9DB8DD}
.ui-net:has(input:checked){border-color:#9DB8DD;background:var(--mca-blue-soft)}
.ui-net:focus-within{box-shadow:var(--mca-focus)}
.ui-net.is-off{cursor:not-allowed;opacity:.6;background:var(--mca-surface-2)}
.ui-net input{width:17px;height:17px;flex:0 0 auto;margin:0;accent-color:var(--mca-blue);cursor:inherit}
.ui-net-ic{display:inline-flex;flex:0 0 auto}
.ui-net-text{display:flex;flex-direction:column;gap:1px;min-width:0}
.ui-net-text b{font-size:14px;font-weight:600;color:var(--mca-text)}
.ui-net-text small{font-size:12px;color:var(--mca-text-3)}
/* publicación y resultado */
.ui-pub-sum{grid-template-columns:auto minmax(0,1fr);gap:8px 14px}
.ui-pub-targets{display:flex;flex-wrap:wrap;gap:4px 12px}
.ui-pub-targets > span{display:inline-flex;align-items:center;gap:6px}
.ui-pub-go{--h:44px;width:100%;font-size:14px}
.ui-pub-res{align-items:flex-start}
.ui-pub-res .ui-list-aside{padding-top:1px}
.ui-pub-err{font-size:12.5px;line-height:1.45;color:var(--mca-danger)}
.ui-pub-err.is-wait{color:var(--mca-warning)}
.ui-pub-empty{padding:28px 20px}
@media(max-width:1023px){
  .ui-pub{grid-template-columns:minmax(0,1fr)}
  .ui-pub-side{position:static}
}
@media(max-width:640px){
  .ui-drop{min-height:180px;padding:18px}
  .ui-drop-file{flex-wrap:wrap}
  .ui-drop-file .ui-btn{flex:1 1 100%}
  .ui-drop-preview img{max-height:360px}
  .ui-pub-res{flex-wrap:wrap}
  .ui-pub-res .ui-list-aside{flex-basis:100%;padding-left:32px}
}

/* ==== etapa 6 · GRUPO A · Ajustes, notificaciones y perfil ==== */
/* pestañas de una sección (p. ej. Ajustes): enlaces con role="tab"; la selección se marca con
   un subrayado fino (indicador funcional) y el texto en tinta. Si no caben, se desplazan en
   horizontal dentro de la barra (nunca la página). */
.ui-tabs{display:flex;gap:2px;margin:0 0 20px;overflow-x:auto;scrollbar-width:none;box-shadow:inset 0 -1px 0 var(--mca-line)}
.ui-tabs::-webkit-scrollbar{display:none}
.ui-tab{display:inline-flex;align-items:center;gap:7px;flex:0 0 auto;height:42px;padding:0 12px;border-bottom:2px solid transparent;
  font-size:13.5px;font-weight:600;color:var(--mca-text-2);text-decoration:none;white-space:nowrap;transition:color .14s,border-color .14s}
.ui-tab .ic{width:15px;height:15px;color:var(--mca-text-3)}
.ui-tab:hover{color:var(--mca-text);border-bottom-color:var(--mca-line-strong)}
.ui-tab[aria-selected=true]{color:var(--mca-text);border-bottom-color:var(--mca-blue)}
.ui-tab[aria-selected=true] .ic{color:var(--mca-blue)}
.ui-tab:focus-visible{outline:none;box-shadow:inset var(--mca-focus);border-radius:6px 6px 0 0}
/* móvil: las pestañas no caben en una línea → rejilla de pastillas (todas visibles, la elegida en blanco) */
@media(max-width:640px){
  .ui-tabs{flex-wrap:wrap;gap:4px;padding:4px;overflow:visible;background:var(--mca-surface-2);border:1px solid var(--mca-line);border-radius:var(--mca-r-ctl);box-shadow:none}
  .ui-tab{flex:1 1 auto;justify-content:center;height:36px;padding:0 10px;border:0;border-radius:6px}
  .ui-tab:hover{background:rgba(19,37,61,.05)}
  .ui-tab[aria-selected=true]{background:var(--mca-surface);box-shadow:var(--mca-shadow-xs),inset 0 0 0 1px var(--mca-line-strong)}
  .ui-tab:focus-visible{border-radius:6px;box-shadow:var(--mca-focus)}
}
/* imagen + controles (logo, foto de perfil): vista previa enmarcada a la izquierda */
.ui-media{display:flex;align-items:center;gap:16px 20px;flex-wrap:wrap;min-width:0}
.ui-media-body{flex:1 1 240px;min-width:0;display:flex;flex-direction:column;gap:8px}
.ui-media--top{align-items:flex-start}
.ui-thumb{display:grid;place-items:center;flex:0 0 auto;width:132px;height:72px;padding:8px;overflow:hidden;border:1px solid var(--mca-line);border-radius:var(--mca-r-ctl);background:var(--mca-surface)}
.ui-thumb img{max-width:100%;max-height:100%;object-fit:contain}
.ui-thumb-empty{display:flex;align-items:center;gap:8px;font-size:12.5px;color:var(--mca-text-3)}
.ui-thumb-empty .ic{width:20px;height:20px}
/* vista previa sobre el fondo del menú lateral (tamaño real del logo) */
.ui-thumb--nav{width:auto;min-width:180px;max-width:100%;height:auto;min-height:72px;padding:14px 18px;place-items:center start;background:var(--mca-nav-bg-1)}
.ui-thumb--nav img{max-width:200px;max-height:none}
/* fila de controles en línea (campo corto + botones) */
.ui-inline{display:flex;align-items:center;gap:8px;flex-wrap:wrap;min-width:0}
.ui-inline > .ui-input{flex:1 1 220px}
.ui-inline > .ui-input--num{flex:0 0 96px;width:96px}
.ui-inline-unit{font-size:13px;color:var(--mca-text-3)}
/* botón que envuelve un <input type=file> visualmente oculto (.ui-sr): el foco se ve en el botón */
.ui-btn:has(> .ui-sr:focus-visible){box-shadow:var(--mca-focus)}
/* filas de lista con acciones: en móvil las acciones bajan bajo el texto */
.ui-list-item--stack-sm > .ui-list-aside{flex-wrap:wrap;justify-content:flex-end}
@media(max-width:640px){
  .ui-list-item--stack-sm{flex-direction:column;align-items:stretch}
  .ui-list-item--stack-sm > .ui-list-aside{justify-content:flex-start}
}
/* texto introductorio de una tarjeta, bajo su cabecera */
.ui-card-intro{margin:0;padding:12px 18px;border-bottom:1px solid var(--mca-line);font-size:13px;line-height:1.5;color:var(--mca-text-2);overflow-wrap:anywhere}
.ui-text-success{color:var(--mca-success) !important}
.ui-list-meta .ic{display:inline-block;width:13px;height:13px;vertical-align:-2px;margin-right:4px}
.ui-meta-ic{display:inline-block;width:14px;height:14px;vertical-align:-2px}
/* datos etiqueta → valor con etiquetas largas: en móvil, la etiqueta encima del valor */
@media(max-width:640px){
  .ui-meta--stack-sm{grid-template-columns:minmax(0,1fr);gap:2px}
  .ui-meta--stack-sm dd + dt{margin-top:8px}
}
/* cuerpo editable del editor de correo: marcador vacío, imágenes y listas */
.ui-editor-body:empty::before{content:attr(data-ph);color:var(--mca-text-3);pointer-events:none}
.ui-editor-body img{max-width:100%}
.ui-editor-body ul{list-style:disc;padding-left:1.6em;margin:.5em 0}
.ui-editor-body ol{list-style:decimal;padding-left:1.6em;margin:.5em 0}
.ui-editor-body li{margin:.2em 0}
/* verificación en dos pasos: QR y códigos de recuperación */
.ui-qr{flex:0 0 auto;width:210px;max-width:100%;padding:8px;border:1px solid var(--mca-line);border-radius:var(--mca-r-ctl);background:#fff;line-height:0}
.ui-qr svg{width:100%;height:auto}
.ui-codes{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:6px 16px;margin:4px 0;font-family:ui-monospace,SFMono-Regular,Menlo,Consolas,monospace;font-size:13.5px;color:var(--mca-text)}
/* casilla con título y explicación debajo */
.ui-check--top{align-items:flex-start}
.ui-check--top input{margin-top:2px}
.ui-check-text{display:flex;flex-direction:column;gap:2px;min-width:0}
/* campo no editable (p. ej. correo de acceso ya creado) */
.ui-input:disabled{background:var(--mca-surface-2);color:var(--mca-text-3);cursor:not-allowed;box-shadow:none}
/* formulario heredado (Breeze) dentro de una tarjeta: ancho de lectura */
.ui-form-narrow{max-width:560px}
/* ==== fin GRUPO A ==== */

/* ==== etapa 6 · GRUPO B · Social: canales, Meta, plantillas WA y formularios ==== */
/* fila dato + acciones: el texto a la izquierda y los botones a la derecha (bajan si no caben) */
.ui-split{display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:10px 16px;min-width:0}
.ui-split-main{flex:1 1 300px;min-width:0;display:flex;flex-direction:column;gap:4px;overflow-wrap:anywhere}
.ui-split-main > .ui-person-line{gap:6px 10px}
.ui-card-h > .ui-split-main{flex:1 1 0;gap:2px}
.ui-list-item--col{flex-direction:column;align-items:stretch;gap:10px}
/* fila de lista con tres columnas (nombre · selector · selector); en móvil, una */
.ui-list-item--grid{display:grid;grid-template-columns:minmax(0,1.3fr) minmax(0,1fr) minmax(0,1fr);align-items:center;gap:10px}
.ui-list-item--grid .ui-select{width:100%}
@media(max-width:900px){.ui-list-item--grid{grid-template-columns:minmax(0,1fr)}}
/* tarjeta-opción elegida (p. ej. la Página a conectar) */
.ui-tpl-pick[aria-pressed=true]{background:var(--mca-blue-soft);box-shadow:inset 0 0 0 2px var(--mca-blue)}
.ui-tpl-pick[aria-pressed=true]:focus-visible{box-shadow:inset 0 0 0 2px var(--mca-blue),var(--mca-focus)}
/* asistente por pasos */
.ui-steps{display:grid;grid-template-columns:repeat(5,minmax(0,1fr));gap:8px;margin:0;padding:0;list-style:none}
.ui-steps > li{display:flex;align-items:center;gap:8px;min-width:0;padding:9px 12px;border:1px solid var(--mca-line);border-radius:var(--mca-r-ctl);background:var(--mca-surface);
  font-size:12.5px;font-weight:600;line-height:1.3;color:var(--mca-text-2)}
.ui-step-n .ic{width:13px;height:13px}
.ui-steps .ui-step-n{width:22px;height:22px;font-size:12px}
.ui-step-n.is-pending{color:var(--mca-text-2);background:var(--mca-neutral-soft)}
.ui-step-n.is-done{color:var(--mca-success);background:var(--mca-success-soft)}
.ui-step-n.is-current{color:#fff;background:var(--mca-blue)}
.ui-steps > li.is-current{border-color:#9DB8DD;background:var(--mca-blue-soft);color:var(--mca-blue-deep)}
@media(max-width:1023px){.ui-steps{grid-template-columns:repeat(3,minmax(0,1fr))}}
@media(max-width:640px){.ui-steps{grid-template-columns:repeat(2,minmax(0,1fr))}}
/* lista de comprobaciones (✓ / ✗ / –) */
.ui-checklist{display:grid;gap:5px;margin:0;padding:0;list-style:none;font-size:13px;line-height:1.45;color:var(--mca-text);overflow-wrap:anywhere}
.ui-checklist b{display:inline-block;width:18px;font-weight:700;color:var(--mca-text-3)}
.ui-checklist .is-ok b{color:var(--mca-success)}
.ui-checklist .is-fail b{color:var(--mca-danger)}
/* tarjeta plegable (<details>): la cabecera hace de botón */
.ui-disclosure > summary{cursor:pointer;list-style:none;border-bottom:0;border-radius:var(--mca-r-card)}
.ui-disclosure > summary::-webkit-details-marker{display:none}
.ui-disclosure > summary:focus-visible{outline:none;box-shadow:var(--mca-focus)}
.ui-disclosure > summary .ui-disclosure-chev{width:16px;height:16px;color:var(--mca-text-3);transition:transform .14s}
.ui-disclosure[open] > summary{border-bottom:1px solid var(--mca-line);border-radius:var(--mca-r-card) var(--mca-r-card) 0 0}
.ui-disclosure[open] > summary .ui-disclosure-chev{transform:rotate(180deg)}
/* vista previa de un mensaje (plantillas de WhatsApp): burbuja de la bandeja sobre fondo de chat */
.ui-msg-preview{display:flex;flex-direction:column;align-items:flex-start;min-height:200px;padding:16px;border-radius:var(--mca-r-ctl);background:var(--mca-surface-2);box-shadow:inset 0 0 0 1px var(--mca-line)}
.ui-msg-preview > .ui-bubble{margin-top:0;max-width:100%;min-width:min(100%,240px)}
.ui-bubble-text{white-space:pre-wrap}
.ui-bubble-head{font-weight:700}
.ui-bubble-foot{font-size:12px;color:var(--mca-text-3)}
.ui-bubble-btns{display:flex;flex-direction:column;margin:2px -13px -7px;border-top:1px solid var(--mca-line)}
.ui-bubble-btn{display:flex;align-items:center;justify-content:center;gap:6px;padding:8px 10px;font-size:13px;font-weight:600;color:var(--mca-blue);overflow-wrap:anywhere;text-align:center}
.ui-bubble-btn + .ui-bubble-btn{border-top:1px solid var(--mca-line)}
.ui-bubble-btn .ic{width:14px;height:14px;flex:0 0 auto}
/* icono de marca en una ficha (WhatsApp, Instagram, Messenger) */
.ui-brand-ic{width:40px;height:40px;flex:0 0 auto;display:grid;place-items:center;border-radius:10px;background:var(--mca-surface-2);box-shadow:inset 0 0 0 1px var(--mca-line)}
/* tres columnas fijas (p. ej. webhooks por red) o una sola: nunca un 2 + 1 sin separadores */
.ui-vr-grid--3{grid-template-columns:minmax(0,1fr)}
.ui-vr-grid--3 > .ui-vr + .ui-vr{border-left:0;border-top:1px solid var(--mca-line)}
@media(min-width:1180px){
  .ui-vr-grid--3{grid-template-columns:repeat(3,minmax(0,1fr))}
  .ui-vr-grid--3 > .ui-vr + .ui-vr{border-top:0;border-left:1px solid var(--mca-line)}
}
/* botón con texto largo: en móvil puede partirse en dos líneas */
@media(max-width:640px){
  .ui-split > .ui-actions-row{width:100%}
  .ui-split > .ui-actions-row > .ui-btn,.ui-split > .ui-actions-row > span{flex:1 1 auto}
  .ui-split > .ui-actions-row > span > .ui-btn{width:100%}
  .ui-btn.ui-btn--wrap{white-space:normal;height:auto;min-height:var(--h);padding-top:7px;padding-bottom:7px;line-height:1.3;text-align:center}
  .mca-pagehead.ui-ph-stack .ph-actions > .ui-btn--wrap{flex-basis:100%}
}
/* barra con buscador y varios selectores: en móvil el buscador ocupa su propia fila */
@media(max-width:767px){
  .ui-card--list > .ui-toolbar--stack > .ui-search{flex:1 1 100%}
}
@media (prefers-reduced-motion: reduce){.ui-disclosure > summary .ui-disclosure-chev{transition:none}}
/* ==== fin GRUPO B ==== */

/* ==== etapa 6 · GRUPO C · IA: Centro de Conocimiento y Asesores ==== */
/* (usa además .ui-switch/.ui-switch-track del GRUPO G y `.ui-help code` del GRUPO B) */
/* pestañas de sección como enlaces (role=tab) sobre el control segmentado */
.ui-seg a{display:inline-flex;align-items:center;gap:7px;height:32px;padding:0 14px;border-radius:6px;font-size:13px;font-weight:600;color:var(--mca-text-2);text-decoration:none;white-space:nowrap}
.ui-seg a svg.ic{width:15px;height:15px}
.ui-seg a:hover{color:var(--mca-text);background:rgba(19,37,61,.05)}
.ui-seg a[aria-selected=true]{background:var(--mca-surface);color:var(--mca-blue-deep);box-shadow:var(--mca-shadow-xs),inset 0 0 0 1px var(--mca-line-strong)}
.ui-seg a:focus-visible{outline:none;box-shadow:var(--mca-focus)}
.ui-seg--nav{align-self:flex-start;max-width:100%;overflow-x:auto}
/* indicadores en rejilla; como botón filtran el listado (.is-on = filtro aplicado) */
.ui-kpi-grid{display:grid;gap:16px;grid-template-columns:repeat(var(--cols,4),minmax(0,1fr))}
button.ui-kpi{width:100%;font:inherit;text-align:left;cursor:pointer}
button.ui-kpi:hover{border-color:var(--mca-line-strong)}
button.ui-kpi:focus-visible{outline:none;box-shadow:var(--mca-focus)}
.ui-kpi.is-on{border-color:var(--mca-blue);box-shadow:0 0 0 1px var(--mca-blue),var(--mca-shadow-card)}
@media(max-width:640px){.ui-kpi-grid{grid-template-columns:repeat(2,minmax(0,1fr));gap:10px}.ui-kpi-grid > .ui-kpi{padding:12px 14px;gap:8px}}
/* desplegable propio (botón con aspecto de .ui-select + .ui-menu de opciones) */
.ui-listbox{position:relative;display:inline-flex;min-width:0}
.ui-listbox--block{display:flex;width:100%}
.ui-listbox-btn{display:inline-flex;align-items:center;gap:6px;max-width:260px;text-align:left}
.ui-listbox--block .ui-listbox-btn{width:100%;max-width:none;height:var(--mca-h-lg);font-size:14px}
.ui-listbox-lbl{flex:0 0 auto;color:var(--mca-text-3);font-weight:500}
.ui-listbox-val{min-width:0;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;font-weight:600}
.ui-listbox-val.is-ph{color:var(--mca-text-3);font-weight:400}
.ui-listbox > .ui-menu{min-width:100%;width:max-content;max-width:min(460px,calc(100vw - 32px));max-height:280px;overflow:auto}
.ui-menu-item[aria-selected=true]{background:var(--mca-blue-soft);color:var(--mca-blue-deep);font-weight:600}
.ui-menu-item > span{min-width:0;overflow-wrap:anywhere}
.ui-menu-item svg.ui-menu-check{width:15px;height:15px;flex:0 0 auto;margin-left:auto;visibility:hidden}
.ui-menu-item[aria-selected=true] svg.ui-menu-check{visibility:visible}
.ui-menu-empty{padding:8px 10px;font-size:13px;color:var(--mca-text-3)}
.ui-filters-body .ui-listbox-btn{width:100%;max-width:none}
@media(max-width:767px){
  .ui-toolbar > .ui-listbox,.ui-filters-body > .ui-listbox{flex:1 1 calc(50% - 4px)}
  .ui-toolbar .ui-listbox-btn{width:100%;max-width:none}
}
/* pila de avatares pequeños (quién usa algo) */
.ui-avatars{display:inline-flex;align-items:center}
.ui-avatar--sm{--s:24px;font-size:10.5px;box-shadow:0 0 0 2px var(--mca-surface)}
.ui-avatars > .ui-avatar + .ui-avatar{margin-left:-6px}
/* columnas que se pliegan según el ancho de su contenedor (no de la ventana) */
.ui-cq{container-type:inline-size}
.ui-cq .ui-cq-narrow{display:none}
@container (max-width:960px){.ui-cq .ui-cq-wide{display:none !important}.ui-cq .ui-cq-narrow{display:block}}
/* formulario que ocupa cuerpo y pie de una modal */
.ui-modal-form{display:flex;flex-direction:column;flex:1;min-height:0}
/* documento: índice de secciones + texto (markdown) */
.ui-doc{display:grid;grid-template-columns:200px minmax(0,1fr);gap:20px;align-items:start}
.ui-doc-nav{margin:6px 0 0;padding:0;list-style:none;font-size:12.5px;color:var(--mca-text-2)}
.ui-doc-nav li{padding:6px 0;border-top:1px solid var(--mca-line);overflow-wrap:anywhere}
.ui-doc-nav li:first-child{border-top:0}
.ui-prose{min-width:0;font-size:14px;line-height:1.65;color:var(--mca-text);overflow-wrap:anywhere}
.ui-prose h1{margin:0 0 12px;font-size:18px;font-weight:700}
.ui-prose h2{margin:18px 0 6px;font-size:15px;font-weight:700;color:var(--mca-blue-deep)}
.ui-prose h3{margin:14px 0 4px;font-size:14px;font-weight:700}
.ui-prose p,.ui-prose ul,.ui-prose ol{margin:0 0 10px}
.ui-prose ul,.ui-prose ol{padding-left:20px}
.ui-prose a{color:var(--mca-blue)}
.ui-doc > nav{position:sticky;top:0}
@media(max-width:767px){.ui-doc{grid-template-columns:minmax(0,1fr)}.ui-doc > nav{position:static}}
/* tabla compacta (resultados, vista previa en modales) y caja con desplazamiento */
.ui-table--dense td{height:auto;padding-top:9px;padding-bottom:9px}
.ui-scroll-box{max-height:300px;overflow:auto;border:1px solid var(--mca-line);border-radius:var(--mca-r-ctl)}
@media(max-width:767px){.ui-scroll-box{max-height:none;overflow:visible;border:0}}
/* cabecera de sección (título + descripción + dato a la derecha) */
.ui-section-h{display:flex;align-items:flex-end;justify-content:space-between;gap:8px 16px;flex-wrap:wrap}
.ui-section-h h2{margin:0;font-size:16px;font-weight:700;color:var(--mca-text);letter-spacing:-.01em}
.ui-section-h p{margin:4px 0 0;font-size:13px;line-height:1.45;color:var(--mca-text-2);max-width:90ch}
/* subcabecera de una tarjeta, con botón que pliega/despliega (aria-expanded) */
.ui-subhead{display:flex;align-items:center;gap:8px 12px;flex-wrap:wrap;min-height:48px;padding:8px 18px;border-top:1px solid var(--mca-line)}
.ui-subhead--soft{background:var(--mca-surface-2)}
.ui-subhead .sp{flex:1}
.ui-subhead-title{font-size:12.5px;font-weight:600;color:var(--mca-text-3)}
.ui-subhead-title--strong{font-size:13.5px;color:var(--mca-text)}
.ui-fold{display:inline-flex;align-items:center;gap:6px 8px;flex-wrap:wrap;min-height:32px;padding:0 4px 0 0;border:0;background:none;font:inherit;color:var(--mca-text);text-align:left;cursor:pointer;border-radius:6px}
.ui-fold:focus-visible{outline:none;box-shadow:var(--mca-focus)}
.ui-fold > svg.ic{width:16px;height:16px;color:var(--mca-text-3);transform:rotate(-90deg);transition:transform .15s}
.ui-fold[aria-expanded=true] > svg.ic{transform:none}
.ui-list-item.is-off .ui-list-title,.ui-list-item.is-off .ui-mono{color:var(--mca-text-3)}
.ui-list--indent .ui-list-item{padding-left:42px}
.ui-list-item > .ui-mono{flex:0 0 auto;min-width:64px;padding-top:2px;color:var(--mca-text-2)}
.ui-list-item > .ui-switch.ui-switch{margin:-6px 0 -6px -6px}
.ui-list-aside--wrap{flex:0 1 auto;flex-wrap:wrap;justify-content:flex-end;min-width:0}
.ui-sep-top{border-top:1px solid var(--mca-line)}
.ui-switch.ui-switch--flush{align-self:flex-start;max-width:100%;margin-left:-6px;white-space:normal;text-align:left;line-height:1.35;color:var(--mca-text)}
@media(max-width:640px){.ui-list-item--wrap > .ui-list-aside--wrap{flex:1 1 100%;justify-content:flex-start}}
@media(max-width:640px){
  .ui-list--indent .ui-list-item{padding-left:18px}
  .ui-subhead .sp{display:none}
  /* fila con código: el código sube junto al estado y el nombre usa todo el ancho */
  .ui-list-item--coded{display:grid;grid-template-columns:auto minmax(0,1fr) auto;gap:2px 10px;align-items:center}
  .ui-list-item--coded > .ui-switch{grid-row:1 / span 2}
  .ui-list-item--coded > .ui-mono{grid-column:2;grid-row:1;min-width:0;padding-top:0}
  .ui-list-item--coded > .ui-list-aside{grid-column:3;grid-row:1}
  .ui-list-item--coded > .ui-list-main{grid-column:2 / span 2;grid-row:2}
}
/* insignia que admite texto largo (nombres de archivo) y botón-etiqueta de archivo */
.ui-badge--wrap{height:auto;min-height:22px;padding-top:3px;padding-bottom:3px;white-space:normal;overflow-wrap:anywhere;line-height:1.3}
label.ui-btn{position:relative}
label.ui-btn:focus-within{box-shadow:var(--mca-focus)}
.ui-icon-tile{width:40px;height:40px;border-radius:10px;display:grid;place-items:center;flex:0 0 auto;color:var(--tone);background:var(--tone-soft)}
.ui-icon-tile svg.ic{width:19px;height:19px}
/* piezas menores: rejilla de dos fichas, etiqueta de chips, campo + botón en línea, icono en texto */
.ui-grid--2{grid-template-columns:repeat(auto-fit,minmax(min(100%,340px),1fr))}
.ui-chips-label{align-self:center;margin-right:4px}
.ui-input-row{display:flex;align-items:center;gap:8px}
.ui-input-row > .ui-input{flex:1;min-width:0}
.ui-strike{text-decoration:line-through}
svg.ic.ui-inline-ic{display:inline-block;width:14px;height:14px;margin-right:2px;vertical-align:-2px}
@media(max-width:640px){.ui-input-row{flex-wrap:wrap}.ui-input-row > .ui-btn{flex:1 1 100%}}
@media (prefers-reduced-motion: reduce){.ui-fold > svg.ic{transition:none}}
/* ==== fin GRUPO C ==== */

/* ==== etapa 6 · GRUPO G · IA: Asesores Inteligentes y alertas ==== */
/* texto introductorio de una tarjeta (qué hace la sección) y código en línea en ayudas */
.ui-card-desc{margin:0 0 16px;font-size:13px;line-height:1.5;color:var(--mca-text-2);overflow-wrap:anywhere}
.ui-card-desc:last-child{margin-bottom:0}
.ui-card-desc code,.ui-help code,.ui-code-h code{padding:1px 5px;border-radius:4px;background:var(--mca-neutral-soft);font-family:ui-monospace,SFMono-Regular,Menlo,Consolas,monospace;font-size:12px;color:var(--mca-text);overflow-wrap:anywhere}
/* identidad con foto: columna de foto + campos */
.ui-identity{display:grid;grid-template-columns:168px minmax(0,1fr);gap:20px 28px;align-items:start}
.ui-photo{display:flex;flex-direction:column;align-items:center;gap:10px;min-width:0;text-align:center}
.ui-photo .ui-help{max-width:168px}
.ui-avatar--photo{--s:96px;font-size:30px}
.ui-avatar--photo .ic{width:36px;height:36px}
@media(max-width:640px){
  .ui-identity{grid-template-columns:minmax(0,1fr)}
  .ui-photo{flex-direction:row;flex-wrap:wrap;justify-content:flex-start;text-align:left}
  .ui-photo .ui-avatar--photo{--s:72px;font-size:24px}
  .ui-photo .ui-help{flex:1 1 100%;max-width:none}
}
/* barra de guardado fija al pie mientras se editan las secciones que guarda */
.ui-savebar{position:sticky;bottom:12px;z-index:6;display:flex;align-items:center;gap:8px 12px;flex-wrap:wrap;padding:10px 12px 10px 18px;
  background:var(--mca-surface);border:1px solid var(--mca-line-strong);border-radius:var(--mca-r-card);box-shadow:var(--mca-shadow-card)}
.ui-savebar-note{flex:1 1 240px;min-width:0;margin:0;font-size:12.5px;line-height:1.4;color:var(--mca-text-3)}
@media(max-width:640px){.ui-savebar{bottom:8px;padding:10px 12px}.ui-savebar > .ui-btn{flex:1 1 auto}}
.ui-field > .ui-seg{align-self:flex-start;max-width:100%}
/* rejilla de campos que pasa de 3 a 2 y a 1 columna según el ancho disponible */
.ui-form-grid--fluid{grid-template-columns:repeat(auto-fill,minmax(min(100%,300px),1fr))}
/* fila de acciones alineada al centro (subir archivos, enlaces de prueba) */
.ui-actions-row--center{align-items:center}
/* icono al inicio de una fila de lista (documentos) */
.ui-list-ic{width:32px;height:32px;flex:0 0 auto;display:grid;place-items:center;border-radius:8px;color:var(--mca-text-3);background:var(--mca-neutral-soft)}
.ui-list-ic .ic{width:16px;height:16px}
.ui-list + .ui-card-kicker{padding-top:14px;border-top:1px solid var(--mca-line)}
.ui-list--sep{border-top:1px solid var(--mca-line)}
.ui-list-foot{display:flex;flex-wrap:wrap;gap:8px;flex:1 1 100%;min-width:0}
/* interruptor (role=switch, aria-checked) */
.ui-switch{display:inline-flex;align-items:center;gap:8px;min-height:32px;padding:0 6px;border:0;border-radius:var(--mca-r-ctl);background:none;font:inherit;font-size:13px;font-weight:600;
  color:var(--mca-text-2);white-space:nowrap;cursor:pointer}
.ui-switch-track{position:relative;width:36px;height:20px;flex:0 0 auto;border-radius:999px;background:color-mix(in srgb,var(--mca-neutral) 62%,var(--mca-surface));transition:background-color .15s}
.ui-switch-track::after{content:"";position:absolute;top:2px;left:2px;width:16px;height:16px;border-radius:50%;background:var(--mca-surface);box-shadow:var(--mca-shadow-xs);transition:transform .15s}
.ui-switch[aria-checked=true]{color:var(--mca-text)}
.ui-switch[aria-checked=true] .ui-switch-track{background:var(--mca-blue)}
.ui-switch[aria-checked=true] .ui-switch-track::after{transform:translateX(16px)}
.ui-switch:focus-visible{outline:none;box-shadow:var(--mca-focus)}
.ui-switch:disabled{opacity:.5;cursor:not-allowed}
/* bloque de código copiable (p. ej. fragmento para incrustar) */
.ui-code{min-width:0;border:1px solid var(--mca-line);border-radius:var(--mca-r-ctl);background:var(--mca-surface-2)}
.ui-code + .ui-code{margin-top:14px}
.ui-code-h{display:flex;align-items:center;gap:8px 12px;flex-wrap:wrap;padding:8px 8px 8px 14px;border-bottom:1px solid var(--mca-line);font-size:12.5px;font-weight:600;color:var(--mca-text-2)}
.ui-code-h > span{flex:1 1 240px;min-width:0}
.ui-code pre{margin:0;padding:14px;overflow-x:auto;font-family:ui-monospace,SFMono-Regular,Menlo,Consolas,monospace;font-size:12.5px;line-height:1.55;color:var(--mca-text);white-space:pre}
/* campana de alertas de IA: el número en rojo (servicio caído) y el título con el tono del estado */
.ui-bell-n--danger{background:var(--mca-danger)}
.ui-bell-tone{color:var(--tone)}
.ui-bell-panel{width:min(320px,calc(100vw - 96px))}
@media (prefers-reduced-motion: reduce){.ui-switch-track,.ui-switch-track::after{transition:none}}
/* ==== fin GRUPO G ==== */

/* ==== etapa 6 · GRUPO D · Catálogo, Integraciones, MCP y videoconferencias ==== */
/* (usa además .ui-grid--2/.ui-seg--nav del GRUPO C, .ui-steps/.ui-checklist/.ui-disclosure/.ui-split
   del GRUPO B, .ui-card-intro del GRUPO A y .ui-code del GRUPO G) */
/* iconos dentro de ayudas y subtítulos de tarjeta (p. ej. candado del secreto enmascarado) */
.ui-help svg.ic{display:inline-block;width:13px;height:13px;margin-right:2px;vertical-align:-2px}
.ui-card-sub svg.ic{width:14px;height:14px}
.ui-form-grid--1{--cols:1}
.ui-grow{flex:1 1 auto}
.ui-select-all{user-select:all}
/* icono de marca con texto (siglas de un asistente) */
.ui-icon-tile--text{font-size:13px;font-weight:700;letter-spacing:-.01em}
/* caja suave dentro de una tarjeta (datos complementarios, resumen de un paso) */
.ui-box{display:flex;flex-direction:column;gap:12px;min-width:0;padding:12px 14px;border:1px solid var(--mca-line);border-radius:var(--mca-r-ctl);background:var(--mca-surface-2)}
dl.ui-box{display:grid;gap:6px 16px}
/* campos cortos en línea (parámetros numéricos) con una casilla alineada a su altura */
.ui-inline-fields{display:flex;flex-wrap:wrap;align-items:flex-end;gap:12px 16px}
.ui-inline-fields > .ui-field{flex:0 0 132px}
.ui-inline-fields > .ui-field .ui-input{height:var(--mca-h-md);font-size:13.5px}
.ui-inline-fields > .ui-check{min-height:var(--mca-h-md)}
@media(max-width:640px){
  .ui-inline-fields{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:10px}
  .ui-inline-fields > .ui-check{grid-column:1/-1;min-height:34px}
}
/* filas «clave → controles» (p. ej. un proceso de IA con su proveedor y modelo) */
.ui-keyrow{display:grid;grid-template-columns:190px minmax(0,1fr);gap:10px 20px;align-items:start;padding:16px 18px;border-top:1px solid var(--mca-line)}
.ui-keyrows > .ui-keyrow:first-child{border-top:0}
.ui-keyrow-k{display:flex;flex-direction:column;gap:2px;min-width:0;padding-top:2px;font-size:14px;overflow-wrap:anywhere}
.ui-keyrow-b{display:flex;flex-direction:column;gap:12px;min-width:0}
@media(max-width:767px){.ui-keyrow{grid-template-columns:minmax(0,1fr);padding:14px 16px}}
/* tabla de una tarjeta normal: en móvil, filas planas (sin tarjeta dentro de tarjeta) y una celda por fila */
.ui-input--cell{width:100%}
.ui-cell-stack{display:flex;flex-direction:column;align-items:flex-start;gap:6px;min-width:0}
@media(max-width:767px){
  table.ui-table.ui-table--stack-cells td[data-label]{flex-basis:100%}
  table.ui-table.ui-table--stack-cells td[data-label] > *{align-self:stretch}
  table.ui-table.ui-table--flat tbody{gap:0}
  table.ui-table.ui-table--flat tr{padding:14px 16px;border:0;border-top:1px solid var(--mca-line);border-radius:0;box-shadow:none}
  table.ui-table.ui-table--flat tbody tr:first-child{border-top:0}
  table.ui-table.ui-table--flat tr:has(> td.rt-empty){padding:0}
}
/* modales pequeñas de confirmación */
.ui-modal--xs{--w:460px}
.ui-modal-text{margin:0;font-size:14px;line-height:1.55;color:var(--mca-text-2);overflow-wrap:anywhere}
.ui-steps--3{grid-template-columns:repeat(3,minmax(0,1fr))}
/* plegable en línea (sin tarjeta), p. ej. configuración manual */
.ui-disclosure-sum{display:inline-flex;align-items:center;gap:6px;min-height:32px;padding:0 4px;font-size:13px;font-weight:600;color:var(--mca-text-2)}
.ui-disclosure-sum:hover{color:var(--mca-text)}
details.ui-disclosure:not(.ui-card)[open] > summary{border-bottom:0}
.ui-disclosure-b{padding-top:8px}
/* ==== fin GRUPO D ==== */

/* ==== etapa 6 · GRUPO E · CRM: Pipeline y conversaciones ==== */
/* barra de herramientas sola en su tarjeta: sin línea inferior duplicada */
.ui-toolbar--flush{border-bottom:0}
@media(min-width:768px){.ui-board-bar .ui-select{max-width:220px}}
@media(max-width:767px){
  .ui-board-bar .ui-seg{flex:1 1 100%}
  .ui-board-bar .ui-seg button{flex:1;height:34px}
}
/* tablero por etapas (kanban): columnas con cabecera (insignia de etapa + recuento) y tarjetas.
   Contenedor ancho (≥1088 px): todas las columnas a la vista; más estrecho: desplazamiento
   horizontal con scroll-snap; <600 px: pestañas de etapa (.ui-board-tabs) y una sola columna. */
.ui-board{container:board / inline-size;min-width:0}
.ui-board.is-busy .ui-board-cols{opacity:.7;pointer-events:none}
.ui-board-tabs{display:none}
.ui-board-cols{display:grid;grid-auto-flow:column;grid-auto-columns:minmax(0,1fr);gap:12px;align-items:start}
.ui-board-cols > section{display:flex;flex-direction:column;gap:8px;min-width:0;padding:8px;background:var(--mca-surface-2);border:1px solid var(--mca-line);
  border-radius:var(--mca-r-card);transition:border-color .14s,box-shadow .14s}
.ui-board-cols > section.is-over{border-color:var(--mca-blue);box-shadow:var(--mca-focus)}
.ui-board-col-h{display:flex;align-items:center;gap:8px;min-height:32px;padding:0 4px}
.ui-board-col-h > .ui-badge--count{margin-left:auto}
.ui-board-list{display:flex;flex-direction:column;gap:8px;min-height:56px}
.ui-board-list > article{display:flex;flex-direction:column;gap:6px;min-width:0;padding:12px;background:var(--mca-surface);border:1px solid var(--mca-line);
  border-radius:var(--mca-r-ctl);box-shadow:var(--mca-shadow-xs);font-size:12.5px;color:var(--mca-text-2)}
.ui-board-list > article[draggable=true]{cursor:grab}
.ui-board-list > article.is-dragging{opacity:.45}
.ui-board-list > article p{margin:0}
.ui-board-list .ui-badge{white-space:normal;height:auto;min-height:22px;padding-top:3px;padding-bottom:3px;line-height:1.25}
.ui-board-card-h{display:flex;align-items:flex-start;justify-content:space-between;gap:8px}
.ui-board-card-h .ui-person-name{min-width:0;overflow-wrap:anywhere}
.ui-board-card-h .ui-badge{flex:0 0 auto;white-space:nowrap}
.ui-board-line{line-height:1.4;overflow-wrap:anywhere}
.ui-board-line b{color:var(--mca-text);font-weight:600}
.ui-board-next{display:flex;align-items:center;flex-wrap:wrap;gap:6px 8px;padding:7px 9px;border-radius:var(--mca-r-ctl);background:var(--mca-blue-soft);color:var(--mca-text)}
.ui-board-next > .ic{width:14px;height:14px;flex:0 0 auto;color:var(--mca-blue)}
.ui-board-next > a:not(.ag-join){flex:1 1 auto;min-width:0;font-weight:600;color:inherit;text-decoration:none;overflow-wrap:anywhere}
.ui-board-next > a:not(.ag-join):hover{color:var(--mca-blue);text-decoration:underline;text-underline-offset:3px}
.ui-board-next > a:not(.ag-join):focus-visible{outline:2px solid var(--mca-blue);outline-offset:2px;border-radius:3px}
/* acceso a la reunión (mismo aspecto que en el Calendario; clase ag-join del parcial compartido) */
.ui-board .ag-join{display:inline-flex;align-items:center;gap:6px;height:28px;padding:0 10px;border-radius:var(--mca-r-ctl);background:var(--mca-blue);
  color:#fff;font-size:12.5px;font-weight:600;text-decoration:none;white-space:nowrap}
.ui-board .ag-join:hover{background:var(--mca-blue-hover)}
.ui-board .ag-join:focus-visible{outline:none;box-shadow:var(--mca-focus)}
.ui-board .ag-join .ic{width:14px;height:14px}
.ui-board-acts{display:flex;flex-direction:column;align-items:stretch;gap:8px;margin-top:2px;padding-top:10px;border-top:1px solid var(--mca-line)}
.ui-board-acts > .ui-badge{align-self:flex-start}
.ui-board-links{display:flex;flex-wrap:wrap;gap:4px 14px}
.ui-board-links .ui-link{white-space:normal}
.ui-board-acts > .ui-select{width:100%;height:var(--mca-h-sm);font-size:13px}
.ui-board-empty{margin:0;padding:16px 6px;text-align:center;font-size:12.5px;color:var(--mca-text-3)}
@container board (max-width:1087px){
  .ui-board-cols{grid-auto-columns:minmax(264px,1fr);overflow-x:auto;scroll-snap-type:x mandatory;padding-bottom:10px;overscroll-behavior-x:contain}
  .ui-board-cols > section{scroll-snap-align:start}
}
@container board (max-width:599px){
  .ui-board-tabs{display:flex;margin-bottom:12px}
  .ui-board-tabs .ui-chip{display:inline-flex;align-items:center;gap:6px;height:36px;padding:0 13px;color:var(--mca-text-2);border-color:var(--mca-line-strong)}
  .ui-board-tabs .ui-chip[aria-selected=true]{color:#fff;background:var(--mca-blue);border-color:var(--mca-blue)}
  .ui-board-tab-n{font-size:12px;opacity:.8}
  .ui-board-cols{display:block;overflow:visible;padding-bottom:0}
  .ui-board-cols > section{padding:0;background:none;border:0}
  .ui-board-cols > section.is-over{box-shadow:none}
  .ui-board-cols > section.is-hidden-sm{display:none}
  .ui-board-acts{flex-direction:row;flex-wrap:wrap;align-items:center}
  .ui-board-links{flex:1 1 auto}
  .ui-board-acts > .ui-select{flex:1 1 160px;width:auto;height:36px}
  .ui-board .ag-join{height:32px}
}
/* aviso emergente de lead nuevo (campana de la barra superior) */
.ui-sound .ic{width:19px;height:19px}
.mca-topbar .ui-sound.is-on{color:var(--mca-blue);background:var(--mca-blue-soft)}
.ui-toasts{position:fixed;top:16px;right:16px;z-index:9999;display:flex;flex-direction:column;gap:10px;width:min(360px,calc(100vw - 32px));pointer-events:none}
.ui-toast{pointer-events:auto;display:flex;align-items:flex-start;gap:12px;padding:12px 12px 12px 14px;background:var(--mca-surface);border:1px solid var(--mca-line);
  border-radius:var(--mca-r-card);box-shadow:var(--mca-shadow-pop);animation:uiToastIn .28s cubic-bezier(.2,.7,.3,1)}
.ui-toast-ic{width:32px;height:32px;flex:0 0 auto;display:grid;place-items:center;border-radius:8px;color:var(--tone,var(--mca-blue));background:var(--tone-soft,var(--mca-blue-soft))}
.ui-toast-ic .ic{width:16px;height:16px}
.ui-toast-b{flex:1;min-width:0;display:flex;flex-direction:column;gap:2px}
.ui-toast-kicker{font-size:12px;font-weight:600;color:var(--mca-text-3)}
.ui-toast-title{font-size:14px;font-weight:600;line-height:1.3;color:var(--mca-text);overflow-wrap:anywhere}
.ui-toast-b .ui-link{align-self:flex-start;margin-top:4px}
.ui-toast .ui-btn--icon{--h:30px;margin:-4px -4px 0 0}
@keyframes uiToastIn{from{opacity:0;transform:translateY(-8px)}to{opacity:1;transform:none}}
@media (prefers-reduced-motion: reduce){.ui-toast{animation:none}.ui-board-cols > section{transition:none}}
/* ==== fin GRUPO E ==== */

/* ==== etapa 6 · GRUPO F · Acceso y autenticación ==== */
/* ==== fin GRUPO F ==== */

@media (prefers-reduced-motion: reduce){
  .ui-drop,.ui-net{transition:none}
  .ui-cal-body{transition:none}
  .ui-badge--pulse .dot,.ui-spin{animation:none}
  .ui-btn,.ui-kpi,.ui-kpi-go,.ui-input,.ui-select,.ui-table tbody tr{transition:none}
}
/* táctil: segmentados y chips con al menos 34 px de alto en móvil */
@media(max-width:767px){
  .ui-seg button,.ui-seg a{height:34px}
  .ui-chip{height:34px}
}

</style>
