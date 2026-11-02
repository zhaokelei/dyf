<script setup>
import { computed } from 'vue';
import { ChevronLeft, ChevronRight } from 'lucide-vue-next';

const props = defineProps({
    paginator: { type: Object, required: true },
    label: { type: String, default: '条记录' },
    pageSize: { type: [Number, String], default: null },
    pageSizeOptions: { type: Array, default: () => [] },
    showPageSize: { type: Boolean, default: false },
});

const emit = defineEmits(['change', 'size-change']);

const pageItems = computed(() => {
    const links = (props.paginator.links ?? []).filter((link) => /^\d+$/.test(String(link.label).trim()));

    if (links.length <= 7) {
        return links;
    }

    const currentIndex = links.findIndex((link) => link.active);
    const start = Math.max(0, currentIndex - 2);
    const end = Math.min(links.length, currentIndex + 3);
    const items = [];

    if (start > 0) {
        items.push(links[0]);
        if (start > 1) items.push({ label: '…', url: null, active: false });
    }

    items.push(...links.slice(start, end));

    if (end < links.length) {
        if (end < links.length - 1) items.push({ label: '…', url: null, active: false });
        items.push(links[links.length - 1]);
    }

    return items;
});

function go(url) {
    if (url) {
        emit('change', url);
    }
}
</script>

<template>
    <div
        v-if="paginator.total > 0"
        class="flex flex-col gap-3 border-t border-slate-100 px-5 py-4 sm:flex-row sm:items-center sm:justify-between"
    >
        <div class="flex flex-wrap items-center gap-3">
            <p class="text-xs text-slate-500">
                共 {{ paginator.total }} {{ label }}<template v-if="String(pageSize) === 'all'">，已全部显示</template><template v-else>，第 {{ paginator.current_page }} / {{ paginator.last_page }} 页</template>
            </p>
            <label v-if="showPageSize && pageSizeOptions.length" class="flex items-center gap-1.5 text-xs text-slate-500">
                每页
                <select
                    :value="String(pageSize)"
                    class="input !w-auto !py-1.5 !pl-2.5 !pr-7 text-xs"
                    @change="$emit('size-change', $event.target.value)"
                >
                    <option v-for="option in pageSizeOptions" :key="option.value" :value="option.value">{{ option.label }}</option>
                </select>
            </label>
        </div>
        <div v-if="String(pageSize) !== 'all' && paginator.last_page > 1" class="flex flex-wrap items-center gap-1">
            <button
                type="button"
                :disabled="!paginator.prev_page_url"
                class="flex h-8 w-8 items-center justify-center rounded-lg text-slate-500 transition hover:bg-slate-100 disabled:cursor-not-allowed disabled:text-slate-300"
                title="上一页"
                @click="go(paginator.prev_page_url)"
            >
                <ChevronLeft class="h-4 w-4" />
            </button>

            <button
                v-for="(item, index) in pageItems"
                :key="index"
                type="button"
                :disabled="!item.url"
                :class="[
                    'min-w-8 rounded-lg px-2 py-1.5 text-xs font-medium transition',
                    item.active
                        ? 'bg-brand-600 text-white'
                        : item.url
                          ? 'text-slate-600 hover:bg-slate-100'
                          : 'cursor-default text-slate-400',
                ]"
                @click="go(item.url)"
            >
                {{ item.label }}
            </button>

            <button
                type="button"
                :disabled="!paginator.next_page_url"
                class="flex h-8 w-8 items-center justify-center rounded-lg text-slate-500 transition hover:bg-slate-100 disabled:cursor-not-allowed disabled:text-slate-300"
                title="下一页"
                @click="go(paginator.next_page_url)"
            >
                <ChevronRight class="h-4 w-4" />
            </button>
        </div>
    </div>
</template>
