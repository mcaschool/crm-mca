{{--
  Estilos del CRM core (leads y conversaciones). Portan EXACTAMENTE el prototipo
  aprobado `crm_core_prototipo.html`, scoped bajo `.crm-page` para no colisionar
  con el resto del panel. Se incluye una vez por pagina. Autocontenido: no depende
  de recompilar Tailwind. Los iconos usan <x-ui.icon> (Lucide, stroke currentColor).
--}}
<style>
@import url("https://fonts.bunny.net/css?family=dm-sans:400,500,600,700");
.crm-page{
  --ink:#13253D;--blue-deep:#143B6B;--blue:#1E5AA8;--blue-2:#2E6DB4;--blue-3:#3B82D6;
  --yellow:#C9A84C;--paper:#F4F7FB;--card:#FFF;--line:#E7EDF5;--muted:#61748F;--soft:#F1F6FC;
  --shadow:0 18px 48px -24px rgba(19,37,61,.4);
  font-family:"DM Sans",system-ui,-apple-system,Arial,sans-serif;color:var(--ink);
}
.crm-page *{box-sizing:border-box}

/* Iconos (Lucide via x-ui.icon). Tamanos del prototipo por clase modificadora. */
.crm-page .ic{width:15px;height:15px;flex:0 0 auto;vertical-align:middle}
.crm-page .i12{width:12px;height:12px}
.crm-page .i13{width:13px;height:13px}
.crm-page .i14{width:14px;height:14px}
.crm-page .i15{width:15px;height:15px}
.crm-page .i16{width:16px;height:16px}
.crm-page .i18{width:18px;height:18px}

/* Cabecera de pagina */
.crm-page .lead-h{margin:0 0 24px}
.crm-page .eyebrow{font-size:12px;letter-spacing:.2em;text-transform:uppercase;color:var(--blue);font-weight:600;margin-bottom:8px}
.crm-page .lead-h h1{font-size:26px;margin:0 0 6px;font-weight:700}
.crm-page .lead-h p{color:var(--muted);font-size:14px;margin:0;line-height:1.6}
.crm-page .cap{font-size:11px;letter-spacing:.14em;text-transform:uppercase;color:var(--muted);font-weight:600;margin:30px 0 12px}

.crm-page .panel{background:var(--card);border:1px solid var(--line);border-radius:18px;box-shadow:var(--shadow);overflow:hidden}

