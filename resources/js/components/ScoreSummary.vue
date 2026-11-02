<script setup>
import { computed } from 'vue';

const props = defineProps({
    base: { type: Number, required: true },
    add: { type: Number, required: true },
    deduct: { type: Number, required: true },
    total: { type: Number, required: true },
});

const max = computed(() => Math.max(props.total, props.base + props.add, 100));
const addWidth = computed(() => `${Math.min(100, (props.add / max.value) * 100)}%`);
const baseWidth = computed(() => `${Math.min(100, (props.base / max.value) * 100)}%`);
</script>

<template>
    <div class="card overflow-hidden">
        <div class="bg-gradient-to-br from-slate-900 via-slate-900 to-brand-900 px-6 py-6 text-white">
            <p class="text-xs font-medium uppercase tracking-[0.18em] text-white/60">当前德育分</p>
            <div class="mt-2 flex items-end gap-2">
                <span class="text-5xl font-semibold tracking-tight">{{ total }}</span>
                <span class="pb-1.5 text-sm text-white/60">分</span>
            </div>
            <p class="mt-2 text-xs text-white/60">基础分 {{ base }} + 加分 {{ add }} - 扣分 {{ deduct }}</p>
            <div class="mt-5 flex h-2 overflow-hidden rounded-full bg-white/15">
                <div class="h-full bg-emerald-400/90" :style="{ width: addWidth }" />
                <div class="h-full bg-white/70" :style="{ width: baseWidth }" />
            </div>
            <div class="mt-2 flex justify-between text-[11px] text-white/50">
                <span>加分</span>
                <span>基础分</span>
            </div>
        </div>
        <div class="grid grid-cols-3 divide-x divide-slate-100 text-center">
            <div class="px-3 py-4">
                <p class="text-lg font-semibold text-emerald-600">+{{ add }}</p>
                <p class="mt-0.5 text-xs text-slate-400">累计加分</p>
            </div>
            <div class="px-3 py-4">
                <p class="text-lg font-semibold text-rose-600">-{{ deduct }}</p>
                <p class="mt-0.5 text-xs text-slate-400">累计扣分</p>
            </div>
            <div class="px-3 py-4">
                <p class="text-lg font-semibold text-slate-800">{{ base }}</p>
                <p class="mt-0.5 text-xs text-slate-400">基础分</p>
            </div>
        </div>
    </div>
</template>
