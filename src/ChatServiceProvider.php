<?php

namespace Chat;

use Chat\Console\Commands\InstallCommand;
use Chat\Console\Commands\PruneExpiredMessagesCommand;
use Chat\Console\Commands\SweepPresenceCommand;
use Chat\Contracts\AttachmentServiceInterface;
use Chat\Contracts\BlockedUserServiceInterface;
use Chat\Contracts\ChatListServiceInterface;
use Chat\Contracts\ConversationRepositoryInterface;
use Chat\Contracts\ConversationServiceInterface;
use Chat\Contracts\EventRsvpServiceInterface;
use Chat\Contracts\LinkPreviewFetcher;
use Chat\Contracts\MalwareScanner;
use Chat\Contracts\MediaProcessor;
use Chat\Contracts\MessageReactionServiceInterface;
use Chat\Contracts\MessageReceiptServiceInterface;
use Chat\Contracts\MessageRepositoryInterface;
use Chat\Contracts\MessageServiceInterface;
use Chat\Contracts\ParticipantRepositoryInterface;
use Chat\Contracts\ParticipantServiceInterface;
use Chat\Contracts\PinnedMessageServiceInterface;
use Chat\Contracts\PollVoteServiceInterface;
use Chat\Contracts\PresenceServiceInterface;
use Chat\Contracts\StarredMessageServiceInterface;
use Chat\Contracts\UserSearchServiceInterface;
use Chat\Contracts\UserSettingsServiceInterface;
use Chat\Models\Conversation;
use Chat\Models\Message;
use Chat\Policies\ConversationPolicy;
use Chat\Policies\MessagePolicy;
use Chat\Repositories\ConversationRepository;
use Chat\Repositories\MessageRepository;
use Chat\Repositories\ParticipantRepository;
use Chat\Services\AttachmentService;
use Chat\Services\BlockedUserService;
use Chat\Services\ChatListService;
use Chat\Services\ConversationService;
use Chat\Services\EventRsvpService;
use Chat\Services\MessageReactionService;
use Chat\Services\MessageReceiptService;
use Chat\Services\MessageService;
use Chat\Services\NullMalwareScanner;
use Chat\Services\NullMediaProcessor;
use Chat\Services\OpenGraphLinkPreviewFetcher;
use Chat\Services\ParticipantService;
use Chat\Services\PinnedMessageService;
use Chat\Services\PollVoteService;
use Chat\Services\PresenceService;
use Chat\Services\StarredMessageService;
use Chat\Services\UserSearchService;
use Chat\Services\UserSettingsService;
use Illuminate\Contracts\Http\Kernel;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\Gate;
use Laravel\Sanctum\Http\Middleware\EnsureFrontendRequestsAreStateful;
use Spatie\LaravelPackageTools\Package;
use Spatie\LaravelPackageTools\PackageServiceProvider;

class ChatServiceProvider extends PackageServiceProvider
{
    /**
     * Auto-bound by Laravel's base ServiceProvider (interface => concrete).
     */
    public array $bindings = [
        ParticipantRepositoryInterface::class => ParticipantRepository::class,
        ConversationRepositoryInterface::class => ConversationRepository::class,
        MessageRepositoryInterface::class => MessageRepository::class,
        ConversationServiceInterface::class => ConversationService::class,
        MessageServiceInterface::class => MessageService::class,
        MessageReactionServiceInterface::class => MessageReactionService::class,
        StarredMessageServiceInterface::class => StarredMessageService::class,
        MessageReceiptServiceInterface::class => MessageReceiptService::class,
        PresenceServiceInterface::class => PresenceService::class,
        ParticipantServiceInterface::class => ParticipantService::class,
        BlockedUserServiceInterface::class => BlockedUserService::class,
        AttachmentServiceInterface::class => AttachmentService::class,
        MediaProcessor::class => NullMediaProcessor::class,
        MalwareScanner::class => NullMalwareScanner::class,
        LinkPreviewFetcher::class => OpenGraphLinkPreviewFetcher::class,
        UserSearchServiceInterface::class => UserSearchService::class,
        PinnedMessageServiceInterface::class => PinnedMessageService::class,
        ChatListServiceInterface::class => ChatListService::class,
        PollVoteServiceInterface::class => PollVoteService::class,
        EventRsvpServiceInterface::class => EventRsvpService::class,
    ];

    protected array $policies = [
        Conversation::class => ConversationPolicy::class,
        Message::class => MessagePolicy::class,
    ];

    /**
     * UserSettingsService::get() memoizes per chatable for the instance's lifetime (see its
     * docblock), so MessageResource doesn't re-query the same row for every receipt on a single
     * message list response. That only works if every resolution point — constructor-injected
     * (ProfileController, PresenceService, TypingController) and ad-hoc app()-resolved
     * (MessageResource, ChatUserResource) — shares one instance per request; a plain bind()
     * would hand each of those a fresh, empty cache.
     *
     * scoped(), not singleton(): singleton() ties the instance to the container's lifetime, not
     * the request's — those only coincide under classic PHP-FPM. Under Octane or a persistent
     * queue worker (this package ships a ShouldQueue notification), a plain singleton() would
     * keep serving one process's first-resolved cache of every chatable's settings forever,
     * growing unbounded and going stale the moment settings change anywhere else. scoped() gives
     * the same one-instance-per-resolution-chain memoization but is flushed automatically
     * between Octane requests and after every queue job.
     */
    public function register(): void
    {
        parent::register();

        $this->app->scoped(UserSettingsServiceInterface::class, UserSettingsService::class);
    }

