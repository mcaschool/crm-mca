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
.ui-card-h{display:flex;align-items:center;gap:10px;min-height:52px;padding:10px 18px;border-bottom:1px solid var(--mca-line)}
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
  .ui-badge--pulse .dot{animation:none}
  .ui-btn,.ui-kpi,.ui-kpi-go,.ui-input,.ui-select,.ui-table tbody tr{transition:none}
}
</style>
