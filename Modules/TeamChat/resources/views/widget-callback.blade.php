<!doctype html><html><meta charset="utf-8"><title>MCA Colaboración</title><body>
<p>Conexión autorizada. Puedes cerrar esta ventana. / Connected. You can close this window.</p>
<script nonce="{{ $nonce }}">if(window.opener){window.opener.postMessage({!! json_encode($payload,JSON_HEX_TAG|JSON_HEX_AMP|JSON_HEX_APOS|JSON_HEX_QUOT) !!},{!! json_encode($origin,JSON_HEX_TAG|JSON_HEX_AMP|JSON_HEX_APOS|JSON_HEX_QUOT) !!});window.close();}</script>
</body></html>
