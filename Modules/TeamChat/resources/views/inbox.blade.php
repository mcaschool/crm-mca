<x-app-layout>
    @php
        $chatConfig = [
            'userId' => auth()->id(),
            'locale' => app()->getLocale(),
            'api' => url('/api/v1/collaboration'),
            'labels' => trans('teamchat::chat'),
            'interactions' => ['emojis'=>config('teamchat.interactions.emojis')],
            'attachments' => collect(config('teamchat.attachments'))->only(['max_bytes','max_count','types','voice_seconds'])->all(),
            'socket' => [
                'key' => config('broadcasting.connections.reverb.key'),
                'host' => config('teamchat.reverb_host'),
                'port' => config('teamchat.reverb_port'),
                'scheme' => config('teamchat.reverb_scheme'),
            ],
        ];
    @endphp
    <section id="teamchat" class="tc" aria-label="{{ __('teamchat::chat.title') }}">
        <script type="application/json" id="teamchat-config">{!! json_encode($chatConfig, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) !!}</script>
        <aside class="tc-sidebar" aria-label="{{ __('teamchat::chat.conversations') }}">
            <header class="tc-brand">
                <span class="tc-brandmark" aria-hidden="true">M</span>
                <div><h1>{{ __('teamchat::chat.title') }}</h1><p>{{ __('teamchat::chat.subtitle') }}</p></div>
            </header>
            <div class="tc-tools">
                <button type="button" class="tc-primary" data-new><span aria-hidden="true">＋</span> {{ __('teamchat::chat.new') }}</button>
                <label class="tc-search"><span aria-hidden="true">⌕</span><input data-search type="search" maxlength="100" autocomplete="off" aria-label="{{ __('teamchat::chat.search') }}" placeholder="{{ __('teamchat::chat.search') }}"></label>
            </div>
            <nav class="tc-tabs" aria-label="{{ __('teamchat::chat.spaces') }}">
                @foreach (['direct' => 'chats', 'group' => 'groups', 'team' => 'teams', 'channel' => 'channels'] as $kind => $label)
                    <button type="button" data-space-tab="{{ $kind }}" aria-pressed="{{ $kind === 'direct' ? 'true' : 'false' }}">{{ __('teamchat::chat.'.$label) }}</button>
                @endforeach
            </nav>
            <div class="tc-space-actions"><button type="button" data-create-group>{{ __('teamchat::chat.create_group') }}</button><button type="button" data-create-team>{{ __('teamchat::chat.create_team') }}</button></div>
            <div class="tc-list" data-list></div>
            <div class="tc-list" data-team-tree hidden></div>
            <button type="button" class="tc-more" data-list-more hidden>{{ __('teamchat::chat.more') }}</button>
            <footer class="tc-connection" role="status"><i></i><span data-connection>{{ __('teamchat::chat.connecting') }}</span></footer>
        </aside>
        <div class="tc-main">
            <div class="tc-welcome" data-welcome><div class="tc-welcome-icon" aria-hidden="true">M</div><h2>{{ __('teamchat::chat.empty') }}</h2><p>{{ __('teamchat::chat.empty_hint') }}</p></div>
            <div class="tc-thread" data-thread hidden>
                <header class="tc-header">
                    <button type="button" class="tc-back" data-back aria-label="{{ __('teamchat::chat.back') }}">←</button>
                    <span class="tc-avatar" data-peer-avatar aria-hidden="true"></span>
                    <div><h2 data-peer-name></h2><p data-peer-presence></p></div>
                    <div class="tc-thread-tools"><button type="button" data-search-messages>{{ __('teamchat::chat.search_messages') }}</button><button type="button" data-show-pins>{{ __('teamchat::chat.pinned') }}</button></div>
                    <button type="button" class="tc-manage" data-manage hidden>{{ __('teamchat::chat.manage') }}</button>
                </header>
                <div class="tc-history" data-history tabindex="0" aria-label="{{ __('teamchat::chat.messages') }}">
                    <button type="button" class="tc-more" data-older hidden>{{ __('teamchat::chat.older') }}</button>
                    <div data-messages></div>
                    <p class="tc-empty" data-empty-chat>{{ __('teamchat::chat.empty_chat') }}</p>
                </div>
                <button type="button" class="tc-new-messages" data-jump hidden>↓ {{ __('teamchat::chat.new_messages') }}</button>
                <form class="tc-composer" data-form>
                    <div class="tc-reply-draft" data-reply-draft hidden></div>
                    <div class="tc-attachment-tools">
                        <button type="button" data-attach>{{ __('teamchat::chat.attach_file') }}</button>
                        <button type="button" data-attach-image>{{ __('teamchat::chat.attach_image') }}</button>
                        <button type="button" data-record>{{ __('teamchat::chat.record_voice') }}</button>
                        <span data-record-time role="timer"></span>
                        <button type="button" data-record-cancel hidden>{{ __('teamchat::chat.cancel') }}</button>
                    </div>
                    <input type="file" data-file-input multiple hidden accept=".pdf,.txt,.csv,.docx,.xlsx,.pptx,.jpg,.jpeg,.png,.webp">
                    <input type="file" data-image-input multiple hidden accept="image/jpeg,image/png,image/webp">
                    <div class="tc-file-drafts" data-file-drafts hidden></div>
                    <p class="tc-file-error" data-file-error role="alert" hidden></p>
                    <div class="tc-composer-row"><textarea data-body rows="1" maxlength="5000" aria-label="{{ __('teamchat::chat.write') }}" placeholder="{{ __('teamchat::chat.write') }}"></textarea>
                    <button type="submit" class="tc-send" aria-label="{{ __('teamchat::chat.send') }}"><span>{{ __('teamchat::chat.send') }}</span><span aria-hidden="true">➜</span></button></div>
                    <p data-composer-hint>{{ __('teamchat::chat.hint') }}</p>
                </form>
            </div>
        </div>
        <div class="tc-mention-notice" data-mention-notice role="status" hidden></div>
        <dialog class="tc-interaction-dialog" data-interaction-dialog aria-label="{{ __('teamchat::chat.message_actions') }}"><button type="button" data-interaction-close>{{ __('teamchat::chat.close') }}</button><div data-interaction-panel></div><p class="tc-interaction-error" data-interaction-error role="alert" hidden></p></dialog>
        <dialog class="tc-image-viewer" data-image-viewer aria-label="{{ __('teamchat::chat.expand_image') }}"><button type="button" data-viewer-close>{{ __('teamchat::chat.close') }}</button><img alt=""></dialog>
        <dialog class="tc-dialog" data-space-dialog aria-labelledby="tc-dialog-title"><div data-space-panel></div></dialog>
        <div class="tc-toast" data-toast role="status" hidden></div>
        <div class="tc-error" data-error role="alert" hidden><span></span><button type="button" data-retry>{{ __('teamchat::chat.retry') }}</button><button type="button" data-dismiss aria-label="{{ __('teamchat::chat.close') }}">×</button></div>
    </section>
</x-app-layout>
