<script setup>
defineProps({
    modelValue: { type: Boolean, default: false },
    disabled: { type: Boolean, default: false },
});

const emit = defineEmits(['update:modelValue']);
</script>

<template>
    <button
        type="button"
        role="switch"
        :aria-checked="modelValue"
        :disabled="disabled"
        :class="[
            'relative inline-flex h-6 w-11 shrink-0 items-center rounded-full border transition-all duration-300 ease-[cubic-bezier(0.34,1.56,0.64,1)] active:scale-95 focus:outline-none focus-visible:ring-2 focus-visible:ring-brand-500/40 disabled:cursor-not-allowed disabled:opacity-50',
            modelValue
                ? 'border-emerald-400/60 bg-emerald-500 shadow-[inset_0_1px_2px_rgba(255,255,255,0.35),0_2px_8px_rgba(16,185,129,0.25)]'
                : 'border-slate-300 bg-slate-200/80',
        ]"
        @click="emit('update:modelValue', !modelValue)"
    >
        <span
            :class="[
                'pointer-events-none inline-block h-5 w-5 rounded-full bg-white shadow-md ring-1 ring-black/5 transition-transform duration-300 ease-[cubic-bezier(0.34,1.56,0.64,1)]',
                modelValue ? 'translate-x-[22px] scale-105' : 'translate-x-0.5',
            ]"
        />
    </button>
</template>
