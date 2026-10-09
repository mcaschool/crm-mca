<div>
    <x-ui.styles />
    {{-- Botones con icono + texto en la MISMA línea (8px). El icono SVG es de bloque (preflight),
         así que el contenedor del texto debe ser flex en línea. Los estados wire:loading usan
         wire:loading.inline-flex (sin display propio aquí, para no anular el ocultado). --}}
    <style>
        .adv-form .btn{gap:8px}
        .adv-form .mca-section h3{display:flex;align-items:center;gap:8px}
        .adv-bi{display:inline-flex;align-items:center;gap:8px;white-space:nowrap}
        .adv-bg{align-items:center;gap:8px;white-space:nowrap}
        .adv-form .mca-seg button,.adv-form .mca-filebtn{display:inline-flex;align-items:center;justify-content:center;gap:8px;white-space:nowrap}
    </style>
    <div class="mca-panel adv-form" style="padding:22px 26px 34px">
        <div class="mca-head">
            <div style="display:flex;align-items:center;gap:12px">
                <a href="{{ route('advisors.index') }}" class="btn btn-ghost btn-sm" title="{{ __('Volver') }}" aria-label="{{ __('Volver') }}"><x-ui.icon name="chevron-left" class="ic" style="width:16px;height:16px" /></a>
                <div>
                    <h1 class="mca-h1">{{ $editing ? __('Configurar asesor') : __('Crear asesor') }}</h1>
                    <p class="mca-sub">{{ $editing ? __('Identidad, tipo, foto, proceso de IA y conocimiento.') : __('Identidad, tipo, foto y proceso de IA.') }}</p>
                </div>
            </div>
        </div>

        @if (session('status'))
            <div class="mca-toast ok fade"><x-ui.icon name="check" class="ic" /> {{ session('status') }}</div>
        @endif
        @if (session('status_error'))
            <div class="mca-toast err fade"><x-ui.icon name="x" class="ic" /> {{ session('status_error') }}</div>
        @endif

        {{-- Identidad --}}
        <div class="card card-p fade">
            <div style="display:flex;flex-wrap:wrap;gap:24px">
                {{-- Avatar (solo en edicion; en creacion se sube tras guardar) --}}
                <div style="text-align:center">
                    <span class="mca-av lg" style="margin:0 auto">
                        @if ($avatar)
                            <img src="{{ $avatar->temporaryUrl() }}" alt="preview">
                        @elseif ($avatarUrl)
                            <img src="{{ $avatarUrl }}" alt="{{ $name }}">
                        @else
                            <x-ui.icon name="graduation-cap" />
                        @endif
                    </span>
                    @if ($editing)
                        <div style="margin-top:10px;display:flex;flex-direction:column;align-items:center;gap:6px">
                            <label class="mca-filebtn">
                                <x-ui.icon name="upload" class="ic" style="width:15px;height:15px" /> {{ __('Elegir foto') }}
                                <input type="file" wire:model="avatar" accept=".png,.jpg,.jpeg,.svg,.webp,.gif" class="hidden">
                            </label>
                            <span wire:loading wire:target="avatar" class="mca-help"><span class="mca-spin"></span> {{ __('Cargando…') }}</span>
                            @error('avatar') <span class="mca-err">{{ $message }}</span> @enderror
                            <div style="display:flex;gap:6px">
                                @if ($avatar)
                                    <button type="button" wire:click="saveAvatar" class="btn btn-primary btn-sm">{{ __('Guardar foto') }}</button>
                                @endif
                                @if ($avatarUrl)
                                    <button type="button" wire:click="removeAvatar" class="btn btn-ghost btn-sm">{{ __('Quitar') }}</button>
                                @endif
                            </div>
                            <span class="mca-help">{{ __('PNG, JPG, SVG o WebP · máx 1 MB') }}</span>
                        </div>
                    @else
                        <span class="mca-help" style="display:block;margin-top:8px;max-width:9rem">{{ __('La foto se sube al guardar') }}</span>
                    @endif
                </div>

                {{-- Campos --}}
                <div style="flex:1;min-width:240px">
                    <div class="field">
                        <label>{{ __('Nombre del asesor') }}</label>
                        <input type="text" wire:model="name" maxlength="60" placeholder="{{ __('Ej. Celia') }}">
                        @error('name') <span class="mca-err">{{ $message }}</span> @enderror
                        <div class="mca-help">{{ __('El widget y los saludos leen este valor.') }}</div>
                    </div>

                    <div class="field">
                        <label>{{ __('Tipo') }}</label>
                        <div class="mca-seg">
                            <button type="button" wire:click="$set('type','ia')" class="{{ $type === 'ia' ? 'active' : '' }}">
                                <x-ui.icon name="bot" class="ic" style="width:15px;height:15px" /> {{ __('IA') }}
                            </button>
                            <button type="button" wire:click="$set('type','human')" class="{{ $type === 'human' ? 'active' : '' }}">
                                <x-ui.icon name="user" class="ic" style="width:15px;height:15px" /> {{ __('Humano') }}
                            </button>
                        </div>
                        <div class="mca-help">{{ __('“IA” opera hoy. “Humano” queda como ficha etiqueta para intervención en vivo (futuro).') }}</div>
                    </div>

                    <div class="field">
                        <label>{{ __('Estado') }}</label>
                        <div class="mca-seg">
                            <button type="button" wire:click="$set('status','active')" class="{{ $status === 'active' ? 'active' : '' }}">{{ __('Activo') }}</button>
                            <button type="button" wire:click="$set('status','inactive')" class="{{ $status === 'inactive' ? 'active' : '' }}">{{ __('Inactivo') }}</button>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        {{-- Configuración de IA (solo tipo IA) --}}
        @if ($type === 'ia')
            <div class="card card-p fade" style="margin-top:16px">
                <div class="mca-section" style="border-top:none;padding-top:0;margin-top:0">
                    <h3>{{ __('Configuración de IA') }}</h3>
                    <p class="mca-sub">{!! __('Proceso de conversación e idioma. Las credenciales se gestionan en :link (no se duplican aquí).', ['link' => '<a href="'.e(route('integrations.index')).'" style="color:var(--mca);font-weight:600">'.e(__('Integraciones')).'</a>']) !!}</p>
                </div>
                <div style="display:flex;flex-wrap:wrap;gap:16px">
                    <div class="field" style="flex:1;min-width:160px;margin-bottom:0">
                        <label>{{ __('Idioma principal') }}</label>
                        <select wire:model="language">
                            <option value="es">Español</option>
                            <option value="en">English</option>
                        </select>
                    </div>
                    <div class="field" style="flex:1;min-width:200px;margin-bottom:0">
                        <label>{{ __('Proveedor (integración)') }}</label>
                        <select wire:model.live="integrationId">
                            <option value="">{{ __('— Elegir —') }}</option>
                            @foreach ($integrations as $int)
                                <option value="{{ $int->id }}">{{ $int->name }} ({{ $int->provider }})</option>
                            @endforeach
                        </select>
                        @if ($integrations->isEmpty())
                            <div class="mca-help">{!! __('No hay proveedores de IA. :link.', ['link' => '<a href="'.e(route('integrations.index')).'" style="color:var(--mca)">'.e(__('Configura uno')).'</a>']) !!}</div>
                        @endif
                    </div>
                    <div class="field" style="flex:1;min-width:160px;margin-bottom:0">
                        <label>{{ __('Modelo') }}</label>
                        <input type="text" wire:model.blur="model" maxlength="100" placeholder="{{ __('Ej. qwen3.7-plus') }}">
                    </div>
                </div>
                <div style="display:flex;flex-wrap:wrap;gap:16px;margin-top:16px">
                    <div class="field" style="flex:2;min-width:240px;margin-bottom:0">
                        <label for="adv-retrieval">{{ __('Búsqueda en el conocimiento') }}</label>
                        <select id="adv-retrieval" wire:model="knowledgeRetrieval" data-testid="knowledge-retrieval">
                            <option value="classic">{{ __('Clásica') }}</option>
                            <option value="precise">{{ __('Precisa (recomendada si el asesor atiende varias líneas)') }}</option>
                        </select>
                        <div class="mca-help">{{ __('La precisa entiende variantes de las palabras, reconoce el programa nombrado, mantiene el tema en los seguimientos y solo deja enlaces que estén en el conocimiento. En «Probar asesor» muestra el diagnóstico de cada respuesta.') }}</div>
                        @error('knowledgeRetrieval') <span class="mca-err">{{ $message }}</span> @enderror
                    </div>
                    <div class="field" style="flex:1;min-width:160px;margin-bottom:0">
                        <label for="adv-limit">{{ __('Respuestas de IA por conversación') }}</label>
                        <input id="adv-limit" type="number" min="1" max="200" wire:model="messageLimit" placeholder="{{ (int) config('crm.celia.message_limit', 15) }}" data-testid="message-limit">
                        <div class="mca-help">{{ __('Vacío = el valor general (:n).', ['n' => (int) config('crm.celia.message_limit', 15)]) }}</div>
                        @error('messageLimit') <span class="mca-err">{{ $message }}</span> @enderror
                    </div>
                </div>
            </div>

            {{-- Identidad e instrucciones: cómo es y cómo responde ESTE asesor --}}
            <div class="card card-p fade" style="margin-top:16px">
                <div class="mca-section" style="border-top:none;padding-top:0;margin-top:0">
                    <h3><x-ui.icon name="user-cog" class="ic" style="width:17px;height:17px" /> {{ __('Identidad e instrucciones') }}</h3>
                    <p class="mca-sub">{{ __('Cómo se presenta y cómo responde este asesor. Se combina siempre con las reglas de la institución (es un asistente virtual, no inventa datos y responde solo con su conocimiento), que no se pueden desactivar desde aquí.') }}</p>
                </div>
                @if ($usesGlobalPrompt)
                    <div class="mca-help" style="margin-bottom:12px;padding:10px 12px;border-radius:10px;background:var(--mca-blue-soft)">{{ __('Este asesor usa ahora las instrucciones generales de siempre. Cuando completes esta sección, pasará a usar las suyas.') }}</div>
                @endif
                <div class="field">
                    <label for="adv-role">{{ __('Función o presentación') }}</label>
                    <input id="adv-role" type="text" wire:model="roleDescription" maxlength="255" placeholder="{{ __('Ej.: asesora de Diplomas Avanzados de la escuela') }}">
                    <div class="mca-help">{{ __('El nombre público es el «Nombre del asesor» de arriba.') }}</div>
                    @error('roleDescription') <span class="mca-err">{{ $message }}</span> @enderror
                </div>
                <div class="field">
                    <label for="adv-instructions">{{ __('Instrucciones del asesor') }}</label>
                    <textarea id="adv-instructions" wire:model="instructions" rows="7" maxlength="6000" placeholder="{{ __('Qué hace, a quién atiende, qué debe priorizar y cómo debe orientar a cada persona…') }}"></textarea>
                    @error('instructions') <span class="mca-err">{{ $message }}</span> @enderror
                </div>
                <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(260px,1fr));gap:16px">
                    <div class="field" style="margin-bottom:0">
                        <label for="adv-tone">{{ __('Tono de comunicación') }}</label>
                        <input id="adv-tone" type="text" wire:model="tone" maxlength="255" placeholder="{{ __('Ej.: cercano y profesional, frases breves') }}">
                        @error('tone') <span class="mca-err">{{ $message }}</span> @enderror
                    </div>
                    <div class="field" style="margin-bottom:0">
                        <label for="adv-notfound">{{ __('Mensaje para información no encontrada') }}</label>
                        <input id="adv-notfound" type="text" wire:model="notFoundMessage" maxlength="500" placeholder="{{ __('Ej.: No tengo ese dato; te recomiendo revisar la ficha del programa.') }}">
                        @error('notFoundMessage') <span class="mca-err">{{ $message }}</span> @enderror
                    </div>
                    <div class="field" style="margin-bottom:0">
                        <label for="adv-limits">{{ __('Límites: asuntos que no debe responder') }}</label>
                        <textarea id="adv-limits" wire:model="restrictions" rows="3" maxlength="2000" placeholder="{{ __('Ej.: precios concretos, temas médicos o legales, otras instituciones…') }}"></textarea>
                        @error('restrictions') <span class="mca-err">{{ $message }}</span> @enderror
                    </div>
                    <div class="field" style="margin-bottom:0">
                        <label for="adv-handoff">{{ __('Cuándo transferir a una persona') }}</label>
                        <textarea id="adv-handoff" wire:model="handoffRules" rows="3" maxlength="2000" placeholder="{{ __('Ej.: si pide hablar con alguien, si quiere una beca especial o si está molesto.') }}"></textarea>
                        @error('handoffRules') <span class="mca-err">{{ $message }}</span> @enderror
                    </div>
                </div>
            </div>

            {{-- Presentación del widget: los dos textos visibles antes de abrir el chat --}}
            <div class="card card-p fade" style="margin-top:16px">
                <div class="mca-section" style="border-top:none;padding-top:0;margin-top:0">
                    <h3><x-ui.icon name="message-circle" class="ic" style="width:17px;height:17px" /> {{ __('Presentación del widget') }}</h3>
                    <p class="mca-sub">{{ __('Lo que ve el visitante en la web antes de abrir el chat. Déjalo vacío para usar el texto actual del widget. Texto plano: se admiten emojis, no HTML.') }}</p>
                </div>
                <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(240px,1fr));gap:16px">
                    <div class="field" style="margin-bottom:0">
                        <label for="adv-welcome-es">{{ __('Mensaje de bienvenida') }} <span class="mca-help" style="display:inline">· {{ __('Español') }}</span></label>
                        <input id="adv-welcome-es" type="text" wire:model="welcomeEs" maxlength="200" placeholder="{{ $widgetDefaults['es']['welcome'] ?? '' }}">
                        @error('welcomeEs') <span class="mca-err">{{ $message }}</span> @enderror
                    </div>
                    <div class="field" style="margin-bottom:0">
                        <label for="adv-button-es">{{ __('Texto del botón') }} <span class="mca-help" style="display:inline">· {{ __('Español') }}</span></label>
                        <input id="adv-button-es" type="text" wire:model="buttonEs" maxlength="40" placeholder="{{ $widgetDefaults['es']['button'] ?? '' }}">
                        @error('buttonEs') <span class="mca-err">{{ $message }}</span> @enderror
                    </div>
                    <div class="field" style="margin-bottom:0">
                        <label for="adv-welcome-en">{{ __('Mensaje de bienvenida') }} <span class="mca-help" style="display:inline">· English</span></label>
                        <input id="adv-welcome-en" type="text" wire:model="welcomeEn" maxlength="200" placeholder="{{ $widgetDefaults['en']['welcome'] ?? '' }}">
                        @error('welcomeEn') <span class="mca-err">{{ $message }}</span> @enderror
                    </div>
                    <div class="field" style="margin-bottom:0">
                        <label for="adv-button-en">{{ __('Texto del botón') }} <span class="mca-help" style="display:inline">· English</span></label>
                        <input id="adv-button-en" type="text" wire:model="buttonEn" maxlength="40" placeholder="{{ $widgetDefaults['en']['button'] ?? '' }}">
                        @error('buttonEn') <span class="mca-err">{{ $message }}</span> @enderror
                    </div>
                </div>
                <div class="mca-help" style="margin-top:10px">{{ __('El widget ya instalado en la web los recibe al cargar la página (no hace falta cambiar el código incrustado). El indicador «En línea» no cambia.') }}</div>
                <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(240px,1fr));gap:16px;margin-top:16px">
                    <div class="field" style="margin-bottom:0">
                        <label for="adv-greeting-es">{{ __('Saludo inicial de la conversación') }} <span class="mca-help" style="display:inline">· {{ __('Español') }}</span></label>
                        <textarea id="adv-greeting-es" wire:model="greetingEs" rows="2" maxlength="500" data-testid="greeting-es"></textarea>
                        @error('greetingEs') <span class="mca-err">{{ $message }}</span> @enderror
                    </div>
                    <div class="field" style="margin-bottom:0">
                        <label for="adv-greeting-en">{{ __('Saludo inicial de la conversación') }} <span class="mca-help" style="display:inline">· English</span></label>
                        <textarea id="adv-greeting-en" wire:model="greetingEn" rows="2" maxlength="500"></textarea>
                        @error('greetingEn') <span class="mca-err">{{ $message }}</span> @enderror
                    </div>
                </div>
                <div class="mca-help" style="margin-top:6px">{{ __('Primer mensaje del asesor al empezar a conversar. Vacío = el saludo por defecto. También lo cambia una respuesta aprobada sobre el saludo en «Probar asesor».') }}</div>
            </div>
        @endif

        {{-- Guardar --}}
        <div style="margin-top:18px;display:flex;align-items:center;gap:12px">
            <button type="button" wire:click="save" wire:loading.attr="disabled" wire:target="save" class="btn btn-primary">
                <span wire:loading.remove wire:target="save" class="adv-bi"><x-ui.icon name="check" class="ic" style="width:16px;height:16px" /> {{ $editing ? __('Guardar cambios') : __('Crear asesor') }}</span>
                <span wire:loading.inline-flex wire:target="save" class="adv-bg"><span class="mca-spin"></span> {{ __('Guardando…') }}</span>
            </button>
            <a href="{{ route('advisors.index') }}" class="btn btn-ghost">{{ __('Cancelar') }}</a>
        </div>

        {{-- Probar asesor (solo IA en edición): enlace privado del modo de prueba --}}
        @if ($editing && $type === 'ia')
            <div class="card card-p fade" style="margin-top:22px">
                <div class="mca-section" style="border-top:none;padding-top:0;margin-top:0">
                    <h3><x-ui.icon name="sparkles" class="ic" style="width:17px;height:17px" /> {{ __('Probar asesor') }}</h3>
                    <p class="mca-sub">{{ __('Conversa con el asesor real (mismo modelo, instrucciones y conocimiento) antes de activarlo. Las conversaciones de prueba no crean contactos ni leads, no envían nada a redes y no cuentan en las métricas.') }}</p>
                </div>

                @if ($previewUrl)
                    <div x-data="{ copied: false }" style="display:flex;flex-wrap:wrap;gap:8px;align-items:center">
                        <a href="{{ $previewUrl }}" target="_blank" rel="noopener noreferrer" class="btn btn-primary btn-sm"><span class="adv-bi"><x-ui.icon name="external-link" class="ic" style="width:15px;height:15px" /> {{ __('Abrir prueba en nueva pestaña') }}</span></a>
                        <button type="button" class="btn btn-ghost btn-sm" x-on:click="navigator.clipboard.writeText(@js($previewUrl)).then(() => { copied = true; setTimeout(() => copied = false, 2000) })">
                            <span class="adv-bi" x-show="! copied"><x-ui.icon name="file-text" class="ic" style="width:15px;height:15px" /> {{ __('Copiar enlace') }}</span>
                            <span class="adv-bi" x-show="copied" x-cloak><x-ui.icon name="check" class="ic" style="width:15px;height:15px" /> {{ __('Copiado') }}</span>
                        </button>
                        <button type="button" wire:click="generatePreviewLink" wire:confirm="{{ __('El enlace actual dejará de funcionar. ¿Generar uno nuevo?') }}" class="btn btn-ghost btn-sm"><span class="adv-bi"><x-ui.icon name="refresh" class="ic" style="width:15px;height:15px" /> {{ __('Regenerar') }}</span></button>
                        <button type="button" wire:click="revokePreviewLink" wire:confirm="{{ __('¿Revocar el enlace de prueba? Dejará de funcionar para todos.') }}" class="btn btn-ghost btn-sm"><span class="adv-bi"><x-ui.icon name="x" class="ic" style="width:15px;height:15px" /> {{ __('Revocar') }}</span></button>
                    </div>
                    <div class="mca-help" style="margin-top:8px">{{ __('Enlace privado: compártelo solo con el equipo. Quien lo tenga puede conversar con el asesor en modo de prueba.') }}</div>
                @else
                    <button type="button" wire:click="generatePreviewLink" class="btn btn-primary btn-sm"><span class="adv-bi"><x-ui.icon name="plus" class="ic" style="width:15px;height:15px" /> {{ __('Generar enlace de prueba') }}</span></button>
                @endif

                @if ($feedback && ($feedback['correct'] + $feedback['needs_improvement']) > 0)
                    <div class="mca-section">
                        <h3 style="font-size:14px">{{ __('Valoraciones del equipo') }}</h3>
                        <p class="mca-sub">{{ __(':ok correctas · :bad necesitan mejora. Las marcadas como «Esta es la respuesta correcta» pasan a «Correcciones aprendidas»; los comentarios no cambian nada automáticamente.', ['ok' => $feedback['correct'], 'bad' => $feedback['needs_improvement']]) }}</p>
                        @foreach ($feedback['recent'] as $f)
                            <div style="border:1px solid var(--line);border-radius:10px;padding:8px 10px;margin-top:6px;font-size:13px">
                                <div class="mca-help">{{ \Illuminate\Support\Str::limit((string) $f->message?->content, 160) }}</div>
                                @if ($f->comment)
                                    <div style="margin-top:4px"><strong>{{ __('Observación') }}:</strong> {{ $f->comment }}</div>
                                @endif
                            </div>
                        @endforeach
                    </div>
                @endif
            </div>
        @endif

        {{-- Correcciones aprendidas: respuestas aprobadas por el equipo en «Probar asesor» --}}
        @if ($editing && $type === 'ia')
            <div class="card card-p fade" style="margin-top:22px" data-testid="corrections">
                <div class="mca-section" style="border-top:none;padding-top:0;margin-top:0">
                    <h3><x-ui.icon name="check" class="ic" style="width:17px;height:17px" /> {{ __('Correcciones aprendidas') }}</h3>
                    <p class="mca-sub">{{ __('Respuestas aprobadas por el equipo en «Probar asesor». El asesor las sigue al instante en preguntas equivalentes del mismo tema. Desactiva o elimina las que ya no correspondan.') }}</p>
                </div>
                @forelse ($corrections as $c)
                    <div wire:key="corr-{{ $c->id }}" style="border:1px solid var(--line);border-radius:10px;padding:10px 12px;margin-top:8px;font-size:13px;{{ $c->active ? '' : 'opacity:.6' }}">
                        @if ($editingCorrectionId === $c->id)
                            <div class="field"><label>{{ __('Pregunta') }}</label><textarea wire:model="correctionQuestion" rows="2" maxlength="1000"></textarea>@error('correctionQuestion') <span class="mca-err">{{ $message }}</span> @enderror</div>
                            <div class="field"><label>{{ __('Tema') }}</label>
                                <select wire:model="correctionTopic">
                                    <option value="">{{ __('General (cualquier tema)') }}</option>
                                    @foreach ($lineLabels as $slug => $label)<option value="{{ $slug }}">{{ __($label) }}</option>@endforeach
                                </select>@error('correctionTopic') <span class="mca-err">{{ $message }}</span> @enderror</div>
                            <div class="field"><label>{{ __('Respuesta aprobada') }}</label><textarea wire:model="correctionAnswer" rows="4" maxlength="1000"></textarea>@error('correctionAnswer') <span class="mca-err">{{ $message }}</span> @enderror</div>
                            <div style="display:flex;gap:8px"><button type="button" wire:click="saveCorrection" class="btn btn-primary btn-sm">{{ __('Guardar') }}</button><button type="button" wire:click="cancelCorrection" class="btn btn-ghost btn-sm">{{ __('Cancelar') }}</button></div>
                        @else
                            <div><strong>{{ __('Pregunta') }}:</strong> {{ $c->question }}</div>
                            <div class="mca-help">{{ __('Tema') }}: {{ $c->topic_line ? __($lineLabels[$c->topic_line] ?? $c->topic_line) : __('General (cualquier tema)') }} · {{ $c->user?->name ?? '—' }} · {{ $c->updated_at?->format('d/m/Y H:i') }} · {{ $c->active ? __('Activa') : __('Desactivada') }}</div>
                            <div style="margin-top:4px"><strong>{{ __('Respuesta aprobada') }}:</strong> {{ $c->answer }}</div>
                            <div style="display:flex;gap:8px;margin-top:8px">
                                <button type="button" wire:click="editCorrection({{ $c->id }})" class="btn btn-ghost btn-sm">{{ __('Editar') }}</button>
                                <button type="button" wire:click="toggleCorrection({{ $c->id }})" class="btn btn-ghost btn-sm">{{ $c->active ? __('Desactivar') : __('Activar') }}</button>
                                <button type="button" wire:click="deleteCorrection({{ $c->id }})" wire:confirm="{{ __('¿Eliminar esta corrección? El asesor dejará de usarla.') }}" class="btn btn-soft btn-sm">{{ __('Eliminar') }}</button>
                            </div>
                        @endif
                    </div>
                @empty
                    <p class="mca-help" style="margin:0">{{ __('Aún no hay correcciones. En «Probar asesor», marca «Necesita mejora» en una respuesta y escribe la respuesta correcta.') }}</p>
                @endforelse
            </div>
        @endif

        {{-- Base de conocimiento (solo IA en edicion) --}}
        @if ($editing && $type === 'ia')
            <div class="card card-p fade" style="margin-top:22px">
                <div class="mca-section" style="border-top:none;padding-top:0;margin-top:0;display:flex;align-items:flex-start;justify-content:space-between;gap:12px">
                    <div>
                        <h3>{{ __('Base de conocimiento') }}</h3>
                        <p class="mca-sub" style="margin-bottom:0">{!! __('Sube uno o varios .md: entran a la biblioteca central (upsert por código) y quedan asignados a este asesor. La gestión completa (categorías, otros agentes) está en el :link.', ['link' => '<a href="'.e(route('ai.knowledge.agents')).'" style="color:var(--mca,#1E5AA8);font-weight:600">'.e(__('Centro de Conocimiento')).'</a>']) !!}</p>
                    </div>
                    <button type="button" wire:click="sync" wire:loading.attr="disabled" wire:target="sync" class="btn btn-ghost btn-sm">
                        <span wire:loading.remove wire:target="sync" class="adv-bi"><x-ui.icon name="refresh" class="ic" style="width:15px;height:15px" /> {{ __('Re-sincronizar') }}</span>
                        <span wire:loading.inline-flex wire:target="sync" class="adv-bg"><span class="mca-spin"></span> …</span>
                    </button>
                </div>

                <div class="mca-drop" style="margin-bottom:14px">
                    <label class="mca-filebtn"><x-ui.icon name="upload" class="ic" style="width:15px;height:15px" /> {{ __('Elegir archivos .md') }}
                        <input type="file" wire:model="docs" accept=".md" multiple class="hidden">
                    </label>
                    <span wire:loading wire:target="docs" class="mca-help" style="margin-left:8px"><span class="mca-spin"></span> {{ __('Cargando…') }}</span>
                    @error('docs') <div class="mca-err">{{ $message }}</div> @enderror
                    @if (count($docs))
                        <div style="margin-top:12px">
                            <div class="mca-help">{{ __(':n archivo(s) listo(s):', ['n' => count($docs)]) }}</div>
                            <ul style="margin:6px 0 0;padding-left:18px;font-size:13px" class="mca-muted">
                                @foreach ($docs as $d)<li>{{ $d->getClientOriginalName() }}</li>@endforeach
                            </ul>
                            <button type="button" wire:click="uploadKnowledge" class="btn btn-primary btn-sm" style="margin-top:10px">{{ __('Subir y sincronizar') }}</button>
                        </div>
                    @endif
                </div>

                @forelse ($sources as $source)
                    <div class="mca-doc fade" wire:key="ks-{{ $source->id }}">
                        <span class="di"><x-ui.icon name="file-text" class="ic" style="width:18px;height:18px" /></span>
                        <div class="dm">
                            <b>{{ $source->name }}</b>
                            <span>{{ $source->code }} · {{ $source->last_synced_at ? __('sincronizado :t', ['t' => $source->last_synced_at->diffForHumans()]) : __('sin sincronizar') }}@if (! $source->pivot->is_active) · <em>{{ __('pausada para este asesor') }}</em>@endif</span>
                        </div>
                        <button type="button" wire:click="removeKnowledge({{ $source->id }})"
                                wire:confirm="{{ __('¿Quitar este documento de este asesor? Seguirá disponible en la biblioteca.') }}" class="btn btn-danger btn-sm" title="{{ __('Quitar de este asesor') }}" aria-label="{{ __('Quitar de este asesor') }}">
                            <x-ui.icon name="trash" class="ic" style="width:15px;height:15px" />
                        </button>
                    </div>
                @empty
                    <div class="mca-help">{{ __('Sin documentos. Sube uno o varios .md para cargar el conocimiento del asesor.') }}</div>
                @endforelse
            </div>
        @endif

        {{-- Incrustar widget (solo edicion): dos variantes listas para copiar --}}
        @if ($editing && $embedSnippet)
            <div class="card card-p fade" style="margin-top:22px"
                 x-data="{
                     copiedA: false, copiedB: false,
                     snippets: { a: @js($embedSnippet), b: @js($embedSnippetJs) },
                     copy(which) {
                         const text = this.snippets[which];
                         const key = 'copied' + which.toUpperCase();
                         const mark = () => { this[key] = true; setTimeout(() => this[key] = false, 2000) };
                         if (navigator.clipboard && window.isSecureContext) {
                             navigator.clipboard.writeText(text).then(mark).catch(() => this.fallback(text, mark));
                         } else {
                             this.fallback(text, mark);
                         }
                     },
                     fallback(text, mark) {
                         const ta = document.createElement('textarea');
                         ta.value = text; ta.style.position = 'fixed'; ta.style.opacity = '0';
                         document.body.appendChild(ta); ta.focus(); ta.select();
                         try { document.execCommand('copy'); mark(); } catch (e) {}
                         document.body.removeChild(ta);
                     }
                 }">
                <div class="mca-section" style="border-top:none;padding-top:0;margin-top:0">
                    <h3><x-ui.icon name="globe" class="ic" style="width:17px;height:17px" /> {{ __('Incrustar widget') }}</h3>
                    <p class="mca-sub" style="margin-bottom:14px">{!! __('Lleva la <b>clave pública</b> de este asesor (no contiene secretos). Elige la variante según dónde lo pegues.') !!}</p>
                </div>

                {{-- Opción 1: etiqueta <script> --}}
                <div class="mca-lbl" style="margin-bottom:6px">{!! __('Opción 1 · Etiqueta <code>&lt;script&gt;</code> (pégala antes de <code>&lt;/body&gt;</code>)') !!}</div>
                <div style="position:relative">
                    <pre style="background:var(--mca-blue-deep,#13253D);color:#E7EEF7;border-radius:12px;padding:16px 16px 16px 18px;margin:0;font-size:12.5px;line-height:1.55;overflow-x:auto;white-space:pre;font-family:ui-monospace,SFMono-Regular,Menlo,monospace"><code>{{ $embedSnippet }}</code></pre>
                    <button type="button" @click="copy('a')" class="btn btn-primary btn-sm" style="position:absolute;top:10px;right:10px" :class="{ 'btn-ok': copiedA }">
                        <span x-show="!copiedA" class="adv-bi"><x-ui.icon name="file-text" class="ic" style="width:14px;height:14px" /> {{ __('Copiar') }}</span>
                        <span x-show="copiedA" x-cloak class="adv-bi"><x-ui.icon name="check" class="ic" style="width:14px;height:14px" /> {{ __('¡Copiado!') }}</span>
                    </button>
                </div>

                {{-- Opción 2: JavaScript puro (WordPress / "Custom Scripts / Footer", sin <script>) --}}
                <div class="mca-lbl" style="margin:18px 0 6px">{!! __('Opción 2 · JavaScript (WordPress, campo «Custom Scripts / Footer» — sin <code>&lt;script&gt;</code>)') !!}</div>
                <div style="position:relative">
                    <pre style="background:var(--mca-blue-deep,#13253D);color:#E7EEF7;border-radius:12px;padding:16px 16px 16px 18px;margin:0;font-size:12.5px;line-height:1.55;overflow-x:auto;white-space:pre;font-family:ui-monospace,SFMono-Regular,Menlo,monospace"><code>{{ $embedSnippetJs }}</code></pre>
                    <button type="button" @click="copy('b')" class="btn btn-primary btn-sm" style="position:absolute;top:10px;right:10px" :class="{ 'btn-ok': copiedB }">
                        <span x-show="!copiedB" class="adv-bi"><x-ui.icon name="file-text" class="ic" style="width:14px;height:14px" /> {{ __('Copiar') }}</span>
                        <span x-show="copiedB" x-cloak class="adv-bi"><x-ui.icon name="check" class="ic" style="width:14px;height:14px" /> {{ __('¡Copiado!') }}</span>
                    </button>
                </div>

                <div class="mca-help" style="margin-top:12px">
                    {!! __('Clave pública de <b>:name</b>: <code>:key</code> · servido desde <code>:url</code>.', ['name' => e($bot->assistant_name), 'key' => e($bot->public_key), 'url' => e(config('crm.widget_embed_url'))]) !!}<br>
                    {!! __('<code>data-offset-bottom</code> (px) separa el lanzador del borde inferior para no chocar con botones flotantes (p. ej. «subir arriba»). Valor actual: <code>:value</code>; por defecto 24 si se omite.', ['value' => (int) config('crm.widget_offset_bottom', 90)]) !!}
                </div>
            </div>
        @endif

        {{-- Zona de peligro: eliminar (solo edicion) --}}
        @if ($editing)
            <div class="mca-danger fade" style="margin-top:22px">
                <h3>{{ __('Eliminar asesor') }}</h3>
                @if ($deleteBlockReason)
                    <p class="mca-sub" style="margin:0 0 4px">{!! __('El borrado es <b>permanente</b> y distinto de desactivar.') !!}</p>
                    <div class="mca-toast err" style="margin:10px 0 0"><x-ui.icon name="x" class="ic" /> {{ $deleteBlockReason }}</div>
                @else
                    <p class="mca-sub" style="margin:0 0 12px">{{ __('Borra permanentemente el asesor y su configuración (conocimiento y proceso). El histórico de conversaciones/leads/eventos NO se borra. Esta acción no se puede deshacer.') }}</p>
                    <button type="button" wire:click="confirmDelete" class="btn btn-danger">
                        <x-ui.icon name="trash" class="ic" style="width:16px;height:16px" /> {{ __('Eliminar asesor') }}
                    </button>
                @endif
            </div>
        @endif
    </div>

    {{-- Modal de confirmacion (exige teclear el nombre exacto) --}}
    @if ($confirmingDelete && $bot)
        <div class="mca-panel adv-form">
            <div class="mca-modal-bg" wire:key="del-modal">
                <div class="mca-modal">
                    <div class="mm-ic"><x-ui.icon name="trash" class="ic" style="width:22px;height:22px" /></div>
                    <h2>{{ __('Eliminar a :name', ['name' => $bot->assistant_name]) }}</h2>
                    <p>{!! __('Esta acción es <b>permanente</b> y no se puede deshacer. Se borrarán el asesor, su foto, su base de conocimiento (archivos incluidos) y su configuración de IA.') !!}</p>
                    <div class="warn">{{ __('El histórico de conversaciones, leads y eventos NO se borra.') }}</div>
                    <div class="field">
                        <label class="mca-lbl">{!! __('Escribe <b>:name</b> para confirmar', ['name' => e($bot->assistant_name)]) !!}</label>
                        <input type="text" wire:model.live="deleteConfirmName" placeholder="{{ $bot->assistant_name }}" autocomplete="off">
                        @error('deleteConfirmName') <span class="mca-err">{{ $message }}</span> @enderror
                    </div>
                    <div style="display:flex;gap:8px;justify-content:flex-end;margin-top:6px">
                        <button type="button" wire:click="cancelDelete" class="btn btn-ghost">{{ __('Cancelar') }}</button>
                        <button type="button" wire:click="deleteAdvisor" @disabled(! $deleteNameMatches) class="btn btn-danger-solid">
                            <x-ui.icon name="trash" class="ic" style="width:15px;height:15px" /> {{ __('Eliminar definitivamente') }}
                        </button>
                    </div>
                </div>
            </div>
        </div>
    @endif
</div>
