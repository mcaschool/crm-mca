# Revisión del Centro de Comando de Seguimiento Inteligente

Fecha: 5 de noviembre de 2026. Base local: `e928b2f3a1b4d205a64573866abf37bcac9472e9`.
Alcance: perfeccionamiento funcional y UI/UX de FollowUp. Sin nueva etapa comercial,
motor de IA, conexiones Meta, cambios reactivos ni activación de envíos.

## Diagnóstico inicial

- La configuración presentaba dos formularios sin mostrar conexiones reales,
  identidad/estado del asesor ni la diferencia entre asignación y permiso proactivo.
- En móvil, el administrador debía recorrer primero la configuración institucional
  para alcanzar al asesor. El control de parada estaba al final.
- El modo individual estaba prohibido por el servicio: solo heredaba la institución.
- La selección por proveedor no distinguía dos conexiones WhatsApp del mismo asesor.
- Guardar un asesor cancelaba acciones de todos los asesores de la institución.
- Una zona horaria individual distinta desplazaba las franjas institucionales;
  también podía rechazarse la hora inicial exacta al comparar H:i con H:i:s.
- La configuración no tenía enlaces que conservaran el asesor desde una oportunidad.

## Organización y mejoras

Se conservan rutas y navegación de Resumen, Oportunidades, Seguimientos, Borradores,
Atención humana, Configuración, Historial y Analítica. La configuración ahora ofrece:

1. Control de ejecución visible al inicio y parada institucional confirmada.
2. Jerarquía institucional explicada antes de los formularios.
3. Selector de identidades existentes, estado activo y proactividad configurada.
4. Avatar existente o inicial, canales asignados y resumen de reglas guardadas.
5. Modo individual, matriz de canales reales, frecuencia, días y franja del asesor.
6. Límites institucionales separados, con enlace directo desde el asesor.
7. Supervisión: colas, errores, acciones, historial y switches persistidos.

Se reutilizan page-header, button, input, cards, navegación y variables visuales
del CRM. CSS limitado a FollowUp; sin nuevas librerías, fuentes ni cambios de
identidad global. La matriz usa tarjetas con detalle y selector, apiladas en móvil.
Controles táctiles de 44 px, etiquetas, foco visible, errores con foco y estados
textuales. Las confirmaciones de guardado se muestran después del servicio.
Los indicadores de oportunidades se compactaron para evitar tres tarjetas altas
con cajas anidadas en móvil. Los códigos visibles de recomendación, vínculo y
auditoría se presentan mediante Presentation, sin alterar valores persistidos.

Durante la prueba real se detectó que el menú móvil compartido no cerraba con
Escape. La única corrección fuera de FollowUp son dos líneas del layout: cierre
con Escape, retorno de foco al botón y aria-expanded. No cambia la identidad ni
el diseño global. Se verificó el comportamiento en navegador y la regresión del
layout existente.

## Persistencia y seguridad

Se añade una migración aditiva con `mode` nullable y `allowed_channel_ids` JSON
nullable a `follow_up_advisor_settings`. Los registros previos conservan herencia;
la matriz guarda IDs explícitos. Un array vacío bloquea todos los canales; no se
interpreta como autorización implícita. La carga respeta restricciones antiguas
por proveedor. Los IDs validados se normalizan a enteros.

FollowUpSettingsService mantiene validación, transacción, versiones, auditoría e
invalidación. Guardar un asesor solo invalida sus propias acciones pendientes.
Los resultados ya despachados permanecen; el historial no se elimina. No se escribe
en SocialChannel ni Bot al configurar FollowUp. Los canales aparecen por lectura
con eager loading; no se incluye contenido de credenciales en la vista.

Los modos se restringen por orden manual → supervisado → automático controlado.
Un asesor no puede superar la autonomía institucional; si esta se reduce, el modo
efectivo también se restringe. Un asesor inactivo no puede habilitar ni preparar
acciones. Ninguna selección de modo abre switches de ejecución.

La matriz rechaza otros tenants, otro asesor, canales inactivos/desconectados,
atención del asesor deshabilitada y proveedores sin contrato proactivo. Cloud API
y Coexistence solo se muestran cuando existen en los datos. Instagram/Messenger
se muestran no disponibles, con explicación. El motor revalida asignación y selección
del canal, además de consentimiento, vigencia, límites y protecciones previas.
La reevaluación existente cancela acciones incompatibles; la pantalla no reasigna
canales ni introduce otra vía de envío.

La zona horaria del asesor se hereda de la institución, visible y no editable de
forma independiente: garantiza que restringir horas no desplace la ventana real.
Los ajustes antiguos con zona discrepante quedan bloqueados hasta guardar su
revisión. Esta restricción conservadora es intencional; no se implementan ventanas
individuales en otra zona ni franjas nocturnas. La institución conserva su selector
de zona IANA. Los límites exactos de la franja son aceptados correctamente.

