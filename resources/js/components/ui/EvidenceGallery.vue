<script setup>
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue';
import { ChevronLeft, ChevronRight, Download, FileText, Images } from 'lucide-vue-next';
import Modal from '@/components/ui/Modal.vue';

const props = defineProps({
    files: { type: Array, default: () => [] },
    label: { type: String, default: '证明材料' },
    variant: { type: String, default: 'grid' },
    columns: { type: String, default: 'grid-cols-2 sm:grid-cols-3' },
});

const activeIndex = ref(null);
const open = computed(() => activeIndex.value !== null && activeIndex.value < props.files.length);
const active = computed(() => (open.value ? props.files[activeIndex.value] : null));

function isPdf(file) {
    return /\.pdf(\?|$)/i.test(file?.name ?? '');
}

function openAt(index) {
    if (!props.files.length) {
        return;
    }

    activeIndex.value = Math.min(index, props.files.length - 1);
}

function close() {
    activeIndex.value = null;
}

function step(delta) {
    if (!open.value) {
        return;
    }

    const next = activeIndex.value + delta;

    if (next >= 0 && next < props.files.length) {
        activeIndex.value = next;
    }
}

function onKeydown(event) {
    if (!open.value) {
        return;
    }

    if (event.key === 'ArrowLeft') {
        step(-1);
    } else if (event.key === 'ArrowRight') {
        step(1);
    }
}

watch(
    () => props.files.length,
    (length) => {
        if (length === 0) {
            activeIndex.value = null;
        } else if (activeIndex.value !== null && activeIndex.value >= length) {
            activeIndex.value = length - 1;
        }
    },
);

onMounted(() => document.addEventListener('keydown', onKeydown));
onBeforeUnmount(() => document.removeEventListener('keydown', onKeydown));
</script>

