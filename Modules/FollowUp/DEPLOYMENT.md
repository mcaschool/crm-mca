# Preparación de publicación controlada — MCA CRM

Este documento prepara una operación futura. No autoriza ni acredita publicación,
despliegue, acceso SSH ni activación. Requiere decisiones independientes del usuario.
El procedimiento de referencia de ESTE CRM es
`docs/DEPLOYMENT-SECURITY-CHECKLIST.md`; no se reutiliza el de MCA Workspace.

## Condiciones previas

- Confirmar versión local aceptada, respaldo restaurable de MySQL y archivos,
  ventana de mantenimiento y responsable de recuperación.
- Confirmar en hPanel el destino real, document root, binario PHP >=8.3,
  extensiones exigidas por Composer, MySQL, memoria y límites de conexiones.
  No hay una ruta real de Hostinger verificada en esta auditoría. `RUTA_BASE` en
  el procedimiento oficial es un marcador, no un destino confirmado.
- Confirmar que el plan admite cron por minuto y consumidores de colas. No copiar
  una configuración de Supervisor a un hosting compartido sin soporte confirmado.
- Mantener la misma `APP_KEY`: los borradores y credenciales existentes están
  cifrados. **No ejecutar key:generate durante una actualización.**
- Separar publicación del código, despliegue y activación comercial. Poder ver el
  módulo no autoriza llamadas de IA ni envíos. Configuración conservadora inicial:
  `FOLLOW_UP_DETECTION_ENABLED=false`, `FOLLOW_UP_POLICY_ENABLED=false`,
  `FOLLOW_UP_EXECUTION_ENABLED=false`. No crear interruptores habilitados por SQL.
- Revisar acciones/colas antiguas antes de arrancar consumidores. No ejecutar
  `queue:retry all`: resultados ambiguos requieren conciliación.
- La cadencia de WhatsApp nace desactivada. Configurar sus reglas no activa envíos.
  Mensajes históricos sin evidencia de timestamp del proveedor no habilitan texto
  libre: esperar una nueva entrada verificable, sin inventar un backfill temporal.

## Secuencia futura, solo después de autorización

1. Publicar exclusivamente el commit aprobado cuando se autorice GitHub.
2. Con autorización separada de despliegue, respaldar y poner mantenimiento según
   el procedimiento oficial. Transferencia/checkout y ruta deben acordarse con el
   procedimiento real del servidor; esta auditoría no inventa comandos rsync/SSH.
3. Instalar dependencias de producción desde el lock (`composer install --no-dev
   --optimize-autoloader`) y entregar los assets de `npm run build`. No actualizar
   dependencias durante el despliegue. No usar `composer run setup` en una
   instalación existente: incluye generación de clave y migraciones.
4. Revisar `migrate:status` y la copia de seguridad; ejecutar `migrate --force`
   únicamente con la autorización correspondiente de producción. No `fresh`,
   `refresh`, `reset` ni scripts de pruebas. Las ocho migraciones FollowUp añaden
   tablas/campos; no alteran Leads existentes ni habilitan ejecución.
5. Regenerar cachés de configuración, rutas y vistas según el procedimiento
   oficial. Mantener APP_DEBUG=false y cookies/HTTPS existentes. No modificar
   credenciales como parte de la entrega. Verificar que el entorno efectivo
   conserva ejecución desactivada, incluyendo caché de configuración.
6. Pruebas de humo sin comunicaciones: login/2FA, permisos, separación institucional,
   navegación, estados vacíos/datos autorizados, auditoría y lectura de configuración.
   No pulsar «probar conexión» de proveedores como sustituto de autorización.
7. Mantener apagados los consumidores de ejecución proactiva hasta autorización
   expresa de activación y verificación de capacidad de canal y consentimiento.

## Colas y scheduler

Los jobs usan la conexión `database` explícitamente. Deben existir `jobs`,
`failed_jobs` y almacenamiento/cache compartidos y persistentes para bloqueos.
`QUEUE_CONNECTION=sync` no convierte estos jobs explícitos en un worker.

| Cola | Job | Intentos | Timeout del job |
|---|---|---:|---:|
| followup-drafts | GenerateFollowUpDraft | 1 | 75 s |
| followup-execution | ExecuteFollowUp | 1 | 60 s |

`retry_after` de database es 90 s por defecto; debe ser mayor que el timeout real.
La llamada IA está acotada a 60 s. El worker debe respetar esos límites. El worker
existente de Social Inbox consume `social-advisor`, no estas dos colas.

En un entorno que soporte workers supervisados, el responsable deberá configurar
consumidores separados por cola, reinicio por versión y cierre limpio. En hosting
compartido, verificar antes si admite consumidores acotados con `--stop-when-empty`,
`--max-time`, exclusión mutua y tiempo suficiente para finalizar un job. Un cron
que mata procesos antes del timeout no es aceptable. Esta infraestructura no se
creó ni activó aquí; su confirmación es condición previa de operación.

Conservar **una sola** entrada existente `schedule:run`. La alternativa mensual
del documento histórico solo cubre retención y no sirve para FollowUp. Registros:
detect cada 5 min (flag), maintain cada 5 min, commercial-sync cada 5 min y dispatch
cada minuto (flag); todos con exclusión de solapamiento. Analítica se reconstruye
por comando o petición explícita, no por un cron nuevo. No ejecutar el scheduler
completo como prueba inocua: también invoca publicador, recordatorios y otras
funciones que pueden comunicar. Revisar `schedule:list` antes de activar cron.

La frase histórica «no hay workers» del checklist describe el chatbot antiguo;
no representa el módulo FollowUp actual. No habilitar seguimiento hasta resolver
esa diferencia con las capacidades reales del plan contratado.

## Supervisión y recuperación

- Vigilar antigüedad de jobs, failed_jobs, reservas >3 minutos, `delivery_unknown`,
  rechazos por política, integridad de consentimientos y edad de la proyección.
- Registrar correlación de acción/mensaje sin volcar borradores, tokens o PII.
- En incidente, apagar primero ejecución global e institucional mediante los
  mecanismos existentes, detener consumidores afectados y conservar evidencia.
- Un despacho confirmado en BD puede haber llegado al proveedor aunque la respuesta
  HTTP no llegue. **No reenviar automáticamente** ni cambiar unknown a failed por
  intuición. Conciliar identificador/webhook; escalar a revisión humana si falta.
- Si falla un despliegue, conservar APP_KEY y restaurar el código compatible con
  el esquema. Preferir corrección hacia delante. El rollback de migraciones
  FollowUp elimina historial/borradores y no es una recuperación inocua.
- Si es necesaria restauración de respaldo, detener escrituras y consumidores,
  registrar intervalo perdido y conciliar despachos externos posteriores al respaldo
  antes de reactivar. La reversión del código no revierte mensajes externos.
- No ejecutar fixtures ni scripts `stage8-*` en el servidor. Exigen testing y
  `crm_mca_test`, y el script de migraciones destruye esa BD desechable.

La aprobación de este plan no constituye autorización de ejecución.