El enlace desde una oportunidad conserva `asesor` en la URL de configuración,
validado con el scope institucional; no permite consultar asesores ajenos.

## Validaciones automatizadas

| Comprobación | Resultado |
|---|---|
| FollowUp + Social + AI + Institutions + traducciones | **1.043 pruebas correctas, 6.050 aserciones**, 778,56 s |
| Configuración, políticas y traducciones tras últimos cambios de navegación | **70 correctas, 283 aserciones**, 54,46 s |
| Workspace, layout compartido y traducciones después de revisión visual | **51 correctas, 300 aserciones**, 64,01 s |
| Pint `--test --dirty` final | **13 archivos PHP correctos** |
| PHPStan/Larastan FollowUp + gateway Social | **Sin errores** |
| `npm run build` final | **Correcto**, Vite 8.2.1, 833 ms |
| Siete migraciones acumuladas | Instalación limpia, rollback inverso, reinstalación incremental y conservación del contacto ficticio: **correctos** |
| Git diff check | Sin errores de espacios |

Las suites se solapan: no sumar como pruebas únicas. Todas las pruebas usaron
MySQL `crm_mca_test`; proveedores simulados y prevención de llamadas imprevistas
en las pruebas FollowUp. No se repitió la suite global completa de 1.696 pruebas
del cierre anterior: se ejecutaron las regresiones relacionadas solicitadas.
Las dos incidencias de PHPStan global y cuatro de Pint global documentadas en
ETAPA_8.md no pertenecen a estos cambios y no se silenciaron ni corrigieron aquí.

Se amplió FollowUpWorkspaceTest con persistencia, selección de asesor y URL,
aislamiento, IDs ajenos, reasignación, desconexión, Instagram/Messenger, límites,
modos y reducción institucional, zona horaria, pausa obligatoria, horario exacto,
días, asesor inactivo, cero canales, auditoría, conservación de otras acciones y
de resultados desconocidos, ausencia de cambios reactivos, compatibilidad de
restricciones heredadas y ausencia de N+1 al añadir doce canales. Las regresiones
existentes cubren aprobación/versiones, colas, concurrencia y permisos.

La primera pasada detectó dos expectativas de texto obsoletas; la siguiente,
una expectativa de test sobre ModelNotFoundException en Livewire. Se adaptaron
al nuevo texto y al rechazo real del ID ajeno; no se relajó el aislamiento.
El primer análisis estático indicó un acceso nullsafe redundante, corregido.
Los resultados de la tabla son los posteriores a esas correcciones.

Los assets regenerados incidentalmente por Tailwind se restauraron a su estado
inicial: persiste la dependencia preexistente de storage/framework/views, ya
documentada. Las mejoras de esta revisión se sirven en Blade y estilos del
módulo; no se publica un cambio de CSS compilado dependiente de caché local.

## Verificación real en navegador

Se abrió la aplicación local en `127.0.0.1:8910`, APP_ENV=testing, base
`crm_mca_test`, ejecución global apagada, correo array, sin workers. Datos
ficticios de tres asesores, seis canales y cuatro oportunidades. Se utilizó la
autenticación existente; no se cambiaron credenciales operativas.

- **Escritorio 1440×1000:** resumen, bandeja de borradores, detalle/contexto,
  edición, aprobación y configuración con asesor seleccionado desde la oportunidad.
- **Tablet 768×1024:** formulario institucional y matriz de conexiones.
- **Móvil 390×844:** selección de asesor, matriz apilada, guardado de reglas,
  parada, navegación/menú, oportunidades y búsqueda vacía, seguimientos como
  tarjetas, atención humana, actividad de Agenda, historial y analítica.
- Se abrió Social Inbox desde la conversación original; no se pulsó enviar,
  tomar conversación ni pausar su automatización reactiva.

Recorrido A: Sofía guardó modo manual, primer intervalo 2 h, mínimo 3 h, máximo
2 intentos, 09:00–18:00 y solo canal #1. Se verificó después de recargar. Daniel
pasó de reglas deshabilitadas a habilitadas con su canal #6, heredando los límites;
esto no abrió ningún switch de ejecución. Se guardó también el primer intervalo
institucional de 2 h con confirmación explícita y se verificó la persistencia.

Recorrido B: se abrió el borrador ficticio de Elena, se editó, se guardó versión 2
y se aprobó tras revisar contenido exacto, destinatario, consentimiento, fecha y
advertencias. La pantalla confirmó «No se ha enviado ningún mensaje». El guardado
posterior de reglas invalidó esa acción, como exige el servicio existente.

