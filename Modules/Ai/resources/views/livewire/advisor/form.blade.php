<div class="mca-page ui-record">
    <x-ui.page-header
        :title="$editing ? __('Configurar asesor') : __('Crear asesor')"
        :subtitle="$editing ? __('Identidad, tipo, foto, proceso de IA y conocimiento.') : __('Identidad, tipo, foto y proceso de IA.')"
        :back="route('advisors.index')" :backLabel="__('Asesores Inteligentes')" />

    @if (session('status'))
        <div class="ui-alert ui-alert--flush ui-tone--success" role="status"><x-ui.icon name="check" /> {{ session('status') }}</div>
    @endif
    @if (session('status_error'))
        <div class="ui-alert ui-alert--flush ui-tone--danger" role="alert"><x-ui.icon name="x" /> {{ session('status_error') }}</div>
    @endif

    {{-- Índice de secciones (anclas: todos los campos siguen visibles en la página) --}}
    @if ($type === 'ia')
        <nav class="ui-subnav" aria-label="{{ __('Secciones del asesor') }}">
            <a href="#adv-identity"><x-ui.icon name="user" /> {{ __('Identidad') }}</a>
            <a href="#adv-ai"><x-ui.icon name="cpu" /> {{ __('IA') }}</a>
            <a href="#adv-prompt"><x-ui.icon name="user-cog" /> {{ __('Instrucciones') }}</a>
            <a href="#adv-widget"><x-ui.icon name="message-circle" /> {{ __('Widget') }}</a>
            @if ($editing)
                <a href="#adv-test"><x-ui.icon name="sparkles" /> {{ __('Prueba') }}</a>
                @if (class_exists(\Modules\Social\Livewire\AdvisorChannels::class))
                    <a href="#adv-channels"><x-ui.icon name="inbox" /> {{ __('Canales') }}</a>
                @endif
                <a href="#adv-corrections"><x-ui.icon name="check" /> {{ __('Correcciones') }} <span class="ui-badge ui-badge--count ui-tone--neutral">{{ count($corrections) }}</span></a>
                <a href="#adv-knowledge"><x-ui.icon name="book-open" /> {{ __('Conocimiento') }} <span class="ui-badge ui-badge--count ui-tone--neutral">{{ count($sources) }}</span></a>
                @if ($embedSnippet)
                    <a href="#adv-embed"><x-ui.icon name="globe" /> {{ __('Incrustar') }}</a>
                @endif
            @endif
        </nav>
    @endif

    {{-- Secciones que guarda «Guardar cambios» / «Crear asesor» (la barra queda fija al pie mientras se editan) --}}
    <div class="ui-stack">
        {{-- Identidad --}}
        <section class="ui-card" id="adv-identity" aria-labelledby="adv-identity-t">
            <div class="ui-card-h"><x-ui.icon name="user" class="ui-card-ic" /><h2 id="adv-identity-t">{{ __('Identidad') }}</h2></div>
            <div class="ui-card-p ui-identity">
                {{-- Foto (solo en edición; en creación se sube tras guardar) --}}
                <div class="ui-photo">
                    @if ($avatar)
                        <span class="ui-avatar ui-avatar--photo ui-tone--blue"><img src="{{ $avatar->temporaryUrl() }}" alt="{{ __('Vista previa de la foto') }}"></span>
                    @else
                        <x-ui.avatar :name="$name" :src="$avatarUrl" :seed="$botId" class="ui-avatar--photo" />
                    @endif
                    @if ($editing)
                        <label class="ui-btn ui-btn--secondary ui-btn--sm">
                            <x-ui.icon name="upload" /> {{ __('Elegir foto') }}
                            <input type="file" wire:model="avatar" accept=".png,.jpg,.jpeg,.svg,.webp,.gif" class="hidden">
                        </label>
                        <span wire:loading wire:target="avatar" class="ui-help"><span class="ui-spin"></span> {{ __('Cargando…') }}</span>
                        @error('avatar') <p class="ui-error" role="alert"><x-ui.icon name="alert-triangle" /> {{ $message }}</p> @enderror
                        @if ($avatar || $avatarUrl)
                            <div class="ui-actions-row">
                                @if ($avatar)
                                    <x-ui.button size="sm" variant="primary" icon="check" wire:click="saveAvatar">{{ __('Guardar foto') }}</x-ui.button>
                                @endif
                                @if ($avatarUrl)
                                    <x-ui.button size="sm" variant="ghost" wire:click="removeAvatar">{{ __('Quitar') }}</x-ui.button>
                                @endif
                            </div>
                        @endif
                        <p class="ui-help">{{ __('PNG, JPG, SVG o WebP · máx 1 MB') }}</p>
                    @else
                        <p class="ui-help">{{ __('La foto se sube al guardar') }}</p>
                    @endif
                </div>

                <div class="ui-form-grid">
                    <x-ui.field class="ui-span-full" :label="__('Nombre del asesor')" for="adv-name" required :error="$errors->first('name')" :help="__('El widget y los saludos leen este valor.')">
                        <input id="adv-name" type="text" wire:model="name" maxlength="60" placeholder="{{ __('Ej. Celia') }}" @if ($errors->has('name')) aria-describedby="adv-name-error" @endif @class(['ui-input', 'is-invalid' => $errors->has('name')])>
                    </x-ui.field>

                    <div class="ui-field">
                        <span class="ui-label" id="adv-type-l">{{ __('Tipo') }}</span>
                        <div class="ui-seg ui-seg--icons" role="group" aria-labelledby="adv-type-l">
                            <button type="button" wire:click="$set('type','ia')" aria-pressed="{{ $type === 'ia' ? 'true' : 'false' }}">
                                <x-ui.icon name="bot" /> {{ __('IA') }}
                            </button>
                            <button type="button" wire:click="$set('type','human')" aria-pressed="{{ $type === 'human' ? 'true' : 'false' }}">
                                <x-ui.icon name="user" /> {{ __('Humano') }}
                            </button>
                        </div>
                        <p class="ui-help">{{ __('“IA” opera hoy. “Humano” queda como ficha etiqueta para intervención en vivo (futuro).') }}</p>
                    </div>

                    <div class="ui-field">
                        <span class="ui-label" id="adv-status-l">{{ __('Estado') }}</span>
                        <div class="ui-seg" role="group" aria-labelledby="adv-status-l">
                            <button type="button" wire:click="$set('status','active')" aria-pressed="{{ $status === 'active' ? 'true' : 'false' }}">{{ __('Activo') }}</button>
                            <button type="button" wire:click="$set('status','inactive')" aria-pressed="{{ $status === 'inactive' ? 'true' : 'false' }}">{{ __('Inactivo') }}</button>
                        </div>
                    </div>
                </div>
            </div>
        </section>

        {{-- Configuración de IA (solo tipo IA) --}}
        @if ($type === 'ia')
            <section class="ui-card" id="adv-ai" aria-labelledby="adv-ai-t">
                <div class="ui-card-h"><x-ui.icon name="cpu" class="ui-card-ic" /><h2 id="adv-ai-t">{{ __('Configuración de IA') }}</h2></div>
                <div class="ui-card-p">
                    <p class="ui-card-desc">{!! __('Proceso de conversación e idioma. Las credenciales se gestionan en :link (no se duplican aquí).', ['link' => '<a href="'.e(route('integrations.index')).'" class="ui-link">'.e(__('Integraciones')).'</a>']) !!}</p>
                    <div class="ui-form-grid ui-form-grid--fluid">
                        <x-ui.field :label="__('Idioma principal')" for="adv-lang" :error="$errors->first('language')">
                            <select id="adv-lang" wire:model="language" @class(['ui-select', 'is-invalid' => $errors->has('language')])>
                                <option value="es">Español</option>
                                <option value="en">English</option>
                            </select>
                        </x-ui.field>
                        <x-ui.field :label="__('Proveedor (integración)')" for="adv-int" :error="$errors->first('integrationId')">
                            <select id="adv-int" wire:model.live="integrationId" @class(['ui-select', 'is-invalid' => $errors->has('integrationId')])>
                                <option value="">{{ __('— Elegir —') }}</option>
                                @foreach ($integrations as $int)
                                    <option value="{{ $int->id }}">{{ $int->name }} ({{ $int->provider }})</option>
                                @endforeach
                            </select>
                            @if ($integrations->isEmpty())
                                <p class="ui-help">{!! __('No hay proveedores de IA. :link.', ['link' => '<a href="'.e(route('integrations.index')).'" class="ui-link">'.e(__('Configura uno')).'</a>']) !!}</p>
                            @endif
                        </x-ui.field>
                        <x-ui.field :label="__('Modelo')" for="adv-model" :error="$errors->first('model')">
                            <input id="adv-model" type="text" wire:model.blur="model" maxlength="100" placeholder="{{ __('Ej. qwen3.7-plus') }}" @class(['ui-input', 'is-invalid' => $errors->has('model')])>
                        </x-ui.field>

                        <x-ui.field :label="__('Búsqueda en el conocimiento')" for="adv-retrieval" :error="$errors->first('knowledgeRetrieval')"
                                    :help="__('La precisa entiende variantes de las palabras, reconoce el programa nombrado, mantiene el tema en los seguimientos y solo deja enlaces que estén en el conocimiento. En «Probar asesor» muestra el diagnóstico de cada respuesta.')">
                            <select id="adv-retrieval" wire:model="knowledgeRetrieval" data-testid="knowledge-retrieval" @if ($errors->has('knowledgeRetrieval')) aria-describedby="adv-retrieval-error" @endif @class(['ui-select', 'is-invalid' => $errors->has('knowledgeRetrieval')])>
                                <option value="classic">{{ __('Clásica') }}</option>
                                <option value="precise">{{ __('Precisa (recomendada si el asesor atiende varias líneas)') }}</option>
                            </select>
                        </x-ui.field>
                        <x-ui.field :label="__('Respuestas de IA por conversación')" for="adv-limit" :error="$errors->first('messageLimit')"
                                    :help="__('Vacío = el valor general (:n).', ['n' => (int) config('crm.celia.message_limit', 15)])">
                            <input id="adv-limit" type="number" min="1" max="200" inputmode="numeric" wire:model="messageLimit" placeholder="{{ (int) config('crm.celia.message_limit', 15) }}" data-testid="message-limit" @if ($errors->has('messageLimit')) aria-describedby="adv-limit-error" @endif @class(['ui-input', 'is-invalid' => $errors->has('messageLimit')])>
                        </x-ui.field>
                        <x-ui.field :label="__('Espera mínima antes de responder')" for="adv-typing" :error="$errors->first('typingDelay')"
                                    :help="__('Segundos (0–8) que se muestra «está escribiendo…» como mínimo. Si la IA tarda más, la respuesta sale en cuanto llega. 0 = sin espera.')">
                            <input id="adv-typing" type="number" min="0" max="8" inputmode="numeric" wire:model="typingDelay" data-testid="typing-delay" @if ($errors->has('typingDelay')) aria-describedby="adv-typing-error" @endif @class(['ui-input', 'is-invalid' => $errors->has('typingDelay')])>
                        </x-ui.field>
                    </div>
                </div>
            </section>

            {{-- Identidad e instrucciones: cómo es y cómo responde ESTE asesor --}}
            <section class="ui-card" id="adv-prompt" aria-labelledby="adv-prompt-t">
                <div class="ui-card-h"><x-ui.icon name="user-cog" class="ui-card-ic" /><h2 id="adv-prompt-t">{{ __('Identidad e instrucciones') }}</h2></div>
                <div class="ui-card-p">
                    <p class="ui-card-desc">{{ __('Cómo se presenta y cómo responde este asesor. Se combina siempre con las reglas de la institución (es un asistente virtual, no inventa datos y responde solo con su conocimiento), que no se pueden desactivar desde aquí.') }}</p>
                    <div class="ui-form-grid">
                        @if ($usesGlobalPrompt)
                            <div class="ui-callout ui-callout--sm ui-tone--blue ui-span-full"><x-ui.icon name="bot" /> <span>{{ __('Este asesor usa ahora las instrucciones generales de siempre. Cuando completes esta sección, pasará a usar las suyas.') }}</span></div>
                        @endif
                        <x-ui.field class="ui-span-full" :label="__('Función o presentación')" for="adv-role" :error="$errors->first('roleDescription')" :help="__('El nombre público es el «Nombre del asesor» de arriba.')">
                            <input id="adv-role" type="text" wire:model="roleDescription" maxlength="255" placeholder="{{ __('Ej.: asesora de Diplomas Avanzados de la escuela') }}" @class(['ui-input', 'is-invalid' => $errors->has('roleDescription')])>
                        </x-ui.field>
                        <x-ui.field class="ui-span-full" :label="__('Instrucciones del asesor')" for="adv-instructions" :error="$errors->first('instructions')">
                            <textarea id="adv-instructions" wire:model="instructions" rows="7" maxlength="6000" placeholder="{{ __('Qué hace, a quién atiende, qué debe priorizar y cómo debe orientar a cada persona…') }}" @class(['ui-input ui-textarea', 'is-invalid' => $errors->has('instructions')])></textarea>
                        </x-ui.field>
                        <x-ui.field :label="__('Tono de comunicación')" for="adv-tone" :error="$errors->first('tone')">
                            <input id="adv-tone" type="text" wire:model="tone" maxlength="255" placeholder="{{ __('Ej.: cercano y profesional, frases breves') }}" @class(['ui-input', 'is-invalid' => $errors->has('tone')])>
                        </x-ui.field>
                        <x-ui.field :label="__('Mensaje para información no encontrada')" for="adv-notfound" :error="$errors->first('notFoundMessage')">
                            <input id="adv-notfound" type="text" wire:model="notFoundMessage" maxlength="500" placeholder="{{ __('Ej.: No tengo ese dato; te recomiendo revisar la ficha del programa.') }}" @class(['ui-input', 'is-invalid' => $errors->has('notFoundMessage')])>
                        </x-ui.field>
                        <x-ui.field :label="__('Límites: asuntos que no debe responder')" for="adv-limits" :error="$errors->first('restrictions')">
                            <textarea id="adv-limits" wire:model="restrictions" rows="3" maxlength="2000" placeholder="{{ __('Ej.: precios concretos, temas médicos o legales, otras instituciones…') }}" @class(['ui-input ui-textarea', 'is-invalid' => $errors->has('restrictions')])></textarea>
                        </x-ui.field>
                        <x-ui.field :label="__('Cuándo transferir a una persona')" for="adv-handoff" :error="$errors->first('handoffRules')">
                            <textarea id="adv-handoff" wire:model="handoffRules" rows="3" maxlength="2000" placeholder="{{ __('Ej.: si pide hablar con alguien, si quiere una beca especial o si está molesto.') }}" @class(['ui-input ui-textarea', 'is-invalid' => $errors->has('handoffRules')])></textarea>
                        </x-ui.field>
                    </div>
                </div>
            </section>

            {{-- Presentación del widget: los dos textos visibles antes de abrir el chat --}}
            <section class="ui-card" id="adv-widget" aria-labelledby="adv-widget-t">
                <div class="ui-card-h"><x-ui.icon name="message-circle" class="ui-card-ic" /><h2 id="adv-widget-t">{{ __('Presentación del widget') }}</h2></div>
                <div class="ui-card-p ui-stack">
                    <p class="ui-card-desc">{{ __('Lo que ve el visitante en la web antes de abrir el chat. Déjalo vacío para usar el texto actual del widget. Texto plano: se admiten emojis, no HTML.') }}</p>
                    <div class="ui-form-grid">
                        <x-ui.field :label="__('Mensaje de bienvenida')" :hint="__('Español')" for="adv-welcome-es" :error="$errors->first('welcomeEs')">
                            <input id="adv-welcome-es" type="text" wire:model="welcomeEs" maxlength="200" placeholder="{{ $widgetDefaults['es']['welcome'] ?? '' }}" @class(['ui-input', 'is-invalid' => $errors->has('welcomeEs')])>
                        </x-ui.field>
                        <x-ui.field :label="__('Texto del botón')" :hint="__('Español')" for="adv-button-es" :error="$errors->first('buttonEs')">
                            <input id="adv-button-es" type="text" wire:model="buttonEs" maxlength="40" placeholder="{{ $widgetDefaults['es']['button'] ?? '' }}" @class(['ui-input', 'is-invalid' => $errors->has('buttonEs')])>
                        </x-ui.field>
                        <x-ui.field :label="__('Mensaje de bienvenida')" hint="English" for="adv-welcome-en" :error="$errors->first('welcomeEn')">
                            <input id="adv-welcome-en" type="text" wire:model="welcomeEn" maxlength="200" placeholder="{{ $widgetDefaults['en']['welcome'] ?? '' }}" @class(['ui-input', 'is-invalid' => $errors->has('welcomeEn')])>
                        </x-ui.field>
                        <x-ui.field :label="__('Texto del botón')" hint="English" for="adv-button-en" :error="$errors->first('buttonEn')">
                            <input id="adv-button-en" type="text" wire:model="buttonEn" maxlength="40" placeholder="{{ $widgetDefaults['en']['button'] ?? '' }}" @class(['ui-input', 'is-invalid' => $errors->has('buttonEn')])>
                        </x-ui.field>
                    </div>
                    <p class="ui-help">{{ __('El widget ya instalado en la web los recibe al cargar la página (no hace falta cambiar el código incrustado). El indicador «En línea» no cambia.') }}</p>
                    <div class="ui-form-grid">
                        <x-ui.field :label="__('Saludo inicial de la conversación')" :hint="__('Español')" for="adv-greeting-es" :error="$errors->first('greetingEs')">
                            <textarea id="adv-greeting-es" wire:model="greetingEs" rows="2" maxlength="500" data-testid="greeting-es" @class(['ui-input ui-textarea', 'is-invalid' => $errors->has('greetingEs')])></textarea>
                        </x-ui.field>
                        <x-ui.field :label="__('Saludo inicial de la conversación')" hint="English" for="adv-greeting-en" :error="$errors->first('greetingEn')">
                            <textarea id="adv-greeting-en" wire:model="greetingEn" rows="2" maxlength="500" @class(['ui-input ui-textarea', 'is-invalid' => $errors->has('greetingEn')])></textarea>
                        </x-ui.field>
                    </div>
                    <p class="ui-help">{{ __('Primer mensaje del asesor al empezar a conversar. Vacío = el saludo por defecto. También lo cambia una respuesta aprobada sobre el saludo en «Probar asesor».') }}</p>
                </div>
            </section>
        @endif

        {{-- Guardar --}}
        <div class="ui-savebar">
            <p class="ui-savebar-note ui-hide-sm">{{ $type === 'ia' ? __('Guarda la identidad, la configuración de IA, las instrucciones y el widget.') : __('Guarda la identidad del asesor.') }}</p>
            <x-ui.button :href="route('advisors.index')" variant="ghost">{{ __('Cancelar') }}</x-ui.button>
            <button type="button" wire:click="save" wire:loading.attr="disabled" wire:target="save" class="ui-btn ui-btn--primary">
                <span wire:loading.remove wire:target="save" class="ui-btn-label"><x-ui.icon name="check" /> {{ $editing ? __('Guardar cambios') : __('Crear asesor') }}</span>
                <span wire:loading.inline-flex wire:target="save" class="ui-btn-label"><span class="ui-spin"></span> {{ __('Guardando…') }}</span>
            </button>
        </div>
    </div>

    {{-- Probar asesor (solo IA en edición): enlace privado del modo de prueba --}}
    @if ($editing && $type === 'ia')
        <section class="ui-card" id="adv-test" aria-labelledby="adv-test-t">
            <div class="ui-card-h"><x-ui.icon name="sparkles" class="ui-card-ic" /><h2 id="adv-test-t">{{ __('Probar asesor') }}</h2></div>
            <div class="ui-card-p">
                <p class="ui-card-desc">{{ __('Conversa con el asesor real (mismo modelo, instrucciones y conocimiento) antes de activarlo. Las conversaciones de prueba no crean contactos ni leads, no envían nada a redes y no cuentan en las métricas.') }}</p>

                @if ($previewUrl)
                    <div x-data="{ copied: false }" class="ui-actions-row ui-actions-row--center">
                        <a href="{{ $previewUrl }}" target="_blank" rel="noopener noreferrer" class="ui-btn ui-btn--primary ui-btn--sm"><x-ui.icon name="external-link" /> {{ __('Abrir prueba en nueva pestaña') }}</a>
                        <button type="button" class="ui-btn ui-btn--secondary ui-btn--sm" x-on:click="navigator.clipboard.writeText(@js($previewUrl)).then(() => { copied = true; setTimeout(() => copied = false, 2000) })">
                            <span class="ui-btn-label" x-show="! copied"><x-ui.icon name="file-text" /> {{ __('Copiar enlace') }}</span>
                            <span class="ui-btn-label" x-show="copied" x-cloak><x-ui.icon name="check" /> {{ __('Copiado') }}</span>
                        </button>
                        <x-ui.button size="sm" variant="ghost" icon="refresh" wire:click="generatePreviewLink" wire:confirm="{{ __('El enlace actual dejará de funcionar. ¿Generar uno nuevo?') }}">{{ __('Regenerar') }}</x-ui.button>
                        <x-ui.button size="sm" variant="ghost" icon="x" wire:click="revokePreviewLink" wire:confirm="{{ __('¿Revocar el enlace de prueba? Dejará de funcionar para todos.') }}">{{ __('Revocar') }}</x-ui.button>
                    </div>
                    <p class="ui-note">{{ __('Enlace privado: compártelo solo con el equipo. Quien lo tenga puede conversar con el asesor en modo de prueba.') }}</p>
                @else
                    <x-ui.button size="sm" variant="primary" icon="plus" wire:click="generatePreviewLink">{{ __('Generar enlace de prueba') }}</x-ui.button>
                @endif
            </div>

            @if ($feedback && ($feedback['correct'] + $feedback['needs_improvement']) > 0)
                <div class="ui-card-p ui-card-p--sep">
                    <div>
                        <h3 class="ui-vr-title">{{ __('Valoraciones del equipo') }}</h3>
                        <p class="ui-help">{{ __(':ok correctas · :bad necesitan mejora. Las marcadas como «Esta es la respuesta correcta» pasan a «Correcciones aprendidas»; los comentarios no cambian nada automáticamente.', ['ok' => $feedback['correct'], 'bad' => $feedback['needs_improvement']]) }}</p>
                    </div>
                    @if (count($feedback['recent']))
                        <ul class="ui-list ui-list--boxed">
                            @foreach ($feedback['recent'] as $f)
                                <li class="ui-list-item">
                                    <span class="ui-list-main">
                                        <span class="ui-list-meta">{{ \Illuminate\Support\Str::limit((string) $f->message?->content, 160) }}</span>
                                        @if ($f->comment)
                                            <span><b class="ui-strong">{{ __('Observación') }}:</b> {{ $f->comment }}</span>
                                        @endif
                                    </span>
                                </li>
                            @endforeach
                        </ul>
                    @endif
                </div>
            @endif
        </section>
    @endif

    {{-- Canales de atención automática (Web Chat, Instagram, Messenger, WhatsApp) de este asesor --}}
    @if ($editing && $type === 'ia' && class_exists(\Modules\Social\Livewire\AdvisorChannels::class))
        <div id="adv-channels">
            @livewire('social.advisor-channels', ['botId' => $botId], key('advisor-channels-'.$botId))
        </div>
    @endif

    {{-- Correcciones aprendidas: respuestas aprobadas por el equipo en «Probar asesor» --}}
    @if ($editing && $type === 'ia')
        <section class="ui-card" id="adv-corrections" aria-labelledby="adv-corr-t" data-testid="corrections">
            <div class="ui-card-h"><x-ui.icon name="check" class="ui-card-ic" /><h2 id="adv-corr-t">{{ __('Correcciones aprendidas') }}</h2>
                <span class="ui-badge ui-badge--count ui-tone--neutral">{{ count($corrections) }}</span>
            </div>
            <div class="ui-card-p"><p class="ui-card-desc">{{ __('Respuestas aprobadas por el equipo en «Probar asesor». El asesor las sigue al instante en preguntas equivalentes del mismo tema. Desactiva o elimina las que ya no correspondan.') }}</p></div>
            <ul class="ui-list ui-list--sep">
                @forelse ($corrections as $c)
                    <li wire:key="corr-{{ $c->id }}">
                        @if ($editingCorrectionId === $c->id)
                            <div class="ui-subform">
                                <div class="ui-card-p">
                                    <div class="ui-form-grid">
                                        <x-ui.field class="ui-span-full" :label="__('Pregunta')" for="corr-q-{{ $c->id }}" :error="$errors->first('correctionQuestion')">
                                            <textarea id="corr-q-{{ $c->id }}" wire:model="correctionQuestion" rows="2" maxlength="1000" @class(['ui-input ui-textarea', 'is-invalid' => $errors->has('correctionQuestion')])></textarea>
                                        </x-ui.field>
                                        <x-ui.field class="ui-span-full" :label="__('Tema')" for="corr-t-{{ $c->id }}" :error="$errors->first('correctionTopic')">
                                            <select id="corr-t-{{ $c->id }}" wire:model="correctionTopic" @class(['ui-select', 'is-invalid' => $errors->has('correctionTopic')])>
                                                <option value="">{{ __('General (cualquier tema)') }}</option>
                                                @foreach ($lineLabels as $slug => $label)<option value="{{ $slug }}">{{ __($label) }}</option>@endforeach
                                            </select>
                                        </x-ui.field>
                                        <x-ui.field class="ui-span-full" :label="__('Respuesta aprobada')" for="corr-a-{{ $c->id }}" :error="$errors->first('correctionAnswer')">
                                            <textarea id="corr-a-{{ $c->id }}" wire:model="correctionAnswer" rows="4" maxlength="1000" @class(['ui-input ui-textarea', 'is-invalid' => $errors->has('correctionAnswer')])></textarea>
                                        </x-ui.field>
                                    </div>
                                </div>
                                <div class="ui-card-f ui-card-f--end">
                                    <x-ui.button size="sm" wire:click="cancelCorrection">{{ __('Cancelar') }}</x-ui.button>
                                    <x-ui.button size="sm" variant="primary" icon="check" wire:click="saveCorrection">{{ __('Guardar') }}</x-ui.button>
                                </div>
                            </div>
                        @else
                            <div class="ui-list-item ui-list-item--wrap">
                                <span class="ui-list-main">
                                    <span class="ui-list-title">{{ $c->question }}</span>
                                    <span class="ui-list-meta">{{ __('Tema') }}: {{ $c->topic_line ? __($lineLabels[$c->topic_line] ?? $c->topic_line) : __('General (cualquier tema)') }} · {{ $c->user?->name ?? '—' }} · {{ $c->updated_at?->userTz()->format('d/m/Y H:i') }}</span>
                                    <span class="ui-note-body"><b class="ui-strong">{{ __('Respuesta aprobada') }}:</b> {{ $c->answer }}</span>
                                </span>
                                <span class="ui-list-aside">
                                    <x-ui.badge :tone="$c->active ? 'success' : 'neutral'" dot>{{ $c->active ? __('Activa') : __('Desactivada') }}</x-ui.badge>
                                </span>
                                <span class="ui-list-foot">
                                    <x-ui.button size="sm" icon="pencil" wire:click="editCorrection({{ $c->id }})">{{ __('Editar') }}</x-ui.button>
                                    <x-ui.button size="sm" variant="ghost" icon="power" wire:click="toggleCorrection({{ $c->id }})">{{ $c->active ? __('Desactivar') : __('Activar') }}</x-ui.button>
                                    <x-ui.button size="sm" variant="danger" icon="trash" wire:click="deleteCorrection({{ $c->id }})" wire:confirm="{{ __('¿Eliminar esta corrección? El asesor dejará de usarla.') }}">{{ __('Eliminar') }}</x-ui.button>
                                </span>
                            </div>
                        @endif
                    </li>
                @empty
                    <li class="ui-list-empty">{{ __('Aún no hay correcciones. En «Probar asesor», marca «Necesita mejora» en una respuesta y escribe la respuesta correcta.') }}</li>
                @endforelse
            </ul>
        </section>
    @endif

    {{-- Base de conocimiento (solo IA en edición) --}}
    @if ($editing && $type === 'ia')
        <section class="ui-card" id="adv-knowledge" aria-labelledby="adv-kb-t">
            <div class="ui-card-h"><x-ui.icon name="book-open" class="ui-card-ic" /><h2 id="adv-kb-t">{{ __('Base de conocimiento') }}</h2>
                <span class="ui-badge ui-badge--count ui-tone--neutral">{{ count($sources) }}</span>
                <span class="sp"></span>
                <button type="button" wire:click="sync" wire:loading.attr="disabled" wire:target="sync" class="ui-btn ui-btn--secondary ui-btn--sm">
                    <span wire:loading.remove wire:target="sync" class="ui-btn-label"><x-ui.icon name="refresh" /> {{ __('Re-sincronizar') }}</span>
                    <span wire:loading.inline-flex wire:target="sync" class="ui-btn-label"><span class="ui-spin"></span> {{ __('Sincronizando…') }}</span>
                </button>
            </div>
            <div class="ui-card-p">
                <p class="ui-card-desc">{!! __('Sube uno o varios .md: entran a la biblioteca central (upsert por código) y quedan asignados a este asesor. La gestión completa (categorías, otros agentes) está en el :link.', ['link' => '<a href="'.e(route('ai.knowledge.agents')).'" class="ui-link">'.e(__('Centro de Conocimiento')).'</a>']) !!}</p>
                <div class="ui-actions-row ui-actions-row--center">
                    <label class="ui-btn ui-btn--secondary ui-btn--sm"><x-ui.icon name="upload" /> {{ __('Elegir archivos .md') }}
                        <input type="file" wire:model="docs" accept=".md" multiple class="hidden">
                    </label>
                    <span wire:loading wire:target="docs" class="ui-help"><span class="ui-spin"></span> {{ __('Cargando…') }}</span>
                </div>
                @error('docs') <p class="ui-error" role="alert"><x-ui.icon name="alert-triangle" /> {{ $message }}</p> @enderror
                @if (count($docs))
                    <div class="ui-callout ui-callout--stack ui-actions-row--top">
                        <b>{{ __(':n archivo(s) listo(s):', ['n' => count($docs)]) }}</b>
                        <ul class="ui-callout-list">
                            @foreach ($docs as $d)<li>{{ $d->getClientOriginalName() }}</li>@endforeach
                        </ul>
                        <x-ui.button size="sm" variant="primary" icon="upload" wire:click="uploadKnowledge">{{ __('Subir y sincronizar') }}</x-ui.button>
                    </div>
                @endif
            </div>
            <ul class="ui-list ui-list--sep">
                @forelse ($sources as $source)
                    <li wire:key="ks-{{ $source->id }}">
                        <div class="ui-list-item">
                            <span class="ui-list-ic"><x-ui.icon name="file-text" /></span>
                            <span class="ui-list-main">
                                <span class="ui-list-title">{{ $source->name }}</span>
                                <span class="ui-list-meta"><span class="ui-mono">{{ $source->code }}</span> · {{ $source->last_synced_at ? __('sincronizado :t', ['t' => $source->last_synced_at->diffForHumans()]) : __('sin sincronizar') }}</span>
                            </span>
                            <span class="ui-list-aside">
                                @if (! $source->pivot->is_active)
                                    <x-ui.badge tone="warning">{{ __('pausada para este asesor') }}</x-ui.badge>
                                @endif
                                <x-ui.button size="sm" variant="ghost" icon="trash" wire:click="removeKnowledge({{ $source->id }})"
                                    wire:confirm="{{ __('¿Quitar este documento de este asesor? Seguirá disponible en la biblioteca.') }}" :title="__('Quitar de este asesor')" :aria-label="__('Quitar de este asesor')" />
                            </span>
                        </div>
                    </li>
                @empty
                    <li class="ui-list-empty">{{ __('Sin documentos. Sube uno o varios .md para cargar el conocimiento del asesor.') }}</li>
                @endforelse
            </ul>
        </section>
    @endif

    {{-- Incrustar widget (solo edición): dos variantes listas para copiar --}}
    @if ($editing && $embedSnippet)
        <section class="ui-card" id="adv-embed" aria-labelledby="adv-embed-t"
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
            <div class="ui-card-h"><x-ui.icon name="globe" class="ui-card-ic" /><h2 id="adv-embed-t">{{ __('Incrustar widget') }}</h2></div>
            <div class="ui-card-p">
                <p class="ui-card-desc">{!! __('Lleva la <b>clave pública</b> de este asesor (no contiene secretos). Elige la variante según dónde lo pegues.') !!}</p>

                {{-- Opción 1: etiqueta <script> --}}
                <div class="ui-code">
                    <div class="ui-code-h">
                        <span>{!! __('Opción 1 · Etiqueta <code>&lt;script&gt;</code> (pégala antes de <code>&lt;/body&gt;</code>)') !!}</span>
                        <button type="button" @click="copy('a')" class="ui-btn ui-btn--secondary ui-btn--sm">
                            <span x-show="!copiedA" class="ui-btn-label"><x-ui.icon name="file-text" /> {{ __('Copiar') }}</span>
                            <span x-show="copiedA" x-cloak class="ui-btn-label"><x-ui.icon name="check" /> {{ __('¡Copiado!') }}</span>
                        </button>
                    </div>
                    <pre><code>{{ $embedSnippet }}</code></pre>
                </div>

                {{-- Opción 2: JavaScript puro (WordPress / "Custom Scripts / Footer", sin <script>) --}}
                <div class="ui-code">
                    <div class="ui-code-h">
                        <span>{!! __('Opción 2 · JavaScript (WordPress, campo «Custom Scripts / Footer» — sin <code>&lt;script&gt;</code>)') !!}</span>
                        <button type="button" @click="copy('b')" class="ui-btn ui-btn--secondary ui-btn--sm">
                            <span x-show="!copiedB" class="ui-btn-label"><x-ui.icon name="file-text" /> {{ __('Copiar') }}</span>
                            <span x-show="copiedB" x-cloak class="ui-btn-label"><x-ui.icon name="check" /> {{ __('¡Copiado!') }}</span>
                        </button>
                    </div>
                    <pre><code>{{ $embedSnippetJs }}</code></pre>
                </div>

                <p class="ui-note">
                    {!! __('Clave pública de <b>:name</b>: <code>:key</code> · servido desde <code>:url</code>.', ['name' => e($bot->assistant_name), 'key' => e($bot->public_key), 'url' => e(config('crm.widget_embed_url'))]) !!}<br>
                    {!! __('<code>data-offset-bottom</code> (px) separa el lanzador del borde inferior para no chocar con botones flotantes (p. ej. «subir arriba»). Valor actual: <code>:value</code>; por defecto 24 si se omite.', ['value' => (int) config('crm.widget_offset_bottom', 90)]) !!}
                </p>
            </div>
        </section>
    @endif

    {{-- Zona de peligro: eliminar (solo edición) --}}
    @if ($editing)
        <section class="ui-card ui-card--danger" aria-labelledby="adv-del-t">
            <div class="ui-card-h"><x-ui.icon name="trash" class="ui-card-ic" /><h2 id="adv-del-t">{{ __('Eliminar asesor') }}</h2></div>
            <div class="ui-card-p">
                @if ($deleteBlockReason)
                    <p class="ui-card-desc">{!! __('El borrado es <b>permanente</b> y distinto de desactivar.') !!}</p>
                    <div class="ui-callout ui-tone--danger"><x-ui.icon name="x" /> <span>{{ $deleteBlockReason }}</span></div>
                @else
                    <p class="ui-card-desc">{{ __('Borra permanentemente el asesor y su configuración (conocimiento y proceso). El histórico de conversaciones/leads/eventos NO se borra. Esta acción no se puede deshacer.') }}</p>
                @endif
            </div>
            @unless ($deleteBlockReason)
                <div class="ui-card-f ui-card-f--end">
                    <x-ui.button variant="danger" icon="trash" wire:click="confirmDelete">{{ __('Eliminar asesor') }}</x-ui.button>
                </div>
            @endunless
        </section>
    @endif

    {{-- Modal de confirmación (exige teclear el nombre exacto) --}}
    @if ($confirmingDelete && $bot)
        <div class="ui-modal-bg" wire:key="del-modal">
            <div class="ui-modal ui-modal--sm" role="dialog" aria-modal="true" aria-labelledby="adv-del-modal-t">
                <div class="ui-modal-h">
                    <h2 class="ui-modal-title" id="adv-del-modal-t"><x-ui.icon name="trash" /> {{ __('Eliminar a :name', ['name' => $bot->assistant_name]) }}</h2>
                </div>
                <div class="ui-modal-b">
                    <p class="ui-card-desc">{!! __('Esta acción es <b>permanente</b> y no se puede deshacer. Se borrarán el asesor, su foto, su base de conocimiento (archivos incluidos) y su configuración de IA.') !!}</p>
                    <div class="ui-callout ui-tone--warning"><x-ui.icon name="alert-triangle" /> <span>{{ __('El histórico de conversaciones, leads y eventos NO se borra.') }}</span></div>
                    <div class="ui-field">
                        <label class="ui-label" for="adv-del-name">{!! __('Escribe <b>:name</b> para confirmar', ['name' => e($bot->assistant_name)]) !!}</label>
                        <input id="adv-del-name" type="text" wire:model.live="deleteConfirmName" placeholder="{{ $bot->assistant_name }}" autocomplete="off" @if ($errors->has('deleteConfirmName')) aria-describedby="adv-del-name-error" @endif @class(['ui-input', 'is-invalid' => $errors->has('deleteConfirmName')])>
                        @error('deleteConfirmName') <p class="ui-error" id="adv-del-name-error" role="alert"><x-ui.icon name="alert-triangle" /> {{ $message }}</p> @enderror
                    </div>
                </div>
                <div class="ui-modal-f">
                    <x-ui.button wire:click="cancelDelete">{{ __('Cancelar') }}</x-ui.button>
                    <button type="button" wire:click="deleteAdvisor" @disabled(! $deleteNameMatches) class="ui-btn ui-btn--danger"><x-ui.icon name="trash" /> {{ __('Eliminar definitivamente') }}</button>
                </div>
            </div>
        </div>
    @endif
</div>
