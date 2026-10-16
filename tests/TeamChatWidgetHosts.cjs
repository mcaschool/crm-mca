// Independent loopback origins; simulated applications, never actual SIGAC or Moodle.
const http=require('node:http');
const ports={crm:33320,sigac:33321,moodle:33322};
const servers=Object.entries(ports).map(([name,port])=>http.createServer((req,res)=>{
 res.writeHead(200,{'Content-Type':'text/html; charset=utf-8','Cache-Control':'no-store'});
 res.end('<!doctype html><html lang="es"><meta charset="utf-8"><meta name="viewport" content="width=device-width"><title>'+name+' · local widget test</title><style>body{font:20px system-ui;background:#f2f4f8;margin:40px}button{background:#f00}textarea{border:15px solid red}</style><h1>'+name.toUpperCase()+' · aplicación simulada</h1><p>Contenido independiente de la aplicación anfitriona.</p><input id="host-input" placeholder="Formulario anfitrión"><button id="host-button">Acción anfitriona</button><script>document.querySelector("#host-button").onclick=()=>document.body.dataset.hostAction="ok"</script><script defer src="http://127.0.0.1:33319/collaboration/widget.js" data-app="local-'+name+'"></script></html>');
}).listen(port,'127.0.0.1',()=>console.log('HOST '+name+' '+port)));
// Real loopback reverse proxy over four PHP workers (Windows PHP dev server is serial).
let worker=0;
servers.push(http.createServer((req,res)=>{
 const upstream=http.request({host:'127.0.0.1',port:33323+(worker++%4),method:req.method,path:req.url,headers:req.headers},r=>{res.writeHead(r.statusCode,r.headers);r.pipe(res);});
 upstream.on('error',()=>{if(!res.headersSent)res.writeHead(502);res.end();});req.pipe(upstream);
}).listen(33319,'127.0.0.1'));
process.on('SIGINT',()=>{servers.forEach(s=>s.close());});
