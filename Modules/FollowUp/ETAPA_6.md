# Etapa 6 — Experiencia administrativa de Seguimiento Inteligente

## Alcance y arquitectura

Implementación local sobre los commits de las etapas 1–5, comprobados en el historial: `df81ac56907bd137a4771f4fbdef797837c92424`, `192f3a9c527cf0c15804007ec4615ef9dbd1d238`, `a9556124db355af01c6646ff7014033baae56d01`, `316d6731e85e64dc2b0a59ce5be1d4133eeab4f7` y `c4a575ac80233516d62b92d815bef2ac1d2ff320`.

El menú existente abre `/crm/seguimiento`. Un componente Livewire `Workspace` reúne resumen, seguimientos, borradores, atención humana e historial. Se conservan `Opportunities` y `Settings`, sus servicios y nombres de ruta. La bandeja de oportunidades queda en `/crm/seguimiento/oportunidades`. La navegación interna comparte siete destinos; no se crea otro menú principal, Inbox, CRM ni Agenda.

Se reutilizan layout, tipografía, tokens corporativos, iconos, `x-ui.page-header`, tarjetas, botones, badges, filtros y paginadores. El CSS queda limitado a `.fu-shell`. Las tablas se convierten en fichas por debajo de 1.000 px, incluyendo los controles de cada fila. Las vistas no necesitan una biblioteca nueva ni migraciones.

## Funcionalidad

- Resumen con seis conteos institucionales reales: oportunidades abiertas, prioridad alta, aprobación pendiente, programación, intervención humana y bloqueos. Listas acotadas de oportunidades recientes y próximos pasos/errores. La programación contabiliza el estado y la política persistidos; no promete elegibilidad futura ni inventa conversiones.
- Oportunidades con búsqueda por nombre/identificador, estado, prioridad, canal, asesor, programa, fechas, aprobación, intervención y consentimiento verificable; orden, paginación y detalle enlazable. La ordenación por prioridad utiliza primero la categoría y después la puntuación.
- Detalle con asesor, responsable, programa disponible, señales diferenciadas de hechos, recomendación, seis mensajes originales recientes, vínculos a Inbox, Lead, contacto, Agenda e historial. No copia conversaciones ni modifica Leads.
- Borradores con objetivo, versión, edición humana, caducidad, fuentes, advertencias y contexto. Generación/regeneración, edición y descarte reutilizan los servicios de Etapa 3. La bandeja excluye registros sin borrador; la etiqueta distingue revisión, edición, aprobación y vencimiento. Abrir una pantalla no llama a IA ni invalida registros como efecto del render.
- Aprobación con modal de contenido exacto, destinatario, canal, objetivo, fecha, vencimiento, consentimiento y decisión de política. Identificador, hash y versión revisados son propiedades Livewire bloqueadas. Se exige confirmación de revisión y el ejecutor vuelve a validar institución, permisos, contexto y versión. Aprobar registra/programa localmente; no despacha ni encola el envío.
- Seguimientos con estados persistidos, filtros, política, motivo y navegación al detalle. `delivery_unknown` advierte expresamente que no se debe reenviar. Posponer usa una transacción del planificador con bloqueos en oportunidad/acción, versión de elegibilidad y evaluación de políticas; invalida el borrador y la aprobación anteriores. Pausa/cancelación reutilizan los contratos existentes.
- Atención humana muestra responsable, motivo, prioridad, actividad, fecha, resultado y vencimiento. La tarea se abre en Agenda; no existe un segundo gestor.
- Configuración institucional y por asesor con herencia restrictiva, horarios, días, zona, intervalos, límites y canales. Se muestran políticas obligatorias, errores, generación pendiente e interruptores. Solo se ofrece detener ejecución, nunca activar envíos. El modo por asesor se hereda porque ese es el contrato existente. El consentimiento se gestiona en el detalle mediante `ConsentService`, con procedencia, evidencia y fecha; no se convierte automáticamente lo desconocido en permiso.
- Auditoría paginada por institución, fecha, actor (nombre o `system`), tipo de evento y oportunidad. Muestra actor, motivo y resultado permitido; no vuelca payloads cifrados ni secretos.

