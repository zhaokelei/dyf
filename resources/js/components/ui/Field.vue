<script setup>
defineProps({
    label: { type: String, default: '' },
    error: { type: [String, Array], default: '' },
    hint: { type: String, default: '' },
    required: { type: Boolean, default: false },
});
</script>

<template>
    <label class="block">
        <span v-if="label" class="mb-1.5 flex items-center gap-1 text-sm font-medium text-slate-700">
            {{ label }}
            <span v-if="required" class="text-rose-500">*</span>
        </span>
        <slot />
        <span v-if="error" class="mt-1.5 block text-xs text-rose-600">
            {{ Array.isArray(error) ? error[0] : error }}
        </span>
        <span v-else-if="hint" class="mt-1.5 block text-xs text-slate-400">{{ hint }}</span>
    </label>
</template>
