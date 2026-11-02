<script setup>
import { computed } from 'vue';

const props = defineProps({
    status: { type: String, default: '' },
    tone: { type: String, default: '' },
});

const map = {
    pending: ['待审核', 'bg-amber-50 text-amber-700 ring-amber-200'],
    approved: ['已通过', 'bg-emerald-50 text-emerald-700 ring-emerald-200'],
    rejected: ['已驳回', 'bg-rose-50 text-rose-700 ring-rose-200'],
    withdrawn: ['已撤回', 'bg-slate-100 text-slate-600 ring-slate-200'],
    add: ['加分', 'bg-brand-50 text-brand-700 ring-brand-200'],
    deduct: ['扣分', 'bg-rose-50 text-rose-700 ring-rose-200'],
    active: ['启用', 'bg-emerald-50 text-emerald-700 ring-emerald-200'],
    inactive: ['禁用', 'bg-slate-100 text-slate-500 ring-slate-200'],
    student: ['学生', 'bg-sky-50 text-sky-700 ring-sky-200'],
    teacher: ['教师', 'bg-violet-50 text-violet-700 ring-violet-200'],
    admin: ['管理员', 'bg-amber-50 text-amber-700 ring-amber-200'],
    college_admin: ['学院管理员', 'bg-indigo-50 text-indigo-700 ring-indigo-200'],
    class_admin: ['辅导员', 'bg-teal-50 text-teal-700 ring-teal-200'],
};

const info = computed(() => map[props.status] ?? [props.status, 'bg-slate-100 text-slate-600 ring-slate-200']);
</script>

<template>
    <span :class="['inline-flex shrink-0 items-center whitespace-nowrap rounded-full px-2.5 py-1 text-xs font-medium ring-1 ring-inset', info[1]]">
        <slot>{{ info[0] }}</slot>
    </span>
</template>
