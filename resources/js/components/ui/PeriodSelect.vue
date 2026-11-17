<script setup>
import { computed } from 'vue';
import { usePage } from '@inertiajs/vue3';

defineProps({
    modelValue: { type: String, default: '' },
    includeAll: { type: Boolean, default: false },
});

const emit = defineEmits(['update:modelValue']);
const page = usePage();
const options = computed(() => page.props.periodOptions ?? { semesters: [], years: [] });
</script>

<template>
    <select
        :value="modelValue"
        class="input !w-auto min-w-[190px]"
        @change="emit('update:modelValue', $event.target.value)"
    >
        <option v-if="includeAll" value="all">全部时间（所有学期）</option>
        <optgroup v-if="options.semesters?.length" label="学期">
            <option v-for="item in options.semesters" :key="item.value" :value="item.value">
                {{ item.label }}{{ item.is_current ? '（当前）' : '' }}
            </option>
        </optgroup>
        <optgroup v-if="options.years?.length" label="学年汇总">
            <option v-for="item in options.years" :key="item.value" :value="item.value">
                {{ item.label }}
            </option>
        </optgroup>
    </select>
</template>
