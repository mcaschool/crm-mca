{{--
  Sistema de diseño del panel (MCA). Paleta y tipografia del widget aprobado.
  Se incluye una vez por pagina y todo lo que va dentro de .mca-panel lo hereda.
  Autocontenido (no depende de recompilar Tailwind). Este es el PATRON de estilo
  reutilizable para el resto del panel: card, badge, btn, field, avatar...
--}}
<style>
@import url("https://fonts.bunny.net/css?family=dm-sans:400,500,600,700");
.mca-panel{--deep:var(--mca-blue-deep);--mca:var(--mca-blue);--mid:var(--mca-blue);--bright:var(--mca-blue);
  --yellow:var(--mca-gold);--yellow2:var(--mca-gold);
  --ink:var(--mca-ink);--paper:var(--mca-page-bg);--white:var(--mca-card);--line:var(--mca-card-border);--muted:var(--mca-ink-2);
  font-family:"DM Sans",system-ui,-apple-system,Arial,sans-serif;color:var(--ink)}
.mca-panel *{box-sizing:border-box}
.mca-panel .ic{width:20px;height:20px;flex-shrink:0;vertical-align:middle}
.mca-h1{font-size:22px;font-weight:700;letter-spacing:-.2px;color:var(--ink);margin:0}
.mca-sub{font-size:14px;color:var(--muted);margin:4px 0 0}
.mca-head{display:flex;align-items:flex-start;justify-content:space-between;gap:16px;margin-bottom:22px;flex-wrap:wrap}

/* Card */
.mca-panel .card{background:var(--white);border:1px solid var(--line);border-radius:var(--mca-radius);box-shadow:var(--mca-shadow)}
.mca-panel .card-p{padding:20px}
.mca-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(280px,1fr));gap:16px}