## Permisos y consistencia

Se conserva la matriz efectiva de las etapas anteriores: solo administradores activos acceden al espacio y sus operaciones. Marketing, Admissions y otros roles mantienen sus permisos existentes de CRM/Inbox/Agenda, sin recibir nuevos privilegios administrativos. No se inventa un sistema paralelo de permisos granulares. Los tres componentes comprueban autorización también en `boot`, por lo que una solicitud Livewire posterior a la desactivación del usuario se rechaza.

Los modelos mantienen el scope institucional. Las nuevas relaciones de usuarios restringen explícitamente la institución. El enlace contextual de Inbox acepta `conversacion`, valida la pertenencia al cargar y no marca mensajes como leídos por abrir el enlace. Las mutaciones pasan por servicios, con control de versión, auditoría y bloqueo transaccional. Blade escapa texto y los indicadores solo leen datos.

La bandeja de oportunidades pagina 25 registros; acciones/borradores/intervenciones 20, eventos 30 y acciones de un detalle 10. Las relaciones de listados se precargan. El contexto usa seis mensajes y diez actividades; existe enlace a las áreas originales. Solo se hace polling visible cada 30 segundos mientras un borrador de la página está en generación.

## Verificación visual real

Se levantó temporalmente una instancia en `127.0.0.1:8876`, `APP_ENV=testing`, MySQL `crm_mca_test`, colas sin worker y ejecución deshabilitada. El fixture `tests/Support/visual-preview.php` exige ese entorno/base antes de escribir y crea exclusivamente una institución, usuario y casos ficticios. No altera credenciales operativas. Se inició sesión con la autenticación y el segundo factor normales del usuario ficticio.

Se abrieron realmente en navegador el resumen, oportunidades/detalle, borradores, seguimientos, atención humana, configuración, historial y una actividad original de Agenda. Se revisaron capturas a 1.440 × 1.000, 768 × 1.024 y 390 × 844. Se comprobaron búsqueda vacía, estados, navegación, edición humana (versión 1 → 2), modal de revisión, tabulación dentro del modal y confirmación que dejó la acción programada sin despacho.

La revisión encontró y corrigió el botón de revisión oculto por CSS global en móvil y alturas fijas de celdas que solapaban contenido en tablet. La captura posterior de tablet mostró las fichas y controles sin solapamientos. También se corrigió la presentación de registros vacíos en borradores. El servidor temporal y las pestañas de pruebas se cerraron antes de la regresión ampliada.

Accesibilidad implementada: labels, nombres de controles, foco visible, mensajes `role=status`/`role=alert`, estado escrito además de color, modal etiquetado con Escape y recorrido de foco, controles táctiles y navegación semántica. No se afirma una certificación WCAG ni una prueba con lector de pantalla.

## Validación automatizada

Las suites usan únicamente MySQL `crm_mca_test`, con proveedores simulados. La nueva suite prueba rutas, estados vacíos, filtros, aislamiento, roles, desactivación durante Livewire, aprobación exacta sin despacho, edición concurrente, caducidad, escape de contenido, posposición/versiones, resultado desconocido, interruptores y ausencia de N+1 en el listado. También prueba límites de fecha coincidentes y la distinción de estados del borrador.

Comando de regresión ampliada:

```powershell
php artisan test Modules\FollowUp\tests\Feature Modules\Ai\tests\Feature\AdvisorTurnServiceTest.php Modules\Ai\tests\Feature\AdvisorCatalogTest.php Modules\Social\tests\Feature Modules\Core\tests\Feature Modules\Crm\tests\Feature\LeadServiceTest.php Modules\Crm\tests\Feature\ContactServiceTest.php Modules\Crm\tests\Feature\AgendaTest.php Modules\Crm\tests\Feature\AgendaSenderTest.php Modules\Identity\tests\Feature\PanelAccessTest.php --compact
```

Las pruebas concurrentes existentes usan procesos independientes y reconstruyen exclusivamente la base descartable. No se ejecutaron suites contra la misma base en paralelo. Pint usa la instalación local existente; PHPStan incluye Larastan. El frontend se valida con `npm run build` (Vite); el proyecto no dispone de script separado de lint frontend. No se instalaron dependencias.

