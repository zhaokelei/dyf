<script setup>
import { computed, nextTick, onBeforeUnmount, onMounted, ref, watch } from 'vue';
import { Check, ChevronDown, Search, X } from 'lucide-vue-next';

const props = defineProps({
    modelValue: { type: [Number, String], default: '' },
    users: { type: Array, default: () => [] },
    placeholder: { type: String, default: '搜索姓名、工号或学号' },
    disabled: { type: Boolean, default: false },
});

const emit = defineEmits(['update:modelValue', 'change']);

const open = ref(false);
const keyword = ref('');
const activeIndex = ref(0);
const root = ref(null);
const searchInput = ref(null);

const roleLabels = {
    student: '学生',
    teacher: '教师',
    class_admin: '辅导员',
    college_admin: '学院管理员',
    admin: '管理员',
};

const selected = computed(
    () => props.users.find((user) => String(user.id) === String(props.modelValue)) ?? null,
);

const filtered = computed(() => {
    const value = keyword.value.trim().toLowerCase();

    if (!value) {
        return props.users;
    }

    return props.users.filter((user) =>
        [user.name, user.username, user.student_id, user.employee_id, user.class_name]
            .filter(Boolean)
            .some((field) => String(field).toLowerCase().includes(value)),
    );
});

function subtitle(user) {
    const number = user.student_id || user.employee_id || user.username;
    return [number, user.class_name].filter(Boolean).join(' · ');
}

async function toggle() {
    if (props.disabled) {
        return;
    }

    open.value = !open.value;

    if (open.value) {
        keyword.value = '';
        activeIndex.value = 0;
        await nextTick();
        searchInput.value?.focus();
    }
}

function choose(user) {
    emit('update:modelValue', user.id);
    emit('change', user);
    open.value = false;
    keyword.value = '';
}

function clear() {
    open.value = false;
    emit('update:modelValue', '');
    emit('change', null);
}

function onKeydown(event) {
    if (event.key === 'ArrowDown') {
        event.preventDefault();
        activeIndex.value = Math.min(activeIndex.value + 1, filtered.value.length - 1);
    } else if (event.key === 'ArrowUp') {
        event.preventDefault();
        activeIndex.value = Math.max(activeIndex.value - 1, 0);
    } else if (event.key === 'Enter') {
        event.preventDefault();
        const user = filtered.value[activeIndex.value];
        if (user) {
            choose(user);
        }
    } else if (event.key === 'Escape') {
        open.value = false;
    }
}

function onClickOutside(event) {
    if (open.value && root.value && !root.value.contains(event.target)) {
        open.value = false;
    }
}

watch(keyword, () => {
    activeIndex.value = 0;
});

onMounted(() => document.addEventListener('mousedown', onClickOutside));
onBeforeUnmount(() => document.removeEventListener('mousedown', onClickOutside));
</script>

<template>
    <div ref="root" class="relative">
        <button
            type="button"
            :disabled="disabled"
            class="input flex w-full items-center justify-between gap-2 text-left disabled:cursor-not-allowed disabled:opacity-60"
            @click="toggle"
        >
            <span v-if="selected" class="flex min-w-0 items-baseline gap-1.5">
                <span class="truncate font-medium text-slate-800">{{ selected.name }}</span>
                <span class="truncate text-xs text-slate-400">{{ subtitle(selected) }}</span>
            </span>
            <span v-else class="truncate text-slate-400">{{ placeholder }}</span>
            <span class="flex shrink-0 items-center gap-1">
                <X v-if="selected && !disabled" class="h-4 w-4 text-slate-400 hover:text-rose-500" @click.stop="clear" />
                <ChevronDown :class="['h-4 w-4 text-slate-400 transition', open ? 'rotate-180' : '']" />
            </span>
        </button>

        <div v-if="open" class="mt-1.5 overflow-hidden rounded-xl border border-slate-200 bg-white shadow-lg">
            <div class="border-b border-slate-100 p-2">
                <div class="relative">
                    <Search class="pointer-events-none absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 text-slate-400" />
                    <input
                        ref="searchInput"
                        v-model="keyword"
                        type="text"
                        class="input !h-9 !py-1.5 !pl-9 text-sm"
                        placeholder="输入姓名 / 工号 / 学号搜索"
                        autocomplete="off"
                        @keydown="onKeydown"
                    />
                </div>
            </div>
            <ul class="max-h-60 overflow-y-auto p-1">
                <li v-for="(user, index) in filtered" :key="user.id">
                    <button
                        type="button"
                        :class="[
                            'flex w-full items-center gap-2 rounded-lg px-2.5 py-2 text-left transition',
                            index === activeIndex ? 'bg-brand-50' : 'hover:bg-slate-50',
                        ]"
                        @click="choose(user)"
                        @mouseenter="activeIndex = index"
                    >
                        <span class="min-w-0 flex-1">
                            <span class="flex items-baseline gap-1.5">
                                <span class="truncate text-sm font-medium text-slate-700">{{ user.name }}</span>
                                <span class="shrink-0 rounded bg-slate-100 px-1.5 py-0.5 text-[10px] text-slate-500">
                                    {{ roleLabels[user.role] ?? user.role }}
                                </span>
                            </span>
                            <span class="mt-0.5 block truncate text-xs text-slate-400">
                                {{ subtitle(user) || '未填写学号 / 工号' }}
                            </span>
                        </span>
                        <Check v-if="String(user.id) === String(modelValue)" class="h-4 w-4 shrink-0 text-brand-600" />
                    </button>
                </li>
                <li v-if="!filtered.length" class="px-3 py-6 text-center text-xs text-slate-400">
                    没有匹配的人员
                </li>
            </ul>
        </div>
    </div>
</template>
