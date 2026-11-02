<script setup>
import { ref } from 'vue';
import { Head, router, useForm } from '@inertiajs/vue3';
import { Download, ShieldAlert, ShieldCheck, ShieldX } from 'lucide-vue-next';
import AuthenticatedLayout from '@/layouts/AuthenticatedLayout.vue';
import PageHeader from '@/components/ui/PageHeader.vue';
import Card from '@/components/ui/Card.vue';
import Badge from '@/components/ui/Badge.vue';
import EmptyState from '@/components/ui/EmptyState.vue';
import Pagination from '@/components/ui/Pagination.vue';
import PeriodSelect from '@/components/ui/PeriodSelect.vue';
import Modal from '@/components/ui/Modal.vue';
import Field from '@/components/ui/Field.vue';
import FileInput from '@/components/ui/FileInput.vue';
import Button from '@/components/ui/Button.vue';
import { formatDate, signedScore, sourceLabels } from '@/lib/format';
import { usePageSize } from '@/composables/usePageSize';

const props = defineProps({
    records: { type: Object, required: true },
    period: { type: Object, required: true },
    stats: { type: Object, required: true },
    perPage: { type: [String, Number], default: '10' },
});

const { pageSize, pageSizeOptions, changePageSize } = usePageSize(props.perPage);

const target = ref(null);
const form = useForm({
    score_record_id: '',
    reason: '',
    evidence: null,
});

function changePeriod(value) {
    router.get('/appeals', {
        ...(value ? { period: value } : {}),
        per_page: pageSize.value,
    }, {
        preserveState: true,
        preserveScroll: true,
    });
}

function openAppeal(record) {
    target.value = record;
    form.clearErrors();
    form.score_record_id = record.id;
    form.reason = '';
    form.evidence = null;
}

function submit() {
    form.post('/appeals', {
        forceFormData: true,
        preserveScroll: true,
        onSuccess: () => {
            target.value = null;
            form.reset();
        },
    });
}

function changePage(url) {
    router.get(url, {}, { preserveState: true, preserveScroll: true });
}

function appealLabel(status) {
    return { pending: '待处理', approved: '申诉通过', rejected: '申诉驳回' }[status] ?? status;
}
</script>

