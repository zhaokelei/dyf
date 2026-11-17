<script setup>
import { computed, ref, watch } from 'vue';
import { Loader2 } from 'lucide-vue-next';
import Modal from '@/components/ui/Modal.vue';
import Badge from '@/components/ui/Badge.vue';
import EmptyState from '@/components/ui/EmptyState.vue';
import { formatDate, signedScore, sourceLabels } from '@/lib/format';

const props = defineProps({
    open: { type: Boolean, default: false },
    student: { type: Object, default: null },
    endpoint: { type: String, default: '' },
});

const emit = defineEmits(['update:open']);

const loading = ref(false);
const error = ref('');
const payload = ref(null);
let requestId = 0;

const title = computed(() => (props.student ? `${props.student.name} · 德育分明细` : '德育分明细'));
const description = computed(() => {
    if (!props.student) {
        return '';
    }

    return [props.student.student_id, props.student.class_name].filter(Boolean).join(' · ');
});

function close() {
    emit('update:open', false);
}

watch(
    () => (props.open ? props.endpoint : ''),
    async (endpoint) => {
        const current = ++requestId;
        payload.value = null;
        error.value = '';

        if (!endpoint) {
            loading.value = false;
            return;
        }

        loading.value = true;

        try {
            const response = await fetch(endpoint, {
                headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
                credentials: 'same-origin',
            });

            if (!response.ok) {
                throw new Error(response.status === 403 ? '没有权限查看该学生的德育分记录' : '加载失败，请稍后重试');
            }

            const data = await response.json();

            if (current === requestId) {
                payload.value = data;
            }
        } catch (exception) {
            if (current === requestId) {
                error.value = exception.message || '加载失败，请稍后重试';
            }
        } finally {
            if (current === requestId) {
                loading.value = false;
            }
        }
    },
    { immediate: true },
);
</script>

<template>
    <Modal :open="open" :title="title" :description="description" size="lg" @update:open="close">
        <div v-if="loading" class="flex items-center justify-center gap-2 py-16 text-sm text-slate-400">
            <Loader2 class="h-4 w-4 animate-spin" />
            正在加载该学生的德育分记录…
        </div>

        <div v-else-if="error" class="rounded-xl bg-rose-50 px-4 py-8 text-center text-sm text-rose-600">
            {{ error }}
        </div>

        <div v-else-if="payload" class="space-y-4">
            <div class="grid grid-cols-2 gap-3 sm:grid-cols-4">
                <div class="rounded-xl bg-slate-50 px-3.5 py-3">
                    <p class="text-xs text-slate-400">累计总分</p>
                    <p class="mt-1 text-lg font-semibold text-slate-800">{{ payload.summary.total_score }}</p>
                </div>
                <div class="rounded-xl bg-emerald-50 px-3.5 py-3">
                    <p class="text-xs text-emerald-600">加分合计</p>
                    <p class="mt-1 text-lg font-semibold text-emerald-700">+{{ payload.summary.add_score }}</p>
                </div>
                <div class="rounded-xl bg-rose-50 px-3.5 py-3">
                    <p class="text-xs text-rose-500">扣分合计</p>
                    <p class="mt-1 text-lg font-semibold text-rose-600">-{{ payload.summary.deduct_score }}</p>
                </div>
                <div class="rounded-xl bg-slate-50 px-3.5 py-3">
                    <p class="text-xs text-slate-400">有效记录</p>
                    <p class="mt-1 text-lg font-semibold text-slate-800">
                        {{ payload.summary.record_count }} 条
                        <span v-if="payload.summary.revoked_count" class="text-xs font-normal text-slate-400">
                            （已撤销 {{ payload.summary.revoked_count }}）
                        </span>
                    </p>
                </div>
            </div>

            <div v-if="payload.summary.records.length" class="overflow-hidden rounded-xl border border-slate-200">
                <div class="max-h-[52vh] divide-y divide-slate-100 overflow-y-auto">
                    <div v-for="record in payload.summary.records" :key="record.id" class="flex items-start gap-3 px-3.5 py-3">
                        <span class="w-[5.5rem] shrink-0 pt-0.5 text-xs text-slate-400">{{ formatDate(record.applied_on) }}</span>
                        <div class="min-w-0 flex-1">
                            <div class="flex flex-wrap items-center gap-1.5">
                                <span class="text-sm font-medium text-slate-700">{{ record.category }}</span>
                                <Badge :status="record.category_type" />
                                <Badge v-if="record.is_revoked" status="withdrawn">已撤销</Badge>
                            </div>
                            <p class="mt-1 text-xs leading-relaxed text-slate-500">{{ record.description || '无说明' }}</p>
                            <p class="mt-0.5 text-[11px] text-slate-400">
                                {{ record.semester || '未分学期' }} · {{ sourceLabels[record.source_type] ?? record.source_type }}
                            </p>
                            <p v-if="record.is_revoked && record.revoke_reason" class="mt-0.5 text-[11px] text-rose-400">
                                撤销原因：{{ record.revoke_reason }}
                            </p>
                        </div>
                        <span
                            :class="[
                                'shrink-0 pt-0.5 text-sm font-semibold',
                                record.is_revoked
                                    ? 'text-slate-400 line-through'
                                    : record.score >= 0 ? 'text-emerald-600' : 'text-rose-600',
                            ]"
                        >
                            {{ signedScore(record.score) }}
                        </span>
                    </div>
                </div>
            </div>

            <EmptyState v-else title="该学生暂无德育分记录" />
        </div>
    </Modal>
</template>
