<script setup>
import { computed, ref, watch } from 'vue';
import { Head, Link, router, useForm, usePage } from '@inertiajs/vue3';
import { CheckCircle2, ClipboardCheck, Download, Eye, MinusCircle, PlusCircle, Search, ShieldAlert, Trash2, Trophy, XCircle } from 'lucide-vue-next';
import AuthenticatedLayout from '@/layouts/AuthenticatedLayout.vue';
import PageHeader from '@/components/ui/PageHeader.vue';
import Card from '@/components/ui/Card.vue';
import StatCard from '@/components/ui/StatCard.vue';
import Badge from '@/components/ui/Badge.vue';
import EmptyState from '@/components/ui/EmptyState.vue';
import Pagination from '@/components/ui/Pagination.vue';
import Tabs from '@/components/ui/Tabs.vue';
import Modal from '@/components/ui/Modal.vue';
import ConfirmDialog from '@/components/ui/ConfirmDialog.vue';
import EvidenceGallery from '@/components/ui/EvidenceGallery.vue';
import Field from '@/components/ui/Field.vue';
import Button from '@/components/ui/Button.vue';
import { formatDate, groupScoreRange, signedScore } from '@/lib/format';
import { applicationEvidenceFiles } from '@/lib/evidence';
import { usePageSize } from '@/composables/usePageSize';

const page = usePage();
const permissions = computed(() => page.props.permissions ?? {});

const props = defineProps({
    officerMode: { type: Boolean, default: false },
    officerClasses: { type: Array, default: () => [] },
    personal: { type: Object, required: true },
    groups: { type: Object, required: true },
    manual: { type: Object, required: true },
    semesters: { type: Array, default: () => [] },
    categories: { type: Array, default: () => [] },
    counts: { type: Object, required: true },
    leaderboard: { type: Object, default: () => ({ available: false }) },
    deduction: { type: Object, default: () => ({ available: false }) },
    canModifyApproved: { type: Boolean, default: false },
    canDeletePersonal: { type: Boolean, default: false },
    canDeleteGroups: { type: Boolean, default: false },
    canManageManual: { type: Boolean, default: false },
    filters: { type: Object, default: () => ({}) },
    perPage: { type: [String, Number], default: '10' },
});

const todayString = (() => {
    const now = new Date();
    return `${now.getFullYear()}-${String(now.getMonth() + 1).padStart(2, '0')}-${String(now.getDate()).padStart(2, '0')}`;
})();

const { pageSize, pageSizeOptions, changePageSize } = usePageSize(props.perPage);
const tab = ref('personal');
const selected = ref([]);
const reviewTarget = ref(null);
const reviewForm = useForm({
    final_score: '',
    review_comment: '',
});
const editForm = useForm({
    final_score: '',
    review_comment: '',
});
const bulkOpen = ref(false);
const bulkAction = ref('approve');
const bulkComment = ref('');
const bulkProcessing = ref(false);
const deductionOpen = ref(false);
const deductionForm = useForm({
    student_id: '',
    category_id: '',
    score: '',
    applied_on: todayString,
    description: '',
});
const cancelTarget = ref(null);
const cancelReason = ref('');
const cancelProcessing = ref(false);
const deleteTarget = ref(null);
const deleteProcessing = ref(false);
const manualDeleteTarget = ref(null);
const manualDeleteProcessing = ref(false);

const isOfficer = computed(() => props.officerMode);
const currentUserId = computed(() => page.props.auth.user?.id);
const reviewBase = computed(() => (isOfficer.value ? '/officer/review' : '/admin/review'));
const reviewIsVolunteer = computed(() => reviewTarget.value?.category?.code === 'volunteer');
const canReviewPersonal = computed(
    () => !!permissions.value.can_review || !!permissions.value.is_class_officer,
);
const boardRows = computed(() => {
    const rows = props.leaderboard?.rows ?? [];
    const top = rows.slice(0, 10);

    if (!top.some((row) => row.is_self)) {
        const self = rows.find((row) => row.is_self);

        if (self) {
            top.push(self);
        }
    }

    return top;
});
const boardClass = computed(() => (props.leaderboard?.classes ?? []).find((item) => item.id === props.leaderboard?.class_id));
const deductionStudent = computed(() => (props.deduction?.students ?? []).find((item) => item.id === deductionForm.student_id));
const deductionCategory = computed(() => (props.deduction?.categories ?? []).find((item) => item.id === deductionForm.category_id));

const groupShowBase = computed(() => (isOfficer.value ? '/officer/review/groups' : '/admin/review/groups'));

const tabs = computed(() => (isOfficer.value
    ? [
        { key: 'personal', label: '本班申请', count: props.counts.personal_pending },
        { key: 'group', label: '本班集体', count: props.counts.group_pending },
        { key: 'manual', label: '本班手工录入', count: props.counts.manual_total ?? 0 },
    ]
    : [
        { key: 'personal', label: '个人申请', count: props.counts.personal_pending },
        { key: 'group', label: '集体申请', count: props.counts.group_pending },
        { key: 'manual', label: '手工录入', count: props.counts.manual_total ?? 0 },
    ]));

const currentRows = computed(() => {
    if (tab.value === 'personal') {
        return props.personal.data;
    }

    return tab.value === 'group' ? props.groups.data : [];
});
const currentPaginator = computed(() => {
    if (tab.value === 'manual') {
        return props.manual;
    }

    return tab.value === 'personal' ? props.personal : props.groups;
});
const allSelected = computed(
    () => currentRows.value.length > 0 && currentRows.value.every((row) => selected.value.includes(row.id)),
);

watch(tab, () => {
    selected.value = [];
});

function toggleAll() {
    if (allSelected.value) {
        selected.value = [];
    } else {
        selected.value = currentRows.value.map((row) => row.id);
    }
}