<template>
    <Head title="扣分申诉" />

    <AuthenticatedLayout>
        <PageHeader title="扣分申诉" :description="`${period.label} · 对有异议的扣分记录提交图片证明`">
            <template #actions>
                <PeriodSelect :model-value="period.value" @update:model-value="changePeriod" />
            </template>
        </PageHeader>

        <div class="mb-4 grid grid-cols-3 gap-3">
            <div class="card px-3 py-4 text-center">
                <span class="mx-auto flex h-9 w-9 items-center justify-center rounded-xl bg-amber-50 text-amber-600"><ShieldAlert class="h-4 w-4" /></span>
                <p class="mt-2 text-xl font-semibold text-slate-900">{{ stats.pending }}</p>
                <p class="text-[11px] text-slate-400">待处理</p>
            </div>
            <div class="card px-3 py-4 text-center">
                <span class="mx-auto flex h-9 w-9 items-center justify-center rounded-xl bg-emerald-50 text-emerald-600"><ShieldCheck class="h-4 w-4" /></span>
                <p class="mt-2 text-xl font-semibold text-slate-900">{{ stats.approved }}</p>
                <p class="text-[11px] text-slate-400">申诉通过</p>
            </div>
            <div class="card px-3 py-4 text-center">
                <span class="mx-auto flex h-9 w-9 items-center justify-center rounded-xl bg-rose-50 text-rose-600"><ShieldX class="h-4 w-4" /></span>
                <p class="mt-2 text-xl font-semibold text-slate-900">{{ stats.rejected }}</p>
                <p class="text-[11px] text-slate-400">申诉驳回</p>
            </div>
        </div>

        <Card :padding="false">
            <div v-if="records.data.length" class="divide-y divide-slate-100">
                <article v-for="record in records.data" :key="record.id" class="px-5 py-4">
                    <div class="flex flex-wrap items-start justify-between gap-3">
                        <div class="min-w-0 flex-1">
                            <div class="flex flex-wrap items-center gap-2">
                                <span class="text-sm font-semibold text-slate-800">{{ record.category?.name }}</span>
                                <Badge status="deduct">扣分</Badge>
                                <Badge v-if="record.revoked_at" status="withdrawn">已撤销</Badge>
                                <span class="text-xs text-slate-400">{{ record.semester?.name }}</span>
                            </div>
                            <p class="mt-1.5 text-sm leading-relaxed text-slate-600">{{ record.description }}</p>
                            <p class="mt-2 text-xs text-slate-400">
                                {{ sourceLabels[record.source_type] ?? record.source_type }} · {{ formatDate(record.created_at, true) }}
                            </p>

                            <div v-if="record.latest_appeal" class="mt-2 rounded-lg bg-slate-50 px-3 py-2 text-xs text-slate-500">
                                <div class="flex flex-wrap items-center gap-2">
                                    <Badge :status="record.latest_appeal.status">{{ appealLabel(record.latest_appeal.status) }}</Badge>
                                    <span>{{ formatDate(record.latest_appeal.reviewed_at ?? record.latest_appeal.created_at, true) }}</span>
                                    <a :href="`/appeals/${record.latest_appeal.id}/evidence`" class="inline-flex items-center gap-1 font-medium text-brand-600">
                                        <Download class="h-3 w-3" />
                                        申诉证明
                                    </a>
                                </div>
                                <p v-if="record.latest_appeal.review_comment" class="mt-1">审核意见：{{ record.latest_appeal.review_comment }}</p>
                            </div>
                        </div>

                        <div class="flex shrink-0 flex-col items-end gap-2">
                            <span :class="['text-lg font-semibold', record.revoked_at ? 'text-slate-400 line-through' : 'text-rose-600']">
                                {{ signedScore(record.score) }}
                            </span>
                            <button
                                v-if="!record.revoked_at && (!record.latest_appeal || record.latest_appeal.status === 'rejected')"
                                type="button"
                                class="btn-primary !px-3 !py-1.5 text-xs"
                                @click="openAppeal(record)"
                            >
                                发起申诉
                            </button>
                        </div>
                    </div>
                </article>
            </div>
            <EmptyState v-else title="当前范围没有扣分记录" description="只有扣分记录可以发起申诉" />

            <Pagination
                :paginator="records"
                label="条扣分记录"
                :page-size="pageSize"
                :page-size-options="pageSizeOptions"
                show-page-size
                @change="changePage"
                @size-change="changePageSize"
            />
        </Card>

        <Modal
            :open="!!target"
            title="发起扣分申诉"
            :description="target ? `${target.category?.name} · ${signedScore(target.score)} 分` : ''"
            @update:open="target = null"
        >
            <div v-if="target" class="space-y-4">
                <div class="rounded-xl bg-slate-50 px-4 py-3 text-sm text-slate-600">
                    <p>{{ target.description }}</p>
                    <p class="mt-1 text-xs text-slate-400">{{ target.semester?.name }} · {{ formatDate(target.created_at, true) }}</p>
                </div>

                <Field label="申诉理由" :error="form.errors.reason" required hint="请说明认为该扣分不合理的原因">
                    <textarea v-model="form.reason" rows="4" maxlength="1000" class="input resize-none" placeholder="例如：该活动为学院统一组织，本人已全程参与并完成签到。" />
                </Field>

                <Field label="证明材料" :error="form.errors.evidence" required hint="仅支持图片（JPG / PNG / WebP），不超过 10MB">
                    <FileInput accept=".jpg,.jpeg,.png,.webp" hint="选择图片证明" @change="form.evidence = $event" />
                </Field>

                <div class="flex justify-end gap-2 border-t border-slate-100 pt-4">
                    <Button variant="secondary" @click="target = null">取消</Button>
                    <Button :loading="form.processing" :disabled="!form.evidence" @click="submit">提交申诉</Button>
                </div>
            </div>
        </Modal>
    </AuthenticatedLayout>
</template>
