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
.ui-card-h .ui-card-sub{font-size:12.5px;color:var(--mca-text-3);font-weight:500}
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
  background:var(--mca-blue-soft);box-shadow:inset 3px 0 0 var(--mca-blue)}
.ui-next-activity:hover{background:#E1ECF8}
.ui-next-activity:focus-visible{outline:none;box-shadow:inset 3px 0 0 var(--mca-blue),var(--mca-focus)}
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

@media (prefers-reduced-motion: reduce){
  .ui-badge--pulse .dot,.ui-spin{animation:none}
  .ui-btn,.ui-kpi,.ui-kpi-go,.ui-input,.ui-select,.ui-table tbody tr{transition:none}
}
</style>