function toggleRow(id) {
    selected.value = selected.value.includes(id)
        ? selected.value.filter((value) => value !== id)
        : [...selected.value, id];
}

function changeFilter(event) {
    const form = event.target.closest('form') ?? event.target;
    const data = Object.fromEntries(new FormData(form).entries());
    data.tab = tab.value;
    data.per_page = pageSize.value;
    router.get(reviewBase.value, data, { preserveState: true, preserveScroll: true });
}

function changeTab(value) {
    tab.value = value;
    router.get(reviewBase.value, { ...props.filters, tab: value, per_page: pageSize.value }, { preserveState: true, preserveScroll: true });
}

function changePage(url) {
    router.get(url, {}, { preserveState: true, preserveScroll: true });
}

function openReview(item) {
    reviewTarget.value = item;
    reviewForm.final_score = item.category?.code === 'volunteer' ? 0 : (item.final_score ?? item.score);
    reviewForm.review_comment = '';
    reviewForm.clearErrors();
    editForm.final_score = item.category?.code === 'volunteer' ? 0 : (item.final_score ?? item.score);
    editForm.review_comment = item.review_comment ?? '';
    editForm.clearErrors();
}

function displayScore(item) {
    return item.final_score ?? item.score;
}

function changeBoardClass(event) {
    router.get(reviewBase.value, {
        ...props.filters,
        tab: tab.value,
        per_page: pageSize.value,
        board_class_id: event.target.value,
    }, { preserveState: true, preserveScroll: true });
}

function openDeduction() {
    deductionForm.reset();
    deductionForm.clearErrors();
    deductionForm.applied_on = todayString;
    deductionOpen.value = true;
}

function submitDeduction() {
    deductionForm.post(props.deduction.store_url, {
        preserveScroll: true,
        onSuccess: () => {
            deductionOpen.value = false;
            deductionForm.reset();
        },
    });
}

function openCancel(item) {
    cancelTarget.value = item;
    cancelReason.value = '';
    cancelProcessing.value = false;
    reviewTarget.value = null;
}

function submitCancel() {
    if (!cancelTarget.value) return;

    cancelProcessing.value = true;
    router.put(`${isOfficer.value ? '/officer' : '/admin'}/applications/${cancelTarget.value.id}/cancel`, {
        cancel_reason: cancelReason.value,
    }, {
        preserveScroll: true,
        onFinish: () => {
            cancelProcessing.value = false;
            cancelTarget.value = null;
        },
    });
}

function closeReview() {
    reviewTarget.value = null;
}

function canDeleteRow(item) {
    return props.canDeletePersonal && (!isOfficer.value || item.user_id !== currentUserId.value);
}

function openDelete(type, item) {
    deleteTarget.value = { type, item };
    deleteProcessing.value = false;
}

const deleteMessage = computed(() => {
    if (!deleteTarget.value) {
        return '';
    }

    const { type, item } = deleteTarget.value;

    if (type === 'group') {
        const approvedNote = item.status === 'approved'
            ? `将通过时为 ${item.members_count} 名成员生成的记录全部扣回，`
            : '';

        return `确认删除“${item.title}”这条集体申请吗？${approvedNote}删除后不可恢复。`;
    }

    const approvedNote = item.status === 'approved' ? '该申请已通过，删除后将扣回已计分值，' : '';

    return `确认删除“${item.activity_name || item.category?.name || ''}”（${item.user?.name ?? ''}）吗？${approvedNote}删除后不可恢复。`;
});

function submitDelete() {
    if (!deleteTarget.value) {
        return;
    }

    const { type, item } = deleteTarget.value;
    const url = type === 'group'
        ? `/admin/group-applications/${item.id}`
        : `${isOfficer.value ? '/officer' : '/admin'}/applications/${item.id}`;

    deleteProcessing.value = true;
    router.delete(url, {
        preserveScroll: true,
        onFinish: () => {
            deleteProcessing.value = false;
            deleteTarget.value = null;
        },
    });
}

function submitManualDelete() {
    if (!manualDeleteTarget.value) {
        return;
    }

    manualDeleteProcessing.value = true;
    router.delete(`/admin/records/${manualDeleteTarget.value.id}/force`, {
        preserveScroll: true,
        onFinish: () => {
            manualDeleteProcessing.value = false;
            manualDeleteTarget.value = null;
        },
    });
}

function approve() {
    const prefix = isOfficer.value ? '/officer/applications' : '/admin/applications';
    reviewForm.put(`${prefix}/${reviewTarget.value.id}/approve`, {
        preserveScroll: true,
        onSuccess: closeReview,
    });
}

function reject() {
    const prefix = isOfficer.value ? '/officer/applications' : '/admin/applications';
    reviewForm.put(`${prefix}/${reviewTarget.value.id}/reject`, {
        preserveScroll: true,
        onSuccess: closeReview,
    });
}

function updateApproved() {
    if (!reviewTarget.value) return;

    const prefix = isOfficer.value ? '/officer/applications' : '/admin/applications';
    editForm.put(`${prefix}/${reviewTarget.value.id}/update`, {
        preserveScroll: true,
        onSuccess: closeReview,
    });
}

function openBulk(action) {
    bulkAction.value = action;
    bulkComment.value = '';
    bulkOpen.value = true;
}

function submitBulk() {
    bulkProcessing.value = true;
    router.post(`${reviewBase.value}/bulk`, {
        type: tab.value,
        action: bulkAction.value,
        ids: selected.value,
        review_comment: bulkComment.value,
    }, {
        preserveScroll: true,
        onFinish: () => {
            bulkProcessing.value = false;
            bulkOpen.value = false;
            selected.value = [];
        },
    });
}
</script>

