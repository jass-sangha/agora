# Agora Chat Showcase

This guide documents the bundled chat experience with screenshots from the running application, covering the full-page and embedded views, settings, conversation options, media, calls, and appearance customization. The screenshots are relative to this file so they render on GitHub and in local clones.

## At a glance

Agora is a Laravel chat package with a prebuilt Vue chat client. The same chat page can be used as a full-page experience or embedded in a host page. It supports private and group conversations, real-time presence and message delivery, rich media, message actions, privacy settings, and customizable themes and wallpapers.

## Screenshot tour

### Full-page and embedded views

| Full page | Embedded chat |
| --- | --- |
| ![Full page](screenshots/fullpage.png) | ![Embedded chat](screenshots/imbedded.png) |

### Settings and conversation options

| Chat settings | Chat options |
| --- | --- |
| ![Chat settings](screenshots/chat-settings.png) | ![Chat options](screenshots/chat-options.png) |

| Sidebar options | Sidebar profile settings |
| --- | --- |
| ![Sidebar options](screenshots/sidebar-options.png) | ![Sidebar profile settings](screenshots/sidebar-profile-settings.png) |

### Messages and calls

| Attachments | Reactions |
| --- | --- |
| ![Attachments](screenshots/attachments.png) | ![Reactions](screenshots/reactions.png) |

| Call |
| --- |
| ![Call](screenshots/call.png) |

### Appearance customization

| Dark theme | Custom design |
| --- | --- |
| ![Dark theme](screenshots/dark-theme.png) | ![Custom design](screenshots/custom-design.png) |

| Custom wallpapers |
| --- |
| ![Custom wallpapers](screenshots/custom-wallpapers.png) |

## Font inheritance and fallbacks

When the widget is mounted directly in a host page, its sans and display font tokens inherit the surrounding app's font. The widget's styles remain scoped; font inheritance does not make the host's other CSS leak into the chat.

The standalone `/chat` page uses Figtree as a fallback when there is no host-page font to inherit. An iframe is a separate document and cannot inherit the embedding page's fonts, even when both pages share an origin. To customize the chat font, set the `fontSans` and `fontDisplay` keys in `chat.theme.overrides`, or edit `--chat-font-sans` and `--chat-font-display` in the published theme CSS.

```php
'theme' => [
    'overrides' => [
        'fontSans' => '"Inter", system-ui, sans-serif',
        'fontDisplay' => '"Inter", system-ui, sans-serif',
    ],
],
```

## Embedding in a dashboard

The bundled page is served by the package's `chat.page` route. An authenticated host dashboard can render it in an iframe with a bounded height:

```blade
<section class="overflow-hidden rounded-3xl border border-slate-200 bg-white">
    <header class="border-b border-slate-200 p-5">
        <h2 class="text-xl font-semibold">Messages</h2>
        <p class="text-sm text-slate-600">Stay in your conversations without leaving the dashboard.</p>
    </header>

    <div class="bg-slate-100 p-3">
        <iframe
            src="{{ route('chat.page') }}"
            title="Agora real-time chat"
            class="h-[min(76vh,800px)] min-h-[520px] w-full rounded-2xl border bg-white"
            allow="camera; microphone; autoplay; clipboard-write; fullscreen"
        ></iframe>
    </div>
</section>
```

The iframe and host page should use the same origin for session-cookie authentication without additional cross-origin cookie settings. The package's `chat.frame_ancestors` defaults to same-origin. When serving the chat from a different origin, explicitly allow the host in that setting and configure secure cross-site session cookies; do not disable frame restrictions broadly in production.

The iframe is an independent page, so regular host CSS does not leak into the chat and chat theme changes do not recolor the dashboard. The `allow` attribute enables browser-gated call and media capabilities when the host page and browser permit them. For a plain link to the full-page experience, use `route('chat.page')` outside the iframe.

## Themes and CSS

### Built-in appearance controls

In the chat's settings panel, choose:

- **Light** for a fixed light palette.
- **Dark** for a fixed dark palette.
- **System** to follow the operating-system preference.

The selection is stored as a chat preference. It is applied to the chat mount only, preserving the host application's separate CSS and color scheme.

### Quick token overrides

Set `chat.theme.overrides` in the host's published `config/chat.php` to adjust supported theme tokens without replacing the stylesheet:

```php
'theme' => [
    'overrides' => [
        'accent' => '79 70 229',
        'radius' => '0.875rem',
    ],
],
```

The token values use the package's documented CSS-variable format. See the `theme.overrides` configuration and the package theme stylesheet for the complete list of supported tokens.

### Full stylesheet customization

Publish the package theme file and edit its CSS variables when a design needs more than a few token overrides:

```bash
php artisan vendor:publish --tag=chat-theme
```

Customize the published `public/vendor/chat/theme.css` in the consuming application. This layer is scoped to the widget; it can define light and dark values independently of the host dashboard's styles.

## Wallpapers

The picker supports built-in patterns (Dots, Plain, tighter dots, Lines, and Boxes), tint colors (Default, Sky, Blush, and Slate), a custom color, and an uploaded image. Choose a default wallpaper from Settings; use the conversation's own wallpaper control to override it for one chat. A chat with a wallpaper override can be reset to follow the current default again.

### Add or replace wallpaper choices

The wallpaper registry can be customized in the host `config/chat.php` under `theme.wallpapers` or by publishing the override file:

```bash
php artisan vendor:publish --tag=chat-wallpapers
```

An example `window.ChatWallpaperOverrides` definition for the published JavaScript override file:

```js
window.ChatWallpaperOverrides = {
    patterns: {
        waves: {
            label: "Waves",
            image: "repeating-radial-gradient(circle at 0 0, transparent 0, rgb(var(--chat-text) / .12) 20px)",
            size: "40px 40px",
        },
    },
    colors: {
        brand: {
            label: "Brand",
            css: "rgb(79 70 229 / .14)",
        },
    },
};
```

Keep existing keys stable because they are stored as user preferences. Theme-token-based values adapt to both light and dark palettes.

## Feature tour

| Area | Included behavior |
| --- | --- |
| Conversations | Private and group chats, search, unread/favourites/groups filters, pinning, muting, archiving, and conversation actions |
| Real-time | WebSocket message delivery, online presence, typing indicators, read receipts, and live conversation updates |
| Messages | Text, image, video, audio, voice notes, documents, GIFs, locations, contact cards, polls, events, and call records |
| Message actions | Replies, reactions, forwarding, editing, deletion, starring, and read/message information |
| Groups | Member management, admin roles, group details, and group media |
| Privacy and safety | Blocking, disappearing messages, and controls for last seen, read receipts, and typing visibility |
| Personalization | Profile photo, Light/Dark/System themes, default and per-chat wallpapers, custom colors, and uploaded wallpaper images |
| Integration | Full-page route, Blade widget, iframe embedding, theming tokens, CSS overrides, icon overrides, and wallpaper registries |

Feature availability can depend on host configuration, browser permissions, storage settings, and the broadcasting/queue services configured by the consuming application.

## Reproducing the visual tour

1. Configure and start the host Laravel application, its configured broadcaster (for example Reverb), and a queue worker when broadcasts use queued delivery.
2. Sign in as two separate chat users in isolated browser sessions.
3. Send messages in both directions and confirm each arrives without refreshing the receiving session.
4. Open the host dashboard to view the iframe integration.
5. Use the chat settings to switch between themes and choose a wallpaper. Reloading should preserve the selected preference.
6. For the host-side design examples, apply the theme token or wallpaper registry customization shown above.

The screenshot set is checked into `docs/screenshots/`; it contains no login credentials or session data.
