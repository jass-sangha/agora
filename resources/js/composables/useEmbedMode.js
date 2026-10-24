import { computed } from 'vue';

// window.ChatConfig is set once, in a static <script> tag, before this
// module ever loads — a plain computed (not a ref) is enough, matching the
// other window.ChatConfig-derived singletons in this codebase.
const isEmbedded = computed(() => Boolean(window.ChatConfig?.embed));

export function useEmbedMode() {
    return { isEmbedded };
}
