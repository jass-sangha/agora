<script setup>
import { computed } from 'vue';
import { useUsers } from '../../composables/useUsers';
import Icon from '../shared/Icon.vue';

const props = defineProps({
    replyTo: { type: Object, required: true },
    dismissible: { type: Boolean, default: false },
    isOwn: { type: Boolean, default: false },
});

defineEmits(['dismiss']);

const { get } = useUsers();

const whoName = computed(() => get({ type: props.replyTo.chatable_type, id: props.replyTo.chatable_id }).name);

const MEDIA_THUMBNAIL_TYPES = new Set(['image', 'video', 'gif', 'sticker']);

const firstAttachment = computed(() => props.replyTo.attachments?.[0] ?? null);

const thumbnailUrl = computed(() => {
    if (!MEDIA_THUMBNAIL_TYPES.has(props.replyTo.type)) return null;
    const attachment = firstAttachment.value;
    return attachment ? attachment.thumbnail_url || attachment.url : null;
});

const isVideoThumbnail = computed(() => props.replyTo.type === 'video');

const typeLabel = computed(() => {
    switch (props.replyTo.type) {
        case 'image':
            return 'Photo';
        case 'video':
            return 'Video';
        case 'gif':
            return 'GIF';
        case 'sticker':
            return 'Sticker';
        case 'voice':
            return 'Voice message';
        case 'audio':
            return 'Audio';
        case 'document':
            return firstAttachment.value?.original_filename ?? 'Document';
        case 'poll':
            return props.replyTo.metadata?.question ?? 'Poll';
        case 'event':
            return props.replyTo.metadata?.title ?? 'Event';
        case 'location':
            return props.replyTo.metadata?.name ?? 'Location';
        case 'contact':
            return props.replyTo.metadata?.name ?? 'Contact';
        default:
            return '';
    }
});

const snippet = computed(() => {
    if (props.replyTo.deleted_for_everyone) return 'This message was deleted';
    // The realtime message.sent push omits body/metadata/attachments entirely (see
    // MessageSent::serializeReplyTo() — a broadcast frame is shared by every recipient at once,
    // so it can't redact per-viewer the way the REST response does for a reply target the
    // viewer deleted for themselves). `body` is simply absent here, not empty, until this
    // conversation's messages are (re)loaded over REST — fall back to the type label rather
    // than rendering a blank preview.
    if (props.replyTo.body === undefined) return typeLabel.value || 'Message';
    if (props.replyTo.type === 'text') return props.replyTo.body || '';
    return props.replyTo.body?.trim() || typeLabel.value;
});
</script>

<template>
    <div
        v-if="dismissible"
        class="chat-reply-preview flex items-center gap-3 rounded-chat bg-chat-surface px-4 py-2.5 shadow-chat"
    >
        <span class="shrink-0 self-stretch rounded-full bg-chat-accent" style="width: 3px" />
        <img
            v-if="thumbnailUrl"
            :src="thumbnailUrl"
            alt=""
            class="h-9 w-9 shrink-0 rounded-lg object-cover"
        >
        <div class="min-w-0 flex-1">
            <p class="text-[11.5px] font-bold text-chat-accentText">Replying to {{ whoName }}</p>
            <p class="truncate text-[12.5px] text-chat-textMuted">{{ snippet }}</p>
        </div>
        <button
            type="button"
            title="Cancel reply"
            class="flex h-7 w-7 shrink-0 items-center justify-center rounded-full text-chat-textMuted hover:bg-chat-surfaceHover"
            @click="$emit('dismiss')"
        >
            <Icon name="close-outline" :size="14" />
        </button>
    </div>

    <div
        v-else
        class="chat-reply-preview flex items-center gap-2 rounded-chat-sm px-[11px] py-[7px]"
        :class="isOwn ? 'bg-[rgba(140,73,26,.1)]' : 'bg-chat-surfaceHover'"
    >
        <div class="relative min-w-0 flex-1">
            <p class="text-[11.5px] font-bold text-chat-accentText">{{ whoName }}</p>
            <p class="mt-px truncate text-[12.5px] text-chat-textMuted">{{ snippet }}</p>
        </div>
        <span v-if="thumbnailUrl" class="relative shrink-0">
            <img :src="thumbnailUrl" alt="" class="h-9 w-9 rounded-lg object-cover">
            <Icon
                v-if="isVideoThumbnail"
                name="play-overlay"
                :size="14"
                class="absolute inset-0 m-auto drop-shadow"
            />
        </span>
    </div>
</template>
