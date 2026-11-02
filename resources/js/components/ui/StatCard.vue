<script setup>
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue';

const props = defineProps({
    label: { type: String, required: true },
    value: { type: [String, Number], required: true },
    hint: { type: String, default: '' },
    tone: { type: String, default: 'brand' },
});

const tones = {
    brand: 'bg-brand-50 text-brand-600',
    emerald: 'bg-emerald-50 text-emerald-600',
    amber: 'bg-amber-50 text-amber-600',
    rose: 'bg-rose-50 text-rose-600',
    slate: 'bg-slate-100 text-slate-600',
};

const numeric = computed(() => {
    if (typeof props.value === 'number' && Number.isFinite(props.value)) {
        return { prefix: '', target: props.value, decimals: 0 };
    }

    const match = String(props.value ?? '').match(/^([+-]?)(\d+(?:\.\d+)?)$/);

    if (!match) {
        return null;
    }

    return {
        prefix: match[1],
        target: Number(match[2]),
        decimals: (match[2].split('.')[1] ?? '').length,
    };
});

const current = ref(0);
const displayValue = computed(() => {
    const data = numeric.value;

    if (!data) {
        return props.value;
    }

    return `${data.prefix}${current.value.toFixed(data.decimals)}`;
});

let raf = null;

function animateTo(target) {
    if (raf) cancelAnimationFrame(raf);

    const reduced = window.matchMedia('(prefers-reduced-motion: reduce)').matches;

    if (reduced) {
        current.value = target;
        return;
    }

    const start = current.value;
    const delta = target - start;
    const startedAt = performance.now();
    const duration = 850;

    const tick = (now) => {
        const progress = Math.max(0, Math.min(1, (now - startedAt) / duration));
        const eased = 1 - Math.pow(1 - progress, 4);
        current.value = start + delta * eased;

        if (progress < 1) {
            raf = requestAnimationFrame(tick);
        } else {
            current.value = target;
            raf = null;
        }
    };

    raf = requestAnimationFrame(tick);
}

onMounted(() => {
    if (numeric.value) {
        animateTo(numeric.value.target);
    }
});

watch(
    () => props.value,
    () => {
        if (numeric.value) {
            animateTo(numeric.value.target);
        }
    },
);

onBeforeUnmount(() => {
    if (raf) cancelAnimationFrame(raf);
});
</script>

<template>
    <div class="card flex items-start justify-between gap-4 p-5">
        <div class="min-w-0">
            <p class="text-xs font-medium uppercase tracking-wide text-slate-500">{{ label }}</p>
            <p class="mt-2 text-2xl font-semibold tracking-tight text-slate-900 tabular-nums">{{ displayValue }}</p>
            <p v-if="hint" class="mt-1 text-xs text-slate-400">{{ hint }}</p>
        </div>
        <div v-if="$slots.icon" :class="['flex h-11 w-11 shrink-0 items-center justify-center rounded-2xl', tones[tone] ?? tones.brand]">
            <slot name="icon" />
        </div>
    </div>
</template>
