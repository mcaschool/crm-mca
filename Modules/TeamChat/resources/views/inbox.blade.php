<x-app-layout>
    @php
        $chatConfig = [
            'userId' => auth()->id(),
            'locale' => app()->getLocale(),
            'api' => url('/api/v1/collaboration'),
            'labels' => trans('teamchat::chat'),
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
            <div class="tc-list" data-list></div>
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
                    <span class="tc-header-tag">TeamChat</span>
                </header>
                <div class="tc-history" data-history tabindex="0" aria-label="{{ __('teamchat::chat.messages') }}">
                    <button type="button" class="tc-more" data-older hidden>{{ __('teamchat::chat.older') }}</button>
                    <div data-messages></div>
                    <p class="tc-empty" data-empty-chat>{{ __('teamchat::chat.empty_chat') }}</p>
                </div>
                <button type="button" class="tc-new-messages" data-jump hidden>↓ {{ __('teamchat::chat.new_messages') }}</button>
                <form class="tc-composer" data-form>
                    <div class="tc-composer-row"><textarea data-body rows="1" maxlength="5000" aria-label="{{ __('teamchat::chat.write') }}" placeholder="{{ __('teamchat::chat.write') }}"></textarea>
                    <button type="submit" class="tc-send" aria-label="{{ __('teamchat::chat.send') }}"><span>{{ __('teamchat::chat.send') }}</span><span aria-hidden="true">➜</span></button></div>
                    <p data-composer-hint>{{ __('teamchat::chat.hint') }}</p>
                </form>
            </div>
        </div>
        <div class="tc-toast" data-toast role="status" hidden></div>
        <div class="tc-error" data-error role="alert" hidden><span></span><button type="button" data-retry>{{ __('teamchat::chat.retry') }}</button><button type="button" data-dismiss aria-label="{{ __('teamchat::chat.close') }}">×</button></div>
    </section>
</x-app-layout>
