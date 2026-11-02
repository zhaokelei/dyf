<script setup>
import { ref } from 'vue';
import { Upload } from 'lucide-vue-next';

const props = defineProps({
    accept: { type: String, default: '.pdf,.jpg,.jpeg,.png' },
    hint: { type: String, default: '' },
    multiple: { type: Boolean, default: false },
});

const emit = defineEmits(['change']);
const input = ref(null);
const fileName = ref('');

function onChange(event) {
    const files = Array.from(event.target.files ?? []);

    if (props.multiple) {
        fileName.value = files.length ? `已选择 ${files.length} 张图片` : '';
        emit('change', files);
        return;
    }

    const file = files[0] ?? null;
    fileName.value = file?.name ?? '';
    emit('change', file);
}
</script>

<template>
    <div>
        <input ref="input" type="file" class="hidden" :accept="accept" :multiple="multiple" @change="onChange" />
        <button
            type="button"
            class="flex w-full items-center gap-3 rounded-xl border border-dashed border-slate-300 bg-slate-50/60 px-4 py-3 text-left transition hover:border-brand-300 hover:bg-brand-50/40"
            @click="input.click()"
        >
            <span class="flex h-9 w-9 items-center justify-center rounded-lg bg-white text-slate-400 shadow-sm">
                <Upload class="h-4 w-4" />
            </span>
            <span class="min-w-0 flex-1">
                <span class="block truncate text-sm font-medium text-slate-700">
                    {{ fileName || '选择文件' }}
                </span>
                <span class="block text-xs text-slate-400">{{ hint || accept }}</span>
            </span>
        </button>
    </div>
</template>
