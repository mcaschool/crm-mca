@php
    use Modules\FollowUp\Support\Presentation as P;
    use Modules\FollowUp\Services\FollowUpMetricsService as Metrics;
    $c = $report['counts'];
    $labels = ['detected'=>'Oportunidades detectadas','executed'=>'Seguimientos ejecutados','responders'=>'Prospectos reactivados','meetings'=>'Reuniones registradas','conversions_associated'=>'Matrículas asociadas','blocked'=>'Acciones bloqueadas'];
    $rateLabels = ['response'=>'Tasa de respuesta','reactivation'=>'Tasa de reactivación','conversion'=>'Conversión asociada por prospecto','approval'=>'Aprobación de versiones generadas','error'=>'Tasa de error','exclusion'=>'Tasa de exclusión'];
@endphp
<div class="mca-page fu-shell" x-on:analytics-evidence.window="$refs.evidence.scrollIntoView({behavior: 'smooth', block: 'start'})">
    <x-ui.page-header title="Analítica comercial" subtitle="Resultados verificables, asociaciones temporales y propuestas para revisión humana." />
    @include('followup::partials.navigation', ['active'=>'analytics'])
    <style>
        .fu-shell .fu-metric button{display:block;min-height:40px;text-align:left}.fu-shell .fu-metric>span{display:block}.fu-chart-row{display:grid;grid-template-columns:minmax(95px,1fr) minmax(80px,2fr) 50px;gap:12px;align-items:center;margin:12px 0;font-size:13px}.fu-chart-row meter{width:100%;height:20px;accent-color:var(--mca-blue)}.fu-chart-scroll{max-height:360px;overflow:auto}.fu-shell .fu-chart-row span{overflow-wrap:anywhere}.fu-quality{display:flex;flex-wrap:wrap;gap:8px;align-items:center}
    </style>
    @if(session('analytics_saved'))<p role="status" class="fu-notice">{{ session('analytics_saved') }}</p>@endif
    <section class="ui-card">
        <div class="fu-quality"><x-ui.badge :tone="$report['run']?->completed_at ? 'neutral' : 'warning'">{{ $report['run']?->completed_at ? 'Proyección disponible · cobertura parcial' : 'Pendiente de actualización' }}</x-ui.badge><span class="fu-muted">Institución actual · {{ $report['run']?->completed_at ? 'Actualizada '.$report['run']->completed_at.' UTC' : 'Todavía no hay una proyección calculada.' }}</span></div>
        <p class="fu-muted">Periodos en UTC. Respuestas: 7 días. Conversiones: último seguimiento elegible en 30 días. La asociación no demuestra causalidad. Los estados y dimensiones corresponden a la última reconciliación.</p>
        <x-ui.button wire:click="reconcile" wire:loading.attr="disabled">Actualizar proyección</x-ui.button>
        <div class="fu-filters">
            <label>Desde<input class="ui-input" type="date" wire:model.live="from"></label><label>Hasta<input class="ui-input" type="date" wire:model.live="to"></label>
            <label>Asesor<select class="ui-input" wire:model.live="filters.bot_id"><option value="">Todos</option>@foreach($bots as $bot)<option value="{{ $bot->id }}">{{ $bot->assistant_name ?: $bot->name }}</option>@endforeach</select></label>
            <label>Canal<select class="ui-input" wire:model.live="filters.channel"><option value="">Todos</option>@foreach(['whatsapp','instagram','messenger'] as $channel)<option>{{ $channel }}</option>@endforeach</select></label>
            <label>Programa<select class="ui-input" wire:model.live="filters.program_id"><option value="">Todos</option>@foreach($programs as $program)<option value="{{ $program->id }}">{{ $program->name_es }}</option>@endforeach</select></label>
            <label>Prioridad<select class="ui-input" wire:model.live="filters.priority"><option value="">Todas</option>@foreach(['high','medium','low'] as $value)<option value="{{ $value }}">{{ P::label($value) }}</option>@endforeach</select></label>
            <label>Etapa comercial<select class="ui-input" wire:model.live="filters.stage"><option value="">Todas</option>@foreach(['new','contacted','qualified','enrolled','discarded'] as $value)<option value="{{ $value }}">{{ P::label($value) }}</option>@endforeach</select></label>
            <label>Estado de oportunidad<select class="ui-input" wire:model.live="filters.opportunity_status"><option value="">Todos</option>@foreach(['open','paused','cancelled','closed','suppressed'] as $value)<option value="{{ $value }}">{{ P::label($value) }}</option>@endforeach</select></label>
            <label>Tipo de acción<select class="ui-input" wire:model.live="filters.action_type"><option value="">Todos</option><option value="commercial_follow_up">Seguimiento comercial</option></select></label>
            <label>Resultado de acción<select class="ui-input" wire:model.live="filters.result"><option value="">Todos</option>@foreach(\Modules\FollowUp\Models\FollowUpAction::STATUSES as $value)<option value="{{ $value }}">{{ P::label($value) }}</option>@endforeach</select></label>
            <label>Responsable<select class="ui-input" wire:model.live="filters.owner_id"><option value="">Todos</option>@foreach($owners as $owner)<option value="{{ $owner->id }}">{{ $owner->name }}</option>@endforeach</select></label>
        </div>
        @error('*')<p role="alert">{{ $message }}</p>@enderror
    </section>
    <div class="fu-metrics">
        @foreach($labels as $key=>$label)<div class="fu-metric"><span>{{ $label }}</span><strong>{{ $c[$key] }}</strong><button type="button" class="ui-link" aria-label="Ver evidencia de {{ $label }}" wire:click="inspectMetric('{{ $key }}')">Ver evidencia</button><span>Anterior: {{ $previous['counts'][$key] }} · {{ $previous['from'] }} — {{ $previous['to'] }}</span></div>@endforeach
    </div>
    <div class="fu-columns">
        <section class="ui-card"><h2>Tasas y denominadores</h2>
            @foreach($rateLabels as $key=>$label)<div class="fu-item"><span>{{ $label }}</span><b>{{ $report['rates'][$key] === null ? 'Sin datos suficientes' : $report['rates'][$key].' %' }}</b></div>@endforeach
            <p class="fu-muted">{{ $c['subjects'] }} identidades con seguimiento confirmado; {{ $c['responders'] }} respondieron. {{ $c['generated'] }} versiones generadas; {{ $c['approved'] }} aprobadas. {{ $c['attempted'] }} acciones intentadas; {{ $c['failed'] }} fallidas. {{ $c['excluded'] }} contactos con baja posterior asociada.</p>
            <p class="fu-notice">{{ $report['open_windows'] }} seguimientos tienen su ventana de respuesta abierta. Las tasas son provisionales. Una identidad sin contacto verificado se cuenta por conversación; no permite deduplicar a una persona entre canales.</p>
            <p class="fu-muted">Tiempo medio hasta respuesta: {{ $report['response_seconds'] === null ? 'Sin datos suficientes' : round($report['response_seconds']/3600, 1).' horas' }}. Se usa asociación descriptiva por acción; varias acciones pueden preceder a la misma respuesta, sin multiplicar prospectos.</p>
        </section>
        <section class="ui-card"><h2>Resultados observados y calidad</h2>
            <p>Matrículas observadas en el periodo: <b>{{ $c['conversions_observed'] }}</b>. Asociadas a la cohorte de seguimientos: <b>{{ $c['conversions_associated'] }}</b>.</p>
            <p>Conversaciones cuya primera respuesta posterior a la detección no tiene seguimiento elegible asociado: {{ $c['unassociated_responses'] }}. No se atribuyen al sistema.</p><p>Reuniones completadas: {{ $c['meetings_completed'] }} · Leads cualificados: {{ $c['qualified'] }} · Oportunidades perdidas: {{ $c['lost'] }}.</p>
            <p>Propuestas automáticas de reunión: {{ $c['meeting_proposals'] }} · Señales para revisar inscripción: {{ $c['enrollment_requests'] }}. Son recomendaciones, no reuniones o matrículas confirmadas.</p>
            <p>Entrega confirmada: {{ $c['delivered'] }} · Lectura confirmada: {{ $c['read'] }} · Resultado desconocido: {{ $c['unknown'] }}.</p>
            <p class="fu-muted">Tiempos de entrega/lectura no disponibles: el modelo no conserva una marca temporal fiable de cada transición. No se equipara aceptación de la API con entrega.</p>
            @forelse($report['quality'] as $reason=>$count)<p class="fu-notice fu-warning">{{ P::label($reason) }}: {{ $count }}</p>@empty<p>Sin incidencias detectadas en las referencias analizadas. Esto no certifica cobertura histórica completa.</p>@endforelse
        </section>
    </div>
    <div class="fu-columns">
        @foreach(['daily'=>'Oportunidades por día','states'=>'Seguimientos por estado','conversion_daily'=>'Matrículas asociadas por fecha del resultado','blocks'=>'Motivos de bloqueo','errors'=>'Incidencias de ejecución','priorities'=>'Oportunidades por prioridad'] as $series=>$title)
            <section class="ui-card"><h2>{{ $title }}</h2><p class="fu-muted">Unidad: registros únicos. Periodo {{ $from }} — {{ $to }} UTC.</p><div class="fu-chart-scroll">
                @forelse($report[$series] as $key=>$value)<div class="fu-chart-row"><span>{{ P::label((string) $key) }}</span><meter aria-label="{{ P::label((string) $key) }}: {{ $value }} registros" min="0" max="{{ max(1, max($report[$series])) }}" value="{{ $value }}">{{ $value }}</meter><b>{{ $value }}</b></div>@empty<p class="fu-empty">Sin datos verificables para este periodo.</p>@endforelse
            </div></section>
        @endforeach
    </div>
    @foreach(['channel'=>'Resultados por canal','bot_id'=>'Resultados por asesor','program_id'=>'Resultados por programa'] as $dimension=>$title)
        <section class="ui-card"><h2>{{ $title }}</h2><p class="fu-muted">Hasta 30 segmentos por volumen. Un mayor volumen no significa mayor efectividad. Menos de 20 identidades no permite recomendar cambios.</p>
            <table class="ui-table fu-table"><thead><tr><th>Segmento</th><th>Seguimientos</th><th>Identidades</th><th>Respondieron</th><th>Tasa</th></tr></thead><tbody>
                @forelse($report['groups'][$dimension] as $group)<tr><td data-label="Segmento">{{ $dimension === 'bot_id' ? ($bots->firstWhere('id',$group['key'])?->assistant_name ?: 'Asesor no disponible') : ($dimension === 'program_id' ? ($programs->firstWhere('id',$group['key'])?->name_es ?: 'Programa sin verificar') : ucfirst($group['key'] ?? 'Sin canal')) }}</td><td data-label="Seguimientos">{{ $group['volume'] }}</td><td data-label="Identidades">{{ $group['subjects'] }}</td><td data-label="Respuestas">{{ $group['responses'] }}</td><td data-label="Tasa">{{ Metrics::rate($group['responses'],$group['subjects']) ?? 'Sin datos suficientes' }}{{ $group['subjects'] ? ' %' : '' }} @if($group['subjects'] < 20)<p class="fu-muted">Muestra pequeña</p>@endif</td></tr>@empty<tr><td colspan="5">Sin seguimientos confirmados en este segmento.</td></tr>@endforelse
            </tbody></table>
        </section>
    @endforeach
    <details class="ui-card"><summary>Respuestas por hora de envío (UTC)</summary><p class="fu-muted">Distribución descriptiva; no prescribe horarios. Una misma identidad puede participar en horas distintas.</p>@foreach($report['hours'] as $hour)<p>{{ $hour['hour'] }} h: {{ $hour['responses'] }} respuestas / {{ $hour['volume'] }} identidades.</p>@endforeach</details>
    <details class="ui-card"><summary>Cómo se calculan los indicadores</summary>
        <p>Oportunidades: registros únicos por fecha de detección del ciclo vigente, sin sumar de nuevo los ciclos. Estados, prioridad y vínculos comerciales son los actuales al reconstruir.</p>
        <p>Respuesta: identidades que responden dentro de 7 días / identidades con envío confirmado en el periodo. Reactivación: oportunidades con respuesta / oportunidades con envío confirmado. Se excluyen mensajes anteriores o simultáneos al envío y resultados desconocidos.</p>
        <p>Conversión: identidades con matrícula confirmada y asociada / identidades con envío confirmado. La matrícula se cuenta una vez por Lead, se exige contacto verificado y evento del CRM; se asigna al último envío elegible anterior en 30 días. Puede ocurrir después del periodo de envío. Las cohortes recientes siguen abiertas durante 30 días.</p>
        <p>Aprobación: versiones generadas en el periodo con evento de aprobación de esa misma versión / versiones generadas. Las versiones solo editadas no forman este denominador. Error: acciones fallidas con intento / acciones con intento, según fecha de creación. Exclusión: identidades con baja o retiro verificable posterior asociado en 30 días / identidades alcanzadas.</p>
        <p>Reuniones: actividades de videollamada o reunión presencial actualmente vinculadas y programadas/completadas, según su fecha de creación; las tareas y propuestas no son reuniones. La cifra de completadas refleja su estado actual.</p>
        <p>Generaciones adicionales de una misma acción dentro del periodo: {{ $c['regenerated'] }}. No incluye repeticiones cuya primera generación quedó fuera del periodo. Latencia: promedio de las llamadas registradas; tokens: suma de la telemetría, sin estimación monetaria.</p>
        <p>Actualización explícita por institución, caché de consulta de 60 segundos invalidada por cada reconstrucción. Denominador cero: sin datos suficientes. Las ausencias de identidad, fechas o evidencia se informan como limitaciones; no se completan con estimaciones.</p>
    </details>
    <div class="fu-columns">
        <section class="ui-card"><h2>Consumo y costes</h2><p>Llamadas registradas de generación: {{ $report['usage']['calls'] }}.</p><p>Tokens de entrada: {{ $report['usage']['input_tokens'] }} · Salida: {{ $report['usage']['output_tokens'] }}.</p><p>Latencia media: {{ $report['usage']['latency_ms'] === null ? 'No disponible' : round($report['usage']['latency_ms']).' ms' }}.</p><p class="fu-notice">Costes de IA, mensajería, por oportunidad, respuesta y conversión: <b>No disponibles</b>. No hay tarifas ni importes verificables persistidos; no se calcula rentabilidad.</p><p class="fu-muted">La telemetría existente identifica institución y asesor, no acción/programa/canal. Con filtros de esas dimensiones no puede atribuirse el consumo y no se extrapola.</p></section>
        <section class="ui-card"><h2>Recomendaciones para revisión</h2><p>Se abstiene con muestras pequeñas, ventanas abiertas o incidencias de calidad. Nunca modifica horarios, frecuencias, consentimiento ni configuraciones.</p><label><input type="checkbox" wire:model="useAi"> Usar IA del asesor seleccionado para escoger entre recomendaciones seguras</label><x-ui.button wire:click="generateInsights" wire:loading.attr="disabled">Analizar evidencia</x-ui.button>@error('insights')<p role="alert">{{ $message }}</p>@enderror
            @if($insights)<p role="status">{{ $insights['message'] }}</p>@if($insights['status'] === 'provider_unavailable')<p class="fu-notice">Proveedor no disponible o respuesta no válida. Se conservan las recomendaciones deterministas.</p>@endif
                @foreach($insights['items'] as $item)<article class="fu-bubble"><b>{{ $item['suggestion'] }}</b><p>Métrica: {{ P::label($item['metric']) }} · Actual: {{ $item['value'] }} · Anterior: {{ $item['previous'] ?? 'No disponible' }}</p><p>{{ $item['period'] }} · {{ $item['volume'] }} identidades</p><p class="fu-muted">{{ $item['confidence'] }}. {{ $item['limitations'] }}</p></article>@endforeach
            @endif
        </section>
    </div>
    <section class="ui-card" x-ref="evidence"><h2>Registros que sustentan los indicadores</h2>@if($evidenceMetric)<p role="status">Indicador seleccionado: {{ $labels[$evidenceMetric] }}. Se muestran los registros de origen; los prospectos se deduplican en el indicador.</p><button type="button" class="ui-link" wire:click="$set('evidenceMetric', '')">Mostrar evidencia general</button>@endif<p class="fu-muted">Referencias sin contenido personal. Fecha del hecho; para acciones se muestra también la ejecución confirmada. Abre la oportunidad para consultar la evidencia original.</p>
        <label>Tipo de evidencia<select class="ui-input" wire:model.live="evidenceKind">@foreach(['opportunity'=>'Oportunidades','action'=>'Acciones','conversion'=>'Matrículas','meeting'=>'Reuniones','exclusion'=>'Bajas','generation'=>'Generaciones','approval'=>'Aprobaciones','usage'=>'Telemetría IA'] as $value=>$label)<option value="{{ $value }}">{{ $label }}</option>@endforeach</select></label>
        <table class="ui-table fu-table"><thead><tr><th>Referencia</th><th>Fecha UTC</th><th>Resultado</th><th>Origen</th></tr></thead><tbody>@forelse($evidence as $fact)<tr><td data-label="Referencia">{{ $fact->source_key }}</td><td data-label="Fecha">{{ $fact->occurred_at?->format('d/m/Y H:i') }}@if($fact->eligible_at)<p class="fu-muted">Ejecución: {{ $fact->eligible_at->format('d/m/Y H:i') }}</p>@endif</td><td data-label="Resultado">{{ P::label($fact->status) }} @if($fact->kind === 'conversion'){{ $fact->action_id ? 'Asociada temporalmente' : 'Observada sin atribución' }}@endif</td><td>@if($fact->opportunity_id)<a class="ui-link" href="{{ route('followup.opportunities',['oportunidad'=>$fact->opportunity_id]) }}">Oportunidad #{{ $fact->opportunity_id }}</a>@else Telemetría institucional @endif</td></tr>@empty<tr><td colspan="4">Sin evidencia para estos filtros.</td></tr>@endforelse</tbody></table><x-ui.pager :paginator="$evidence" />
    </section>
</div>
