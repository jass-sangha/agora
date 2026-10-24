<?php

use Chat\Support\ChatConfig;

it('renders chat.theme.overrides as an inline style block scoped to the mount element', function () {
    config(['chat.theme.overrides' => ['accent' => '1 2 3', 'accentContrast' => '4 5 6']]);

    expect(ChatConfig::themeOverrideStyles())
        ->toBe('<style>#chat-app{--chat-accent: 1 2 3;--chat-accent-contrast: 4 5 6;}</style>');
});

it('returns an empty string when no overrides are configured', function () {
    config(['chat.theme.overrides' => []]);

    expect(ChatConfig::themeOverrideStyles())->toBe('');
});

it('renders chat.theme.icons as an inline script seeding window.ChatIconOverrides', function () {
    config(['chat.theme.icons' => ['send' => ['inner' => '<path d="M0 0" />']]]);

    expect(ChatConfig::iconOverrideScript())
        ->toBe('<script>window.ChatIconOverrides = Object.assign({"send":{"inner":"<path d=\"M0 0\" \/>"}}, window.ChatIconOverrides || {});</script>');
});

it('returns an empty string when no icon overrides are configured', function () {
    config(['chat.theme.icons' => []]);

    expect(ChatConfig::iconOverrideScript())->toBe('');
});

it('renders chat.theme.wallpapers as an inline script seeding window.ChatWallpaperOverrides', function () {
    config(['chat.theme.wallpapers' => [
        'patterns' => ['waves' => ['label' => 'Waves']],
        'colors' => ['brand' => ['label' => 'Brand', 'css' => 'rgb(0 0 0)']],
    ]]);

    expect(ChatConfig::wallpaperOverrideScript())
        ->toBe('<script>window.ChatWallpaperOverrides = {'
            . 'patterns: Object.assign({"waves":{"label":"Waves"}}, (window.ChatWallpaperOverrides || {}).patterns || {}),'
            . 'colors: Object.assign({"brand":{"label":"Brand","css":"rgb(0 0 0)"}}, (window.ChatWallpaperOverrides || {}).colors || {}),'
            . '};</script>');
});

it('returns an empty string when no wallpaper overrides are configured', function () {
    config(['chat.theme.wallpapers' => ['patterns' => [], 'colors' => []]]);

    expect(ChatConfig::wallpaperOverrideScript())->toBe('');
});
