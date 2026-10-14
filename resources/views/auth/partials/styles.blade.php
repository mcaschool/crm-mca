{{--
  Estilos de las pantallas de ACCESO (login, recuperar y restablecer contraseña, verificación en
  dos pasos). Es el diseño aprobado del login (v4): fondo institucional, tarjeta translúcida, campos
  con icono y acción principal dorada. Se comparte para que todo el flujo de acceso se lea como una
  sola pantalla; el panel autenticado usa x-ui.kit. Solo capa visual.
--}}
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=DM+Sans:opsz,wght@9..40,400;9..40,500;9..40,600;9..40,700&display=swap" rel="stylesheet">
<style>
    :root{
        --brand:#1E5AA8; --brand-glow:#2E74C9;
        --gold:#C9A84C; --gold-hi:#E4CB84;
        --ink:#EAF1FB; --muted:#9FB4D6;
        --glass:rgba(255,255,255,.07); --glass-brd:rgba(255,255,255,.16);
        --field:rgba(6,18,38,.42); --field-brd:rgba(255,255,255,.14);
    }
    *{box-sizing:border-box;margin:0;padding:0}
    html,body{height:100%}
    body{
        font-family:"DM Sans",system-ui,sans-serif; color:var(--ink);
        min-height:100vh; display:grid; place-items:center; position:relative; overflow-x:hidden;
        background:
            radial-gradient(120% 70% at 50% -8%, rgba(201,168,76,.12), transparent 45%),
            linear-gradient(180deg, rgba(6,18,38,.45) 0%, rgba(5,15,30,.72) 60%, rgba(3,11,22,.9) 100%),
            url('{{ asset('images/auth/bg-login.jpg') }}') center/cover no-repeat fixed,
            #061630;
    }
    .scene{position:fixed;inset:0;z-index:0;pointer-events:none;overflow:hidden}
    .scene .vignette{position:absolute;inset:0;background:radial-gradient(120% 90% at 50% 42%, transparent 52%, rgba(2,8,18,.72) 100%)}

    .wrap{position:relative;z-index:2;width:100%;display:grid;place-items:center;padding:32px 20px}
    .card{
        width:100%;max-width:430px; background:var(--glass);
        border:1px solid var(--glass-brd); border-radius:24px;
        padding:38px 38px 30px;
        backdrop-filter:blur(26px) saturate(120%); -webkit-backdrop-filter:blur(26px) saturate(120%);
        box-shadow:0 30px 80px -20px rgba(0,0,0,.62), inset 0 1px 0 rgba(255,255,255,.22);
        position:relative; overflow:hidden;
        animation:rise .7s cubic-bezier(.16,1,.3,1) both;
    }
    .card::before{content:"";position:absolute;top:0;left:24px;right:24px;height:2px;border-radius:2px;
        background:linear-gradient(90deg,transparent,var(--gold),transparent);opacity:.85}
    @keyframes rise{from{opacity:0;transform:translateY(14px) scale(.985)}to{opacity:1;transform:none}}
    @media (prefers-reduced-motion:reduce){.card{animation:none}}

    .lang{position:absolute;top:16px;right:20px;display:flex;gap:2px;font-size:12.5px;font-weight:600;z-index:3}
    .lang button{background:none;border:0;color:var(--muted);cursor:pointer;padding:4px 6px;border-radius:6px;font-family:inherit;font-weight:600;font-size:12.5px;transition:color .2s,background .2s}
    .lang button.on{color:var(--ink)}
    .lang button:hover{color:var(--ink);background:rgba(255,255,255,.06)}
    .lang button:focus-visible{outline:0;box-shadow:0 0 0 2px rgba(228,203,132,.55)}
    .lang .sep{color:rgba(159,180,214,.45);align-self:center}

    .brand{display:flex;justify-content:center;margin:6px 0 24px}
    .brand img{height:52px;width:auto;max-width:230px;object-fit:contain;filter:drop-shadow(0 2px 10px rgba(0,0,0,.35))}

    .head{text-align:center;margin-bottom:24px}
    .head h1{font-size:23px;font-weight:700;letter-spacing:-.01em}
    .head p{font-size:14px;line-height:1.5;color:var(--muted);margin-top:6px}
    .head-ic{width:44px;height:44px;margin:0 auto 14px;border-radius:12px;display:grid;place-items:center;color:var(--gold-hi);background:rgba(201,168,76,.12);box-shadow:inset 0 0 0 1px rgba(228,203,132,.28)}
    .head-ic svg{width:20px;height:20px}

    .alert{display:flex;align-items:flex-start;gap:9px;border-radius:12px;padding:11px 13px;font-size:13px;line-height:1.4;margin-bottom:18px}
    .alert svg{width:16px;height:16px;flex:0 0 auto;margin-top:1px}
    .alert-danger{background:rgba(222,70,70,.14);border:1px solid rgba(233,96,96,.42);color:#ffd9d9}
    .alert-ok{background:rgba(64,190,124,.13);border:1px solid rgba(96,212,146,.4);color:#c9f6db}

    .field{margin-bottom:15px}
    .field label{display:block;font-size:12.5px;font-weight:600;color:var(--muted);margin-bottom:7px;padding-left:2px}
    .input{display:flex;align-items:center;gap:10px;background:var(--field);border:1px solid var(--field-brd);
        border-radius:13px;padding:0 14px;height:50px;transition:border-color .2s,box-shadow .2s,background .2s}
    .input:focus-within{border-color:var(--brand-glow);box-shadow:0 0 0 3px rgba(46,116,201,.28);background:rgba(6,18,38,.55)}
    .input.has-error{border-color:rgba(233,96,96,.65);box-shadow:0 0 0 3px rgba(222,70,70,.18)}
    .input svg{width:18px;height:18px;color:var(--muted);flex:0 0 auto}
    .input input{flex:1;min-width:0;background:none;border:0;outline:0;color:var(--ink);font-family:inherit;font-size:15px;height:100%}
    .input input::placeholder{color:rgba(159,180,214,.7)}
    .input input[readonly]{color:var(--muted)}
    .input--code input{text-align:center;font-size:22px;font-weight:600;letter-spacing:.42em;font-variant-numeric:tabular-nums;padding-left:.42em}
    .eye{background:none;border:0;cursor:pointer;color:var(--muted);display:grid;place-items:center;padding:4px;border-radius:6px;transition:color .2s}
    .eye:hover{color:var(--ink)}
    .eye:focus-visible{outline:0;box-shadow:0 0 0 2px rgba(46,116,201,.6)}
    .err{display:block;font-size:12px;color:#ffb4b4;margin-top:6px;padding-left:2px}
    .hint{display:block;font-size:12px;line-height:1.45;color:var(--muted);margin-top:6px;padding-left:2px}

    .row{display:flex;align-items:center;justify-content:space-between;gap:10px 16px;flex-wrap:wrap;margin:16px 0 22px}
    .remember{display:flex;align-items:center;gap:9px;cursor:pointer;font-size:13.5px;color:var(--muted);user-select:none}
    .remember input{position:absolute;opacity:0;width:0;height:0}
    .box{width:18px;height:18px;border-radius:6px;border:1.5px solid rgba(255,255,255,.3);background:rgba(255,255,255,.04);display:grid;place-items:center;transition:all .18s}
    .box svg{width:12px;height:12px;color:#1a1205;opacity:0;transform:scale(.6);transition:all .18s}
    .remember input:checked + .box{background:var(--gold);border-color:var(--gold)}
    .remember input:checked + .box svg{opacity:1;transform:scale(1)}
    .remember input:focus-visible + .box{box-shadow:0 0 0 3px rgba(201,168,76,.35)}
    .forgot{font-size:13.5px;color:#bcd0ee;text-decoration:none;font-weight:500;border-radius:4px;transition:color .2s}
    .forgot:hover{color:#fff}
    .forgot:focus-visible{outline:2px solid rgba(46,116,201,.8);outline-offset:2px}

    .btn{width:100%;height:52px;border:0;border-radius:13px;cursor:pointer;font-family:inherit;font-size:15.5px;font-weight:700;letter-spacing:.01em;color:#241a05;
        background:linear-gradient(180deg,var(--gold-hi),var(--gold));
        box-shadow:0 12px 26px -10px rgba(201,168,76,.6),inset 0 1px 0 rgba(255,255,255,.5);
        transition:transform .15s,box-shadow .2s,filter .2s}
    .btn:hover{transform:translateY(-1px);box-shadow:0 16px 30px -10px rgba(201,168,76,.7),inset 0 1px 0 rgba(255,255,255,.55);filter:brightness(1.03)}
    .btn:active{transform:translateY(0)}
    .btn:focus-visible{outline:0;box-shadow:0 0 0 3px rgba(228,203,132,.5)}
    .actions{margin-top:22px}

    /* alternativa plegable (código de recuperación del 2FA) */
    .alt{margin-top:4px;border-top:1px solid rgba(255,255,255,.1);padding-top:14px}
    .alt summary{display:flex;align-items:center;gap:8px;list-style:none;cursor:pointer;font-size:13.5px;font-weight:500;color:#bcd0ee;border-radius:6px}
    .alt summary::-webkit-details-marker{display:none}
    .alt summary svg{width:16px;height:16px;flex:0 0 auto;transition:transform .2s}
    .alt[open] summary svg{transform:rotate(90deg)}
    .alt summary:hover{color:#fff}
    .alt summary:focus-visible{outline:2px solid rgba(46,116,201,.8);outline-offset:2px}
    .alt .field{margin:14px 0 0}

    .back{display:flex;align-items:center;justify-content:center;gap:6px;margin-top:18px;font-size:13.5px;font-weight:500;color:#bcd0ee;text-decoration:none;border-radius:6px}
    .back svg{width:16px;height:16px}
    .back:hover{color:#fff}
    .back:focus-visible{outline:2px solid rgba(46,116,201,.8);outline-offset:2px}

    .note{display:flex;align-items:center;justify-content:center;gap:7px;margin-top:18px;font-size:12.5px;color:var(--muted)}
    .note svg{width:14px;height:14px;opacity:.8}
    .foot{position:relative;z-index:2;text-align:center;font-size:12px;color:rgba(159,180,214,.6);padding:0 20px 26px;margin-top:-6px}

    @media (max-width:480px){.card{padding:32px 24px 26px;border-radius:20px}.head h1{font-size:21px}.brand img{height:46px}}
</style>
