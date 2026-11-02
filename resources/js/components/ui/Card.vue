<script setup>
defineProps({
    padding: { type: Boolean, default: true },
    title: { type: String, default: '' },
    description: { type: String, default: '' },
});
</script>

<template>
    <section class="card">
        <header
            v-if="title || $slots.actions"
            class="flex flex-wrap items-center justify-between gap-3 border-b border-slate-100 px-5 py-4"
        >
            <div>
                <h2 class="text-sm font-semibold text-slate-800">{{ title }}</h2>
                <p v-if="description" class="mt-0.5 text-xs text-slate-500">{{ description }}</p>
            </div>
            <div v-if="$slots.actions" class="flex items-center gap-2">
                <slot name="actions" />
            </div>
        </header>
        <div :class="padding ? 'p-5' : ''">
            <slot />
        </div>
    </section>
</template>
