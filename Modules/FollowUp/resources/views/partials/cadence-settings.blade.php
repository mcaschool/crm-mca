<fieldset class="ui-card">
    <legend>Cadencia dentro de 24 horas · {{ $scope }}</legend>
    <label class="fu-check"><input type="checkbox" wire:model="{{ $model }}.enabled"> Habilitar reglas de cadencia de WhatsApp</label>
    <p class="fu-muted">Solo WhatsApp Cloud API y Coexistence, en los canales permitidos. Guardar no activa envíos. Los momentos se cuentan desde el último mensaje del prospecto, no desde nuestra respuesta inicial.</p>
    <div class="fu-rule-grid">
        @foreach(['first'=>'Primer momento (h)','second'=>'Segundo momento (h)','third'=>'Tercer momento (h)','minimum'=>'Separación mínima absoluta (h)','margin'=>'Margen antes del cierre (min)','maximum'=>'Máximo dentro de ventana'] as $key=>$label)
        <label>{{ $label }} · {{ $scope }}<input class="ui-input" type="number" wire:model="{{ $model }}.{{ $key }}" min="{{ $key === 'margin' ? 30 : ($key === 'minimum' ? 2 : 1) }}" max="{{ ['margin'=>180,'minimum'=>12,'maximum'=>3][$key] ?? 23 }}"></label>
        @endforeach
    </div>
    <p class="fu-muted">Hasta tres seguimientos adicionales; nunca una cuota obligatoria. Se omiten momentos perdidos, no se comprimen. El máximo acumulado de intentos superior se conserva entre respuestas, reaperturas y asesores. La respuesta inicial y los mensajes humanos no consumen seguimientos enviados, pero sí limitan la frecuencia.</p>
    <p class="fu-muted">Al responder el prospecto se cancela lo pendiente y se invalida su borrador. La nueva ventana no reinicia el límite acumulado. El horario institucional y las pausas obligatorias siguen vigentes. Esta cadencia sustituye los intervalos generales solo para texto libre de WhatsApp; las plantillas mantienen su revisión independiente.</p>
</fieldset>
