<script setup>
import { computed, ref } from 'vue';
import { Head, Link, router, usePage } from '@inertiajs/vue3';
import { Pencil, PlusCircle, RotateCcw, Users } from 'lucide-vue-next';
import AuthenticatedLayout from '@/layouts/AuthenticatedLayout.vue';
import PageHeader from '@/components/ui/PageHeader.vue';
import Card from '@/components/ui/Card.vue';
import Badge from '@/components/ui/Badge.vue';
import EmptyState from '@/components/ui/EmptyState.vue';
import Pagination from '@/components/ui/Pagination.vue';
import Tabs from '@/components/ui/Tabs.vue';
import ConfirmDialog from '@/components/ui/ConfirmDialog.vue';
import EvidenceGallery from '@/components/ui/EvidenceGallery.vue';
import { formatDate, signedScore } from '@/lib/format';
import { applicationEvidenceFiles } from '@/lib/evidence';
import { usePageSize } from '@/composables/usePageSize';

const props = defineProps({
    applications: { type: Object, required: true },
    groupRecords: { type: Object, required: true },
    manualRecords: { type: Object, required: true },
    semesters: { type: Array, default: () => [] },
    filters: { type: Object, default: () => ({}) },
    perPage: { type: [String, Number], default: '10' },
});

const { pageSize, pageSizeOptions, changePageSize } = usePageSize(props.perPage);
const page = usePage();
const currentUserId = computed(() => page.props.auth.user?.id);

const tab = ref('personal');
const withdrawTarget = ref(null);
const withdrawing = ref(false);
const groupWithdrawTarget = ref(null);
const groupWithdrawing = ref(false);

const tabs = computed(() => [
    { key: 'personal', label: '个人申请', count: props.applications.total },
    { key: 'group', label: '集体申请记录', count: props.groupRecords.total },
    { key: 'manual', label: '手工录入', count: props.manualRecords.total },
]);

function changeFilter(event) {
    const form = event.target.closest('form') ?? event.target;
    const data = Object.fromEntries(new FormData(form).entries());
    data.per_page = pageSize.value;

    router.get('/applications', data, { preserveState: true, preserveScroll: true });
}

function changePage(url) {
    router.get(url, {}, { preserveState: true, preserveScroll: true });
}

function confirmWithdraw() {
    if (!withdrawTarget.value) return;

    withdrawing.value = true;
    router.post(`/applications/${withdrawTarget.value.id}/withdraw`, {}, {
        preserveScroll: true,
        onFinish: () => {
            withdrawing.value = false;
            withdrawTarget.value = null;
        },
    });
}

function canWithdrawGroup(item) {
    const application = item.group_application;

    return application?.origin === 'student'
        && application?.initiator_id === currentUserId.value
        && ['pending', 'rejected'].includes(application?.status);
}

function canEditGroup(item) {
    const application = item.group_application;

    return application?.origin === 'student'
        && application?.initiator_id === currentUserId.value
        && application?.status !== 'approved';
}

function confirmGroupWithdraw() {
    if (!groupWithdrawTarget.value) return;

    groupWithdrawing.value = true;
    router.post(`/applications/group/${groupWithdrawTarget.value.group_application.id}/withdraw`, {}, {
        preserveScroll: true,
        onFinish: () => {
            groupWithdrawing.value = false;
            groupWithdrawTarget.value = null;
        },
    });
}
</script>

