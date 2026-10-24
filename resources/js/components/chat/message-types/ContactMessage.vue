<script setup>
defineProps({
    message: { type: Object, required: true },
});
</script>

<template>
    <div class="chat-contact-message rounded border border-chat-border bg-chat-surface p-2">
        <div class="chat-contact-message__header flex items-center gap-2">
            <span class="text-2xl">👤</span>
            <span class="text-sm font-medium">{{ message.metadata?.name }}</span>
        </div>
        <p v-for="phone in message.metadata?.phones ?? []" :key="phone" class="mt-1 text-xs text-chat-textMuted">{{ phone }}</p>
        <p v-for="email in message.metadata?.emails ?? []" :key="email" class="text-xs text-chat-textMuted">{{ email }}</p>
    </div>
</template>
