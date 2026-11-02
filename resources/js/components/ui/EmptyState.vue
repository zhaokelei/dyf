<script setup>
import { Inbox } from 'lucide-vue-next';

defineProps({
    title: { type: String, default: '暂无数据' },
    description: { type: String, default: '' },
});
</script>

<template>
    <div class="flex flex-col items-center justify-center px-6 py-10 text-center">
        <div class="flex h-12 w-12 items-center justify-center rounded-2xl bg-slate-100 text-slate-400">
            <Inbox class="h-5 w-5" />
        </div>
        <p class="mt-3 text-sm font-medium text-slate-700">{{ title }}</p>
        <p v-if="description" class="mt-1 text-xs text-slate-400">{{ description }}</p>
        <div v-if="$slots.default" class="mt-4">
            <slot />
        </div>
    </div>
</template>