/* Avatar */
.mca-av{width:52px;height:52px;border-radius:50%;overflow:hidden;background:#EAF1FB;color:var(--mca);display:flex;align-items:center;justify-content:center;flex-shrink:0}
.mca-av img{width:100%;height:100%;object-fit:cover}
.mca-av.lg{width:76px;height:76px}
.mca-av svg{width:24px;height:24px}

/* Badges */
.mca-panel .badge{display:inline-flex;align-items:center;gap:5px;font-size:12px;font-weight:600;padding:3px 9px;border-radius:999px;line-height:1.4}
.badge-ai{background:#E7F0FB;color:var(--mca)}
.badge-human{background:#F1EEF9;color:#6D5AB8}
.badge-on{background:#E4F5EA;color:#1E7C43}
.badge-off{background:#EEF1F5;color:var(--muted)}
.badge-soft{background:var(--paper);color:var(--muted)}

/* Sistema de botones (fino, con acabado): primario sólido azul + sombra sutil,
   secundario blanco+borde+sombra leve, terciario ghost tenue transparente.
   Altura contenida, peso medio; ritmo alineado a inputs/chips. */
.mca-panel .btn{display:inline-flex;align-items:center;justify-content:center;gap:6px;font-family:inherit;font-size:13px;font-weight:500;line-height:1;border-radius:9px;padding:8px 14px;cursor:pointer;border:1px solid transparent;transition:background .14s,border-color .14s,box-shadow .14s,color .14s,transform .08s;text-decoration:none;white-space:nowrap}
.mca-panel .btn:active{transform:translateY(1px)}
.mca-panel .btn:focus-visible{outline:2px solid rgba(30,90,168,.35);outline-offset:2px}
.mca-panel .btn:disabled{opacity:.5;cursor:default;box-shadow:none}
.mca-panel .btn-primary{background:var(--mca-blue);color:#fff;box-shadow:0 1px 2px rgba(19,37,61,.12),0 2px 8px rgba(30,90,168,.20)}
.mca-panel .btn-primary:hover{background:var(--mca-blue-hover);box-shadow:0 1px 2px rgba(19,37,61,.14),0 4px 12px rgba(30,90,168,.26)}
.mca-panel .btn-ghost{background:#fff;border-color:var(--mca-card-border);color:var(--mca-ink-2);box-shadow:var(--mca-shadow-sm)}
.mca-panel .btn-ghost:hover{border-color:#cbd6e6;color:var(--mca-ink);background:#fbfdff}
.mca-panel .btn-soft{background:transparent;border-color:var(--mca-card-border);color:var(--mca-ink-2)}
.mca-panel .btn-soft:hover{background:var(--mca-page-bg);border-color:#cbd6e6;color:var(--mca-ink)}
.mca-panel .btn-danger{background:#fff;border-color:#f2d0d0;color:#b3261e;box-shadow:var(--mca-shadow-sm)}
.mca-panel .btn-danger:hover{background:#fdf3f3;border-color:#e6b7b7}
.mca-panel .btn-danger-solid{background:#b3261e;color:#fff;border-color:#b3261e;box-shadow:0 1px 2px rgba(19,37,61,.12)}
.mca-panel .btn-danger-solid:hover{filter:brightness(1.08)}
.mca-panel .btn-danger-solid:disabled{opacity:.45;cursor:default;filter:none}
.mca-panel .btn-sm{padding:6px 11px;font-size:12.5px;border-radius:8px}
.mca-panel .btn-accent{background:var(--mca-gold);color:#fff;box-shadow:0 1px 2px rgba(19,37,61,.1)}
.mca-panel .btn-accent:hover{filter:brightness(1.04)}

/* Forms */
.mca-panel .field{margin-bottom:16px}
.mca-panel .field label,.mca-lbl{display:block;font-size:13px;font-weight:600;color:var(--ink);margin-bottom:6px}
.mca-panel .field input[type=text],.mca-panel .field input[type=email],.mca-panel .field input[type=password],.mca-panel .field input[type=number],.mca-panel .field input[type=search],.mca-panel .field select,.mca-panel .field textarea{
  width:100%;padding:11px 13px;border:1px solid var(--line);border-radius:11px;font-size:14px;font-family:inherit;color:var(--ink);background:#fff}
.mca-panel .field input:focus,.mca-panel .field select:focus,.mca-panel .field textarea:focus{outline:none;border-color:var(--mca-blue);box-shadow:0 0 0 3px rgba(30,90,168,.12)}
.mca-help{font-size:12px;color:var(--muted);margin-top:6px}
.mca-err{font-size:12.5px;color:#b3261e;margin-top:6px;font-weight:500}

/* Segmented (tipo IA/Humano) */
.mca-seg{display:inline-flex;background:var(--paper);border:1px solid var(--line);border-radius:12px;padding:3px;gap:3px}
.mca-seg button{border:none;background:none;font-family:inherit;font-size:13px;font-weight:600;color:var(--muted);padding:8px 16px;border-radius:9px;cursor:pointer;display:inline-flex;align-items:center;gap:6px}
.mca-seg button.active{background:#fff;color:var(--mca);box-shadow:0 1px 3px rgba(11,37,69,.12)}

/* Section */
.mca-section{border-top:1px solid var(--line);padding-top:20px;margin-top:20px}
.mca-section h3{font-size:15px;font-weight:700;color:var(--ink);margin:0 0 3px}
.mca-section .mca-sub{margin-bottom:14px}

/* Toast / estados */
.mca-toast{display:flex;align-items:center;gap:8px;font-size:13.5px;font-weight:500;padding:11px 14px;border-radius:12px;margin-bottom:16px}
.mca-toast.ok{background:#E4F5EA;color:#1E7C43;border:1px solid #c7ead2}
.mca-toast.err{background:#FCECEC;color:#b3261e;border:1px solid #f2d0d0}

/* Tabla de documentos */
.mca-doc{display:flex;align-items:center;gap:12px;padding:12px 14px;border:1px solid var(--line);border-radius:12px;margin-bottom:8px;background:#fff}
.mca-doc .di{width:34px;height:34px;border-radius:9px;background:#EAF1FB;color:var(--mca);display:flex;align-items:center;justify-content:center;flex-shrink:0}
.mca-doc .dm{flex:1;min-width:0}
.mca-doc .dm b{display:block;font-size:14px;color:var(--ink);font-weight:600;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
.mca-doc .dm span{font-size:12px;color:var(--muted)}

.mca-drop{border:1.5px dashed #cfdaea;border-radius:14px;padding:16px;text-align:center;background:#fbfdff}
.mca-filebtn{display:inline-flex;align-items:center;gap:7px;font-size:13px;font-weight:600;color:var(--mca-ink-2);background:#fff;border:1px solid var(--line);border-radius:9px;padding:8px 14px;cursor:pointer}
.mca-filebtn:hover{border-color:#cbd6e6;background:#fbfdff}
.mca-muted{color:var(--muted)}
.mca-spin{display:inline-block;width:15px;height:15px;border:2px solid rgba(30,90,168,.25);border-top-color:var(--mca);border-radius:50%;animation:mcaspin .7s linear infinite;vertical-align:middle}
@keyframes mcaspin{to{transform:rotate(360deg)}}
.mca-panel .fade{animation:mcafade .3s ease both}
@keyframes mcafade{from{opacity:0;transform:translateY(6px)}to{opacity:1;transform:none}}

/* Barra de herramientas (buscador + filtros + acciones) */
.mca-toolbar{display:flex;align-items:center;gap:10px;flex-wrap:wrap;margin-bottom:16px}
.mca-toolbar .sp{flex:1}
.mca-search{display:flex;align-items:center;gap:8px;background:#fff;border:1px solid var(--line);border-radius:11px;padding:9px 12px;color:var(--muted);min-width:230px}
.mca-search input{border:none;background:none;outline:none;font-family:inherit;font-size:13.5px;color:var(--ink);width:100%}
.mca-panel select.mca-filter{appearance:none;background:#fff url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='14' height='14' viewBox='0 0 24 24' fill='none' stroke='%235A6B84' stroke-width='2' stroke-linecap='round' stroke-linejoin='round'%3E%3Cpath d='m6 9 6 6 6-6'/%3E%3C/svg%3E") no-repeat right 10px center;
  border:1px solid var(--line);border-radius:11px;padding:9px 30px 9px 12px;font-size:13.5px;font-family:inherit;color:var(--ink);cursor:pointer}

/* Tabla (lenguaje v4: cabecera sutil, filas con hover) */
.mca-panel table{width:100%;border-collapse:collapse;font-size:13.5px}
.mca-panel thead th{text-align:left;font-size:11px;letter-spacing:.05em;text-transform:uppercase;color:var(--muted);font-weight:600;padding:12px 16px;border-bottom:1px solid var(--line);background:#FBFCFE}
.mca-panel thead th:first-child{padding-left:20px}
.mca-panel tbody td{padding:13px 16px;border-bottom:1px solid var(--line);vertical-align:middle}
.mca-panel tbody td:first-child{padding-left:20px}
.mca-panel tbody tr:last-child td{border-bottom:none}
.mca-panel tbody tr.row{cursor:pointer;transition:background .12s}
.mca-panel tbody tr.row:hover{background:#F7F9FC}
.mca-panel .t-empty{padding:22px;color:var(--muted);font-size:13.5px;text-align:center}
.mca-panel .t-mut{color:var(--muted)}
.mca-panel .t-strong{font-weight:600}

/* Paginación simple */
.mca-pager{display:flex;align-items:center;justify-content:space-between;gap:12px;padding:14px 16px;border-top:1px solid var(--line);font-size:12.5px;color:var(--muted);flex-wrap:wrap}
.mca-pager .pg{display:inline-flex;align-items:center;gap:6px}
.mca-pager a,.mca-pager span.b{display:inline-flex;align-items:center;justify-content:center;min-width:30px;height:30px;padding:0 8px;border:1px solid var(--line);border-radius:9px;background:#fff;color:var(--ink);text-decoration:none;font-weight:600}
.mca-pager a:hover{background:#F7F9FC}
.mca-pager span.b.cur{background:var(--mca-blue);color:#fff;border-color:var(--mca-blue)}
.mca-pager span.b.dis{opacity:.4}

/* Zona de peligro + modal */
.mca-danger{border:1px solid #f2d0d0;background:#fdf6f6;border-radius:16px;padding:18px}
.mca-danger h3{color:#b3261e;font-size:15px;font-weight:700;margin:0 0 4px}
.mca-modal-bg{position:fixed;inset:0;background:rgba(11,37,69,.5);display:flex;align-items:center;justify-content:center;padding:18px;z-index:60;animation:mcafade .2s ease both}
.mca-modal{background:#fff;border-radius:18px;max-width:440px;width:100%;padding:24px;box-shadow:0 24px 60px rgba(11,37,69,.4);animation:mcapop .22s ease both}
@keyframes mcapop{from{opacity:0;transform:scale(.96)}to{opacity:1;transform:none}}
.mca-modal .mm-ic{width:46px;height:46px;border-radius:50%;background:#FCECEC;color:#b3261e;display:flex;align-items:center;justify-content:center;margin-bottom:14px}
.mca-modal h2{font-size:18px;font-weight:700;color:var(--ink);margin:0 0 6px}
.mca-modal p{font-size:14px;color:var(--muted);line-height:1.55;margin:0 0 14px}
.mca-modal .warn{background:#FCECEC;color:#b3261e;border-radius:10px;padding:9px 12px;font-size:13px;font-weight:500;margin-bottom:14px}

@media (prefers-reduced-motion: reduce){.mca-panel .fade{animation:none}.mca-spin{animation:none}.mca-panel .btn:active{transform:none}.mca-modal-bg,.mca-modal{animation:none}}
</style>
