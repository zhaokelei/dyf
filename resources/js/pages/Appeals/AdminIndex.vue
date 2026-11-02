<script setup>
import { ref } from 'vue';
import { Head, router, useForm } from '@inertiajs/vue3';
import { CheckCircle2, Download, Eye, Search, XCircle } from 'lucide-vue-next';
import AuthenticatedLayout from '@/layouts/AuthenticatedLayout.vue';
import PageHeader from '@/components/ui/PageHeader.vue';
import Card from '@/components/ui/Card.vue';
import Badge from '@/components/ui/Badge.vue';
import EmptyState from '@/components/ui/EmptyState.vue';
import Pagination from '@/components/ui/Pagination.vue';
import Modal from '@/components/ui/Modal.vue';
import Field from '@/components/ui/Field.vue';
import Button from '@/components/ui/Button.vue';
import { formatDate, signedScore } from '@/lib/format';
import { usePageSize } from '@/composables/usePageSize';

const props = defineProps({
    appeals: { type: Object, required: true },
    semesters: { type: Array, default: () => [] },
    counts: { type: Object, required: true },
    filters: { type: Object, default: () => ({}) },
    perPage: { type: [String, Number], default: '20' },
});

const { pageSize, pageSizeOptions, changePageSize } = usePageSize(props.perPage);

const target = ref(null);
const form = useForm({ review_comment: '' });
const processing = ref(false);

function appealLabel(status) {
    return { pending: '待处理', approved: '申诉通过', rejected: '申诉驳回' }[status] ?? status;
}

function openDetail(appeal) {
    target.value = appeal;
    form.clearErrors();
    form.review_comment = '';
}

function approve() {
    if (!target.value) return;
    processing.value = true;
    form.put(`/admin/appeals/${target.value.id}/approve`, {
        preserveScroll: true,
        onSuccess: () => {
            target.value = null;
        },
        onFinish: () => {
            processing.value = false;
        },
    });
}

function reject() {
    if (!target.value) return;
    processing.value = true;
    form.put(`/admin/appeals/${target.value.id}/reject`, {
        preserveScroll: true,
        onSuccess: () => {
            target.value = null;
        },
        onFinish: () => {
            processing.value = false;
        },
    });
}

function changeFilter(event) {
    const form = event.target.closest('form') ?? event.target;
    const data = Object.fromEntries(new FormData(form).entries());
    data.per_page = pageSize.value;
    router.get('/admin/appeals', data, { preserveState: true, preserveScroll: true });
}

function changePage(url) {
    router.get(url, {}, { preserveState: true, preserveScroll: true });
}
</script>

