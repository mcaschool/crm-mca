{{-- Estilos de la Agenda (scoped bajo .crm-page, sobre x-ui.crm-styles). Mobile-first. --}}
<style>
.crm-page .ag-wrap{display:flex;flex-direction:column;gap:16px}
.crm-page .ag-bar{display:flex;align-items:center;gap:10px;padding:14px 18px;border-bottom:1px solid var(--line);flex-wrap:wrap}
.crm-page .ag-bar h2{font-size:16px;margin:0;font-weight:700;display:flex;align-items:center;gap:8px;text-transform:capitalize}
.crm-page .ag-bar .sp{flex:1}
.crm-page .ag-seg{display:inline-flex;border:1px solid var(--line);border-radius:10px;overflow:hidden}
.crm-page .ag-seg button{border:none;background:#fff;padding:8px 12px;font-size:13px;font-family:inherit;color:var(--ink);cursor:pointer;font-weight:500}
.crm-page .ag-seg button+button{border-left:1px solid var(--line)}
.crm-page .ag-seg button.on{background:var(--blue);color:#fff}
.crm-page .ag-nav{display:inline-flex;gap:6px;align-items:center}
.crm-page .ag-nav .ghost{padding:7px 9px}
.crm-page .ag-filters{display:flex;gap:8px;flex-wrap:wrap;padding:12px 18px;border-bottom:1px solid var(--line);background:#FBFCFE}
.crm-page .ag-note{display:flex;align-items:center;gap:8px;padding:10px 18px;font-size:13px;color:var(--muted);border-bottom:1px solid var(--line)}
.crm-page .ag-note a{color:var(--blue);font-weight:600;text-decoration:none}

/* semana */
.crm-page .ag-week{display:grid;grid-template-columns:repeat(7,minmax(0,1fr));min-height:360px}
.crm-page .ag-day{border-right:1px solid var(--line);min-width:0;display:flex;flex-direction:column}
.crm-page .ag-day:last-child{border-right:none}
.crm-page .ag-dh{padding:10px 10px 8px;border-bottom:1px solid var(--line);font-size:12px;color:var(--muted);display:flex;align-items:baseline;gap:6px;cursor:pointer;background:#FBFCFE}
.crm-page .ag-dh b{font-size:17px;color:var(--ink)}
.crm-page .ag-dh.today b{color:#fff;background:var(--blue);border-radius:999px;padding:0 7px}
.crm-page .ag-dbody{padding:8px;display:flex;flex-direction:column;gap:6px;flex:1}
.crm-page .ag-free{font-size:11.5px;color:#B5C2D3;padding:4px 2px}

/* item */
.crm-page .ag-item{display:block;text-decoration:none;color:var(--ink);background:#F3F8FE;border:1px solid #D6E5F7;border-left:3px solid var(--blue);border-radius:9px;padding:7px 9px;font-size:12px;line-height:1.35;min-width:0}
.crm-page .ag-item:hover{background:#EAF2FC}
.crm-page .ag-item .h{font-weight:700;font-size:11.5px;color:var(--blue);display:flex;align-items:center;gap:5px}
.crm-page .ag-item .t{font-weight:600;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
.crm-page .ag-item .s{color:var(--muted);font-size:11.5px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
.crm-page .ag-item.st-completed{background:#EEF8F2;border-color:#CBEBD8;border-left-color:#1E8E5A}
.crm-page .ag-item.st-completed .h{color:#1E8E5A}
.crm-page .ag-item.st-cancelled{background:#F4F6F9;border-color:#E1E6ED;border-left-color:#9AA9BC;opacity:.8}
.crm-page .ag-item.st-cancelled .t{text-decoration:line-through}
.crm-page .ag-item.st-cancelled .h{color:#6B7C93}
.crm-page .ag-item.st-no_show{background:#FDF6E7;border-color:#F2DFB0;border-left-color:#B7791F}
.crm-page .ag-item.st-no_show .h{color:#B7791F}
/* Toda la tarjeta/fila abre el detalle (enlace «estirado» del título); el botón de acceso queda por encima. */
.crm-page .ag-item,.crm-page .ag-row{position:relative}
.crm-page .ag-item .t.ag-open,.crm-page .ag-row .t.ag-open{display:block;color:inherit;text-decoration:none}
.crm-page .ag-open::after{content:"";position:absolute;inset:0;border-radius:inherit}
.crm-page .ag-open:focus-visible{outline:none}
.crm-page .ag-open:focus-visible::after{outline:2px solid var(--blue-3);outline-offset:1px}
.crm-page .ag-join{position:relative;z-index:1;display:inline-flex;align-items:center;gap:4px;margin-top:5px;padding:3px 8px;border-radius:999px;background:var(--blue);color:#fff;font-size:11px;font-weight:600;text-decoration:none;white-space:nowrap;line-height:1.3}
.crm-page .ag-join:hover{background:var(--blue-deep)}
.crm-page .ag-join .ic{color:#fff}
.crm-page .ag-join.only-ic{padding:3px 5px;margin-top:3px}
.crm-page .ag-row .st{display:flex;align-items:center;gap:8px;flex-wrap:wrap;justify-content:flex-end}
.crm-page .ag-row .st .ag-join{margin-top:0}
.crm-page .ag-badge{display:inline-flex;align-items:center;gap:4px;font-size:11px;font-weight:600;border-radius:999px;padding:2px 8px;background:#E8F1FD;color:#1E5AA8;white-space:nowrap}
.crm-page .ag-badge.completed{background:#E3F5EA;color:#1E8E5A}
.crm-page .ag-badge.cancelled{background:#EEF1F5;color:#6B7C93}
.crm-page .ag-badge.no_show{background:#FBF1DC;color:#B7791F}

/* día y lista */
.crm-page .ag-list{display:flex;flex-direction:column}
.crm-page .ag-row{display:grid;grid-template-columns:110px 1fr auto;gap:12px;align-items:center;padding:12px 18px;border-bottom:1px solid var(--line);text-decoration:none;color:var(--ink)}
.crm-page .ag-row:hover{background:#F7FAFE}
.crm-page .ag-row .when{font-weight:700;font-size:13px;color:var(--blue)}
.crm-page .ag-row .when small{display:block;color:var(--muted);font-weight:500;font-size:11.5px}
.crm-page .ag-row .what{min-width:0}
.crm-page .ag-row .what .t{font-weight:600;font-size:14px}
.crm-page .ag-row .what .s{color:var(--muted);font-size:12.5px;display:flex;gap:10px;flex-wrap:wrap;margin-top:2px}
.crm-page .ag-row .what .s span{display:inline-flex;align-items:center;gap:4px}
.crm-page .ag-group{padding:10px 18px 6px;font-size:11px;letter-spacing:.1em;text-transform:uppercase;color:var(--muted);font-weight:700;background:#FBFCFE;border-bottom:1px solid var(--line)}

/* mes */
.crm-page .ag-month{display:grid;grid-template-columns:repeat(7,minmax(0,1fr))}
.crm-page .ag-mh{padding:8px 10px;font-size:11px;text-transform:uppercase;letter-spacing:.08em;color:var(--muted);font-weight:700;border-bottom:1px solid var(--line);background:#FBFCFE}
.crm-page .ag-cell{min-height:96px;border-right:1px solid var(--line);border-bottom:1px solid var(--line);padding:6px;display:flex;flex-direction:column;gap:4px;min-width:0}
.crm-page .ag-cell:nth-child(7n){border-right:none}
.crm-page .ag-cell.out{background:#FAFBFD;color:#B5C2D3}
.crm-page .ag-cell .n{font-size:12px;font-weight:700;cursor:pointer}
.crm-page .ag-cell.today .n{color:var(--blue)}
.crm-page .ag-cell .ag-item{padding:3px 6px}
.crm-page .ag-more{font-size:11px;color:var(--blue);font-weight:600;cursor:pointer;background:none;border:none;padding:0;text-align:left;font-family:inherit}

/* formularios */
.crm-page .ag-form{padding:18px 20px;display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:14px 18px}
.crm-page .ag-form .full{grid-column:1/-1}
.crm-page .ag-f label{display:block;font-size:12px;font-weight:600;color:var(--muted);margin-bottom:5px}
.crm-page .ag-f input[type=text],.crm-page .ag-f input[type=email],.crm-page .ag-f input[type=url],.crm-page .ag-f input[type=date],.crm-page .ag-f input[type=time],.crm-page .ag-f input[type=number],.crm-page .ag-f input[type=tel],.crm-page .ag-f select,.crm-page .ag-f textarea{width:100%;border:1px solid var(--line);border-radius:10px;padding:9px 11px;font-size:13.5px;font-family:inherit;color:var(--ink);background:#fff;outline:none}
.crm-page .ag-f textarea{min-height:84px;resize:vertical}
.crm-page .ag-f input:focus,.crm-page .ag-f select:focus,.crm-page .ag-f textarea:focus{border-color:var(--blue-3)}
.crm-page .ag-f .err{color:#B23B3B;font-size:12px;margin-top:4px}
.crm-page .ag-f .hint{color:var(--muted);font-size:11.5px;margin-top:4px}
.crm-page .ag-checks{display:flex;gap:14px;flex-wrap:wrap;font-size:13px}
.crm-page .ag-checks label{display:inline-flex;align-items:center;gap:6px;color:var(--ink);font-weight:500;margin:0}
.crm-page .ag-warn{background:#FDF6E7;border:1px solid #F2DFB0;border-radius:12px;padding:12px 14px;font-size:13px;color:#7a5a14}
.crm-page .ag-warn ul{margin:6px 0 0 18px;padding:0}
.crm-page .ag-warn label{display:flex;align-items:center;gap:8px;margin-top:10px;font-weight:600;color:#5f4410}
.crm-page .ag-ok{background:#EEF8F2;border:1px solid #CBEBD8;border-radius:12px;padding:10px 14px;font-size:13px;color:#1E6E46}
.crm-page .ag-slots{display:flex;flex-wrap:wrap;gap:6px;margin-top:6px}
.crm-page .ag-slots button{border:1px solid #CFE0F5;background:#fff;color:var(--blue);border-radius:999px;padding:4px 10px;font-size:12px;font-weight:600;cursor:pointer;font-family:inherit}
.crm-page .ag-actions{display:flex;gap:8px;justify-content:flex-end;padding:14px 20px;border-top:1px solid var(--line);flex-wrap:wrap}
.crm-page .ag-picked{display:flex;align-items:center;gap:8px;background:#F1F6FC;border:1px solid #D9E6F6;border-radius:10px;padding:8px 10px;font-size:13px}
.crm-page .ag-picked button{margin-left:auto;border:none;background:none;color:var(--muted);cursor:pointer}
.crm-page .ag-results{border:1px solid var(--line);border-radius:10px;margin-top:6px;overflow:hidden}
.crm-page .ag-results button{display:block;width:100%;text-align:left;border:none;background:#fff;padding:8px 11px;font-size:13px;font-family:inherit;cursor:pointer;border-bottom:1px solid var(--line)}
.crm-page .ag-results button:last-child{border-bottom:none}
.crm-page .ag-results button:hover{background:#F4F8FD}
.crm-page .ag-results small{color:var(--muted)}

/* detalle */
.crm-page .ag-detail{display:grid;grid-template-columns:minmax(0,1fr) 340px}
.crm-page .ag-main{padding:18px 20px;border-right:1px solid var(--line);min-width:0}
.crm-page .ag-side{padding:18px}
.crm-page .ag-side h3,.crm-page .ag-main h3{font-size:12px;letter-spacing:.08em;text-transform:uppercase;color:var(--muted);font-weight:600;margin:0 0 10px;display:flex;align-items:center;gap:7px}
.crm-page .ag-side .block+.block,.crm-page .ag-main .block+.block{margin-top:18px;padding-top:18px;border-top:1px solid var(--line)}
.crm-page .ag-kv{display:grid;grid-template-columns:130px minmax(0,1fr);gap:8px 12px;font-size:13.5px}
.crm-page .ag-kv .k{color:var(--muted)}
.crm-page .ag-kv .v{font-weight:500;overflow-wrap:anywhere}
.crm-page .ag-links{display:flex;gap:8px;flex-wrap:wrap}
.crm-page .ag-guest{display:flex;align-items:flex-start;gap:10px;padding:9px 0;border-bottom:1px solid var(--line);font-size:13px}
.crm-page .ag-guest:last-child{border-bottom:none}
.crm-page .ag-guest .g{min-width:0;flex:1}
.crm-page .ag-guest .g b{display:block;overflow:hidden;text-overflow:ellipsis}
.crm-page .ag-guest .g small{color:var(--muted);display:block;overflow-wrap:anywhere}
.crm-page .ag-guest .ga{display:flex;gap:4px}
.crm-page .ag-guest .ga button{border:1px solid var(--line);background:#fff;border-radius:8px;padding:4px 7px;cursor:pointer;color:var(--ink);display:inline-flex}
.crm-page .ag-gs{font-size:10.5px;font-weight:700;border-radius:999px;padding:1px 7px;background:#EEF1F5;color:#6B7C93;margin-top:3px;display:inline-block}
.crm-page .ag-gs.sent{background:#E3F5EA;color:#1E8E5A}
.crm-page .ag-gs.failed{background:#FDECEC;color:#B23B3B}
.crm-page .ag-gs.pending{background:#E8F1FD;color:#1E5AA8}
.crm-page .ag-box{background:#FBFCFE;border:1px solid var(--line);border-radius:12px;padding:12px;margin-top:10px}

/* disponibilidad */
.crm-page .ag-wd{display:grid;grid-template-columns:120px minmax(0,1fr);gap:10px;padding:10px 0;border-bottom:1px solid var(--line);align-items:start}
.crm-page .ag-wd .nm{font-weight:600;font-size:13.5px;padding-top:7px}
.crm-page .ag-int{display:flex;gap:6px;align-items:center;flex-wrap:wrap;margin-bottom:6px}
.crm-page .ag-int input{width:110px}
.crm-page .ag-int button,.crm-page .ag-linkbtn{border:none;background:none;color:var(--blue);font-weight:600;font-size:12.5px;cursor:pointer;font-family:inherit;padding:4px 0}
.crm-page .ag-int button.rm{color:#B23B3B}

@media(max-width:900px){
  .crm-page .ag-detail{grid-template-columns:1fr}
  .crm-page .ag-main{border-right:none;border-bottom:1px solid var(--line)}
}
@media(max-width:760px){
  .crm-page .ag-week{grid-template-columns:1fr;min-height:0}
  .crm-page .ag-day{border-right:none;border-bottom:1px solid var(--line)}
  .crm-page .ag-dbody{padding:8px 12px}
  .crm-page .ag-month .ag-mh{display:none}
  .crm-page .ag-month{grid-template-columns:1fr}
  .crm-page .ag-cell{min-height:0;border-right:none}
  .crm-page .ag-cell.out,.crm-page .ag-cell.emptyday{display:none}
  .crm-page .ag-row{grid-template-columns:80px minmax(0,1fr)}
  .crm-page .ag-row .st{grid-column:2;justify-content:flex-start}
  .crm-page .ag-form{grid-template-columns:1fr;padding:16px}
  .crm-page .ag-kv{grid-template-columns:1fr;gap:2px}
  .crm-page .ag-kv .v{margin-bottom:8px}
  .crm-page .ag-wd{grid-template-columns:1fr}
  .crm-page .ag-bar{padding:12px}
}
</style>