    public function configurePackage(Package $package): void
    {
        // Spatie's hasConfigFile() merge (via registerPackageConfigs()) runs after this method,
        // so the config()-driven gates below would see unpublished defaults as null without
        // merging here first. Idempotent — mergeConfigFrom() only fills missing keys, so
        // Spatie's later merge is a no-op and a host's published config still wins. The env(...)
        // fallbacks live INSIDE config/chat.php itself (matching chatable_models' convention)
        // rather than at the config()-read call sites, since a read-site fallback would never
        // trigger once the key is merged in.
        $this->mergeConfigFrom(__DIR__.'/../config/chat.php', 'chat');

        $package->name('chat')->hasConfigFile('chat');

        if ((bool) config('chat.run_migrations', true)) {
            // discoversMigrations() (not hasMigrations()) preserves the timestamp-prefixed
            // filenames' natural sort order, which matters here since several tables have
            // foreign keys to earlier ones — hasMigrations() sorts by the bare name instead,
            // which silently breaks creation order (tolerated by SQLite, fatal on MySQL/Postgres).
            $package->discoversMigrations()->runsMigrations();
        }

        if ((bool) config('chat.register_routes', true)) {
            $package->hasRoutes(['api', 'channels']);
        }

        if ((bool) config('chat.register_ui_routes', true)) {
            $package->hasRoutes('web')->hasViews();
        }

        $package->hasCommands([SweepPresenceCommand::class, PruneExpiredMessagesCommand::class, InstallCommand::class]);
    }

    public function packageBooted(): void
    {
        foreach ($this->policies as $model => $policy) {
            Gate::policy($model, $policy);
        }

        Relation::enforceMorphMap(Chat::chatableModels());

        // hasViews()'s loadViewsFrom() (called above via configurePackage()) registers the
        // "chat::" view namespace, but Blade's <x-prefix::name /> component-tag compiler only
        // consults namespaces registered here — without this, <x-chat::widget /> throws
        // "Unable to locate a class or view for component [chat::widget]".
        Blade::anonymousComponentNamespace('chat::components', 'chat');

        $this->registerDefaultDisk();
        $this->registerStatefulApiMiddleware();

        $this->publishes([
            __DIR__.'/../resources/css/theme.css' => public_path('vendor/chat/theme.css'),
        ], 'chat-theme');

        $this->publishes([
            __DIR__.'/../resources/js/icon-overrides.js' => public_path('vendor/chat/icons.js'),
        ], 'chat-icons');

        $this->publishes([
            __DIR__.'/../resources/js/wallpaper-overrides.js' => public_path('vendor/chat/wallpapers.js'),
        ], 'chat-wallpapers');
    }

    /**
     * The bundled chat UI authenticates via Sanctum's session-cookie flow, which needs
     * EnsureFrontendRequestsAreStateful on the 'api' middleware group. Registered here instead
     * of via the host's bootstrap/app.php so installing the package is enough — no host wiring
     * required.
     *
     * Pushed onto the HTTP Kernel, not the Router: the Kernel is the source of truth for
     * middleware groups and re-syncs the Router's copy whenever a later-booting provider
     * (Sanctum's own included) touches its middleware priority list, silently overwriting a
     * push made directly on the Router.
     */
    protected function registerStatefulApiMiddleware(): void
    {
        if (! (bool) env('CHAT_STATEFUL_API', true)) {
            return;
        }

        $this->app[Kernel::class]->prependMiddlewareToGroup('api', EnsureFrontendRequestsAreStateful::class);
    }

    protected function registerDefaultDisk(): void
    {
        $disk = config('chat.media.disk', 'chat');

        if (config("filesystems.disks.{$disk}")) {
            return;
        }

        $private = (bool) config('chat.media.serve_privately');
        $root = config('chat.media.disk_root', storage_path('app/public/chat'));

        config(["filesystems.disks.{$disk}" => [
            'driver' => 'local',
            // Public mode defaults nested under app/public (not app/chat) so it resolves through
            // the standard public/storage symlink — Laravel's dev-server storage.local route
            // 403s anything outside app/public, and this package has no other route serving
            // media, so attachments would 403 on any unreachable disk root. A host overriding
            // chat.media.disk_root/disk_url must preserve that same guarantee (a symlink or
            // equivalent serving route). Under serve_privately, chat.media.disk_root's own
            // default already points outside public/ instead — see config/chat.php — and this
            // disk is the only thing meant to reach it (via AttachmentController's signed
            // routes), so no symlink is created below.
            'root' => $root,
            'url' => config('chat.media.disk_url', '/storage/chat'),
            'visibility' => $private ? 'private' : 'public',
        ]]);

        if (! $private) {
            $this->ensureStorageLinkExists($root);
        }
    }

    /**
     * The disk above only serves through the standard public/storage symlink — without it every
     * avatar/attachment 404s until someone runs `php artisan storage:link` by hand, and re-runs
     * it after every fresh clone/deploy/container rebuild since the symlink isn't committed to
     * git. Create it automatically on boot instead, the same way the artisan command does under
     * the hood (Filesystem::link()) — idempotent and self-healing if the symlink is ever removed.
     *
     * Only for the untouched default root: a host that customized chat.media.disk_root/disk_url
     * already opted into managing serving themselves, so we shouldn't assume they also want this
     * symlink.
     */
    protected function ensureStorageLinkExists(string $root): void
    {
        if ($root !== storage_path('app/public/chat')) {
            return;
        }

        $link = public_path('storage');
        $target = storage_path('app/public');

        if (is_link($link) || file_exists($link) || ! is_dir($target)) {
            return;
        }

        try {
            $this->app->make(Filesystem::class)->link($target, $link);
        } catch (\Throwable) {
            // Best-effort: e.g. no permission to write into public/. Falls back to the
            // previous behavior of needing `php artisan storage:link` run by hand.
        }
    }
}
