<script setup>
import Modal from './Modal.vue';
import Button from './Button.vue';

defineProps({
    open: { type: Boolean, default: false },
    title: { type: String, default: '确认操作' },
    message: { type: String, default: '' },
    confirmText: { type: String, default: '确认' },
    danger: { type: Boolean, default: false },
    loading: { type: Boolean, default: false },
});

const emit = defineEmits(['update:open', 'confirm']);
</script>

<template>
    <Modal
        :open="open"
        :title="title"
        size="sm"
        @update:open="emit('update:open', $event)"
    >
        <p class="text-sm leading-relaxed text-slate-600">{{ message }}</p>
        <slot />
        <template #footer>
            <div class="flex justify-end gap-2">
                <Button variant="secondary" @click="emit('update:open', false)">取消</Button>
                <Button
                    :variant="danger ? 'danger' : 'primary'"
                    :loading="loading"
                    @click="emit('confirm')"
                >
                    {{ confirmText }}
                </Button>
            </div>
        </template>
    </Modal>
</template>
