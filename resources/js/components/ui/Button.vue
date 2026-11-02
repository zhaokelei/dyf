<script setup>
import { computed } from 'vue';
import { Link } from '@inertiajs/vue3';
import { Loader2 } from 'lucide-vue-next';

const props = defineProps({
    variant: { type: String, default: 'primary' },
    size: { type: String, default: 'md' },
    type: { type: String, default: 'button' },
    href: { type: String, default: null },
    loading: { type: Boolean, default: false },
    disabled: { type: Boolean, default: false },
    block: { type: Boolean, default: false },
});

const variants = {
    primary: 'btn-primary',
    secondary: 'btn-secondary',
    ghost: 'btn-ghost',
    danger: 'btn-danger',
};

const sizes = {
    sm: '!px-3 !py-2 text-xs',
    md: '',
    lg: '!px-5 !py-3 text-base',
};

const classes = computed(() => [
    variants[props.variant] ?? variants.primary,
    sizes[props.size] ?? '',
    props.block ? 'w-full' : '',
]);
</script>

<template>
    <Link v-if="href" :href="href" :class="classes">
        <slot />
    </Link>
    <button v-else :type="type" :disabled="disabled || loading" :class="classes">
        <Loader2 v-if="loading" class="h-4 w-4 animate-spin" />
        <slot />
    </button>
</template>
