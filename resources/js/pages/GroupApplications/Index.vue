<script setup>
import { computed, ref } from 'vue';
import { Head, Link, router } from '@inertiajs/vue3';
import { Eye, PlusCircle, RotateCcw } from 'lucide-vue-next';
import AuthenticatedLayout from '@/layouts/AuthenticatedLayout.vue';
import PageHeader from '@/components/ui/PageHeader.vue';
import Card from '@/components/ui/Card.vue';
import StatCard from '@/components/ui/StatCard.vue';
import Badge from '@/components/ui/Badge.vue';
import EmptyState from '@/components/ui/EmptyState.vue';
import Pagination from '@/components/ui/Pagination.vue';
import ConfirmDialog from '@/components/ui/ConfirmDialog.vue';
import { formatDate, groupScoreRange } from '@/lib/format';
import { usePageSize } from '@/composables/usePageSize';

const props = defineProps({
    applications: { type: Object, required: true },
    semesters: { type: Array, default: () => [] },
    stats: { type: Object, required: true },
    filters: { type: Object, default: () => ({}) },
    perPage: { type: [String, Number], default: '10' },
});

const { pageSize, pageSizeOptions, changePageSize } = usePageSize(props.perPage);

const withdrawTarget = ref(null);
const withdrawing = ref(false);

function changeFilter(event) {
    const form = event.target.closest('form') ?? event.target;
    const data = Object.fromEntries(new FormData(form).entries());
    data.per_page = pageSize.value;
    router.get('/teacher/group-applications', data, { preserveState: true, preserveScroll: true });
}

function changePage(url) {
    router.get(url, {}, { preserveState: true, preserveScroll: true });
}

function confirmWithdraw() {
    if (!withdrawTarget.value) return;

    withdrawing.value = true;
    router.post(`/teacher/group-applications/${withdrawTarget.value.id}/withdraw`, {}, {
        preserveScroll: true,
        onFinish: () => {
            withdrawing.value = false;
            withdrawTarget.value = null;
        },
    });
}
</script>

<template>
    <Head title="集体申请" />

    <AuthenticatedLayout>
        <PageHeader title="集体申请" description="批量为本班或活动成员录入德育分">
            <template #actions>
                <Link href="/teacher/group-applications/create" class="btn-primary">
                    <PlusCircle class="h-4 w-4" />
                    发起集体申请
                </Link>
            </template>
        </PageHeader>

        <div class="mb-4 grid grid-cols-2 gap-3 lg:grid-cols-4">
            <StatCard label="全部申请" :value="stats.total" />
            <StatCard label="待审核" :value="stats.pending" tone="amber" />
            <StatCard label="已通过" :value="stats.approved" tone="emerald" />
            <StatCard label="已驳回" :value="stats.rejected" tone="rose" />
        </div>

        <Card :padding="false">
            <form class="flex flex-wrap items-center gap-2 border-b border-slate-100 px-5 py-4" @submit.prevent="changeFilter">
                <select name="status" class="input !w-auto min-w-[120px]" :value="filters.status ?? ''">
                    <option value="">全部状态</option>
                    <option value="pending">待审核</option>
                    <option value="approved">已通过</option>
                    <option value="rejected">已驳回</option>
                    <option value="withdrawn">已撤回</option>
                </select>
                <select name="semester_id" class="input !w-auto min-w-[140px]" :value="filters.semester_id ?? ''">
                    <option value="">全部学期</option>
                    <option v-for="semester in semesters" :key="semester.id" :value="semester.id">{{ semester.name }}</option>
                </select>
                <button type="submit" class="btn-secondary">筛选</button>
                <Link href="/teacher/group-applications" class="btn-ghost">重置</Link>
            </form>

            <div v-if="applications.data.length" class="divide-y divide-slate-100">
                <article v-for="item in applications.data" :key="item.id" class="px-5 py-4">
                    <div class="flex flex-wrap items-start justify-between gap-3">
                        <div class="min-w-0 flex-1">
                            <div class="flex flex-wrap items-center gap-2">
                                <span class="text-sm font-semibold text-slate-800">{{ item.title }}</span>
                                <Badge :status="item.status" />
                                <span class="text-xs text-slate-400">{{ item.semester?.name }}</span>
                            </div>
                            <p class="mt-1.5 line-clamp-2 text-sm text-slate-600">{{ item.description }}</p>
                            <p class="mt-2 text-xs text-slate-400">
                                {{ item.category?.name }} · {{ item.members_count }} 名成员 · {{ groupScoreRange(item.score_min, item.score_max) }} · {{ formatDate(item.created_at, true) }}
                            </p>
                            <p v-if="item.review_comment" class="mt-2 rounded-lg bg-slate-50 px-3 py-2 text-xs text-slate-500">
                                审核意见：{{ item.review_comment }}
                            </p>
                        </div>
                        <div class="flex shrink-0 items-center gap-2">
                            <Link :href="`/teacher/group-applications/${item.id}`" class="btn-secondary !px-3 !py-2 text-xs">
                                <Eye class="h-3.5 w-3.5" />
                                详情
                            </Link>
                            <button
                                v-if="['pending', 'rejected'].includes(item.status)"
                                type="button"
                                class="btn-ghost !px-3 !py-2 text-xs"
                                @click="withdrawTarget = item"
                            >
                                <RotateCcw class="h-3.5 w-3.5" />
                                撤回
                            </button>
                        </div>
                    </div>
                </article>
            </div>
            <EmptyState v-else title="暂无集体申请" description="上传成员名单即可批量提交德育分">
                <Link href="/teacher/group-applications/create" class="btn-primary">发起集体申请</Link>
            </EmptyState>

            <Pagination
                :paginator="applications"
                :page-size="pageSize"
                :page-size-options="pageSizeOptions"
                show-page-size
                @change="changePage"
                @size-change="changePageSize"
            />
        </Card>

        <ConfirmDialog
            :open="!!withdrawTarget"
            title="撤回集体申请"
            :message="`确认撤回“${withdrawTarget?.title ?? ''}”吗？`"
            confirm-text="确认撤回"
            danger
            :loading="withdrawing"
            @update:open="withdrawTarget = null"
            @confirm="confirmWithdraw"
        />
    </AuthenticatedLayout>
</template>
