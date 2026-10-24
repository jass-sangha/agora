<script setup>
import axios from 'axios';
import { onMounted, onUnmounted } from 'vue';
import AppShell from './components/layout/AppShell.vue';
import CallOverlay from './components/call/CallOverlay.vue';
import MinimizedCallBar from './components/call/MinimizedCallBar.vue';
import Toast from './components/shared/Toast.vue';
import { setCurrentChatable } from './store';
import { useEcho } from './composables/useEcho';
import { usePresence } from './composables/usePresence';
import { useProfile } from './composables/useProfile';
import { useBlockedUsers } from './composables/useBlockedUsers';
import { useConversations } from './composables/useConversations';
import { useToast } from './composables/useToast';

const config = window.ChatConfig ?? {};

setCurrentChatable(config.chatableType ?? null, config.chatableId ?? null);

const presence = usePresence();
const { refreshOne, setActive } = useConversations();
const { show: showToast } = useToast();

onMounted(async () => {
    useEcho();
    presence.start();
    useProfile().ensureSelfCached();
    useBlockedUsers().loadBlocked();

    const requestedId = new URLSearchParams(window.location.search).get('conversation');
    if (!requestedId) return;

    const conversationId = Number(requestedId);
    if (!/^[1-9]\d*$/.test(requestedId) || !Number.isSafeInteger(conversationId)) {
        setActive(null);
        return;
    }

    try {
        await refreshOne(conversationId);
        setActive(conversationId);
    } catch (error) {
        if (!axios.isAxiosError(error)) throw error;

        showToast('This conversation could not be opened.');
    }
});

onUnmounted(() => {
    presence.stop();
});
</script>

<template>
    <AppShell />
    <CallOverlay />
    <MinimizedCallBar />
    <Toast />
</template>
