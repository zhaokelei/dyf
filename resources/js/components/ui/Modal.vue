<script>
let nextModalId = 0;
const openStack = [];
</script>

<script setup>
import { onBeforeUnmount, onMounted, watch } from 'vue';
import { X } from 'lucide-vue-next';

const props = defineProps({
    open: { type: Boolean, default: false },
    title: { type: String, default: '' },
    description: { type: String, default: '' },
    size: { type: String, default: 'md' },
});

const emit = defineEmits(['update:open']);

const sizes = {
    sm: 'max-w-md',
    md: 'max-w-xl',
    lg: 'max-w-3xl',
    xl: 'max-w-5xl',
};

const modalId = ++nextModalId;

function close() {
    emit('update:open', false);
}

function onKeydown(event) {
    if (event.key === 'Escape' && props.open && openStack[openStack.length - 1] === modalId) {
        close();
    }
}

function syncStack(open) {
    const index = openStack.indexOf(modalId);

    if (open && index === -1) {
        openStack.push(modalId);
    } else if (!open && index !== -1) {
        openStack.splice(index, 1);
    }

    document.body.style.overflow = openStack.length ? 'hidden' : '';
}

watch(() => props.open, syncStack, { immediate: true });

onMounted(() => document.addEventListener('keydown', onKeydown));
onBeforeUnmount(() => {
    const index = openStack.indexOf(modalId);

    if (index !== -1) {
        openStack.splice(index, 1);
    }

    document.body.style.overflow = openStack.length ? 'hidden' : '';
    document.removeEventListener('keydown', onKeydown);
});
</script>

<template>
    <Teleport to="body">
        <Transition name="modal">
            <div v-if="open" class="fixed inset-0 z-[60] flex items-end justify-center p-0 sm:items-center sm:p-6">
                <div class="absolute inset-0 bg-slate-900/40 backdrop-blur-sm" @click="close" />
                <div
                    :class="[
                        'modal-panel relative flex max-h-[92vh] w-full flex-col overflow-hidden rounded-t-3xl bg-white shadow-2xl sm:rounded-2xl',
                        sizes[size] ?? sizes.md,
                    ]"
                >
                    <header class="flex items-start justify-between gap-4 border-b border-slate-100 px-5 py-4 sm:px-6">
                        <div>
                            <h3 class="break-words text-base font-semibold text-slate-900">{{ title }}</h3>
                            <p v-if="description" class="mt-1 text-sm text-slate-500">{{ description }}</p>
                        </div>
                        <button
                            type="button"
                            class="flex h-9 w-9 shrink-0 items-center justify-center rounded-full text-slate-400 transition hover:bg-slate-100 hover:text-slate-600"
                            @click="close"
                        >
                            <X class="h-4 w-4" />
                        </button>
                    </header>
                    <div class="overflow-y-auto px-5 py-5 sm:px-6">
                        <slot />
                    </div>
                    <footer v-if="$slots.footer" class="border-t border-slate-100 bg-slate-50/60 px-5 py-4 sm:px-6">
                        <slot name="footer" />
                    </footer>
                </div>
            </div>
        </Transition>
    </Teleport>
</template>
