<script setup>
defineProps({
    modelValue: { type: String, required: true },
    items: { type: Array, required: true },
});

const emit = defineEmits(['update:modelValue']);
</script>

<template>
    <div class="flex gap-1 overflow-x-auto rounded-xl bg-slate-100 p-1">
        <button
            v-for="item in items"
            :key="item.key"
            type="button"
            :class="[
                'flex shrink-0 items-center gap-2 rounded-lg px-3.5 py-2 text-sm font-medium transition',
                modelValue === item.key
                    ? 'bg-white text-slate-900 shadow-sm'
                    : 'text-slate-500 hover:text-slate-700',
            ]"
            @click="emit('update:modelValue', item.key)"
        >
            {{ item.label }}
            <span
                v-if="item.count !== undefined"
                :class="[
                    'rounded-full px-1.5 py-0.5 text-[11px] font-semibold',
                    modelValue === item.key ? 'bg-brand-50 text-brand-700' : 'bg-slate-200 text-slate-500',
                ]"
            >
                {{ item.count }}
            </span>
        </button>
    </div>
</template>
