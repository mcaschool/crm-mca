{{--
  Estilos del Centro de Conocimiento (Biblioteca y Por agente). Solo presentación:
  reutiliza los tokens v4 del proyecto (--mca-*: azul #1E5AA8, dorado #C9A84C, radios,
  sombras, DM Sans). Todo va acotado bajo .kc para no afectar a otras pantallas.
--}}
<style>
    .kc{font-family:var(--mca-font);color:var(--mca-ink);--kc-line-soft:#EEF1F6;--kc-head:#F4F6FA;--kc-red:#B3261E}
    .kc *,.kc *::before,.kc *::after{box-sizing:border-box}

    /* Cabecera */
    .kc-header{display:flex;align-items:flex-end;justify-content:space-between;gap:16px;margin-bottom:20px;flex-wrap:wrap}
    .kc-title{font-size:26px;font-weight:700;letter-spacing:-.3px;margin:0;color:var(--mca-ink)}
    .kc-sub{font-size:14px;color:var(--mca-ink-2);margin:5px 0 0}

    /* Pestañas segmentadas */
    .kc-seg{display:inline-flex;gap:4px;background:var(--mca-page-bg);border:1px solid var(--mca-card-border);border-radius:12px;padding:4px;margin-bottom:22px}
    .kc-seg a{display:inline-flex;align-items:center;gap:7px;padding:8px 18px;border-radius:9px;font-size:13.5px;font-weight:600;color:var(--mca-ink-2);text-decoration:none;transition:background .15s,color .15s}
    .kc-seg a svg{width:15px;height:15px}
    .kc-seg a:hover{color:var(--mca-ink);background:rgba(255,255,255,.75)}
    .kc-seg a.on{background:var(--mca-blue);color:#fff;box-shadow:0 1px 2px rgba(19,37,61,.12),0 2px 8px rgba(30,90,168,.22)}

    /* Botones */
    .kc-btn{display:inline-flex;align-items:center;justify-content:center;gap:8px;font:inherit;font-size:13.5px;font-weight:600;line-height:1;padding:10px 18px;border-radius:10px;border:1px solid transparent;cursor:pointer;white-space:nowrap;text-decoration:none;transition:background .14s,border-color .14s,box-shadow .14s,color .14s,transform .08s}
    .kc-btn svg{width:15px;height:15px}
    .kc-btn:active{transform:translateY(1px)}
    .kc-btn:focus-visible{outline:2px solid rgba(30,90,168,.35);outline-offset:2px}
    .kc-btn:disabled{opacity:.55;cursor:default;transform:none}
    .kc-btn-primary{background:var(--mca-blue);color:#fff;box-shadow:0 1px 2px rgba(19,37,61,.12),0 2px 8px rgba(30,90,168,.20)}
    .kc-btn-primary:hover{background:var(--mca-blue-hover)}
    .kc-btn-ghost{background:#fff;border-color:var(--mca-card-border);color:var(--mca-ink-2);box-shadow:var(--mca-shadow-sm)}
    .kc-btn-ghost:hover{border-color:#CBD6E6;color:var(--mca-ink);background:#FBFDFF}
    .kc-btn-sm{padding:7px 12px;font-size:12.5px;border-radius:9px}
    .kc-btn-sm svg{width:13px;height:13px}
    .kc-btn-danger{background:var(--kc-red);color:#fff}
    .kc-btn-danger:hover{filter:brightness(1.08)}

    /* Tarjetas */
    .kc-card{background:#fff;border:1px solid var(--mca-card-border);border-radius:var(--mca-radius);box-shadow:var(--mca-shadow)}

    /* Tarjetas de resumen */
    .kc-stats{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:14px;margin-bottom:20px}
    .kc-stat{display:block;width:100%;text-align:left;font:inherit;color:inherit;background:#fff;border:1px solid var(--mca-card-border);border-radius:var(--mca-radius);padding:18px 20px;box-shadow:var(--mca-shadow);cursor:pointer;transition:border-color .15s,box-shadow .15s}
    .kc-stat:hover{border-color:#CBD6E6}
    .kc-stat.on{border-color:var(--mca-blue);box-shadow:0 0 0 3px rgba(30,90,168,.12),var(--mca-shadow)}
    .kc-stat-top{display:flex;align-items:center;justify-content:space-between;gap:10px}
    .kc-stat-label{font-size:12.5px;font-weight:600;color:var(--mca-ink-2)}
    .kc-stat-num{font-size:30px;font-weight:700;letter-spacing:-.5px;line-height:1.1;margin-top:10px}
    .kc-ic{width:32px;height:32px;border-radius:9px;display:inline-flex;align-items:center;justify-content:center;flex:none}
    .kc-ic svg{width:16px;height:16px}

    /* Tonos (reutilizan tokens) */
    .t-blue{background:var(--mca-blue-soft);color:var(--mca-blue)}
    .t-green{background:var(--mca-ok-soft);color:var(--mca-ok)}
    .t-gray{background:#EEF1F5;color:var(--mca-ink-2)}
    .t-amber{background:var(--mca-warn-soft);color:var(--mca-warn)}
    .t-red{background:#FCECEC;color:var(--kc-red)}
    .c-green{color:var(--mca-ok)} .c-gray{color:var(--mca-ink-2)} .c-amber{color:var(--mca-warn)}

    /* Chips de categoría */
    .kc-chips{display:flex;align-items:center;gap:8px;flex-wrap:wrap;margin-bottom:20px}
    .kc-chips-label{font-size:12px;font-weight:600;color:var(--mca-ink-2);margin-right:2px}
    .kc-chip{font:inherit;font-size:12.5px;font-weight:500;padding:6px 13px;border-radius:999px;border:1px solid var(--mca-card-border);background:#fff;color:var(--mca-ink-2);cursor:pointer;transition:background .14s,border-color .14s,color .14s}
    .kc-chip:hover{border-color:#CBD6E6;color:var(--mca-ink)}
    .kc-chip.on{background:var(--mca-blue);border-color:var(--mca-blue);color:#fff;font-weight:600}

    /* Zona de subida */
    .kc-upload{padding:20px 22px;margin-bottom:20px}
    .kc-upload-row{display:flex;align-items:center;gap:20px}
    .kc-upload-ic{width:52px;height:52px;border-radius:14px;background:var(--mca-blue-soft);color:var(--mca-blue);display:flex;align-items:center;justify-content:center;flex:none}
    .kc-upload-ic svg{width:24px;height:24px}
    .kc-upload-text{flex:1;min-width:0}
    .kc-upload-text h3{font-size:15px;font-weight:700;margin:0;color:var(--mca-ink)}
    .kc-upload-text p{font-size:13px;color:var(--mca-ink-2);margin:3px 0 0}
    .kc-file{position:absolute;width:1px;height:1px;opacity:0;overflow:hidden;clip:rect(0 0 0 0)}
    .kc-picked{margin-top:16px;padding-top:14px;border-top:1px solid var(--kc-line-soft);display:flex;align-items:center;gap:12px;flex-wrap:wrap}
    .kc-picked-files{flex:1;min-width:0;display:flex;gap:6px;flex-wrap:wrap}
    .kc-file-tag{display:inline-flex;align-items:center;gap:6px;padding:5px 10px;border-radius:8px;background:var(--mca-page-bg);border:1px solid var(--mca-card-border);font-size:12px;color:var(--mca-ink-2);font-family:ui-monospace,SFMono-Regular,Menlo,monospace}
    .kc-file-tag svg{width:13px;height:13px;color:var(--mca-ink-3)}
    .kc-results{margin-top:16px;padding-top:14px;border-top:1px solid var(--kc-line-soft)}
    .kc-results h4{font-size:12.5px;font-weight:700;margin:0 0 8px;color:var(--mca-ink)}
    .kc-result{display:flex;align-items:center;gap:10px;padding:7px 0;font-size:13px;border-top:1px dashed var(--kc-line-soft)}
    .kc-result:first-of-type{border-top:0}
    .kc-err{margin-top:10px;font-size:12.5px;color:var(--kc-red)}

    /* Espacios de subida (Programa Académico | Base de Conocimiento) */
    .kc-spaces{display:grid;grid-template-columns:repeat(auto-fit,minmax(320px,1fr));gap:16px;margin-bottom:10px}
    .kc-space{padding:20px 22px;display:flex;flex-direction:column;gap:14px}
    .kc-space-head{display:flex;align-items:center;gap:14px}
    .kc-space-head .kc-upload-ic{width:44px;height:44px;border-radius:12px}
    .kc-space-head .kc-upload-ic svg{width:21px;height:21px}
    .kc-upload-ic.t-gold{background:var(--mca-gold-soft);color:var(--mca-gold)}
    .kc-field{display:flex;flex-direction:column;gap:6px;margin:0}
    .kc-field-label{font-size:11.5px;font-weight:700;letter-spacing:.3px;text-transform:uppercase;color:var(--mca-ink-2)}
    .kc-field select,.kc-field-search input{width:100%;height:40px;border:1px solid var(--mca-card-border);border-radius:10px;background:#fff;font:inherit;font-size:13.5px;color:var(--mca-ink);transition:border-color .14s,box-shadow .14s}
    .kc-field select{appearance:none;-webkit-appearance:none;padding:0 34px 0 12px;cursor:pointer;background:#fff url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='14' height='14' viewBox='0 0 24 24' fill='none' stroke='%238A99B2' stroke-width='2' stroke-linecap='round' stroke-linejoin='round'%3E%3Cpath d='m6 9 6 6 6-6'/%3E%3C/svg%3E") no-repeat right 12px center}
    .kc-field select:focus,.kc-field-search input:focus{outline:none;border-color:var(--mca-blue);box-shadow:0 0 0 3px rgba(30,90,168,.12)}
    .kc-field-search{position:relative}
    .kc-field-search svg{position:absolute;left:12px;top:50%;transform:translateY(-50%);width:15px;height:15px;color:var(--mca-ink-3);pointer-events:none}
    .kc-field-search input{padding:0 12px 0 34px}
    .kc-field-search input::placeholder{color:var(--mca-ink-3)}
    .kc-field .kc-err{margin-top:0}
    .kc-space-foot{margin-top:auto;padding-top:14px;border-top:1px solid var(--kc-line-soft);display:flex;flex-direction:column;gap:10px}
    .kc-space-foot .kc-err{margin-top:0}
    .kc-space-actions{display:flex;justify-content:space-between;gap:10px;flex-wrap:wrap}
    .kc-upload-note{font-size:12px;color:var(--mca-ink-3);margin:0 0 20px}

    /* Programa vinculado y tipo (tabla) */
    .kc-prog{display:flex;align-items:center;gap:4px;margin-top:3px;font-size:11.5px;font-weight:500;color:var(--mca-blue);max-width:150px}
    .kc-prog svg{width:12px;height:12px;flex:none}
    .kc-prog span{min-width:0;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
    .kc-prog.gone{color:var(--mca-ink-3);text-decoration:line-through}
    .kc-type{margin-top:5px;font-size:11.5px;color:var(--mca-ink-2);white-space:nowrap}
    .kc-type.unknown{color:var(--mca-warn)}

    /* Barra de herramientas */
    .kc-toolbar{display:flex;align-items:center;gap:10px;margin-bottom:14px;flex-wrap:wrap}
    .kc-search{position:relative;flex:1;min-width:180px}
    .kc-search svg{position:absolute;left:14px;top:50%;transform:translateY(-50%);width:16px;height:16px;color:var(--mca-ink-3);pointer-events:none}
    .kc-search input{width:100%;padding:10px 14px 10px 38px;border-radius:10px;border:1px solid var(--mca-card-border);background:#fff;font:inherit;font-size:13.5px;color:var(--mca-ink);transition:border-color .14s,box-shadow .14s}
    .kc-search input::placeholder{color:var(--mca-ink-3)}
    .kc-search input:focus,.kc-pill:focus-within{outline:none;border-color:var(--mca-blue);box-shadow:0 0 0 3px rgba(30,90,168,.12)}
    .kc-pill{position:relative;display:inline-flex;align-items:center;gap:6px;height:40px;padding:0 12px 0 14px;border-radius:10px;border:1px solid var(--mca-card-border);background:#fff;font-size:13px;font-weight:500;color:var(--mca-ink-2);transition:border-color .14s,box-shadow .14s}
    .kc-pill select{appearance:none;-webkit-appearance:none;border:0;background:transparent;font:inherit;font-weight:600;color:var(--mca-ink);padding:0 22px 0 0;cursor:pointer;outline:none;max-width:124px;text-overflow:ellipsis}
    .kc-pill svg{position:absolute;right:12px;top:50%;transform:translateY(-50%);width:14px;height:14px;color:var(--mca-ink-3);pointer-events:none}

    /* Contenedores de texto+icono (estados de carga). OJO: los elementos con wire:loading no
       llevan display en línea, para no anular el ocultado por defecto de Livewire. */
    .kc-inl{display:inline-flex;align-items:center;gap:8px}
    .kc-gap{align-items:center;gap:8px}

    /* Tabla */
    .kc-table-card{overflow:hidden;container-type:inline-size}
    .kc-table-scroll{overflow-x:auto}
    .kc-table{width:100%;border-collapse:collapse;font-size:13.5px}
    .kc-table thead th{background:var(--kc-head);font-size:11.5px;font-weight:700;letter-spacing:.3px;text-transform:uppercase;color:var(--mca-ink-2);text-align:left;padding:13px 12px;border:0;white-space:nowrap}
    .kc-table thead th:first-child,.kc-table tbody td:first-child{padding-left:20px}
    .kc-table thead th:last-child,.kc-table tbody td:last-child{padding-right:20px}
    .kc-table tbody td{padding:14px 12px;border-top:1px solid var(--kc-line-soft);vertical-align:middle}
    /* Columnas ajustadas a su contenido; el nombre se queda con el espacio sobrante. */
    .kc-table th.kc-fit{width:1%}
    .kc-table th.kc-col-name{min-width:150px}
    .kc-table td .kc-code{font-size:12px}
    .kc-badge.kc-cat{display:inline-block;white-space:nowrap;max-width:140px;overflow:hidden;text-overflow:ellipsis;display:inline-block}
    /* Prioridad y secciones: columnas en pantallas anchas; plegadas bajo el nombre si no caben. */
    .kc-meta-num{display:none}
    @container (max-width: 960px){
        .kc-col-num{display:none}
        .kc-meta-num{display:block}
    }
    .kc-table tbody tr:hover td{background:#FAFBFD}
    .kc-table tr.is-off td{opacity:.72}
    .kc-code{font-family:ui-monospace,SFMono-Regular,Menlo,monospace;font-size:12.5px;font-weight:600;color:var(--mca-ink-2);white-space:nowrap}
    .kc-name{font-weight:600;color:var(--mca-ink)}
    .kc-meta{font-size:11.5px;color:var(--mca-ink-3);margin-top:2px;font-weight:400}
    .kc-num{color:var(--mca-ink-2)}
    .kc-sec{display:inline-flex;align-items:center;gap:5px;color:var(--mca-ink-2)}
    .kc-sec svg{width:13px;height:13px;color:var(--mca-ink-3)}
    .kc-badge{display:inline-flex;align-items:center;gap:5px;padding:4px 10px;border-radius:7px;font-size:11.5px;font-weight:600;white-space:nowrap;line-height:1.35}
    .kc-dot{width:6px;height:6px;border-radius:50%;background:currentColor;flex:none}
    .kc-avatars{display:inline-flex;align-items:center}
    .kc-av{width:24px;height:24px;border-radius:50%;display:inline-flex;align-items:center;justify-content:center;font-size:10px;font-weight:700;border:1.5px solid #fff;box-shadow:0 0 0 1px currentColor;margin-left:-6px;flex:none}
    .kc-av:first-child{margin-left:0}
    .kc-av-more{background:var(--mca-page-bg);color:var(--mca-ink-2)}
    .kc-none{color:var(--mca-ink-3);font-size:12px;white-space:nowrap}
    .kc-actions{display:flex;justify-content:flex-end;gap:4px}
    .kc-icon-btn{width:30px;height:30px;border-radius:8px;border:1px solid var(--mca-card-border);background:#fff;display:inline-flex;align-items:center;justify-content:center;cursor:pointer;color:var(--mca-ink-2);padding:0;transition:background .14s,border-color .14s,color .14s}
    .kc-icon-btn svg{width:14px;height:14px}
    .kc-icon-btn:hover{border-color:#CBD6E6;background:#F7F9FC;color:var(--mca-ink)}
    .kc-icon-btn.go{color:var(--mca-ok)}
    .kc-icon-btn.danger{color:var(--kc-red)}
    .kc-icon-btn.danger:hover{background:#FDF3F3;border-color:#F2D0D0}
    .kc-foot{text-align:center;margin-top:16px;font-size:12px;color:var(--mca-ink-3)}
    .kc-empty{padding:44px 20px;text-align:center}
    .kc-empty .kc-ic{width:48px;height:48px;border-radius:14px;margin-bottom:10px}
    .kc-empty .kc-ic svg{width:22px;height:22px}
    .kc-empty p{margin:0;color:var(--mca-ink-2);font-size:13.5px}

    /* Interruptor */
    .kc-switch-btn{border:0;background:transparent;padding:0;cursor:pointer;display:inline-flex;align-items:center;gap:9px;font:inherit;font-size:12.5px;font-weight:600;color:var(--mca-ink)}
    .kc-switch-btn:disabled{cursor:default;opacity:.6}
    .kc-switch{position:relative;display:inline-block;width:36px;height:20px;border-radius:999px;background:#CBD5E1;transition:background .15s;flex:none}
    .kc-switch::after{content:"";position:absolute;top:2px;left:2px;width:16px;height:16px;border-radius:50%;background:#fff;box-shadow:0 1px 2px rgba(19,37,61,.25);transition:left .15s}
    .kc-switch.on{background:var(--mca-blue)}
    .kc-switch.on::after{left:18px}

    /* Por agente */
    .kc-picker{display:flex;align-items:center;gap:14px;padding:14px 18px;margin-bottom:20px;flex-wrap:wrap}
    .kc-picker-av{width:40px;height:40px;border-radius:12px;display:flex;align-items:center;justify-content:center;font-size:15px;font-weight:700;flex:none}
    .kc-picker select{border:1px solid var(--mca-card-border)!important;border-radius:10px!important;padding:8px 12px!important;font:inherit!important;font-size:13.5px!important;font-weight:600!important;color:var(--mca-ink)!important;min-width:200px}
    .kc-picker-hint{margin-left:auto;font-size:12.5px;color:var(--mca-ink-3)}
    .kc-group{margin-bottom:16px;overflow:hidden}
    .kc-group-head{display:flex;align-items:center;gap:12px;padding:14px 18px;background:var(--kc-head);border-bottom:1px solid var(--kc-line-soft);flex-wrap:wrap}
    .kc-group-title{font-size:14.5px;font-weight:700;color:var(--mca-ink)}
    .kc-group-head .kc-switch-btn{margin-left:auto}
    .kc-src{display:flex;align-items:center;gap:14px;padding:13px 18px;border-top:1px solid var(--kc-line-soft)}
    .kc-src:first-of-type{border-top:0}
    .kc-src-main{flex:1;min-width:0}
    .kc-src-tags{display:flex;align-items:center;gap:8px;flex-wrap:wrap;justify-content:flex-end}
    .kc-shared{display:inline-flex;align-items:center;gap:7px;font-size:12px;color:var(--mca-ink-2)}

    /* Secciones de «Por agente» y «Programas que puede recomendar» (Bloque 4c) */
    .kc-section-head{display:flex;align-items:flex-end;justify-content:space-between;gap:12px;margin:4px 0 12px;flex-wrap:wrap}
    .kc-section-gap{margin-top:30px}
    .kc-section-title{font-size:16px;font-weight:700;color:var(--mca-ink);margin:0}
    .kc-section-sub{font-size:12.5px;color:var(--mca-ink-2);margin:3px 0 0}
    .kc-prog-tools{margin-bottom:14px}
    .kc-prog-count{font-size:12.5px;font-weight:600;color:var(--mca-ink-2);white-space:nowrap}
    .kc-group-head:last-child{border-bottom:0}
    .kc-area-toggle{display:inline-flex;align-items:center;gap:10px;border:0;background:transparent;padding:0;cursor:pointer;font:inherit;text-align:left}
    .kc-chev{display:inline-flex;color:var(--mca-ink-3);transition:transform .15s;transform:rotate(-90deg)}
    .kc-chev.open{transform:none}
    .kc-chev svg{width:16px;height:16px}
    .kc-area-actions{margin-left:auto;display:flex;gap:8px;flex-wrap:wrap}
    .kc-prog-row{padding:10px 18px}
    .kc-prog-code{min-width:62px}
    .kc-src.is-off .kc-name,.kc-src.is-off .kc-prog-code{opacity:.6}

    /* Drawer y modal */
    .kc-overlay{position:fixed;inset:0;background:rgba(19,37,61,.38);z-index:50;backdrop-filter:blur(1px)}
    .kc-drawer{position:fixed;top:0;right:0;bottom:0;width:min(700px,94vw);background:#fff;z-index:51;box-shadow:-12px 0 40px rgba(19,37,61,.18);display:flex;flex-direction:column}
    .kc-drawer-head{display:flex;align-items:center;gap:12px;padding:16px 20px;border-bottom:1px solid var(--mca-card-border)}
    .kc-drawer-head strong{flex:1;font-size:15px}
    .kc-drawer-body{display:flex;flex:1;min-height:0}
    .kc-drawer-nav{width:210px;border-right:1px solid var(--mca-card-border);padding:16px;overflow:auto;background:var(--kc-head)}
    .kc-drawer-nav h5{font-size:11px;letter-spacing:.05em;text-transform:uppercase;color:var(--mca-ink-3);margin:0 0 10px}
    .kc-drawer-nav div{font-size:12.5px;color:var(--mca-ink-2);padding:6px 0;border-bottom:1px dashed var(--mca-card-border)}
    .kc-prose{flex:1;overflow:auto;padding:20px 24px;font-size:14px;line-height:1.65;color:var(--mca-ink)}
    .kc-prose h1{font-size:19px;margin:0 0 12px} .kc-prose h2{font-size:15.5px;margin:18px 0 6px;color:var(--mca-blue)} .kc-prose p{margin:0 0 10px}
    .kc-modal-wrap{position:fixed;inset:0;background:rgba(19,37,61,.45);z-index:60;display:flex;align-items:center;justify-content:center;padding:16px}
    .kc-modal{background:#fff;border-radius:var(--mca-radius);max-width:480px;width:100%;padding:22px 24px;box-shadow:0 24px 64px rgba(19,37,61,.28)}
    .kc-modal h2{margin:0 0 6px;font-size:16px;font-weight:700}
    .kc-modal p{margin:0 0 12px;font-size:13.5px;color:var(--mca-ink-2)}
    .kc-modal-note{background:var(--mca-warn-soft);border:1px solid #F1DDB4;border-radius:10px;padding:10px 12px;font-size:13px;color:var(--mca-ink)}
    .kc-modal-foot{display:flex;gap:8px;justify-content:flex-end;margin-top:18px}

    @media (max-width: 1100px){ .kc-stats{grid-template-columns:repeat(2,minmax(0,1fr))} }
    @media (max-width: 640px){ .kc-stats{grid-template-columns:1fr} .kc-upload-row{flex-wrap:wrap} .kc-src{flex-wrap:wrap} }
</style>