<template>
    <Head title="扣分申诉" />

    <AuthenticatedLayout>
        <PageHeader title="扣分申诉审核" description="审核学生对扣分记录的申诉，通过后将撤销对应扣分" />

        <div class="mb-4 grid grid-cols-3 gap-3">
            <div class="card px-4 py-4">
                <p class="text-xs text-slate-400">待处理</p>
                <p class="mt-1 text-2xl font-semibold text-amber-600">{{ counts.pending }}</p>
            </div>
            <div class="card px-4 py-4">
                <p class="text-xs text-slate-400">申诉通过</p>
                <p class="mt-1 text-2xl font-semibold text-emerald-600">{{ counts.approved }}</p>
            </div>
            <div class="card px-4 py-4">
                <p class="text-xs text-slate-400">申诉驳回</p>
                <p class="mt-1 text-2xl font-semibold text-rose-600">{{ counts.rejected }}</p>
            </div>
        </div>

        <Card :padding="false">
            <form class="flex flex-wrap items-center gap-2 border-b border-slate-100 px-5 py-4" @submit.prevent="changeFilter">
                <select name="status" class="input !w-auto min-w-[110px]" :value="filters.status ?? 'pending'">
                    <option value="pending">待处理</option>
                    <option value="approved">申诉通过</option>
                    <option value="rejected">申诉驳回</option>
                    <option value="all">全部状态</option>
                </select>
                <select name="semester_id" class="input !w-auto min-w-[150px]" :value="filters.semester_id ?? ''">
                    <option value="">全部学期</option>
                    <option v-for="semester in semesters" :key="semester.id" :value="semester.id">{{ semester.name }}</option>
                </select>
                <div class="relative">
                    <Search class="pointer-events-none absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 text-slate-400" />
                    <input name="search" :value="filters.search ?? ''" placeholder="姓名或学号" class="input !w-40 !pl-9" />
                </div>
                <button type="submit" class="btn-secondary">筛选</button>
                <a href="/admin/appeals" class="btn-ghost">重置</a>
            </form>

            <div v-if="appeals.data.length" class="divide-y divide-slate-100 px-4 md:hidden">
                <div v-for="appeal in appeals.data" :key="appeal.id" class="py-3.5">
                    <div class="flex items-start justify-between gap-3">
                        <div class="min-w-0">
                            <div class="flex flex-wrap items-center gap-1.5">
                                <span class="text-sm font-semibold text-slate-800">{{ appeal.user?.name }}</span>
                                <Badge :status="appeal.status">{{ appealLabel(appeal.status) }}</Badge>
                            </div>
                            <p class="text-xs text-slate-400">{{ appeal.user?.student_id }} · {{ appeal.user?.class_name }}</p>
                        </div>
                        <span class="shrink-0 text-base font-semibold text-rose-600">{{ signedScore(appeal.score_record?.score ?? 0) }}</span>
                    </div>
                    <p class="mt-1 text-sm text-slate-600">{{ appeal.score_record?.category?.name }} · {{ appeal.score_record?.semester?.name }}</p>
                    <p class="mt-0.5 line-clamp-2 text-xs text-slate-500">{{ appeal.reason }}</p>
                    <button type="button" class="btn-secondary mt-2 !px-3 !py-1.5 text-xs" @click="openDetail(appeal)">
                        <Eye class="h-3.5 w-3.5" />
                        {{ appeal.status === 'pending' ? '审核' : '查看' }}
                    </button>
                </div>
            </div>

            <div v-if="appeals.data.length" class="table-wrap hidden md:block">
                <table class="data-table">
                    <thead>
                        <tr>
                            <th>学生</th>
                            <th>扣分记录</th>
                            <th class="text-right">分值</th>
                            <th class="hidden lg:table-cell">学期</th>
                            <th class="hidden lg:table-cell">申诉理由</th>
                            <th>状态</th>
                            <th class="hidden xl:table-cell">提交时间</th>
                            <th class="text-right">操作</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="appeal in appeals.data" :key="appeal.id">
                            <td>
                                <p class="font-medium text-slate-800">{{ appeal.user?.name }}</p>
                                <p class="text-xs text-slate-400">{{ appeal.user?.student_id }} · {{ appeal.user?.class_name }}</p>
                            </td>
                            <td>
                                <p class="text-slate-700">{{ appeal.score_record?.category?.name }}</p>
                                <p class="max-w-[240px] truncate text-xs text-slate-400">{{ appeal.score_record?.description }}</p>
                            </td>
                            <td class="text-right font-semibold text-rose-600">{{ signedScore(appeal.score_record?.score ?? 0) }}</td>
                            <td class="hidden whitespace-nowrap lg:table-cell">{{ appeal.score_record?.semester?.name }}</td>
                            <td class="hidden max-w-[220px] lg:table-cell">
                                <span class="line-clamp-2 text-slate-500">{{ appeal.reason }}</span>
                            </td>
                            <td><Badge :status="appeal.status">{{ appealLabel(appeal.status) }}</Badge></td>
                            <td class="hidden whitespace-nowrap text-xs text-slate-400 xl:table-cell">{{ formatDate(appeal.created_at, true) }}</td>
                            <td class="text-right">
                                <button type="button" class="btn-secondary !px-3 !py-1.5 text-xs" @click="openDetail(appeal)">
                                    <Eye class="h-3.5 w-3.5" />
                                    {{ appeal.status === 'pending' ? '审核' : '查看' }}
                                </button>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
            <EmptyState v-else title="没有符合条件的申诉" />

            <Pagination
                :paginator="appeals"
                label="条申诉"
                :page-size="pageSize"
                :page-size-options="pageSizeOptions"
                show-page-size
                @change="changePage"
                @size-change="changePageSize"
            />
        </Card>

        <Modal
            :open="!!target"
            title="扣分申诉详情"
            :description="target ? `${target.user?.name} · ${target.user?.student_id}` : ''"
            size="lg"
            @update:open="target = null"
        >
            <div v-if="target" class="space-y-4">
                <div class="rounded-xl bg-slate-50 px-4 py-3.5">
                    <div class="flex flex-wrap items-center gap-2">
                        <span class="text-sm font-semibold text-slate-800">{{ target.score_record?.category?.name }}</span>
                        <Badge status="deduct">扣分</Badge>
                        <span class="text-xs text-slate-400">{{ target.score_record?.semester?.name }}</span>
                        <span class="ml-auto text-lg font-semibold text-rose-600">{{ signedScore(target.score_record?.score ?? 0) }}</span>
                    </div>
                    <p class="mt-2 text-sm text-slate-600">{{ target.score_record?.description }}</p>
                    <p class="mt-1 text-xs text-slate-400">记录时间：{{ formatDate(target.score_record?.created_at, true) }}</p>
                </div>

                <div>
                    <p class="text-xs font-medium text-slate-500">申诉理由</p>
                    <p class="mt-1 whitespace-pre-line rounded-xl border border-slate-100 px-4 py-3 text-sm leading-relaxed text-slate-600">{{ target.reason }}</p>
                </div>

                <div class="flex flex-wrap items-center gap-3">
                    <a :href="`/appeals/${target.id}/evidence`" class="btn-secondary !py-2 text-xs">
                        <Download class="h-3.5 w-3.5" />
                        查看申诉证明
                    </a>
                    <Badge :status="target.status">{{ appealLabel(target.status) }}</Badge>
                    <span class="text-xs text-slate-400">提交于 {{ formatDate(target.created_at, true) }}</span>
                </div>

                <p v-if="target.review_comment" class="rounded-xl bg-slate-50 px-4 py-3 text-sm text-slate-600">
                    审核意见：{{ target.review_comment }}
                </p>

                <template v-if="target.status === 'pending'">
                    <Field label="审核意见" :error="form.errors.review_comment" hint="驳回时必填；通过后扣分记录将被撤销">
                        <textarea v-model="form.review_comment" rows="3" class="input resize-none" placeholder="填写审核说明或驳回原因" />
                    </Field>

                    <div class="flex justify-end gap-2 border-t border-slate-100 pt-4">
                        <Button variant="danger" :loading="processing" @click="reject">
                            <XCircle class="h-4 w-4" />
                            驳回申诉
                        </Button>
                        <Button :loading="processing" @click="approve">
                            <CheckCircle2 class="h-4 w-4" />
                            通过并撤销扣分
                        </Button>
                    </div>
                </template>
            </div>
        </Modal>
    </AuthenticatedLayout>
</template>