Resultados de cierre:

| Verificación | Resultado |
|---|---|
| Regresión ampliada | 740 pruebas, 4.220 aserciones; sin fallos; 471,62 s |
| Suite final de interfaz, incluidos los últimos ajustes de filtros/orden/paginación | 22 pruebas, 104 aserciones; sin fallos; 27,18 s |
| PHPStan/Larastan sobre FollowUp, Inbox y Breadcrumbs | Sin errores |
| Pint `--test --dirty` | 13 archivos PHP aprobados |
| `npm run build` | Vite correcto, sin nuevas dependencias |
| `git diff --cached --check` | Sin errores de espacios |

La suite específica final se ejecutó con `php artisan test Modules\FollowUp\tests\Feature\FollowUpWorkspaceTest.php --compact`. La regresión ampliada incluye las pruebas de concurrencia entre procesos de las etapas anteriores. El manifiesto incluye 23 archivos, contando la sustitución del CSS compilado y su manifest. El hash local se entrega en el mensaje final para evitar una referencia circular dentro del propio commit.

## Límites y aceptación funcional

- La revisión visual es una comprobación local con datos ficticios, no aceptación de usuarios comerciales ni una matriz exhaustiva de navegadores/dispositivos físicos. No se simuló una caída de red en el navegador ni se hizo auditoría instrumental completa de contraste/lector de pantalla.
- Los escenarios de errores, permisos, caducidad y concurrencia tienen cobertura automatizada; no todos se reprodujeron manualmente en navegador. Los enlaces a Inbox se prueban por HTTP; no se declara prueba visual de cada pantalla de Lead/contacto/Inbox.
- No se ha realizado benchmark con volumen productivo. Los conteos no calculan métricas de conversión ni reevaluación masiva de políticas al renderizar.
- El estado de colas se presenta mediante borradores pendientes persistidos; no pretende reemplazar la monitorización de workers. El canal Email continúa futuro según contratos existentes.
- La ejecución, integración real con proveedores y activación de automatizaciones quedan fuera de esta entrega. La aprobación es independiente de la activación y siempre está sujeta a revalidación.

Todo permanece local. No hubo push, PR remoto, despliegue, SSH, migraciones productivas, mensajes reales ni activación productiva. No se inicia la Etapa 7. Los archivos ajenos `capturas-publicador/` y `evaluacion_sophia_30_preguntas.md` permanecen fuera del commit.

## Archivos incluidos

- `Modules/FollowUp/ETAPA_6.md`
- `Modules/FollowUp/app/Livewire/Opportunities.php`
- `Modules/FollowUp/app/Livewire/Settings.php`
- `Modules/FollowUp/app/Livewire/Workspace.php`
- `Modules/FollowUp/app/Models/FollowUpAction.php`
- `Modules/FollowUp/app/Models/FollowUpEvent.php`
- `Modules/FollowUp/app/Models/FollowUpOpportunity.php`
- `Modules/FollowUp/app/Services/FollowUpActionPlanner.php`
- `Modules/FollowUp/app/Support/Presentation.php`
- `Modules/FollowUp/resources/views/livewire/opportunities.blade.php`
- `Modules/FollowUp/resources/views/livewire/settings.blade.php`
- `Modules/FollowUp/resources/views/livewire/workspace.blade.php`
- `Modules/FollowUp/resources/views/partials/approval.blade.php`
- `Modules/FollowUp/resources/views/partials/navigation.blade.php`
- `Modules/FollowUp/tests/Feature/FollowUpWorkspaceTest.php`
- `Modules/FollowUp/tests/Support/visual-preview.php`
- `Modules/Social/app/Livewire/Inbox.php`
- `app/View/Breadcrumbs.php`
- `public/build/assets/app-79HDc2IC.css`
- `public/build/assets/app-lWA03BGC.css`
- `public/build/manifest.json`
- `resources/views/layouts/navigation.blade.php`
- `routes/web.php`
