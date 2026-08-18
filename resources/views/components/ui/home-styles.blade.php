{{--
  Estilos de la pantalla Inicio / dashboard (rediseño shell_dashboard_v4). Patrón
  x-ui: <style> autocontenido, scoped bajo `.mca-home`, consume var(--mca-*). Layout
  a lo ancho, alineado a la izquierda. Las gráficas viven en x-ui.chart-bars/funnel.
--}}
<style>
/* Inicio: única pantalla con carril derecho reservado (para métricas futuras). */
.mca-home{font-family:var(--mca-font);color:var(--mca-ink);padding:22px 26px 34px;display:grid;grid-template-columns:minmax(0,1fr) 300px;gap:18px;align-items:start}
.mca-home *{box-sizing:border-box}
.mca-home .ic{flex:0 0 auto}
.mca-home .home-main{display:flex;flex-direction:column;gap:16px;min-width:0}
.mca-home .home-rail{position:sticky;top:76px}
.mca-home .rail-card{background:#fff;border:1px dashed var(--mca-card-border);border-radius:var(--mca-radius);padding:24px 18px;text-align:center;box-shadow:var(--mca-shadow-sm)}
.mca-home .rail-ic{width:40px;height:40px;border-radius:11px;background:var(--mca-page-bg);color:var(--mca-ink-3);display:grid;place-items:center;margin:0 auto 12px}
.mca-home .rail-ic .ic{width:20px;height:20px}
.mca-home .rail-t{font-size:13.5px;font-weight:600;color:var(--mca-ink-2)}
.mca-home .rail-s{font-size:12px;color:var(--mca-ink-3);margin-top:5px;line-height:1.5}
@media(max-width:1180px){.mca-home{grid-template-columns:1fr}}

/* saludo compacto (una línea) */
.mca-home .hello{display:flex;align-items:center;gap:14px;flex-wrap:wrap}
.mca-home .hello h2{font-size:20px;font-weight:700;letter-spacing:-.015em;margin:0}
.mca-home .hello .role{font-size:12px;font-weight:600;color:var(--mca-blue);background:var(--mca-blue-soft);padding:4px 10px;border-radius:20px}
.mca-home .hello .role.ro{color:var(--mca-ink-2);background:#EEF1F6}
.mca-home .hello .sp{flex:1}
.mca-home .btn{padding:8px 14px;border-radius:9px;font-size:13px;font-weight:500;line-height:1;display:inline-flex;align-items:center;justify-content:center;gap:6px;cursor:pointer;border:1px solid transparent;text-decoration:none;font-family:inherit;transition:background .14s,border-color .14s,box-shadow .14s,transform .08s;white-space:nowrap}
.mca-home .btn:active{transform:translateY(1px)}
.mca-home .btn .ic{width:15px;height:15px}
.mca-home .btn.primary{background:var(--mca-blue);color:#fff;box-shadow:0 1px 2px rgba(19,37,61,.12),0 2px 8px rgba(30,90,168,.20)}
.mca-home .btn.primary:hover{background:var(--mca-blue-hover);box-shadow:0 1px 2px rgba(19,37,61,.14),0 4px 12px rgba(30,90,168,.26)}
.mca-home .btn.ghost{background:#fff;border-color:var(--mca-card-border);color:var(--mca-ink-2);box-shadow:var(--mca-shadow-sm)}
.mca-home .btn.ghost:hover{border-color:#cbd6e6;color:var(--mca-ink);background:#fbfdff}

/* grid */
.mca-home .grid{display:grid;gap:16px}
.mca-home .kpis{grid-template-columns:repeat(5,1fr)}
.mca-home .cols{grid-template-columns:7fr 5fr}
.mca-home .card{background:var(--mca-card);border:1px solid var(--mca-card-border);border-radius:var(--mca-radius);box-shadow:var(--mca-shadow)}

/* KPI */
.mca-home .kpi{padding:16px 16px 15px;display:flex;flex-direction:column;gap:12px}
.mca-home .kpi .top{display:flex;align-items:center;justify-content:space-between}
.mca-home .kpi .kic{width:38px;height:38px;border-radius:10px;display:grid;place-items:center}
.mca-home .kpi .kic .ic{width:19px;height:19px}
.mca-home .kpi .trend{font-size:11.5px;font-weight:600;color:var(--mca-ok);display:flex;align-items:center;gap:3px}
.mca-home .kpi .trend .ic{width:12px;height:12px}
.mca-home .kpi .num{font-size:29px;font-weight:700;line-height:1;letter-spacing:-.02em}
.mca-home .kpi .lab{font-size:12.5px;color:var(--mca-ink-2);font-weight:500;margin-top:4px}
.mca-home .kpi.gold{background:linear-gradient(160deg,#fff,var(--mca-gold-soft));border-color:#EED9A6}
.mca-home .b-info{background:var(--mca-info-soft);color:var(--mca-info)}
.mca-home .b-warn{background:var(--mca-warn-soft);color:var(--mca-warn)}
.mca-home .b-ok{background:var(--mca-ok-soft);color:var(--mca-ok)}
.mca-home .b-blue{background:var(--mca-blue-soft);color:var(--mca-blue)}
.mca-home .b-gold{background:#F5E7BC;color:#9A7B1F}

/* card header */
.mca-home .card-h{display:flex;align-items:center;gap:9px;padding:15px 17px;border-bottom:1px solid #EEF1F6}
.mca-home .card-h .hi{width:28px;height:28px;border-radius:8px;background:var(--mca-blue-soft);color:var(--mca-blue);display:grid;place-items:center}
.mca-home .card-h .hi .ic{width:16px;height:16px}
.mca-home .card-h h3{font-size:14.5px;font-weight:600;flex:1;margin:0}
.mca-home .card-h .pill{font-size:12px;font-weight:600;color:var(--mca-ink-2);background:var(--mca-page-bg);padding:3px 10px;border-radius:20px}
.mca-home .card-b{padding:8px}
.mca-home .empty{padding:26px 18px;text-align:center;color:var(--mca-ink-3);font-size:13px}

/* lista de leads */
.mca-home .lead{display:flex;align-items:center;gap:12px;padding:11px 12px;border-radius:11px;text-decoration:none;color:inherit}
.mca-home .lead:hover{background:#F7F9FC}
.mca-home .lead .av{width:38px;height:38px;border-radius:10px;background:var(--mca-blue-soft);color:var(--mca-blue);display:grid;place-items:center;font-weight:600;font-size:14px;flex-shrink:0}
.mca-home .lead .tx{flex:1;min-width:0}
.mca-home .lead .n{font-size:14px;font-weight:600}
.mca-home .lead .m{font-size:12px;color:var(--mca-ink-3);margin-top:1px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.mca-home .lead .rt{margin-left:auto;display:flex;align-items:center;gap:7px;flex-shrink:0}
.mca-home .tag{font-size:11px;font-weight:600;padding:3px 10px;border-radius:20px;background:var(--mca-blue-soft);color:var(--mca-blue)}
.mca-home .tag.st-nuevo{background:var(--mca-blue-soft);color:var(--mca-blue)}
.mca-home .tag.st-cont{background:var(--mca-info-soft);color:var(--mca-info)}
.mca-home .tag.st-seg{background:var(--mca-gold-soft);color:#9A7B1F}
.mca-home .tag.st-matr{background:var(--mca-ok-soft);color:var(--mca-ok)}
.mca-home .tag.st-desc{background:#EEF1F6;color:var(--mca-ink-2)}
.mca-home .tag.emp{background:var(--mca-gold-soft);color:#9A7B1F;display:inline-flex;align-items:center;gap:4px}
.mca-home .tag.emp .ic{width:11px;height:11px}

/* actividad */
.mca-home .act{display:flex;gap:11px;padding:10px 12px}
.mca-home .act .d{width:30px;height:30px;border-radius:8px;display:grid;place-items:center;flex-shrink:0;margin-top:1px}
.mca-home .act .d .ic{width:15px;height:15px}
.mca-home .act .tx{flex:1;min-width:0}
.mca-home .act .t{font-size:13px;font-weight:500;line-height:1.35}
.mca-home .act .t b{font-weight:600}
.mca-home .act .ago{font-size:11px;color:var(--mca-ink-3);margin-top:2px}

/* gráficas (chart-bars / funnel) */
.mca-home .bars{padding:16px 18px 18px;display:flex;flex-direction:column;gap:14px}
.mca-home .bar-row{display:flex;align-items:center;gap:12px}
.mca-home .bar-row .bl{font-size:12.5px;color:var(--mca-ink-2);width:150px;flex-shrink:0}
.mca-home .bar-track{flex:1;height:9px;border-radius:6px;background:#EEF1F6;overflow:hidden}
.mca-home .bar-fill{height:100%;border-radius:6px;background:linear-gradient(90deg,var(--mca-info),var(--mca-blue))}
.mca-home .bar-row .bv{font-size:12.5px;font-weight:600;width:26px;text-align:right}

.mca-home .funnel{padding:14px 18px 18px;display:flex;flex-direction:column;gap:10px}
.mca-home .fn{display:flex;align-items:center;gap:12px}
.mca-home .fn .fl{font-size:12.5px;color:var(--mca-ink-2);width:118px;flex-shrink:0}
.mca-home .fn .ft{flex:1;height:26px;border-radius:7px;background:var(--mca-page-bg);position:relative;overflow:hidden}
/* Relleno con tinte suave del token + número/etiqueta en el acento sólido (mismo
   lenguaje sobrio que KPIs y tags; nada de fills 100% saturados). */
.mca-home .fn .ff{height:100%;border-radius:7px;display:flex;align-items:center;padding-left:11px;font-size:12px;font-weight:700;min-width:26px}
.mca-home .fn.st-new .ff{background:var(--mca-blue-soft);color:var(--mca-blue)}
.mca-home .fn.st-con .ff{background:var(--mca-info-soft);color:var(--mca-info)}
.mca-home .fn.st-seg .ff{background:var(--mca-gold-soft);color:#9A7B1F}
.mca-home .fn.st-mat .ff{background:var(--mca-ok-soft);color:var(--mca-ok)}
.mca-home .fn.st-des .ff{background:#EEF1F6;color:var(--mca-ink-2)}

@media(max-width:1100px){
  .mca-home .kpis{grid-template-columns:repeat(2,1fr)}
  .mca-home .cols{grid-template-columns:1fr}
}
@media(max-width:760px){
  .mca-home .kpis{grid-template-columns:1fr}
}
@media (prefers-reduced-motion: reduce){ .mca-home *{transition:none !important} }
</style>
