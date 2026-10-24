<script setup>
import { computed } from 'vue';
import { useChatStore } from '../../store';
import { useConversations } from '../../composables/useConversations';
import { useSidebarUi } from '../../composables/useSidebarUi';
import Icon from '../shared/Icon.vue';

const store = useChatStore();
const { setActive } = useConversations();
const { view, setView, setFilter } = useSidebarUi();

const unreadCount = computed(
    () => store.conversations.reduce((sum, c) => sum + (c.unread_count ?? 0), 0),
);

function openChats() {
    setActive(null);
    setView('chats');
    setFilter('all');
}

function openMedia() {
    setView('media');
}

function openProfile() {
    setView('profile');
}
</script>

<template>
    <nav
        class="chat-mobile-tab-bar flex shrink-0 items-stretch gap-0.5 border-t border-chat-border bg-chat-surface px-3 pb-[max(env(safe-area-inset-bottom),6px)] pt-1.5 sm:hidden"
    >
        <button
            type="button"
            title="Chats"
            class="flex flex-1 flex-col items-center gap-0.5 rounded-2xl py-1"
            :class="view === 'chats' ? 'text-chat-accent' : 'text-chat-textMuted'"
            @click="openChats"
        >
            <span class="relative flex h-7 w-[52px] items-center justify-center rounded-full" :class="view === 'chats' ? 'bg-chat-accent/15' : ''">
                <Icon name="chat-bubble" :size="20" />
                <span
                    v-if="unreadCount > 0"
                    class="absolute -top-0.5 right-1 min-w-[17px] rounded-full bg-chat-accent px-1 text-center text-[10px] font-bold leading-[17px] text-chat-accentContrast"
                >{{ unreadCount }}</span>
            </span>
            <span class="text-[10.5px] font-semibold">Chats</span>
        </button>

        <button
            type="button"
            title="Media"
            class="flex flex-1 flex-col items-center gap-0.5 rounded-2xl py-1"
            :class="view === 'media' ? 'text-chat-accent' : 'text-chat-textMuted'"
            @click="openMedia"
        >
            <span class="flex h-7 w-[52px] items-center justify-center rounded-full" :class="view === 'media' ? 'bg-chat-accent/15' : ''">
                <Icon name="gallery" :size="20" />
            </span>
            <span class="text-[10.5px] font-semibold">Media</span>
        </button>

        <button
            type="button"
            title="Profile"
            class="flex flex-1 flex-col items-center gap-0.5 rounded-2xl py-1"
            :class="view === 'profile' ? 'text-chat-accent' : 'text-chat-textMuted'"
            @click="openProfile"
        >
            <span class="flex h-7 w-[52px] items-center justify-center rounded-full" :class="view === 'profile' ? 'bg-chat-accent/15' : ''">
                <Icon name="settings" :size="20" />
            </span>
            <span class="text-[10.5px] font-semibold">Profile</span>
        </button>
    </nav>
</template>
