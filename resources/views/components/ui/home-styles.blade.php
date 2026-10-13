{{--
  Composición de la pantalla Inicio (dashboard). Los componentes (cabecera, botones,
  indicadores, tarjetas, filas, insignias, avatares) vienen de x-ui.kit; aquí solo vive
  la rejilla de la página y las piezas propias del inicio: agenda del día, actividad
  reciente y las gráficas nativas x-ui.chart-bars / x-ui.funnel. Solo consume var(--mca-*).
--}}
<style>
.mca-home{font-family:var(--mca-font);color:var(--mca-text);display:flex;flex-direction:column;gap:16px}
.mca-home .mca-pagehead{margin-bottom:4px}

/* rejillas */
.mca-home .home-kpis{display:grid;gap:12px;grid-template-columns:repeat(5,minmax(0,1fr))}
.mca-home .home-kpis:has(> [data-kpi]){grid-template-columns:repeat(4,minmax(0,1fr))}
.mca-home .home-cols{display:grid;gap:16px;grid-template-columns:minmax(0,7fr) minmax(0,5fr);align-items:start}

/* estado vacío dentro de una tarjeta */
.mca-home .home-empty{display:flex;align-items:center;justify-content:center;gap:8px;padding:28px 16px;font-size:13.5px;color:var(--mca-text-3)}
.mca-home .home-empty .ic{width:16px;height:16px}

/* agenda del día */
.mca-home .ag-row{display:flex;align-items:center;gap:14px;padding:10px 12px;border-radius:var(--mca-r-ctl)}
.mca-home .ag-row:hover{background:var(--mca-surface-hover)}
.mca-home .ag-time{flex:0 0 auto;min-width:54px;height:28px;display:grid;place-items:center;border-radius:6px;background:var(--mca-blue-soft);color:var(--mca-blue);font-size:13px;font-weight:700;font-variant-numeric:tabular-nums}
.mca-home .ag-title{display:block;text-decoration:none}
.mca-home .ag-title:hover{color:var(--mca-blue)}
.mca-home .ag-acts{display:flex;gap:6px;flex-wrap:wrap;justify-content:flex-end}

/* actividad reciente */
.mca-home .home-act{display:flex;gap:12px;padding:10px 12px}
.mca-home .home-act-ic{width:28px;height:28px;border-radius:8px;display:grid;place-items:center;flex:0 0 auto;color:var(--tone);background:var(--tone-soft)}
.mca-home .home-act-ic .ic{width:15px;height:15px}
.mca-home .home-act-t{font-size:13.5px;line-height:1.4;color:var(--mca-text-2)}
.mca-home .home-act-t .ui-person-name{font-size:13.5px}
.mca-home .home-act-d{color:var(--mca-text-3)}

/* gráficas nativas (x-ui.chart-bars / x-ui.funnel) */
.mca-home .bars{padding:18px 20px 20px;display:flex;flex-direction:column;gap:14px}
.mca-home .bar-row{display:flex;align-items:center;gap:12px}
.mca-home .bar-row .bl{font-size:13px;color:var(--mca-text-2);width:150px;flex-shrink:0;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
.mca-home .bar-track{flex:1;height:8px;border-radius:4px;background:var(--mca-neutral-soft);overflow:hidden}
.mca-home .bar-fill{height:100%;border-radius:4px;background:var(--mca-blue)}
.mca-home .bar-row .bv{font-size:13px;font-weight:600;width:32px;text-align:right;font-variant-numeric:tabular-nums}

.mca-home .funnel{padding:16px 20px 20px;display:flex;flex-direction:column;gap:8px}
.mca-home .fn{display:flex;align-items:center;gap:12px}
.mca-home .fn .fl{font-size:13px;color:var(--mca-text-2);width:118px;flex-shrink:0}
.mca-home .fn .ft{flex:1;height:28px;border-radius:6px;background:var(--mca-surface-2);box-shadow:inset 0 0 0 1px var(--mca-line);position:relative;overflow:hidden}
.mca-home .fn .ff{height:100%;border-radius:6px;display:flex;align-items:center;padding-left:10px;font-size:12.5px;font-weight:700;min-width:28px;font-variant-numeric:tabular-nums}
.mca-home .fn.st-new .ff{background:var(--mca-blue-soft);color:var(--mca-blue)}
.mca-home .fn.st-con .ff{background:var(--mca-teal-soft);color:var(--mca-teal)}
.mca-home .fn.st-seg .ff{background:var(--mca-warning-soft);color:var(--mca-warning)}
.mca-home .fn.st-mat .ff{background:var(--mca-success-soft);color:var(--mca-success)}
.mca-home .fn.st-des .ff{background:var(--mca-neutral-soft);color:var(--mca-neutral)}
.mca-home .empty{padding:28px 16px;text-align:center;color:var(--mca-text-3);font-size:13.5px}

@media(max-width:1180px){
  .mca-home .home-kpis,.mca-home .home-kpis:has(> [data-kpi]){grid-template-columns:repeat(2,minmax(0,1fr))}
  .mca-home .home-cols{grid-template-columns:minmax(0,1fr)}
}
@media(min-width:768px) and (max-width:1180px){
  .mca-home .home-kpis,.mca-home .home-kpis:has(> [data-kpi]){grid-template-columns:repeat(4,minmax(0,1fr))}
}
@media(max-width:640px){
  .mca-home{gap:12px}
  .mca-home .home-kpis{gap:10px}
  /* indicador compacto, dos por fila: cifra con su icono a la derecha y la etiqueta en
     una línea debajo (toda la tarjeta es el enlace, así que la flecha sobra) */
  .mca-home .ui-kpi{display:grid;grid-template-columns:minmax(0,1fr) auto;grid-template-areas:"value icon" "label label";align-items:center;gap:6px 8px;padding:12px 14px}
  .mca-home .ui-kpi-top{display:contents}
  .mca-home .ui-kpi-ic{grid-area:icon;width:26px;height:26px;border-radius:7px}
  .mca-home .ui-kpi-ic .ic{width:15px;height:15px}
  .mca-home .ui-kpi-label{grid-area:label;font-size:12.5px}
  .mca-home .ui-kpi-go{display:none}
  .mca-home .ui-kpi-value{grid-area:value;font-size:24px}
  .mca-home .ui-card-h{padding:10px 14px}
  .mca-home .ui-card-h .ui-btn--sm{padding:0 10px}
  .mca-home .ag-row{flex-wrap:wrap;gap:10px}
  .mca-home .ag-acts{width:100%;justify-content:flex-start;padding-left:68px}
  .mca-home .bar-row .bl{width:110px}
  .mca-home .fn .fl{width:96px}
}
</style>