<template>
    <Head :title="isOfficer ? '班干部审批' : '审核中心'" />

    <AuthenticatedLayout>
        <PageHeader
            :title="isOfficer ? '班干部审批' : '审核中心'"
            :description="isOfficer
                ? `审核本班同学的个人申请，查看本班集体申请与手工录入记录${officerClasses.length ? ' · ' + officerClasses.join('、') : ''}`
                : '统一审核学生个人申请、教师集体申请与手工录入记录'"
        >
            <template #actions>
                <button v-if="deduction.available" type="button" class="btn-primary" @click="openDeduction">
                    <MinusCircle class="h-4 w-4" />
                    扣分
                </button>
                <template v-if="!isOfficer">
                    <Link href="/admin/appeals" class="btn-secondary">
                        <ShieldAlert class="h-4 w-4" />
                        扣分申诉 {{ counts.pending_appeals ?? 0 }}
                    </Link>
                    <Link href="/admin/statistics" class="btn-secondary">查看统计</Link>
                </template>
            </template>
        </PageHeader>

        <div class="mb-4 grid grid-cols-2 gap-3 lg:grid-cols-4">
            <StatCard :label="isOfficer ? '本班待审申请' : '待审个人申请'" :value="counts.personal_pending" tone="amber" />
            <StatCard :label="isOfficer ? '本班待审集体' : '待审集体申请'" :value="counts.group_pending" tone="amber" />
            <StatCard label="累计通过" :value="counts.approved" tone="emerald" />
            <StatCard label="累计驳回" :value="counts.rejected" tone="rose" />
        </div>

        <div v-if="leaderboard.available || deduction.available" class="mb-4 grid grid-cols-1 gap-4" :class="leaderboard.available && deduction.available ? 'lg:grid-cols-3' : ''">
            <Card
                v-if="leaderboard.available"
                :class="deduction.available ? 'lg:col-span-2' : ''"
                title="班内排行榜"
                :description="`${leaderboard.semester ?? '当前学期'} · ${boardClass?.name ?? ''}`"
            >
                <template #actions>
                    <a
                        v-if="leaderboard.export_url"
                        :href="leaderboard.export_url"
                        class="btn-secondary !px-3 !py-1.5 text-xs"
                    >
                        <Download class="h-3.5 w-3.5" />
                        导出
                    </a>
                    <select
                        v-if="(leaderboard.classes ?? []).length > 1"
                        class="input !w-auto min-w-[150px]"
                        :value="leaderboard.class_id"
                        @change="changeBoardClass"
                    >
                        <option v-for="item in leaderboard.classes" :key="item.id" :value="item.id">
                            {{ item.name }}{{ item.grade ? ` · ${item.grade}` : '' }}
                        </option>
                    </select>
                    <span v-else class="inline-flex items-center gap-1 text-xs text-slate-400">
                        <Trophy class="h-3.5 w-3.5" />
                        {{ boardClass?.grade ?? '' }}
                    </span>
                </template>

                <div v-if="boardRows.length" class="space-y-1">
                    <div
                        v-for="row in boardRows"
                        :key="row.id"
                        :class="[
                            'flex items-center gap-3 rounded-lg px-3 py-2 text-sm',
                            row.is_self ? 'bg-brand-50/70 ring-1 ring-brand-200' : 'hover:bg-slate-50',
                        ]"
                    >
                        <span
                            :class="[
                                'flex h-6 w-6 shrink-0 items-center justify-center rounded-full text-xs font-semibold',
                                row.rank === 1 ? 'bg-amber-100 text-amber-700'
                                    : row.rank === 2 ? 'bg-slate-200 text-slate-600'
                                        : row.rank === 3 ? 'bg-orange-100 text-orange-700'
                                            : 'bg-slate-100 text-slate-500',
                            ]"
                        >
                            {{ row.rank }}
                        </span>
                        <div class="min-w-0 flex-1">
                            <p class="truncate font-medium text-slate-800">
                                {{ row.name }}
                                <span v-if="row.is_self" class="ml-1 text-[11px] font-normal text-brand-600">我</span>
                            </p>
                            <p class="text-xs text-slate-400">{{ row.student_id }} · {{ row.class_name }}</p>
                        </div>
                        <span class="text-right text-sm font-semibold text-slate-800">{{ row.total_score }} 分</span>
                    </div>
                    <p v-if="(leaderboard.rows ?? []).length > boardRows.length" class="pt-1 text-center text-[11px] text-slate-400">
                        仅显示前 10 名
                    </p>
                </div>
                <EmptyState v-else title="本班暂无成绩记录" />
            </Card>

            <Card v-if="deduction.available" title="扣分" description="为权限范围内的学生录入扣分记录">
                <div class="rounded-xl bg-slate-50 px-4 py-3 text-xs leading-relaxed text-slate-500">
                    仅可选择扣分类别；扣分将直接计入当前学期，并记录操作人。
                    {{ isOfficer ? '班干部不能为本人扣分。' : '扣分范围以你的管理权限为限。' }}
                </div>
                <dl class="mt-3 space-y-2 text-xs">
                    <div class="flex items-center justify-between gap-2">
                        <dt class="text-slate-400">可扣分学生</dt>
                        <dd class="font-medium text-slate-700">{{ (deduction.students ?? []).length }} 人</dd>
                    </div>
                    <div class="flex items-center justify-between gap-2">
                        <dt class="text-slate-400">当前学期</dt>
                        <dd class="max-w-[65%] truncate text-right font-medium text-slate-700">{{ deduction.semester?.name ?? '未设置' }}</dd>
                    </div>
                </dl>
                <Button block class="mt-3" :disabled="!deduction.semester" @click="openDeduction">
                    <MinusCircle class="h-4 w-4" />
                    录入扣分
                </Button>
            </Card>
        </div>

        <div class="mb-4">
            <Tabs :model-value="tab" :items="tabs" @update:model-value="changeTab" />
        </div>

        <Card :padding="false">
            <form class="flex flex-wrap items-center gap-2 border-b border-slate-100 px-5 py-4" @submit.prevent="changeFilter">
                <select v-if="tab !== 'manual'" name="status" class="input !w-auto min-w-[110px]" :value="filters.status ?? 'pending'">
                    <option value="pending">待审核</option>
                    <option value="approved">已通过</option>
                    <option value="rejected">已驳回</option>
                    <option value="withdrawn">已撤回</option>
                    <option value="all">全部状态</option>
                </select>
                <select v-else name="manual_status" class="input !w-auto min-w-[110px]" :value="filters.manual_status ?? 'all'">
                    <option value="all">全部状态</option>
                    <option value="active">有效</option>
                    <option value="revoked">已撤销</option>
                </select>
                <select name="semester_id" class="input !w-auto min-w-[130px]" :value="filters.semester_id ?? ''">
                    <option value="">全部学期</option>
                    <option v-for="semester in semesters" :key="semester.id" :value="semester.id">{{ semester.name }}</option>
                </select>
                <select name="category_id" class="input !w-auto min-w-[120px]" :value="filters.category_id ?? ''">
                    <option value="">全部类别</option>
                    <option v-for="category in categories" :key="category.id" :value="category.id">{{ category.name }}</option>
                </select>
                <div class="relative">
                    <Search class="pointer-events-none absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 text-slate-400" />
                    <input name="search" :value="filters.search ?? ''" placeholder="姓名或学号" class="input !w-40 !pl-9" />
                </div>
                <button type="submit" class="btn-secondary">筛选</button>
                <Link :href="reviewBase" class="btn-ghost">重置</Link>
            </form>

            <div v-if="selected.length" class="flex flex-wrap items-center justify-between gap-3 border-b border-brand-100 bg-brand-50/60 px-5 py-3">
                <p class="text-sm text-brand-800">已选择 <span class="font-semibold">{{ selected.length }}</span> 条记录</p>
                <div class="flex items-center gap-2">
                    <button type="button" class="btn-primary !py-2 text-xs" @click="openBulk('approve')">
                        <CheckCircle2 class="h-3.5 w-3.5" />
                        批量通过
                    </button>
                    <button type="button" class="btn-danger !py-2 text-xs" @click="openBulk('reject')">
                        <XCircle class="h-3.5 w-3.5" />
                        批量驳回
                    </button>
                </div>
            </div>

            <div v-if="tab === 'personal'">
                <div v-if="personal.data.length" class="divide-y divide-slate-100 px-4 md:hidden">
                    <div v-for="item in personal.data" :key="item.id" class="flex items-start gap-3 py-3.5">
                        <input
                            type="checkbox"
                            :checked="selected.includes(item.id)"
                            class="mt-1 h-4 w-4 rounded border-slate-300 text-brand-600"
                            @change="toggleRow(item.id)"
                        />
                        <div class="min-w-0 flex-1">
                            <div class="flex flex-wrap items-center gap-1.5">
                                <span class="text-sm font-semibold text-slate-800">{{ item.user?.name }}</span>
                                <Badge :status="item.status" />
                            </div>
                            <p class="text-xs text-slate-400">{{ item.user?.student_id }} · {{ item.user?.class_name }} · {{ item.semester?.name }}</p>
                            <p class="text-xs text-slate-400">活动日期 {{ formatDate(item.applied_on) }}</p>
                            <p class="mt-1 text-sm text-slate-600">
                                {{ item.category?.name }}
                                <span class="font-semibold text-slate-800">{{ signedScore(displayScore(item)) }} 分</span>
                            </p>
                            <p class="mt-1 text-sm font-medium text-slate-700">{{ item.activity_name }}</p>
                            <p class="mt-0.5 line-clamp-2 text-xs leading-relaxed text-slate-500">{{ item.description }}</p>
                            <div class="mt-2 flex flex-wrap items-center gap-2">
                                <button type="button" class="btn-secondary !px-3 !py-1.5 text-xs" @click="openReview(item)">
                                    <Eye class="h-3.5 w-3.5" />
                                    {{ item.status === 'pending' && canReviewPersonal ? '审核' : '查看' }}
                                </button>
                                <button
                                    v-if="canDeleteRow(item)"
                                    type="button"
                                    class="btn-ghost !px-3 !py-1.5 text-xs text-rose-600 hover:text-rose-700"
                                    @click="openDelete('personal', item)"
                                >
                                    <Trash2 class="h-3.5 w-3.5" />
                                    删除
                                </button>
                            </div>
                        </div>
                    </div>
                </div>

                <div v-if="personal.data.length" class="table-wrap hidden md:block">
                    <table class="data-table">
                        <thead>
                            <tr>
                                <th class="w-10">
                                    <input type="checkbox" :checked="allSelected" class="h-4 w-4 rounded border-slate-300 text-brand-600" @change="toggleAll" />
                                </th>
                                <th>申请人</th>
                                <th>类别</th>
                                <th class="text-right">分值</th>
                                <th class="hidden lg:table-cell">活动 / 说明</th>
                                <th class="hidden sm:table-cell">学期</th>
                                <th>状态</th>
                                <th class="hidden xl:table-cell">活动日期</th>
                                <th class="text-right">操作</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr v-for="item in personal.data" :key="item.id">
                                <td>
                                    <input
                                        type="checkbox"
                                        :checked="selected.includes(item.id)"
                                        class="h-4 w-4 rounded border-slate-300 text-brand-600"
                                        @change="toggleRow(item.id)"
                                    />
                                </td>
                                <td>
                                    <p class="font-medium text-slate-800">{{ item.user?.name }}</p>
                                    <p class="text-xs text-slate-400">{{ item.user?.student_id }} · {{ item.user?.class_name }}</p>
                                </td>
                                <td>
                                    <div class="flex items-center gap-1.5">
                                        <span class="whitespace-nowrap text-slate-700">{{ item.category?.name }}</span>
                                        <Badge :status="item.category?.type" />
                                    </div>
                                </td>
                                <td class="text-right font-semibold text-slate-800">
                                    {{ signedScore(displayScore(item)) }}
                                    <span v-if="item.final_score !== null && item.final_score !== item.score" class="ml-1 block text-[11px] font-normal text-slate-400">
                                        申请 {{ signedScore(item.score) }}
                                    </span>
                                </td>
                                <td class="hidden max-w-[260px] lg:table-cell">
                                    <p class="truncate font-medium text-slate-700">{{ item.activity_name || item.description }}</p>
                                    <p class="truncate text-xs text-slate-400">{{ item.description }}</p>
                                </td>
                                <td class="hidden whitespace-nowrap text-slate-500 sm:table-cell">{{ item.semester?.name }}</td>
                                <td><Badge :status="item.status" /></td>
                                <td class="hidden whitespace-nowrap text-slate-400 xl:table-cell">
                                    <p>{{ formatDate(item.applied_on) }}</p>
                                    <p class="text-[11px] text-slate-300">提交 {{ formatDate(item.created_at) }}</p>
                                </td>
                                <td class="text-right">
                                    <div class="flex items-center justify-end gap-1.5">
                                        <button type="button" class="btn-secondary !px-3 !py-1.5 text-xs" @click="openReview(item)">
                                            <Eye class="h-3.5 w-3.5" />
                                            {{ item.status === 'pending' && canReviewPersonal ? '审核' : '查看' }}
                                        </button>
                                        <button
                                            v-if="canDeleteRow(item)"
                                            type="button"
                                            class="btn-ghost !px-2.5 !py-1.5 text-xs text-rose-600 hover:text-rose-700"
                                            title="删除申请记录"
                                            @click="openDelete('personal', item)"
                                        >
                                            <Trash2 class="h-3.5 w-3.5" />
                                        </button>
                                    </div>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
                <EmptyState v-else title="没有符合条件的个人申请" />
            </div>

            <div v-else-if="tab === 'group'">
                <div v-if="groups.data.length" class="divide-y divide-slate-100 px-4 md:hidden">
                    <div v-for="item in groups.data" :key="item.id" class="flex items-start gap-3 py-3.5">
                        <input
                            v-if="permissions.can_review_groups"
                            type="checkbox"
                            :checked="selected.includes(item.id)"
                            class="mt-1 h-4 w-4 rounded border-slate-300 text-brand-600"
                            @change="toggleRow(item.id)"
                        />
                        <div class="min-w-0 flex-1">
                            <div class="flex flex-wrap items-center gap-1.5">
                                <span class="text-sm font-semibold text-slate-800">{{ item.title }}</span>
                                <Badge :status="item.status" />
                            </div>
                            <p class="mt-0.5 text-xs text-slate-400">
                                {{ item.teacher?.name }} · {{ item.category?.name }} · {{ item.members_count }} 人 · {{ groupScoreRange(item.score_min, item.score_max) }}
                            </p>
                            <p class="mt-0.5 line-clamp-2 text-xs leading-relaxed text-slate-500">{{ item.description }}</p>
                            <div class="mt-2 flex flex-wrap items-center gap-2">
                                <Link :href="`${groupShowBase}/${item.id}`" class="btn-secondary !px-3 !py-1.5 text-xs">
                                    <Eye class="h-3.5 w-3.5" />
                                    {{ item.status === 'pending' && permissions.can_review_groups ? '审核' : '查看' }}
                                </Link>
                                <button
                                    v-if="canDeleteGroups"
                                    type="button"
                                    class="btn-ghost !px-3 !py-1.5 text-xs text-rose-600 hover:text-rose-700"
                                    @click="openDelete('group', item)"
                                >
                                    <Trash2 class="h-3.5 w-3.5" />
                                    删除
                                </button>
                            </div>
                        </div>
                    </div>
                </div>

                <div v-if="groups.data.length" class="table-wrap hidden md:block">
                    <table class="data-table">
                        <thead>
                            <tr>
                                <th v-if="tab === 'personal' || permissions.can_review_groups" class="w-10">
                                    <input type="checkbox" :checked="allSelected" class="h-4 w-4 rounded border-slate-300 text-brand-600" @change="toggleAll" />
                                </th>
                                <th>活动名称</th>
                                <th class="hidden sm:table-cell">提交教师</th>
                                <th>类别</th>
                                <th class="text-right">成员</th>
                                <th class="text-right">分值</th>
                                <th>状态</th>
                                <th class="hidden xl:table-cell">提交时间</th>
                                <th class="text-right">操作</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr v-for="item in groups.data" :key="item.id">
                                <td v-if="tab === 'personal' || permissions.can_review_groups">
                                    <input
                                        type="checkbox"
                                        :checked="selected.includes(item.id)"
                                        class="h-4 w-4 rounded border-slate-300 text-brand-600"
                                        @change="toggleRow(item.id)"
                                    />
                                </td>
                                <td>
                                    <p class="max-w-[260px] truncate font-medium text-slate-800">{{ item.title }}</p>
                                    <p class="max-w-[260px] truncate text-xs text-slate-400">{{ item.description }}</p>
                                </td>
                                <td class="hidden whitespace-nowrap sm:table-cell">
                                    <p class="text-slate-700">{{ item.teacher?.name }}</p>
                                    <p class="text-xs text-slate-400">{{ item.teacher?.employee_id }}</p>
                                </td>
                                <td class="whitespace-nowrap">
                                    <span class="text-slate-700">{{ item.category?.name }}</span>
                                </td>
                                <td class="text-right text-slate-700">{{ item.members_count }} 人</td>
                                <td class="whitespace-nowrap text-right text-slate-700">{{ groupScoreRange(item.score_min, item.score_max) }}</td>
                                <td><Badge :status="item.status" /></td>
                                <td class="hidden whitespace-nowrap text-slate-400 xl:table-cell">{{ formatDate(item.created_at, true) }}</td>
                                <td class="text-right">
                                    <div class="flex items-center justify-end gap-1.5">
                                        <Link :href="`${groupShowBase}/${item.id}`" class="btn-secondary !px-3 !py-1.5 text-xs">
                                            <Eye class="h-3.5 w-3.5" />
                                            {{ item.status === 'pending' && permissions.can_review_groups ? '审核' : '查看' }}
                                        </Link>
                                        <button
                                            v-if="canDeleteGroups"
                                            type="button"
                                            class="btn-ghost !px-2.5 !py-1.5 text-xs text-rose-600 hover:text-rose-700"
                                            title="删除集体申请记录"
                                            @click="openDelete('group', item)"
                                        >
                                            <Trash2 class="h-3.5 w-3.5" />
                                        </button>
                                    </div>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
                <EmptyState v-else title="没有符合条件的集体申请" />
            </div>

            <div v-else>
                <div v-if="manual.data.length" class="divide-y divide-slate-100 px-4 md:hidden">
                    <div v-for="item in manual.data" :key="`manual-${item.id}`" class="py-3.5">
                        <div class="flex items-start justify-between gap-3">
                            <div class="min-w-0 flex-1">
                                <div class="flex flex-wrap items-center gap-1.5">
                                    <span class="text-sm font-semibold text-slate-800">{{ item.user?.name }}</span>
                                    <Badge :status="item.category?.type" />
                                    <Badge v-if="item.revoked_at" status="withdrawn">已撤销</Badge>
                                    <Badge v-else status="active">有效</Badge>
                                </div>
                                <p class="text-xs text-slate-400">{{ item.user?.student_id }} · {{ item.user?.class_name }} · {{ item.semester?.name }}</p>
                                <p class="mt-1 text-sm text-slate-600">
                                    {{ item.category?.name }}
                                    <span class="font-semibold text-slate-800">{{ signedScore(item.score) }} 分</span>
                                </p>
                                <p class="mt-0.5 line-clamp-2 text-xs leading-relaxed text-slate-500">{{ item.description || '无说明' }}</p>
                                <p class="mt-1 text-[11px] text-slate-400">
                                    上报 {{ formatDate(item.applied_on) }} · 录入人 {{ item.creator?.name || '系统' }}
                                </p>
                                <p v-if="item.revoked_at" class="mt-1 text-[11px] text-slate-400">
                                    撤销于 {{ formatDate(item.revoked_at, true) }} · {{ item.revoke_reason || '管理员撤销' }}
                                </p>
                                <div v-if="canManageManual" class="mt-2">
                                    <button
                                        type="button"
                                        class="btn-ghost !px-3 !py-1.5 text-xs text-rose-600 hover:text-rose-700"
                                        @click="manualDeleteTarget = item"
                                    >
                                        <Trash2 class="h-3.5 w-3.5" />
                                        永久删除
                                    </button>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <div v-if="manual.data.length" class="table-wrap hidden md:block">
                    <table class="data-table">
                        <thead>
                            <tr>
                                <th>学生</th>
                                <th>类别</th>
                                <th class="text-right">分值</th>
                                <th class="hidden sm:table-cell">学期</th>
                                <th>状态</th>
                                <th class="hidden lg:table-cell">说明</th>
                                <th class="hidden xl:table-cell">录入人</th>
                                <th class="hidden xl:table-cell">上报日期</th>
                                <th v-if="canManageManual" class="text-right">操作</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr v-for="item in manual.data" :key="`manual-${item.id}`">
                                <td>
                                    <p class="font-medium text-slate-800">{{ item.user?.name }}</p>
                                    <p class="text-xs text-slate-400">{{ item.user?.student_id }} · {{ item.user?.class_name }}</p>
                                </td>
                                <td>
                                    <div class="flex items-center gap-1.5">
                                        <span class="whitespace-nowrap text-slate-700">{{ item.category?.name }}</span>
                                        <Badge :status="item.category?.type" />
                                    </div>
                                </td>
                                <td :class="['text-right font-semibold', item.revoked_at ? 'text-slate-400 line-through' : item.score >= 0 ? 'text-emerald-600' : 'text-rose-600']">
                                    {{ signedScore(item.score) }}
                                </td>
                                <td class="hidden whitespace-nowrap text-slate-500 sm:table-cell">{{ item.semester?.name }}</td>
                                <td>
                                    <Badge v-if="item.revoked_at" status="withdrawn">已撤销</Badge>
                                    <Badge v-else status="active">有效</Badge>
                                </td>
                                <td class="hidden max-w-[240px] lg:table-cell">
                                    <span class="line-clamp-2 text-slate-500">{{ item.description || '—' }}</span>
                                </td>
                                <td class="hidden whitespace-nowrap text-slate-500 xl:table-cell">{{ item.creator?.name || '系统' }}</td>
                                <td class="hidden whitespace-nowrap text-xs text-slate-400 xl:table-cell">
                                    <p class="text-slate-600">{{ formatDate(item.applied_on) }}</p>
                                    <p v-if="item.revoked_at" class="text-[11px] text-slate-300">撤销于 {{ formatDate(item.revoked_at, true) }}</p>
                                </td>
                                <td v-if="canManageManual" class="text-right">
                                    <button
                                        type="button"
                                        class="btn-ghost !px-2.5 !py-1.5 text-rose-600 hover:text-rose-700"
                                        title="永久删除（所有人都看不到）"
                                        @click="manualDeleteTarget = item"
                                    >
                                        <Trash2 class="h-4 w-4" />
                                    </button>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
                <EmptyState v-else title="没有符合条件的手工录入记录" description="系统管理员录入的加减分记录会显示在这里" />
            </div>

            <Pagination
                :paginator="currentPaginator"
                :page-size="pageSize"
                :page-size-options="pageSizeOptions"
                show-page-size
                @change="changePage"
                @size-change="changePageSize"
            />
        </Card>

        <Modal
            :open="!!reviewTarget"
            :title="reviewTarget?.status === 'pending' ? '审核个人申请' : '申请详情'"
            :description="reviewTarget ? `${reviewTarget.user?.name} · ${reviewTarget.user?.student_id}` : ''"
            @update:open="closeReview"
        >
            <div v-if="reviewTarget" class="space-y-4">
                <div class="rounded-xl bg-slate-50 px-4 py-3.5">
                    <div class="flex flex-wrap items-center gap-2">
                        <span class="text-sm font-semibold text-slate-800">{{ reviewTarget.activity_name || reviewTarget.category?.name }}</span>
                        <Badge :status="reviewTarget.category?.type" />
                        <Badge :status="reviewTarget.status" />
                        <span class="text-xs text-slate-400">{{ reviewTarget.semester?.name }}</span>
                    </div>
                    <p class="mt-1 text-xs text-slate-400">
                        类别：{{ reviewTarget.category?.name }} · 活动日期：{{ formatDate(reviewTarget.applied_on) }}
                    </p>
                    <p class="mt-2 whitespace-pre-line text-sm leading-relaxed text-slate-600">{{ reviewTarget.description }}</p>
                    <div class="mt-3 grid grid-cols-1 gap-2 rounded-xl bg-white px-3 py-3 text-xs text-slate-500 sm:grid-cols-2">
                        <p>提交时间：<span class="text-slate-700">{{ formatDate(reviewTarget.created_at, true) }}</span></p>
                        <p>提交分值：<strong class="text-slate-700">{{ signedScore(reviewTarget.score) }}</strong></p>
                        <p>审核人：<span class="text-slate-700">{{ reviewTarget.reviewer?.name ?? '—' }}</span></p>
                        <p>审核时间：<span class="text-slate-700">{{ reviewTarget.reviewed_at ? formatDate(reviewTarget.reviewed_at, true) : '—' }}</span></p>
                        <p v-if="reviewTarget.final_score !== null" class="sm:col-span-2">
                            核定分值：<strong class="text-slate-700">{{ signedScore(reviewTarget.final_score) }}</strong>
                        </p>
                    </div>
                    <EvidenceGallery
                        v-if="applicationEvidenceFiles(reviewTarget).length"
                        class="mt-3"
                        :files="applicationEvidenceFiles(reviewTarget)"
                    />
                    <p v-if="reviewTarget.review_comment" class="mt-2 rounded-lg bg-white px-3 py-2 text-xs text-slate-600">
                        审核意见：{{ reviewTarget.review_comment }}
                    </p>
                    <p v-else-if="reviewTarget.status !== 'pending'" class="mt-2 text-xs text-slate-400">审核意见：无</p>
                </div>

                <template v-if="reviewTarget.status === 'pending'">
                    <Field v-if="!reviewIsVolunteer" label="核定分值" :error="reviewForm.errors.final_score" required hint="可与学生申请分值不同，支持 0.5 分">
                        <input v-model="reviewForm.final_score" type="number" min="0.5" step="0.5" inputmode="decimal" class="input" />
                    </Field>
                    <div v-else class="rounded-xl border border-amber-200 bg-amber-50 px-3.5 py-3 text-xs leading-relaxed text-amber-700">
                        志愿服务申请本次计 0 分。本学期累计通过 4 次后，系统将自动为该学生加 6 分；达到 4 次上限后不可再通过新的志愿服务申请。
                    </div>
                    <Field label="审核意见" :error="reviewForm.errors.review_comment" hint="可选，驳回原因可以留空">
                        <textarea v-model="reviewForm.review_comment" rows="3" class="input resize-none" placeholder="填写审核说明或驳回原因（可留空）" />
                    </Field>

                    <div class="flex justify-end gap-2 pt-1">
                        <Button variant="danger" :loading="reviewForm.processing" @click="reject">
                            <XCircle class="h-4 w-4" />
                            驳回
                        </Button>
                        <Button :loading="reviewForm.processing" @click="approve">
                            <CheckCircle2 class="h-4 w-4" />
                            通过并计分
                        </Button>
                    </div>
                </template>

                <template v-else-if="reviewTarget.status === 'approved'">
                    <div v-if="canModifyApproved" class="rounded-xl border border-brand-200 bg-brand-50/60 px-4 py-3.5">
                        <p class="text-sm font-semibold text-slate-800">修改核定分值</p>
                        <p class="mt-0.5 text-xs leading-relaxed text-slate-500">
                            修改后学生已生成的分值会同步更新，审核状态与通过时间保持不变。
                        </p>
                        <div v-if="!reviewIsVolunteer" class="mt-3">
                            <Field label="核定分值" :error="editForm.errors.final_score" required hint="支持 0.5 分">
                                <input v-model="editForm.final_score" type="number" min="0.5" step="0.5" inputmode="decimal" class="input" />
                            </Field>
                        </div>
                        <div v-else class="mt-3 rounded-lg border border-amber-200 bg-amber-50 px-3 py-2 text-xs leading-relaxed text-amber-700">
                            志愿服务申请按规则计 0 分，仅可修改审核意见。
                        </div>
                        <Field class="mt-3" label="审核意见" :error="editForm.errors.review_comment" hint="可选">
                            <textarea v-model="editForm.review_comment" rows="2" maxlength="1000" class="input resize-none" placeholder="填写修改说明（可留空）" />
                        </Field>
                        <Button class="mt-3" :loading="editForm.processing" @click="updateApproved">
                            <CheckCircle2 class="h-4 w-4" />
                            保存修改
                        </Button>
                    </div>

                    <div v-if="canReviewPersonal" class="rounded-xl border border-rose-200 bg-rose-50 px-4 py-3" :class="canModifyApproved ? 'mt-3' : ''">
                        <p class="text-xs leading-relaxed text-rose-700">
                            取消通过后，该申请已生成的分值会立即扣回，申请退回待审核状态，可重新审核。
                        </p>
                        <Button variant="danger" class="mt-2.5" @click="openCancel(reviewTarget)">
                            <XCircle class="h-4 w-4" />
                            取消通过并扣回分值
                        </Button>
                    </div>
                </template>
            </div>
        </Modal>

        <ConfirmDialog
            :open="bulkOpen"
            :title="bulkAction === 'approve' ? '批量通过' : '批量驳回'"
            :message="`即将${bulkAction === 'approve' ? '通过' : '驳回'}已选择的 ${selected.length} 条申请。${bulkAction === 'approve' ? '通过后将按申请原分值生成成绩。' : ''}`"
            :confirm-text="bulkAction === 'approve' ? '确认通过' : '确认驳回'"
            :danger="bulkAction === 'reject'"
            :loading="bulkProcessing"
            @update:open="bulkOpen = false"
            @confirm="submitBulk"
        >
            <div v-if="bulkAction === 'reject'" class="mt-3">
                <Field label="驳回原因" hint="可选，可以留空">
                    <textarea v-model="bulkComment" rows="3" class="input resize-none" placeholder="填写批量驳回原因（可留空）" />
                </Field>
            </div>
        </ConfirmDialog>

        <Modal
            :open="deductionOpen"
            title="录入扣分"
            :description="deduction.semester?.name ?? ''"
            @update:open="deductionOpen = false"
        >
            <div v-if="deductionOpen" class="space-y-4">
                <Field label="学生" :error="deductionForm.errors.student_id" required>
                    <select v-model="deductionForm.student_id" class="input">
                        <option value="">请选择学生</option>
                        <option v-for="student in deduction.students ?? []" :key="student.id" :value="student.id">
                            {{ student.name }} · {{ student.student_id }} · {{ student.class_name }}
                        </option>
                    </select>
                </Field>
                <Field label="扣分类别" :error="deductionForm.errors.category_id" required>
                    <select v-model="deductionForm.category_id" class="input">
                        <option value="">请选择扣分类别</option>
                        <option v-for="category in deduction.categories ?? []" :key="category.id" :value="category.id">
                            {{ category.name }}
                        </option>
                    </select>
                </Field>
                <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                    <Field label="扣分分值" :error="deductionForm.errors.score" required hint="支持 0.5 分">
                        <div class="relative">
                            <input
                                v-model="deductionForm.score"
                                type="number"
                                min="0.5"
                                step="0.5"
                                inputmode="decimal"
                                placeholder="例如 1 或 0.5"
                                class="input !pr-10"
                            />
                            <span class="absolute right-3.5 top-1/2 -translate-y-1/2 text-xs text-slate-400">分</span>
                        </div>
                    </Field>
                    <Field label="扣分日期" :error="deductionForm.errors.applied_on" required>
                        <input v-model="deductionForm.applied_on" type="date" :max="todayString" class="input" />
                    </Field>
                </div>
                <Field label="扣分原因" :error="deductionForm.errors.description" required>
                    <textarea v-model="deductionForm.description" rows="3" maxlength="500" class="input resize-none" placeholder="填写扣分事由" />
                </Field>

                <div class="rounded-xl bg-rose-50 px-4 py-3 text-xs leading-relaxed text-rose-700">
                    将从
                    <strong>{{ deductionStudent?.name ?? '所选学生' }}</strong>
                    当前学期德育分中扣减
                    <strong>{{ deductionForm.score || 0 }} 分</strong>
                    <span v-if="deductionCategory">（{{ deductionCategory.name }}）</span>。
                </div>

                <div class="flex justify-end gap-2 pt-1">
                    <Button variant="secondary" @click="deductionOpen = false">取消</Button>
                    <Button variant="danger" :loading="deductionForm.processing" :disabled="!deductionForm.student_id || !deductionForm.category_id" @click="submitDeduction">
                        <MinusCircle class="h-4 w-4" />
                        确认扣分
                    </Button>
                </div>
            </div>
        </Modal>

        <ConfirmDialog
            :open="!!cancelTarget"
            title="取消审核通过"
            :message="`确认取消“${cancelTarget?.activity_name || cancelTarget?.category?.name || ''}”的审核通过吗？已生成的 ${Math.abs(Number(cancelTarget?.final_score ?? 0))} 分将立即扣回，申请退回待审核。`"
            confirm-text="取消通过并扣回分值"
            danger
            :loading="cancelProcessing"
            @update:open="cancelTarget = null"
            @confirm="submitCancel"
        >
            <div class="mt-3">
                <Field label="取消原因" hint="可选，将记录在申请与操作日志中">
                    <textarea v-model="cancelReason" rows="3" maxlength="400" class="input resize-none" placeholder="填写取消原因（可留空）" />
                </Field>
            </div>
        </ConfirmDialog>

        <ConfirmDialog
            :open="!!deleteTarget"
            :title="deleteTarget?.type === 'group' ? '删除集体申请记录' : '删除申请记录'"
            :message="deleteMessage"
            confirm-text="确认删除"
            danger
            :loading="deleteProcessing"
            @update:open="deleteTarget = null"
            @confirm="submitDelete"
        />

        <ConfirmDialog
            :open="!!manualDeleteTarget"
            title="永久删除手工录入记录"
            :message="`确认永久删除“${manualDeleteTarget?.user?.name ?? ''}”的这条${manualDeleteTarget?.category?.name ?? ''}记录（${signedScore(manualDeleteTarget?.score ?? 0)} 分）吗？删除后学生、班干部、辅导员和管理员都将看不到这条记录，且不可恢复。`"
            confirm-text="永久删除"
            danger
            :loading="manualDeleteProcessing"
            @update:open="manualDeleteTarget = null"
            @confirm="submitManualDelete"
        />
    </AuthenticatedLayout>
</template>
