<?php

use App\Models\User;

return [

    /*
    |--------------------------------------------------------------------------
    | Chatable Models
    |--------------------------------------------------------------------------
    |
    | Every Authenticatable model allowed to participate in chat, keyed by a
    | short alias. Every chat_* table stores a polymorphic `chatable_type` /
    | `chatable_id` pair instead of a plain `user_id`, so more than one model
    | (e.g. users and support agents) can hold conversations with each other.
    | The alias — not the FQCN — is what's persisted (see morph map below),
    | so renaming a model class later doesn't orphan existing rows.
    |
    | The first entry is the default used wherever a single fallback model is
    | needed (e.g. CHAT_USER_MODEL-style env overrides, user search defaults).
    |
    | Each entry may be either a plain model class string (uses the global
    | user_search.name_field below for its display name) or an array with a
    | 'model' key plus a per-model 'name_field' override, for a chatable whose
    | display-name column differs from the rest, e.g.:
    |   'agent' => ['model' => App\Models\Agent::class, 'name_field' => 'full_name'],
    |
    */
    'chatable_models' => [
        'user' => env('CHAT_USER_MODEL', User::class),
    ],

    /*
    |--------------------------------------------------------------------------
    | Table Names
    |--------------------------------------------------------------------------
    |
    | Override any table name if it collides with something in your app.
    |
    */
    'table_names' => [
        'conversations' => 'chat_conversations',
        'conversation_participants' => 'chat_conversation_participants',
        'messages' => 'chat_messages',
        'message_deletions' => 'chat_message_deletions',
        'message_attachments' => 'chat_message_attachments',
        'message_reactions' => 'chat_message_reactions',
        'message_receipts' => 'chat_message_receipts',
        'starred_messages' => 'chat_starred_messages',
        'blocked_users' => 'chat_blocked_users',
        'user_presence' => 'chat_user_presence',
        'pinned_messages' => 'chat_pinned_messages',
        'user_settings' => 'chat_user_settings',
        'lists' => 'chat_lists',
        'list_conversations' => 'chat_list_conversations',
        'poll_votes' => 'chat_poll_votes',
        'event_rsvps' => 'chat_event_rsvps',
        'message_edits' => 'chat_message_edits',
    ],

    /*
    |--------------------------------------------------------------------------
    | Routing
    |--------------------------------------------------------------------------
    |
    | register_routes gates the JSON API + broadcast channel routes below;
    | register_ui_routes (further down, under "Bundled Chat UI") gates the
    | web/page routes and the compiled Vue widget's own views separately, so
    | a host can keep the API but disable the built-in UI, or vice versa.
    |
    */
    'register_routes' => env('CHAT_REGISTER_ROUTES', true),
    'run_migrations' => env('CHAT_RUN_MIGRATIONS', true),
    'route_prefix' => 'api/chat',

    /*
    |--------------------------------------------------------------------------
    | Broadcasting
    |--------------------------------------------------------------------------
    |
    | Default to immediate delivery so local/demo apps work without a queue worker.
    | Set CHAT_BROADCAST_MODE=queue to push chat events onto a Laravel queue.
    |
    */
    'broadcasting' => [
        'mode' => env('CHAT_BROADCAST_MODE', 'sync'),
        'queue' => env('CHAT_BROADCAST_QUEUE', 'chat-broadcasts'),
    ],
    // General ceiling for every route in this group (messages, typing, presence heartbeat,
    // attachments, etc.). A host can override this array to change or drop it. The trailing
    // 'chat' segment is a throttle key prefix, not a limiter name — Laravel's default throttle
    // key is just the authenticated user id, with no route component, so without distinct
    // prefixes here and on rate_limits/link_preview's own throttle below, all of them would
    // silently share one counter (hitting this ceiling would then also trip, say,
    // rate_limits.search's much lower one, and vice versa, for requests that never touched
    // search at all).
    'middleware' => ['api', 'auth:sanctum', 'throttle:120,1,chat'],

    /*
    |--------------------------------------------------------------------------
    | Rate Limits
    |--------------------------------------------------------------------------
    |
    | Tighter, action-specific throttles layered on top of the group-wide ceiling
    | above — that blanket 120/min doesn't distinguish between actions, so a
    | participant could spend the whole budget on message sends alone, each of
    | which triggers a broadcast plus a queued notification per recipient, or on
    | attachment uploads, each of which does disk I/O and (if enabled) thumbnail
    | generation. Each value is a full `throttle:max,decayMinutes,prefix`
    | middleware string; keep the prefix unique per entry (see the note on
    | `middleware` above for why) or set an entry to null to disable it.
    |
    */
    'rate_limits' => [
        'message_send' => 'throttle:60,1,chat-message-send',
        'attachment_upload' => 'throttle:20,1,chat-attachment-upload',
        'search' => 'throttle:60,1,chat-search',
        'reactions' => 'throttle:60,1,chat-reactions',
    ],

    /*
    |--------------------------------------------------------------------------
    | Bundled Chat UI
    |--------------------------------------------------------------------------
    |
    | The package ships a pre-built, self-contained Vue chat widget. These
    | control the page/asset routes it registers — set register_ui_routes to
    | false (or CHAT_REGISTER_UI_ROUTES=false) to disable the built-in UI
    | entirely and use only the JSON API above.
    |
    */
    'register_ui_routes' => env('CHAT_REGISTER_UI_ROUTES', true),
    'web_middleware' => ['web', 'auth'],
    'chat_route_prefix' => 'chat',
    'asset_middleware' => [],
    'asset_route_prefix' => 'chat/assets',

    /*
    |--------------------------------------------------------------------------
    | Iframe Embedding
    |--------------------------------------------------------------------------
    |
    | Controls the frame-ancestors policy sent with the full-page chat route
    | (chat.page) so a host can embed it in an <iframe>. Defaults to
    | same-origin only. Set to a space-separated origin list to allow specific
    | cross-origin hosts, or to null/false to disable the header entirely
    | (fully open embedding — opt-in only).
    |
    */
    'frame_ancestors' => "'self'",

    /*
    |--------------------------------------------------------------------------
    | Pagination
    |--------------------------------------------------------------------------
    */
    'pagination' => [
        'messages_per_page' => 50,
        'conversations_per_page' => 30,
        'users_per_page' => 20,
        'lists_per_page' => 30,
    ],

    /*
    |--------------------------------------------------------------------------
    | User Search
    |--------------------------------------------------------------------------
    |
    | Fallback display-name column read off a chatable model for the "new
    | chat"/"add member" pickers and for rendering participants, used for any
    | chatable_models entry that doesn't declare its own name_field above.
    | There's no equivalent avatar_field — avatars are always package-owned
    | (chat_user_settings.avatar_path, set via the profile upload endpoints),
    | never read from a column on the host's chatable model.
    |
    */
    'user_search' => [
        'name_field' => 'name',
        // 'contains' (default) matches the query anywhere in name_field via a leading-
        // wildcard LIKE '%term%', which can't use a normal index. This package can't add one
        // itself — name_field lives on the host's own chatable model/table (e.g. `users`),
        // not one this package migrates. 'starts_with' switches to LIKE 'term%' instead
        // (narrower matching, but index-friendly if the host adds a normal index on that
        // column) for a deployment that needs search to hold up at scale.
        'strategy' => 'contains',
        // A 1-character query under the 'contains' strategy can match a large fraction of a
        // big users table via an unindexable scan. Below this length, UserSearchService::search()
        // returns an empty page instead of running the query at all.
        'min_length' => 2,
        // Chat::matchingChatablePairs() (used by conversation/message search's "does this match
        // a participant's name" clause) pulls every matching id into PHP before building an OR
        // condition from them — this caps how many it will collect per search, regardless of how
        // broadly the term matches.
        'max_matching_ids' => 500,
    ],

    /*
    |--------------------------------------------------------------------------
    | Media
    |--------------------------------------------------------------------------
    */
    'media' => [
        'disk' => 'chat',
        // Off by default (preserves the existing public-URL behavior for hosts already
        // depending on it). When true, MessageAttachment::url/thumbnail_url stop returning the
        // disk's own static, unauthenticated URL and instead return a short-TTL signed URL to
        // this package's own GET /attachments/{id}[/thumbnail] route, which re-checks
        // MessagePolicy::view (participant, not blocked-for-them, not expired, not "deleted for
        // me") on every single fetch — not just once at send time. A plain public URL, once
        // copied/screenshotted/proxy-cached, stays fetchable forever; this makes
        // "delete for everyone" and disappearing messages' expires_at bite the *file* the moment
        // they take effect, not just the message row (and not just whenever
        // chat:prune-expired-messages next runs). Also flips ChatServiceProvider's
        // auto-registered default disk to a non-public root with 'private' visibility and skips
        // the public/storage symlink — see registerDefaultDisk(). Turn on together with pointing
        // `disk` at an actually-private disk (a custom local root outside public/, or an S3
        // bucket with default-deny ACLs) if you override `disk` yourself.
        'serve_privately' => env('CHAT_MEDIA_SERVE_PRIVATELY', false),
        // How long a signed attachment URL stays valid once generated. Every API response that
        // serializes an attachment (send, list, search, media, …) generates a fresh one, so this
        // only actually matters for a URL a client holds onto without ever refetching — e.g. a
        // realtime MessageSent payload kept in memory in an open chat window. Too short and
        // images silently break under a viewer's nose with no built-in retry; long enough here
        // to make that rare while still being nowhere near "permanent".
        'signed_url_ttl_minutes' => 60,
        // Only consulted when the disk above isn't already defined in the host's own
        // config/filesystems.php — see ChatServiceProvider::registerDefaultDisk(). Under
        // serve_privately, defaults outside public/ so the files aren't reachable by a raw
        // guessed path even before the signed-URL layer is considered.
        'disk_root' => env('CHAT_MEDIA_SERVE_PRIVATELY', false)
            ? storage_path('app/chat')
            : storage_path('app/public/chat'),
        'disk_url' => '/storage/chat',
        'thumbnail_enabled' => true,
        'mime_types' => [
            'image' => ['image/jpeg', 'image/png', 'image/webp', 'image/gif'],
            'video' => ['video/mp4', 'video/quicktime', 'video/webm'],
            'audio' => ['audio/mpeg', 'audio/mp4', 'audio/ogg', 'audio/wav'],
            'voice' => ['audio/webm', 'audio/mp4', 'audio/ogg'],
            'document' => [
                'application/pdf',
                'application/msword',
                'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
                'application/vnd.ms-excel',
                'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
                'application/zip',
                'text/plain',
            ],
        ],
        'max_sizes' => [
            'image' => 10 * 1024,
            'video' => 100 * 1024,
            'audio' => 25 * 1024,
            'voice' => 15 * 1024,
            'document' => 50 * 1024,
        ],
        // Total bytes a single chatable may have stored across every attachment they've ever
        // uploaded (attached or not) before further uploads are rejected. Null disables the
        // check entirely — opt in once you need to cap per-user storage.
        'max_storage_per_user_mb' => env('CHAT_MAX_STORAGE_PER_USER_MB'),
        // How long an uploaded-but-never-attached file (POST /attachments without a follow-up
        // message) is kept before chat:prune-orphaned-attachments removes it.
        'orphan_ttl_minutes' => 1440,
    ],

    /*
    |--------------------------------------------------------------------------
    | Presence
    |--------------------------------------------------------------------------
    */
    'presence' => [
        'heartbeat_ttl_seconds' => 60,
        'online_grace_seconds' => 90,
    ],

    /*
    |--------------------------------------------------------------------------
    | Privacy
    |--------------------------------------------------------------------------
    |
    | Default values for the per-user privacy toggles in `chat_user_settings`.
    | Sharing last-seen and read-receipts is reciprocal: a user who has hidden
    | their own also stops seeing everyone else's.
    |
    */
    'privacy' => [
        'last_seen_default' => true,
        'read_receipts_default' => true,
        'typing_indicator_default' => true,
    ],

    /*
    |--------------------------------------------------------------------------
    | Typing Indicators
    |--------------------------------------------------------------------------
    */
    'typing' => [
        'ttl_seconds' => 5,
    ],

    /*
    |--------------------------------------------------------------------------
    | Disappearing Messages
    |--------------------------------------------------------------------------
    */
    'disappearing_messages' => [
        'enabled' => true,
        'default_ttl' => null,
    ],

    /*
    |--------------------------------------------------------------------------
    | Message Rules
    |--------------------------------------------------------------------------
    */
    'message' => [
        'edit_window_minutes' => 15,
        'delete_for_everyone_window_minutes' => 5,
        // Bounds body length on both send and edit. chat_messages.body is a TEXT column
        // (~64KB) with no other cap, so without this an authenticated participant could send
        // arbitrarily large bodies, each broadcast in real time to every other participant.
        'max_body_length' => 4096,
        // Each edit snapshots the previous body into its own chat_message_edits row (see
        // MessageService::update()) — edit_window_minutes above bounds how long a message stays
        // editable, but not how many times within that window, so without this a message could
        // otherwise accumulate an unbounded number of edit-history rows.
        'max_edits' => 100,
        // Backstop on top of metadata.*'s per-field limits — bounds the whole serialized
        // metadata payload (stored on the message row, and included in the realtime
        // broadcast/queued notification), not just whichever individual keys StoreMessageRequest
        // happens to validate.
        'max_metadata_bytes' => 16384,
    ],

    /*
    |--------------------------------------------------------------------------
    | Call Signaling
    |--------------------------------------------------------------------------
    */
    'calls' => [
        'enabled' => env('CHAT_CALLS_ENABLED', false),
        // Additional ICE servers without credentials that remain safe to expose in the page.
        'ice_servers' => [],
        // coturn TURN REST API settings. The shared secret stays on the server; the API
        // generates short-lived credentials for the authenticated caller.
        'turn' => [
            'urls' => array_values(array_filter(array_map('trim', explode(',', (string) env('CHAT_TURN_URLS', ''))))),
            'shared_secret' => env('CHAT_TURN_SHARED_SECRET'),
            'credential_ttl' => (int) env('CHAT_TURN_CREDENTIAL_TTL', 3600),
        ],
        'signal_rate_limit' => 'throttle:240,1,chat-call-signal',
        'ice_server_rate_limit' => 'throttle:30,1,chat-call-ice-servers',
        // Bounds the WebRTC signaling payload (SDP offer/answer, ICE candidates) broadcast
        // through CallController::signal(). A real SDP negotiation is a few KB at most — without
        // this, 'payload' => ['array'] alone accepts an arbitrarily large nested structure that
        // gets broadcast to another participant's channel on every call.
        'max_payload_bytes' => 65536,
    ],

    /*
    |--------------------------------------------------------------------------
    | Notifications
    |--------------------------------------------------------------------------
    |
    | Extra notification channels appended to the default 'broadcast' channel
    | on new-message notifications. The package never depends on any push
    | SDK itself — add your own (fcm, webpush, etc.) and list it here.
    |
    */
    'notifications' => [
        'channels' => [],
        // Notification::send($others, ...) dispatches one queued job per active recipient —
        // up to ~200 in a max-size group, for every single message sent. Tagging those jobs
        // (NewChatMessageNotification's constructor calls onQueue() with this) with a queue
        // name of their own means a host can point a dedicated worker pool at it
        // (`php artisan queue:work --queue=chat-notifications`) so a busy group chat's fan-out
        // doesn't delay unrelated queued work sitting on the default queue. Set to null to
        // leave these jobs on the default queue instead — this only tags the job, a host still
        // has to actually run a worker listening on it (or point queue.php's connection default
        // at it) for the tag to change anything.
        'queue' => env('CHAT_NOTIFICATIONS_QUEUE', 'chat-notifications'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Link Previews
    |--------------------------------------------------------------------------
    */
    'link_preview' => [
        'enabled' => true,
        'cache_ttl_minutes' => 1440,
    ],

    /*
    |--------------------------------------------------------------------------
    | Chat Lists
    |--------------------------------------------------------------------------
    */
    'lists' => [
        // Caps how many separate lists one chatable can create in total —
        // StoreChatListRequest's own 'conversation_ids' => ['max:200'] only bounds a single
        // list's membership, not how many lists exist (see ChatListService::guardListCap()).
        // Null disables the cap.
        'max_per_user' => 200,
    ],

    /*
    |--------------------------------------------------------------------------
    | Theme Overrides
    |--------------------------------------------------------------------------
    |
    | Quick, config-only tweaks — no CSS file required. Every knob theme.css
    | defines is fair game here, not just colors: radius scale (radiusSm,
    | radius, radiusLg, radiusXl), borderWidth, fontSans, fontDisplay,
    | shadow, shadowLg — anything in theme.css. Keys are camelCase and get
    | kebab-cased into the matching --chat-* custom property (fontSans =>
    | --chat-font-sans), values are raw CSS exactly as they'd appear in
    | theme.css, e.g.:
    |   'overrides' => [
    |       'accent' => '198 113 57',
    |       'radius' => '0.75rem',
    |       'fontSans' => '"Inter", sans-serif',
    |       'borderWidth' => '2px',
    |       'shadowLg' => '0 20px 40px rgba(0, 0, 0, 0.35)',
    |   ],
    |
    | For full control (every color, both themes), instead publish and
    | hand-edit the CSS file: php artisan vendor:publish --tag=chat-theme.
    | This layer is additive to that one, not a replacement — it's applied on
    | top via an inline <style> block, after both stylesheets.
    |
    | `icons` is the same idea for icon glyphs: a name => definition map (see
    | resources/js/icon-overrides.js for the shape and an example) rendered
    | as an inline <script> that seeds window.ChatIconOverrides before the
    | app boots. For full control over every icon, instead publish and
    | hand-edit the JS file: php artisan vendor:publish --tag=chat-icons.
    |
    | `wallpapers` covers the wallpaper picker's background patterns/colors:
    | ['patterns' => [...], 'colors' => [...]], each a key => definition map
    | (see resources/js/wallpaper-overrides.js for the shape and an example).
    | For full control, instead publish and hand-edit the JS file:
    | php artisan vendor:publish --tag=chat-wallpapers.
    |
    */
    'theme' => [
        'overrides' => [],
        'icons' => [],
        'wallpapers' => [
            'patterns' => [],
            'colors' => [],
        ],
    ],
];