<template>
    <Head title="我的申请" />

    <AuthenticatedLayout>
        <PageHeader title="我的申请" description="查看个人申请进度、集体活动与手工录入记录">
            <template #actions>
                <Link href="/applications/group/create" class="btn-secondary">
                    <Users class="h-4 w-4" />
                    发起集体申请
                </Link>
                <Link href="/applications/create" class="btn-primary">
                    <PlusCircle class="h-4 w-4" />
                    提交申请
                </Link>
            </template>
        </PageHeader>

        <div class="mb-4">
            <Tabs v-model="tab" :items="tabs" />
        </div>

        <template v-if="tab === 'personal'">
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
                    <Link href="/applications" class="btn-ghost">重置</Link>
                </form>

                <div v-if="applications.data.length" class="divide-y divide-slate-100">
                    <article v-for="item in applications.data" :key="item.id" class="px-5 py-4">
                        <div class="flex flex-wrap items-start justify-between gap-3">
                            <div class="min-w-0 flex-1">
                                <div class="flex flex-wrap items-center gap-2">
                                    <span class="text-sm font-semibold text-slate-800">{{ item.activity_name || item.category?.name }}</span>
                                    <Badge :status="item.status" />
                                    <span class="text-xs text-slate-400">{{ item.category?.name }} · {{ item.semester?.name }}</span>
                                </div>
                                <p class="mt-1.5 text-sm leading-relaxed text-slate-600">{{ item.description }}</p>
                                <div class="mt-2 flex flex-wrap items-center gap-3 text-xs text-slate-400">
                                    <span v-if="item.applied_on">活动日期 {{ formatDate(item.applied_on) }}</span>
                                    <span>提交于 {{ formatDate(item.created_at, true) }}</span>
                                    <span v-if="item.reviewer">审核人：{{ item.reviewer.name }}</span>
                                    <EvidenceGallery
                                        v-if="applicationEvidenceFiles(item).length"
                                        :files="applicationEvidenceFiles(item)"
                                        variant="inline"
                                        label="证明"
                                    />
                                </div>
                                <p v-if="item.review_comment" class="mt-2 rounded-lg bg-slate-50 px-3 py-2 text-xs text-slate-500">
                                    审核意见：{{ item.review_comment }}
                                </p>
                            </div>
                            <div class="flex shrink-0 flex-col items-end gap-2">
                                <span class="text-lg font-semibold text-slate-800">{{ signedScore(item.final_score ?? item.score) }}<span class="ml-0.5 text-xs font-normal text-slate-400">分</span></span>
                                <Link
                                    v-if="item.status !== 'approved'"
                                    :href="`/applications/${item.id}/edit`"
                                    class="inline-flex items-center gap-1 text-xs font-medium text-brand-600 transition hover:text-brand-700"
                                >
                                    <Pencil class="h-3.5 w-3.5" />
                                    修改
                                </Link>
                                <button
                                    v-if="['pending', 'rejected'].includes(item.status)"
                                    type="button"
                                    class="inline-flex items-center gap-1 text-xs font-medium text-slate-400 transition hover:text-rose-600"
                                    @click="withdrawTarget = item"
                                >
                                    <RotateCcw class="h-3.5 w-3.5" />
                                    撤回申请
                                </button>
                            </div>
                        </div>
                    </article>
                </div>
                <EmptyState v-else title="没有符合条件的申请" description="调整筛选条件，或提交一条新申请">
                    <Link href="/applications/create" class="btn-primary">提交申请</Link>
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
        </template>

        <template v-else-if="tab === 'group'">
            <Card :padding="false">
                <div v-if="groupRecords.data.length" class="divide-y divide-slate-100">
                    <article v-for="item in groupRecords.data" :key="item.id" class="px-5 py-4">
                        <div class="flex flex-wrap items-start justify-between gap-3">
                            <div class="min-w-0 flex-1">
                                <div class="flex flex-wrap items-center gap-2">
                                    <Link :href="`/applications/group/${item.group_application?.id}`" class="text-sm font-semibold text-slate-800 hover:text-brand-600">
                                        {{ item.group_application?.title }}
                                    </Link>
                                    <Badge :status="item.group_application?.status" />
                                    <span v-if="item.group_application?.origin === 'student'" class="rounded-full bg-brand-50 px-2 py-0.5 text-[11px] font-medium text-brand-600">学生集体申请</span>
                                    <span class="text-xs text-slate-400">{{ item.group_application?.semester?.name }}</span>
                                </div>
                                <p class="mt-1.5 text-sm leading-relaxed text-slate-600">{{ item.group_application?.description }}</p>
                                <p class="mt-2 flex flex-wrap items-center gap-x-3 gap-y-1 text-xs text-slate-400">
                                    <span>{{ item.group_application?.category?.name }}</span>
                                    <span v-if="item.group_application?.origin === 'student'">
                                        发起人：{{ item.group_application?.initiator?.name }}
                                    </span>
                                    <span v-else>提交教师：{{ item.group_application?.teacher?.name }}</span>
                                    <span v-if="item.group_application?.applied_on">活动日期 {{ formatDate(item.group_application.applied_on) }}</span>
                                    <span>{{ formatDate(item.group_application?.created_at) }}</span>
                                    <Link :href="`/applications/group/${item.group_application?.id}`" class="inline-flex items-center gap-1 font-medium text-brand-600 hover:text-brand-700">
                                        查看详情
                                    </Link>
                                </p>
                                <p v-if="item.group_application?.review_comment" class="mt-2 rounded-lg bg-slate-50 px-3 py-2 text-xs text-slate-500">
                                    审核意见：{{ item.group_application.review_comment }}
                                </p>
                            </div>
                            <div class="flex shrink-0 flex-col items-end gap-2">
                                <span class="text-lg font-semibold text-slate-800">{{ signedScore(item.final_score ?? item.score) }}<span class="ml-0.5 text-xs font-normal text-slate-400">分</span></span>
                                <p class="text-xs text-slate-400">我的集体记录</p>
                                <Link
                                    v-if="canEditGroup(item)"
                                    :href="`/applications/group/${item.group_application.id}/edit`"
                                    class="inline-flex items-center gap-1 text-xs font-medium text-brand-600 transition hover:text-brand-700"
                                >
                                    <Pencil class="h-3.5 w-3.5" />
                                    修改
                                </Link>
                                <button
                                    v-if="canWithdrawGroup(item)"
                                    type="button"
                                    class="inline-flex items-center gap-1 text-xs font-medium text-slate-400 transition hover:text-rose-600"
                                    @click="groupWithdrawTarget = item"
                                >
                                    <RotateCcw class="h-3.5 w-3.5" />
                                    撤回申请
                                </button>
                            </div>
                        </div>
                    </article>
                </div>
                <EmptyState v-else title="暂无集体申请记录" description="教师提交并审核通过后会自动出现在这里" />

                <Pagination
                    :paginator="groupRecords"
                    label="条记录"
                    :page-size="pageSize"
                    :page-size-options="pageSizeOptions"
                    show-page-size
                    @change="changePage"
                    @size-change="changePageSize"
                />
            </Card>
        </template>

        <template v-else>
            <Card :padding="false">
                <form class="flex flex-wrap items-center gap-2 border-b border-slate-100 px-5 py-4" @submit.prevent="changeFilter">
                    <select name="semester_id" class="input !w-auto min-w-[140px]" :value="filters.semester_id ?? ''">
                        <option value="">全部学期</option>
                        <option v-for="semester in semesters" :key="semester.id" :value="semester.id">{{ semester.name }}</option>
                    </select>
                    <button type="submit" class="btn-secondary">筛选</button>
                    <Link href="/applications" class="btn-ghost">重置</Link>
                </form>

                <div v-if="manualRecords.data.length" class="divide-y divide-slate-100">
                    <article v-for="item in manualRecords.data" :key="`manual-${item.id}`" class="px-5 py-4">
                        <div class="flex flex-wrap items-start justify-between gap-3">
                            <div class="min-w-0 flex-1">
                                <div class="flex flex-wrap items-center gap-2">
                                    <span class="text-sm font-semibold text-slate-800">{{ item.category?.name }}</span>
                                    <Badge :status="item.category?.type" />
                                    <Badge v-if="item.revoked_at" status="withdrawn">已撤销</Badge>
                                    <Badge v-else status="active">有效</Badge>
                                    <span class="text-xs text-slate-400">{{ item.semester?.name }}</span>
                                </div>
                                <p class="mt-1.5 text-sm leading-relaxed text-slate-600">{{ item.description }}</p>
                                <div class="mt-2 flex flex-wrap items-center gap-3 text-xs text-slate-400">
                                    <span v-if="item.applied_on">上报日期 {{ formatDate(item.applied_on) }}</span>
                                    <span>录入人：{{ item.creator?.name || '系统' }}</span>
                                    <span v-if="item.revoked_at">撤销于 {{ formatDate(item.revoked_at, true) }}</span>
                                </div>
                                <p v-if="item.revoked_at" class="mt-2 rounded-lg bg-slate-50 px-3 py-2 text-xs text-slate-500">
                                    撤销原因：{{ item.revoke_reason || '管理员撤销' }}
                                </p>
                            </div>
                            <span :class="['shrink-0 text-lg font-semibold', item.revoked_at ? 'text-slate-400 line-through' : 'text-slate-800']">
                                {{ signedScore(item.score) }}<span class="ml-0.5 text-xs font-normal text-slate-400">分</span>
                            </span>
                        </div>
                    </article>
                </div>
                <EmptyState v-else title="暂无手工录入记录" description="管理员手工录入的加减分记录会显示在这里" />

                <Pagination
                    :paginator="manualRecords"
                    label="条记录"
                    :page-size="pageSize"
                    :page-size-options="pageSizeOptions"
                    show-page-size
                    @change="changePage"
                    @size-change="changePageSize"
                />
            </Card>
        </template>

        <ConfirmDialog
            :open="!!withdrawTarget"
            title="撤回申请"
            :message="`确认撤回“${withdrawTarget?.activity_name || withdrawTarget?.category?.name || ''}”这条申请吗？撤回后需要重新提交。`"
            confirm-text="确认撤回"
            danger
            :loading="withdrawing"
            @update:open="withdrawTarget = null"
            @confirm="confirmWithdraw"
        />

        <ConfirmDialog
            :open="!!groupWithdrawTarget"
            title="撤回集体申请"
            :message="`确认撤回“${groupWithdrawTarget?.group_application?.title ?? ''}”吗？撤回后需要重新发起。`"
            confirm-text="确认撤回"
            danger
            :loading="groupWithdrawing"
            @update:open="groupWithdrawTarget = null"
            @confirm="confirmGroupWithdraw"
        />
    </AuthenticatedLayout>
</template>