Recorrido C: una propuesta a las 14:50 UTC se registró bloqueada por intervalo
mínimo. Otra evaluación a las 16:00 UTC quedó pendiente de aprobación. No se
eludió la política ni se generó/envió contenido mediante proveedor real.

Recorrido D: se pulsó Detener nuevos envíos, se aceptó la confirmación y el
servidor confirmó la parada. La fila institucional quedó desactivada y se
conservaron historial, mensajes y el resultado desconocido del fixture.

También se probó un horario inválido 18:00–09:00: mensaje funcional de rechazo y
foco en role=alert. Tab mueve el foco del selector a la habilitación del asesor;
el contorno observado es visible (2,4 px en el navegador). El menú pasa
aria-expanded true→false al pulsar Escape y devuelve el foco a Abrir menú.
Las etiquetas de modo se simplificaron para evitar que el lector anuncie todas
las opciones como parte del nombre del control.

Sin desbordamiento horizontal en la configuración móvil (scrollWidth 375 px
con viewport 390 px). Contraste calculado de los colores realmente renderizados
sobre superficie blanca: texto 15,44:1, secundario 7,46:1, atenuado 5,43:1 y azul
6,81:1. Los estados incluyen texto, no dependen solo de color. No equivale a una
certificación WCAG completa ni a una prueba con lector de pantalla.

La consola no registró errores nuevos durante la aceptación final. Conservaba
un error JSON/HTML anterior, mientras se reinicializaba la base ficticia para
tests; no apareció en los recorridos finales. Se cerraron pestaña y servidor
local y se restableció el viewport antes de la regresión final.

Evidencia final leída directamente de la base de prueba, antes de esa regresión:
**8 mensajes originales, 0 despachos, 0 jobs, 0 switches habilitados**, ejecución
global false y switch institucional false. Los dos asesores conservaron sus IDs,
modos y selecciones de canales guardados. Log: `storage/logs/followup-ux-visual-verification.log`.

Capturas locales fuera del repositorio (no contienen datos operativos), bajo
`C:/Users/princ/.codex/visualizations/2026/11/04/01a1a81e-a8d1-7bb3-b099-8ee3298b5259/`:
`followup-config-desktop.jpg`, `followup-config-mobile.jpg`,
`followup-channels-tablet.jpg` y `followup-channels-mobile.jpg`.
Logs de pruebas y calidad: `storage/logs/followup-ux-*.log` (ignorados por Git).

## Archivos modificados

Dentro de `Modules/FollowUp/`:

- `app/Data/EffectiveFollowUpSettings.php`
- `app/Livewire/Settings.php`
- `app/Models/FollowUpAdvisorSetting.php`
- `app/Services/ActionInvalidator.php`
- `app/Services/FollowUpPolicyEngine.php`
- `app/Services/FollowUpSettingsResolver.php`
- `app/Services/FollowUpSettingsService.php`
- `app/Support/Presentation.php`
- `app/Support/AdvisorChannels.php` (nuevo)
- `database/migrations/2026_11_05_120000_add_advisor_controls_to_follow_up_settings.php` (nuevo)
- `resources/views/livewire/settings.blade.php`
- `resources/views/livewire/opportunities.blade.php`
- `resources/views/partials/navigation.blade.php`
- `tests/Feature/FollowUpPolicyTest.php`
- `tests/Feature/FollowUpWorkspaceTest.php`
- `tests/Support/visual-preview.php`
- `DEPLOYMENT.md`
- `COMMAND_CENTER_REVIEW.md` (nuevo)

Fuera del módulo: `resources/views/layouts/app.blade.php`, exclusivamente la
corrección de teclado/estado del botón del menú compartido.

## Limitaciones y cierre

Aceptación visual realizada en español y en el navegador local disponible. No
se certifican todos los navegadores, lectores de pantalla, dispositivos físicos,
todos los cruces de roles/tamaños ni errores de red simulados. Permisos e
instituciones ajenas se verificaron mediante tests; no se habilitaron conexiones
reales para comprobar entrega. La regeneración IA se cubre con dobles automáticos,
no con llamadas reales en la navegación.

La zona horaria individual independiente y las franjas nocturnas no están
disponibles: el asesor restringe la ventana institucional en su misma zona. Las
pausas obligatorias no tienen controles para desactivarse. Estas son restricciones
funcionales explícitas, no botones decorativos ni envíos ocultos.

Revisión implementada y validada localmente. No hay defectos críticos conocidos
introducidos por este cambio; no se promete ausencia absoluta de errores. Se
conservan intactos `capturas-publicador/` y `evaluacion_sophia_30_preguntas.md`.
Sin push, PR, publicación de ramas, despliegue, SSH, cambios productivos, cambios
de credenciales, llamadas reales a Meta ni comunicaciones reales. El hash del
commit local se entrega en la respuesta final. Se espera autorización antes de
cualquier publicación o despliegue.