/* toolbar */
.crm-page .toolbar{display:flex;align-items:center;gap:12px;padding:16px 18px;border-bottom:1px solid var(--line);flex-wrap:wrap}
.crm-page .toolbar h2{font-size:16px;margin:0;font-weight:600;display:flex;align-items:center;gap:8px}
.crm-page .toolbar h2 .ic{color:var(--blue)}
.crm-page .count{font-size:12px;color:var(--muted);background:var(--soft);border-radius:999px;padding:2px 9px;font-weight:600}
.crm-page .search{margin-left:auto;display:flex;align-items:center;gap:8px;background:#FAFCFE;border:1px solid var(--line);border-radius:10px;padding:8px 12px;color:var(--muted);min-width:230px}
.crm-page .search input{border:none;background:none;outline:none;font-family:inherit;font-size:13.5px;color:var(--ink);width:100%}
.crm-page .fbtn{display:inline-flex;align-items:center;gap:7px;border:1px solid var(--line);background:#fff;border-radius:10px;padding:8px 12px;font-size:13px;font-weight:500;color:var(--ink);cursor:pointer;font-family:inherit}
.crm-page .fbtn.blue{background:var(--blue);color:#fff;border-color:var(--blue)}
.crm-page select.fbtn{padding-right:26px;appearance:none;background-image:url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='14' height='14' viewBox='0 0 24 24' fill='none' stroke='%2361748F' stroke-width='2' stroke-linecap='round' stroke-linejoin='round'%3E%3Cpath d='m6 9 6 6 6-6'/%3E%3C/svg%3E");background-repeat:no-repeat;background-position:right 9px center}

/* table */
.crm-page table{width:100%;border-collapse:collapse;font-size:13.5px}
.crm-page thead th{text-align:left;font-size:11px;letter-spacing:.06em;text-transform:uppercase;color:var(--muted);font-weight:600;padding:12px 16px;border-bottom:1px solid var(--line);background:#FBFCFE}
.crm-page thead th:first-child{padding-left:22px}
.crm-page tbody td{padding:14px 16px;border-bottom:1px solid var(--line);vertical-align:middle}
.crm-page tbody td:first-child{padding-left:22px}
.crm-page tbody tr:last-child td{border-bottom:none;padding-bottom:18px}
.crm-page tbody tr.row{cursor:pointer;transition:background .12s}
.crm-page tbody tr.row:hover{background:#F7FAFE}
.crm-page tbody tr.sel{background:#EEF5FD}
.crm-page .who{display:flex;align-items:center;gap:10px}
.crm-page .ava{width:32px;height:32px;border-radius:50%;background:linear-gradient(140deg,var(--blue),var(--blue-3));color:#fff;display:grid;place-items:center;font-size:12.5px;font-weight:600;flex:0 0 auto}
.crm-page .who .nm{font-weight:600}
.crm-page .src{display:inline-flex;align-items:center;gap:6px;color:var(--blue);font-weight:500}
.crm-page .src .ic{color:var(--blue)}
.crm-page .mail{color:var(--muted)}
.crm-page .chip{display:inline-flex;align-items:center;gap:5px;font-size:12px;font-weight:600;padding:3px 9px;border-radius:999px}
.crm-page .st-nuevo{background:#E8F1FD;color:#1E5AA8}
.crm-page .st-cont{background:#E0F3F4;color:#0E7C86}
.crm-page .st-seg{background:#FBF1DC;color:#B7791F}
.crm-page .st-matr{background:#E3F5EA;color:#1E8E5A}
.crm-page .st-desc{background:#EEF1F5;color:#6B7C93}
.crm-page .match{color:var(--ink)}
.crm-page .match small{color:var(--muted)}
.crm-page .emp{display:inline-flex;align-items:center;gap:5px;font-size:12px;font-weight:600;color:#9A7B12;background:#FFF6DA;border:1px solid #F5E4A8;border-radius:999px;padding:3px 9px}
/* Senal "volvio a contactar": actividad nueva no vista de un lead conocido. */
.crm-page .recontact{display:inline-flex;align-items:center;gap:5px;font-size:11px;font-weight:600;color:#1B7A4B;background:#E7F7EF;border:1px solid #BFE8D2;border-radius:999px;padding:2px 8px;line-height:1.4;white-space:nowrap}
.crm-page .recontact .dot{width:6px;height:6px;border-radius:50%;background:#22A866;flex:0 0 auto;animation:recontactPulse 1.8s ease-in-out infinite}
@keyframes recontactPulse{0%,100%{opacity:1;transform:scale(1)}50%{opacity:.45;transform:scale(.7)}}
@media (prefers-reduced-motion:reduce){.crm-page .recontact .dot{animation:none}}
.crm-page .dash{color:#C2CEDC}
.crm-page .date{color:var(--muted);white-space:nowrap}
.crm-page .empty{padding:22px;color:var(--muted);font-size:13.5px}

/* paginacion */
.crm-page .pager{display:flex;align-items:center;justify-content:space-between;gap:12px;padding:14px 18px;border-top:1px solid var(--line);font-size:12.5px;color:var(--muted);flex-wrap:wrap}
.crm-page .pager .links{display:flex;gap:6px;align-items:center}
.crm-page .pager a,.crm-page .pager span.pg{display:inline-flex;align-items:center;justify-content:center;min-width:30px;height:30px;padding:0 8px;border:1px solid var(--line);border-radius:8px;background:#fff;color:var(--ink);text-decoration:none;font-weight:600}
.crm-page .pager a:hover{background:#F7FAFE}
.crm-page .pager span.pg.cur{background:var(--blue);color:#fff;border-color:var(--blue)}
.crm-page .pager span.pg.dis{opacity:.4}

/* detail */
.crm-page .d-head{display:flex;align-items:center;gap:14px;padding:18px 20px;border-bottom:1px solid var(--line);flex-wrap:wrap}
.crm-page .d-head .ava{width:46px;height:46px;font-size:16px}
.crm-page .d-head .nm{font-size:18px;font-weight:700;line-height:1.1}
.crm-page .d-head .sub{color:var(--muted);font-size:13px;margin-top:2px;display:flex;align-items:center;gap:8px;flex-wrap:wrap}
.crm-page .d-head .sub .ic{color:var(--blue)}
.crm-page .d-head .sub .ic.gray{color:var(--muted)}
.crm-page .d-actions{margin-left:auto;display:flex;align-items:center;gap:8px;flex-wrap:wrap}
.crm-page .statuspick{display:inline-flex;align-items:center;gap:7px;border:1px solid #F0DCA6;background:#FBF1DC;color:#B7791F;border-radius:10px;padding:8px 12px;font-size:13px;font-weight:600;cursor:pointer;font-family:inherit}
.crm-page .statuspick.st-nuevo{border-color:#BBD8F7;background:#E8F1FD;color:#1E5AA8}
.crm-page .statuspick.st-cont{border-color:#B7E1E4;background:#E0F3F4;color:#0E7C86}
.crm-page .statuspick.st-seg{border-color:#F0DCA6;background:#FBF1DC;color:#B7791F}
.crm-page .statuspick.st-matr{border-color:#BEE7CD;background:#E3F5EA;color:#1E8E5A}
.crm-page .statuspick.st-desc{border-color:#D7DEE8;background:#EEF1F5;color:#6B7C93}
.crm-page .ghost{display:inline-flex;align-items:center;gap:7px;border:1px solid var(--line);background:#fff;border-radius:10px;padding:8px 12px;font-size:13px;font-weight:500;color:var(--ink);cursor:pointer;font-family:inherit;text-decoration:none}
.crm-page .ghost:hover{background:#F7FAFE}
.crm-page .ghost.solid{background:var(--blue);color:#fff;border-color:var(--blue)}

.crm-page .d-body{display:grid;grid-template-columns:1fr 340px;gap:0}
@media(max-width:820px){.crm-page .d-body{grid-template-columns:1fr}}
.crm-page .conv{padding:18px 20px;border-right:1px solid var(--line)}
.crm-page .conv h3,.crm-page .side h3{font-size:12px;letter-spacing:.08em;text-transform:uppercase;color:var(--muted);font-weight:600;margin:0 0 12px;display:flex;align-items:center;gap:7px}
.crm-page .side .block+.block{margin-top:18px;padding-top:18px;border-top:1px solid var(--line)}
.crm-page .side{padding:18px 18px}

.crm-page .m{margin-bottom:14px;max-width:92%}
.crm-page .m .lbl{font-size:10.5px;font-weight:600;color:var(--muted);margin:0 0 4px 2px}
.crm-page .m .bub{padding:10px 13px;border-radius:13px;font-size:13.5px;line-height:1.5}
.crm-page .m.bot .bub{background:#EFF4FA;border-bottom-left-radius:4px}
.crm-page .m.bot .bub a{color:var(--blue);font-weight:600;overflow-wrap:anywhere}
.crm-page .m.me{margin-left:auto}
.crm-page .m.me .lbl{text-align:right;margin:0 2px 4px 0}
.crm-page .m.me .bub{background:#FFFCF0;border:1px solid #F6EECB;color:var(--ink);border-bottom-right-radius:4px}
.crm-page .opts{display:flex;flex-wrap:wrap;gap:6px;margin:0 0 14px}
.crm-page .opt{font-size:12px;border:1px solid #CFE0F5;color:var(--blue);background:#fff;border-radius:999px;padding:5px 11px;font-weight:600}
.crm-page .conv-empty{color:var(--muted);font-size:13px}

.crm-page .field{display:flex;align-items:center;gap:9px;font-size:13px;padding:7px 0}
.crm-page .field .ic{color:var(--blue)}
.crm-page .field .k{color:var(--muted);min-width:74px}
.crm-page .field .v{font-weight:500;margin-left:auto;text-align:right}
.crm-page .reveal{font-size:11px;color:var(--blue);font-weight:600;cursor:pointer;display:inline-flex;align-items:center;gap:4px;background:none;border:none;font-family:inherit;padding:0}
.crm-page .locknote{display:flex;align-items:center;gap:7px;font-size:11.5px;color:var(--muted);background:#F7FAFE;border:1px solid var(--line);border-radius:9px;padding:8px 10px;margin-top:8px}
.crm-page .locknote .ic{color:var(--muted)}

.crm-page .mrow{display:flex;align-items:center;gap:8px;font-size:12.5px;padding:5px 0}
.crm-page .mrow .k{color:var(--muted);min-width:80px}
.crm-page .mrow .v{font-weight:600}
.crm-page .rec{background:#E3F5EA;border:1px solid #BEE7CD;border-radius:10px;padding:10px 12px;margin-top:8px;font-size:12.5px}
.crm-page .rec b{color:#1E8E5A}

.crm-page .ev{display:flex;gap:9px;font-size:12.5px;padding:6px 0}
.crm-page .ev .ic{color:#9A7B12;flex:0 0 auto;margin-top:1px}
.crm-page .ev .t{color:var(--muted);font-size:11px}

.crm-page .note{background:#FFFDF3;border:1px solid #F3E7BF;border-radius:10px;padding:10px 12px;font-size:12.5px;line-height:1.45}
.crm-page .note+.note{margin-top:8px}
.crm-page .note .meta{color:var(--muted);font-size:11px;margin-top:5px}
.crm-page .addnote{display:flex;gap:8px;margin-top:8px}
.crm-page .addnote input{flex:1;border:1px solid var(--line);border-radius:9px;padding:8px 10px;font-size:12.5px;font-family:inherit;outline:none}
.crm-page .addnote button{border:none;background:var(--blue);color:#fff;border-radius:9px;padding:0 12px;cursor:pointer;display:inline-flex;align-items:center}

.crm-page .moodle{opacity:.62;background:repeating-linear-gradient(45deg,#F7FAFE,#F7FAFE 10px,#F3F7FC 10px,#F3F7FC 20px);border:1px dashed #CBD8E8;border-radius:10px;padding:12px}
.crm-page .moodle .tag{font-size:10px;letter-spacing:.1em;text-transform:uppercase;font-weight:700;color:#8397AE;background:#E7EEF6;border-radius:6px;padding:2px 7px;display:inline-block;margin-bottom:8px}
.crm-page .moodle .mrow .v{color:#9DB0C6}

.crm-page .transfer{display:flex;align-items:center;gap:8px;margin-top:8px;background:#F1F6FC;border:1px solid #D9E6F6;border-radius:10px;padding:10px 12px;font-size:12.5px}
.crm-page .transfer .ic{color:var(--blue)}
.crm-page .transfer select{margin-left:auto;border:1px solid var(--line);border-radius:8px;padding:6px 8px;font-family:inherit;font-size:12.5px;color:var(--ink);background:#fff;max-width:200px}

.crm-page .toast{margin:0 0 16px;display:flex;align-items:center;gap:8px;font-size:13px;font-weight:500;padding:10px 14px;border-radius:12px}
.crm-page .toast.ok{background:#E3F5EA;border:1px solid #BEE7CD;color:#1E8E5A}
.crm-page .toast.err{background:#FDECEC;border:1px solid #F5C6C6;color:#B23B3B}
.crm-page .ro-tag{display:inline-flex;align-items:center;gap:5px;font-size:11px;color:#6B7C93;background:#EEF1F5;border-radius:999px;padding:6px 11px;font-weight:600}
.crm-page .ro-tag .ic{color:#6B7C93}

/* menu de estado (cambiar estado desde la ficha) */
.crm-page .statusmenu{position:relative}
.crm-page .statusmenu .menu{position:absolute;top:calc(100% + 6px);left:0;z-index:20;background:#fff;border:1px solid var(--line);border-radius:12px;box-shadow:var(--shadow);padding:6px;min-width:190px}
.crm-page .statusmenu .menu button{display:flex;align-items:center;gap:8px;width:100%;text-align:left;border:none;background:none;font-family:inherit;font-size:13px;color:var(--ink);padding:8px 10px;border-radius:8px;cursor:pointer}
.crm-page .statusmenu .menu button:hover{background:#F4F8FD}
.crm-page .statusmenu .menu button[disabled]{opacity:.45;cursor:not-allowed}

@media (prefers-reduced-motion: reduce){
  .crm-page *{transition:none !important;animation:none !important}
}
</style>
