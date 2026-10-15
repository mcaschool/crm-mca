# Zoom — inicio de reuniones como anfitrión desde el CRM: prueba piloto real

Las pruebas automáticas simulan Zoom. No demuestran que **nuestro plan y nuestra cuenta** permitan
iniciar una reunión con el enlace de inicio sin credenciales. Antes de habilitar el botón para
todos, hay que hacer esta prueba real con **una sola sala**.

## Cómo funciona (resumen)

- Cada usuario con acceso al perfil de reuniones ve **Mi sala de Zoom** en Calendario › Perfil de
  reuniones, con «Iniciar mi reunión como anfitrión» y «Copiar enlace para invitados».
- El botón envía un POST (con CSRF, límite de 10 por minuto y **sin ningún id**: siempre es la sala
  de quien lo pulsa). El CRM pide a Zoom `GET /meetings/{id}`, comprueba la sala, el anfitrión, el
  enlace de invitados, la seguridad y la capacidad, y redirige (303, sin caché ni Referer) al
  `start_url` recién obtenido. El `start_url` no se guarda, no se registra y no aparece en el HTML.
- Solo funciona con salas **protegidas**: sala de espera activa, sin «unirse antes que el
  anfitrión» y con contraseña, verificadas en Zoom.
- Está **desactivado por defecto**. Se habilita en Integraciones › Videoconferencias ›
  «Iniciar reuniones desde el CRM»: *Desactivado* · *Prueba piloto* (un usuario) · *Todos*.

## Requisitos antes de la prueba

1. Desplegar la rama y ejecutar `php artisan migrate` (añade la columna `secure` a
   `crm_video_rooms`; no cambia enlaces ni salas).
2. En la app Server-to-Server OAuth de Zoom, el scope de lectura de reuniones del administrador
   (p. ej. `meeting:read:meeting:admin` o `meeting:read:admin`) además del de escritura ya usado.
   Sin él, Zoom no devuelve el `start_url`.
3. En Integraciones › Videoconferencias: «Probar conexión» de Zoom correcta.

## Procedimiento

1. **Elegir el piloto**: un usuario SIN licencia individual (el caso nuevo) que tenga sala de Zoom
   generada. Opcional después: repetir con un usuario con licencia.
2. **Proteger las salas**: pulsar «Aplicar configuración segura». Comprobar que la sala del piloto
   aparece como *Protegida*. Si aparece *Sin proteger*, la cuenta de Zoom bloquea alguna opción
   (sala de espera o «unirse antes»): revisar la configuración de la cuenta en Zoom antes de seguir.
3. **Habilitar solo el piloto**: «Iniciar reuniones desde el CRM» → *Prueba piloto* → elegir el
   usuario → Guardar.
4. **Iniciar** (el piloto, con su sesión del CRM, en un navegador SIN sesión de Zoom iniciada):
   Perfil de reuniones › Mi sala de Zoom › «Iniciar mi reunión como anfitrión».
   - Esperado: se abre Zoom y la reunión se inicia **con controles de anfitrión** sin pedir usuario
     ni contraseña de Zoom.
5. **Invitado**: desde otro dispositivo, abrir el enlace copiado con «Copiar enlace para invitados».
   - Esperado: queda en la **sala de espera** y no entra hasta que el piloto lo admite.
6. **Controles**: el piloto admite, silencia, expulsa y finaliza la reunión.
7. **Capacidad** (si la capacidad configurada es 1): con la reunión del piloto en curso, otro usuario
   sin licencia intenta iniciar la suya (habilitarlo temporalmente o comprobar desde la API).
   - Esperado: el CRM avisa de que el anfitrión institucional ya tiene una reunión en curso.
8. **Segundo clic**: con la reunión ya iniciada, volver a pulsar el botón.
   - Esperado: vuelve a entrar como anfitrión a la MISMA reunión (no se crea otra; el enlace de
     invitados no cambia).
9. **Comprobar que no queda rastro**: en el registro de auditoría aparece «Reunión de Zoom iniciada
   como anfitrión» sin enlaces; en `storage/logs` no aparece `zak=` ni `/s/`.

## Resultado y siguiente paso

- Si los pasos 4–8 se cumplen: cambiar a *Todos*.
- Si Zoom pide iniciar sesión, muestra «otra reunión en curso» sin motivo o el invitado entra sin
  sala de espera: dejar *Desactivado* y revisar con Zoom la configuración de la cuenta/plan (los
  usuarios siguen pudiendo compartir el enlace de invitados como hasta ahora).

## Limitaciones de Zoom que se mantienen

- Las salas de usuarios sin licencia pertenecen al anfitrión técnico institucional: comparten su
  capacidad (Pro: 1 reunión a la vez; Business, Education y Enterprise: hasta 2; más, con
  complementos de Zoom). La capacidad configurada en el CRM debe coincidir con el plan.
- Quien inicia una reunión del anfitrión institucional actúa con esa identidad en esa reunión.
- El `start_url` caduca (Zoom lo renueva en cada consulta): por eso se pide en el momento del clic.
- Si el navegador ya tiene una sesión de Zoom de otra cuenta, Zoom puede pedir cambiar de cuenta.
