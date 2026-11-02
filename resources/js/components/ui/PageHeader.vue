<script setup>
defineProps({
    title: { type: String, required: true },
    description: { type: String, default: '' },
});
</script>

<template>
    <div class="mb-5 flex flex-wrap items-end justify-between gap-3">
        <div>
            <h1 class="text-xl font-semibold tracking-tight text-slate-900 sm:text-2xl">{{ title }}</h1>
            <p v-if="description" class="mt-1 text-sm text-slate-500">{{ description }}</p>
        </div>
        <div v-if="$slots.actions" class="flex flex-wrap items-center gap-2">
            <slot name="actions" />
        </div>
    </div>
</template>