<template>
    <div v-if="files.length">
        <template v-if="variant === 'inline'">
            <div class="flex flex-wrap items-center gap-2">
                <button
                    v-for="(file, index) in files"
                    :key="file.key"
                    type="button"
                    class="group relative h-12 w-16 shrink-0 overflow-hidden rounded-lg border border-slate-200 bg-slate-100 transition hover:border-brand-300 hover:ring-2 hover:ring-brand-100"
                    :title="file.name"
                    @click="openAt(index)"
                >
                    <img
                        v-if="!isPdf(file)"
                        :src="file.preview"
                        :alt="file.name"
                        class="h-full w-full object-cover"
                        loading="lazy"
                    />
                    <span v-else class="flex h-full w-full items-center justify-center text-slate-400">
                        <FileText class="h-5 w-5" />
                    </span>
                    <span class="absolute inset-0 bg-slate-900/0 transition group-hover:bg-slate-900/10" />
                </button>
                <button
                    type="button"
                    class="inline-flex items-center gap-1 text-xs font-medium text-brand-600 hover:text-brand-700"
                    @click="openAt(0)"
                >
                    <Images class="h-3.5 w-3.5" />
                    {{ label }}{{ files.length > 1 ? ` ${files.length}` : '' }}
                </button>
            </div>
        </template>

        <template v-else>
            <div class="space-y-2">
                <p class="text-xs font-medium text-slate-500">{{ label }}（{{ files.length }} 张）</p>
                <div class="grid gap-3" :class="columns">
                    <div
                        v-for="(file, index) in files"
                        :key="file.key"
                        class="group relative min-w-0 overflow-hidden rounded-xl border border-slate-200 bg-white"
                    >
                        <button type="button" class="block w-full text-left" @click="openAt(index)">
                            <div class="relative aspect-[4/3] w-full overflow-hidden bg-slate-100">
                                <img
                                    v-if="!isPdf(file)"
                                    :src="file.preview"
                                    :alt="file.name"
                                    class="absolute inset-0 h-full w-full object-cover transition duration-300 group-hover:scale-[1.03]"
                                    loading="lazy"
                                />
                                <div v-else class="absolute inset-0 flex flex-col items-center justify-center gap-1.5 text-slate-400">
                                    <FileText class="h-7 w-7" />
                                    <span class="text-[11px]">PDF 文件</span>
                                </div>
                                <span class="pointer-events-none absolute inset-0 bg-gradient-to-t from-slate-900/35 via-transparent to-transparent opacity-0 transition group-hover:opacity-100" />
                                <span class="pointer-events-none absolute bottom-2 left-2 rounded-full bg-white/90 px-2 py-0.5 text-[11px] font-medium text-slate-600 opacity-0 shadow-sm transition group-hover:opacity-100">
                                    点击预览
                                </span>
                            </div>
                            <div class="flex h-9 items-center px-2.5">
                                <span class="truncate text-xs text-slate-500">{{ file.name }}</span>
                            </div>
                        </button>
                        <a
                            :href="file.download"
                            class="absolute right-2 top-2 z-10 inline-flex h-7 w-7 items-center justify-center rounded-full bg-white/90 text-slate-600 shadow-sm ring-1 ring-slate-200 transition hover:bg-white hover:text-brand-600"
                            title="下载原图"
                            @click.stop
                        >
                            <Download class="h-3.5 w-3.5" />
                        </a>
                    </div>
                </div>
            </div>
        </template>

        <Modal
            :open="open"
            :title="active?.name ?? label"
            :description="active ? `${label} · 第 ${activeIndex + 1} / ${files.length} 张` : ''"
            size="lg"
            @update:open="close"
        >
            <div v-if="active" class="space-y-3">
                <div class="flex min-h-[220px] items-center justify-center overflow-hidden rounded-xl bg-slate-100 p-2">
                    <img
                        v-if="!isPdf(active)"
                        :src="active.preview"
                        :alt="active.name"
                        class="max-h-[58vh] w-auto max-w-full object-contain"
                    />
                    <iframe
                        v-else
                        :src="active.preview"
                        :title="active.name"
                        class="h-[58vh] w-full rounded-lg bg-white"
                    />
                </div>

                <div v-if="files.length > 1" class="flex gap-2 overflow-x-auto pb-1">
                    <button
                        v-for="(file, index) in files"
                        :key="file.key"
                        type="button"
                        class="relative h-14 w-20 shrink-0 overflow-hidden rounded-lg border-2 bg-slate-100 transition"
                        :class="index === activeIndex ? 'border-brand-500' : 'border-transparent hover:border-slate-300'"
                        :title="file.name"
                        @click="openAt(index)"
                    >
                        <img
                            v-if="!isPdf(file)"
                            :src="file.preview"
                            :alt="file.name"
                            class="h-full w-full object-cover"
                            loading="lazy"
                        />
                        <span v-else class="flex h-full w-full items-center justify-center text-slate-400">
                            <FileText class="h-5 w-5" />
                        </span>
                    </button>
                </div>
            </div>

            <template #footer>
                <div class="flex flex-wrap items-center justify-between gap-3">
                    <a :href="active?.download" class="btn-secondary !px-3 !py-2 text-xs">
                        <Download class="h-3.5 w-3.5" />
                        下载原图
                    </a>
                    <div v-if="files.length > 1" class="flex items-center gap-2">
                        <button
                            type="button"
                            class="btn-secondary !px-2.5 !py-2 text-xs disabled:cursor-not-allowed disabled:opacity-40"
                            :disabled="activeIndex === 0"
                            @click="step(-1)"
                        >
                            <ChevronLeft class="h-4 w-4" />
                            上一张
                        </button>
                        <button
                            type="button"
                            class="btn-secondary !px-2.5 !py-2 text-xs disabled:cursor-not-allowed disabled:opacity-40"
                            :disabled="activeIndex === files.length - 1"
                            @click="step(1)"
                        >
                            下一张
                            <ChevronRight class="h-4 w-4" />
                        </button>
                    </div>
                </div>
            </template>
        </Modal>
    </div>
</template>
