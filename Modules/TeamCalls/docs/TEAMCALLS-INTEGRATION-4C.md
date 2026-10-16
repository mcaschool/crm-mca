# TeamCalls · Integración de la corrección 4C y cierre de la carrera «busy»

Rama local `integration/collaboration-4c` · worktree `CRM-MCA-integration-4c` · sin push ni cambios en `main` ni en los
worktrees originales.

## 1. Integración controlada de 4C
| Paso | Resultado |
|---|---|
| Base | `0d3c629` (integración 4B validada) |
| Commit de Codex | `7256bdf5055a5197d66879f61a7d33ddc1973240`, sobre Fases 6 (`771758f`), 7 (`44f66d5`) y 8 (`42f12a7`) de TeamChat |
| Dependencias verificadas | Ninguno de los 16 archivos de 4C cambia en las Fases 6–8; sus pruebas nuevas no usan clases de esas fases |
| Método | `cherry-pick -x` de **solo** 4C → `4711a80`. Las Fases 6–8 (79 archivos, ~4.700 líneas de TeamChat) **no** se incorporan; requieren su propia integración autorizada |
| Conflictos | Solo comentarios en `tests/RealtimeFixtures.php` y `Compatibility4BTest.php`, mis ajustes 4B que Codex aceptó; se toma la versión de Codex |
| Comprobación | Los 16 archivos quedan idénticos a `7256bdf` |

**Revisión del contenido de 4C:**
- `CurrentInstitution` sigue siendo *scoped*. Los siete servicios de Social, `InstitutionExecution` y
  `DatabaseEventRecorder` dejan de capturar el contexto en el constructor y lo resuelven en cada uso.
- No se quita ningún filtro institucional ni se introduce una institución por defecto.
- `DatabaseEventRecorder` ahora rechaza un evento de A con el contexto ambiente en B.
- Esto es coherente con lo que ya hacía TeamCalls en 4B: `CallEventPolicy` resuelve `CurrentInstitution` en cada uso.

## 2. Carrera «busy» en `CallService::start()`
**Causa:**
1. `start()` consultaba la idempotencia **sin bloqueo**.
2. Antes de la transacción, decidía `busy` también sin bloqueo.
3. Si otro proceso confirmaba entre ambas lecturas la llamada con **la misma clave**, el reintento veía esa llamada como
   ocupación propia y respondía `busy`. No llegaba a la revalidación bajo bloqueo, que la habría devuelto.

No se creaban duplicados, pero la respuesta al reintento legítimo era errónea. El fallo existía igual en `0d3c629`.

**Corrección** (`287d918`):
- `busy` se decide **solo** dentro de la transacción, tras bloquear la fila de la persona y revisar la clave con
  bloqueo.
- El mismo comando recupera la misma llamada.
- Comandos distintos simultáneos siguen serializados por el bloqueo de la persona y el perdedor recibe `busy`.
- Se mantienen la huella (`idempotency_conflict`), el límite de participantes y la red de seguridad del índice único.

**Pruebas** (MySQL y procesos PHP independientes con conexiones propias):
- **Entrelazado determinista** (`start_interleaved` del worker): justo después de la lectura sin bloqueo, otro proceso
  confirma el mismo comando.
  - Sin la corrección: `busy`.
  - Con la corrección: la misma llamada y una sola fila.
- **Comandos distintos entrelazados:** uno inicia y el otro recibe `busy` (la protección se conserva).
- **Suite real de concurrencia:** 8/8. Los escenarios de carrera, repetidos 5 veces: 15/15.

## 3. Validación (árbol `287d918`, MySQL, BD aislada)
**Batería completa del CRM: 1786 superadas, 1 fallida, 3 omitidas** (11.877 aserciones, 3.662 s).

| Área | Resultado |
|---|---|
| Social (incluidos los 15 casos de la regresión 4B y los nuevos de 4C) | 480/480 |
| TeamCalls (Integration4B con servicios reales, conformidad con Core real, concurrencia real) | 215 superadas, 3 omitidas |
| CollaborationCore · CollaborationRealtime · TeamChat | 34 · 24 · 44, todas superadas |
| Core (incluido el ciclo de vida de contexto 4C) · Identity · Institutions | 21 · 29 · 8 |
| CRM transversal (Auth, Security, Architecture, Localization, Layout…) | todas superadas |
| Mcp | 66 superadas y 1 fallida (`crm_git_status`, ambiental) |

**Además:**
- Contratos 1.1.0: 30/30.
- Worker real del outbox: 15/15. Incluye revocaciones 4B, reintento, *lease* y avisos solo a participantes.
- Reverb real con WebSocket: 5/5.
- PHPStan sin errores: TeamCalls y las configuraciones de Codex (contracts, collaboration, realtime, teamchat).
- PHPStan con la configuración global `phpstan.neon` (app y Modules): 20 avisos, ninguno en TeamCalls ni en archivos
  de 4C.
  - 3 son preexistentes del CRM, en archivos idénticos a `main`.
  - El resto son validaciones defensivas de los DTO de colaboración, que Codex analiza con sus configuraciones propias.
- Pint: correcto en lo modificado.

## 4. Pendientes ambientales (no se dan por superados)
- `McpOpsTest` › `crm_git_status`: falla en worktrees porque `.git` es un archivo. Corrección en otra tarea.
- Tres pruebas omitidas de LiveKit (`LiveKitRealServerTest`, grupo `livekit-real`): exigen `TEAMCALLS_LIVEKIT_REAL_*`
  contra infraestructura multimedia real.
