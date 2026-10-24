{{--
    Shared by chat.blade.php (full page) and components/widget.blade.php (embed).
    Wrapped in a keyed @once so accidentally including it more than once on the
    same page never duplicates the mount div or the asset tags.
--}}
@once('chat-widget-assets')
    <link rel="stylesheet" href="{{ url(config('chat.asset_route_prefix', 'chat/assets').'/app.css') }}?v={{ $chatConfig['assetVersion'] }}">
    @if($themeOverrideVersion)
        <link rel="stylesheet" href="{{ asset('vendor/chat/theme.css') }}?v={{ $themeOverrideVersion }}">
    @endif
    {!! \Chat\Support\ChatConfig::themeOverrideStyles() !!}
    {!! \Chat\Support\ChatConfig::iconOverrideScript() !!}
    @if($iconOverrideVersion ?? null)
        <script src="{{ asset('vendor/chat/icons.js') }}?v={{ $iconOverrideVersion }}"></script>
    @endif
    {!! \Chat\Support\ChatConfig::wallpaperOverrideScript() !!}
    @if($wallpaperOverrideVersion ?? null)
        <script src="{{ asset('vendor/chat/wallpapers.js') }}?v={{ $wallpaperOverrideVersion }}"></script>
    @endif
    <div id="chat-app"></div>
    <script>
        // Resolves and applies the theme synchronously, before the deferred app.js
        // bundle downloads/parses/executes. Without this, the widget briefly shows
        // whatever the CSS default happens to be until usePreferences.js's own
        // applyTheme() call runs — on a slow load that gap is visible as a flash.
        // Mirrors the localStorage/matchMedia logic in resources/js/composables/usePreferences.js;
        // keep both in sync if that logic changes.
        (function () {
            try {
                var raw = localStorage.getItem('chat:theme');
                var theme = raw !== null ? JSON.parse(raw) : 'system';
                var value = theme === 'system'
                    ? (window.matchMedia && window.matchMedia('(prefers-color-scheme: dark)').matches ? 'dark' : 'light')
                    : theme;
                document.getElementById('chat-app').setAttribute('data-theme', value);
            } catch (e) {}
        })();
    </script>
    <script>window.ChatConfig = @json($chatConfig);</script>
    <script src="{{ url(config('chat.asset_route_prefix', 'chat/assets').'/app.js') }}?v={{ $chatConfig['assetVersion'] }}" defer></script>
@endonce
