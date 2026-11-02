<script setup>
import { computed, onMounted, ref } from 'vue';
import { Head, Link } from '@inertiajs/vue3';
import {
    ArrowUpRight,
    Building2,
    ClipboardCheck,
    FileText,
    GraduationCap,
    Megaphone,
    PlusCircle,
    School,
    Trophy,
    Users,
} from 'lucide-vue-next';
import AuthenticatedLayout from '@/layouts/AuthenticatedLayout.vue';
import PageHeader from '@/components/ui/PageHeader.vue';
import Card from '@/components/ui/Card.vue';
import StatCard from '@/components/ui/StatCard.vue';
import Badge from '@/components/ui/Badge.vue';
import EmptyState from '@/components/ui/EmptyState.vue';
import Modal from '@/components/ui/Modal.vue';
import ScoreSummary from '@/components/ScoreSummary.vue';
import { formatDate, signedScore } from '@/lib/format';
import { sanitizeHtml } from '@/lib/html';

const props = defineProps({
    mode: { type: String, required: true },
    summary: { type: Object, default: null },
    recentApplications: { type: Array, default: () => [] },
    announcements: { type: Array, default: () => [] },
    popupAnnouncement: { type: Object, default: null },
    stats: { type: Object, default: null },
    overview: { type: Object, default: null },
    categoryStats: { type: Array, default: () => [] },
    monthlyTrend: { type: Array, default: () => [] },
    pendingApplications: { type: Array, default: () => [] },
    pendingGroups: { type: Array, default: () => [] },
    pendingAppeals: { type: Array, default: () => [] },
    currentSemester: { type: Object, default: null },
    classInfo: { type: Object, default: null },
    scopeLabel: { type: String, default: '全校' },
    topStudents: { type: Array, default: () => [] },
});

const greeting = computed(() => {
    const hour = new Date().getHours();
    if (hour < 6) return '夜深了';
    if (hour < 9) return '早上好';
    if (hour < 12) return '上午好';
    if (hour < 14) return '中午好';
    if (hour < 18) return '下午好';
    return '晚上好';
});

const today = new Intl.DateTimeFormat('zh-CN', {
    month: 'long',
    day: 'numeric',
    weekday: 'long',
}).format(new Date());

const selectedAnnouncement = ref(null);
const popupAnnouncement = ref(null);

function popupStorageKey(id) {
    return `moral-announcement-popup-${id}`;
}

onMounted(() => {
    if (props.mode !== 'student' || !props.popupAnnouncement) return;

    try {
        if (window.sessionStorage.getItem(popupStorageKey(props.popupAnnouncement.id))) return;
    } catch {
        // 会话存储不可用时仍展示一次
    }

    popupAnnouncement.value = props.popupAnnouncement;
});

function closePopup() {
    if (popupAnnouncement.value) {
        try {
            window.sessionStorage.setItem(popupStorageKey(popupAnnouncement.value.id), '1');
        } catch {
            // 忽略存储失败
        }
    }

    popupAnnouncement.value = null;
}

const maxCategoryCount = computed(() =>
    Math.max(1, ...props.categoryStats.map((item) => item.count)),
);
</script>

<template>
    <Head title="工作台" />

    <AuthenticatedLayout>
        <PageHeader title="工作台" :description="`${today} · ${currentSemester?.name ?? '未设置当前学期'}${mode === 'admin' ? ` · ${scopeLabel}` : ''}`">
            <template #actions>
                <template v-if="mode === 'student'">
                    <Link href="/applications/create" class="btn-primary">
                        <PlusCircle class="h-4 w-4" />
                        提交申请
                    </Link>
                </template>
                <template v-else-if="mode === 'teacher'">
                    <Link href="/teacher/group-applications/create" class="btn-primary">
                        <PlusCircle class="h-4 w-4" />
                        发起集体申请
                    </Link>
                </template>
                <template v-else>
                    <Link href="/admin/review" class="btn-primary">
                        <ClipboardCheck class="h-4 w-4" />
                        进入审核中心
                    </Link>
                </template>
            </template>
        </PageHeader>

        <template v-if="mode === 'student'">
            <div class="mb-4 flex flex-wrap items-center gap-x-6 gap-y-2 rounded-2xl border border-slate-200/80 bg-white px-5 py-3.5 shadow-sm">
                <div class="flex items-center gap-2 text-sm text-slate-500">
                    <Building2 class="h-4 w-4 text-slate-400" />
                    学院
                    <span class="font-medium text-slate-800">{{ classInfo?.college || '未设置' }}</span>
                </div>
                <div class="flex items-center gap-2 text-sm text-slate-500">
                    <GraduationCap class="h-4 w-4 text-slate-400" />
                    专业
                    <span class="font-medium text-slate-800">{{ classInfo?.major || '未设置' }}</span>
                </div>
                <div class="flex items-center gap-2 text-sm text-slate-500">
                    <School class="h-4 w-4 text-slate-400" />
                    班级
                    <span class="font-medium text-slate-800">{{ classInfo?.class_name || '未分班' }}</span>
                    <span v-if="classInfo?.grade" class="text-xs text-slate-400">{{ classInfo.grade }}</span>
                </div>
                <div v-if="classInfo?.is_officer" class="flex flex-wrap items-center gap-1.5">
                    <span v-for="item in classInfo.officer_titles" :key="item.class_name" class="rounded-full bg-amber-50 px-2.5 py-1 text-xs font-medium text-amber-700 ring-1 ring-amber-200">
                        {{ item.class_name }} {{ item.title }}
                    </span>
                </div>
            </div>

            <div class="grid grid-cols-1 gap-4 lg:grid-cols-3">
                <div class="space-y-4 lg:col-span-2">
                    <ScoreSummary
                        :base="summary.base_score"
                        :add="summary.add_score"
                        :deduct="summary.deduct_score"
                        :total="summary.total_score"
                    />

                    <Card title="最近申请" description="最新的个人申请与审核状态">
                        <template #actions>
                            <Link href="/applications" class="text-xs font-medium text-brand-600 hover:text-brand-700">
                                查看全部
                            </Link>
                        </template>
                        <div v-if="recentApplications.length" class="divide-y divide-slate-100">
                            <div
                                v-for="item in recentApplications"
                                :key="item.id"
                                class="flex items-start justify-between gap-3 py-3 first:pt-0 last:pb-0"
                            >
                                <div class="min-w-0">
                                    <p class="truncate text-sm font-medium text-slate-800">{{ item.category?.name }}</p>
                                    <p class="mt-0.5 truncate text-xs text-slate-400">{{ item.description }}</p>
                                </div>
                                <div class="flex shrink-0 items-center gap-2">
                                    <span class="text-sm font-semibold text-slate-700">{{ signedScore(item.final_score ?? item.score) }}</span>
                                    <Badge :status="item.status" />
                                </div>
                            </div>
                        </div>
                        <EmptyState v-else title="还没有提交过申请" description="选择活动类别后即可提交第一条申请">
                            <Link href="/applications/create" class="btn-secondary">立即申请</Link>
                        </EmptyState>
                    </Card>
                </div>

                <div class="space-y-4">
                    <Card title="快捷入口" padding>
                        <div class="space-y-2">
                            <Link
                                v-if="classInfo?.is_officer"
                                href="/officer/review"
                                class="flex items-center justify-between rounded-xl border border-amber-200 bg-amber-50/60 px-4 py-3 text-sm font-medium text-amber-800 transition hover:border-amber-300 hover:bg-amber-50"
                            >
                                <span class="flex items-center gap-2">
                                    <ClipboardCheck class="h-4 w-4" />
                                    班干部审批
                                </span>
                                <span class="flex items-center gap-2 text-xs">
                                    <span v-if="classInfo.officer_pending" class="rounded-full bg-amber-500 px-2 py-0.5 font-semibold text-white">
                                        {{ classInfo.officer_pending }}
                                    </span>
                                    <ArrowUpRight class="h-4 w-4 text-amber-500" />
                                </span>
                            </Link>
                            <Link href="/my-scores" class="flex items-center justify-between rounded-xl border border-slate-100 px-4 py-3 text-sm font-medium text-slate-700 transition hover:border-brand-200 hover:bg-brand-50/40">
                                查看我的德育分
                                <ArrowUpRight class="h-4 w-4 text-slate-400" />
                            </Link>
                            <Link href="/applications" class="flex items-center justify-between rounded-xl border border-slate-100 px-4 py-3 text-sm font-medium text-slate-700 transition hover:border-brand-200 hover:bg-brand-50/40">
                                我的申请记录
                                <ArrowUpRight class="h-4 w-4 text-slate-400" />
                            </Link>
                            <Link href="/applications/group/create" class="flex items-center justify-between rounded-xl border border-slate-100 px-4 py-3 text-sm font-medium text-slate-700 transition hover:border-brand-200 hover:bg-brand-50/40">
                                发起集体申请
                                <ArrowUpRight class="h-4 w-4 text-slate-400" />
                            </Link>
                            <Link href="/appeals" class="flex items-center justify-between rounded-xl border border-slate-100 px-4 py-3 text-sm font-medium text-slate-700 transition hover:border-brand-200 hover:bg-brand-50/40">
                                扣分申诉
                                <ArrowUpRight class="h-4 w-4 text-slate-400" />
                            </Link>
                            <Link href="/announcements" class="flex items-center justify-between rounded-xl border border-slate-100 px-4 py-3 text-sm font-medium text-slate-700 transition hover:border-brand-200 hover:bg-brand-50/40">
                                查看公告
                                <ArrowUpRight class="h-4 w-4 text-slate-400" />
                            </Link>
                        </div>
                    </Card>

                    <Card title="类别明细" description="按类别汇总当前学年得分">
                        <div v-if="summary.category_breakdown.length" class="space-y-3">
                            <div v-for="item in summary.category_breakdown" :key="item.name">
                                <div class="flex items-center justify-between text-xs">
                                    <span class="text-slate-600">{{ item.name }}</span>
                                    <span :class="item.score >= 0 ? 'font-semibold text-emerald-600' : 'font-semibold text-rose-600'">
                                        {{ signedScore(item.score) }}
                                    </span>
                                </div>
                            </div>
                        </div>
                        <EmptyState v-else title="暂无成绩记录" />
                    </Card>

                    <Card title="公告" description="最新通知">
                        <div v-if="announcements.length" class="space-y-3">
                            <button
                                v-for="item in announcements.slice(0, 3)"
                                :key="item.id"
                                type="button"
                                class="block w-full text-left"
                                @click="selectedAnnouncement = item"
                            >
                                <div class="flex items-start gap-2">
                                    <Megaphone class="mt-0.5 h-4 w-4 shrink-0 text-brand-500" />
                                    <div class="min-w-0">
                                        <p class="truncate text-sm text-slate-700">{{ item.title }}</p>
                                        <p class="mt-0.5 text-[11px] text-slate-400">{{ formatDate(item.published_at) }}</p>
                                    </div>
                                </div>
                            </button>
                        </div>
                        <EmptyState v-else title="暂无公告" />
                    </Card>
                </div>
            </div>
        </template>

        <template v-else-if="mode === 'teacher'">
            <div class="mb-4 grid grid-cols-2 gap-3 lg:grid-cols-4">
                <StatCard label="全部申请" :value="stats.total" hint="累计提交" />
                <StatCard label="待审核" :value="stats.pending" hint="等待管理员处理" tone="amber" />
                <StatCard label="已通过" :value="stats.approved" hint="已生成成绩" tone="emerald" />
                <StatCard label="已驳回" :value="stats.rejected" hint="可修改后重新提交" tone="rose" />
            </div>

            <div class="grid grid-cols-1 gap-4 lg:grid-cols-3">
                <Card class="lg:col-span-2" title="最近集体申请">
                    <template #actions>
                        <Link href="/teacher/group-applications" class="text-xs font-medium text-brand-600 hover:text-brand-700">
                            查看全部
                        </Link>
                    </template>
                    <div v-if="recentApplications.length" class="divide-y divide-slate-100">
                        <Link
                            v-for="item in recentApplications"
                            :key="item.id"
                            :href="`/teacher/group-applications/${item.id}`"
                            class="flex items-start justify-between gap-3 py-3 first:pt-0 last:pb-0"
                        >
                            <div class="min-w-0">
                                <p class="truncate text-sm font-medium text-slate-800">{{ item.title }}</p>
                                <p class="mt-0.5 text-xs text-slate-400">
                                    {{ item.category?.name }} · {{ item.members_count }} 人 · {{ formatDate(item.created_at) }}
                                </p>
                            </div>
                            <Badge :status="item.status" />
                        </Link>
                    </div>
                    <EmptyState v-else title="还没有集体申请" description="上传成员名单即可批量提交">
                        <Link href="/teacher/group-applications/create" class="btn-primary">发起集体申请</Link>
                    </EmptyState>
                </Card>

                <div class="space-y-4">
                    <Card title="快捷入口">
                        <div class="space-y-2">
                            <Link href="/leaderboard" class="flex items-center justify-between rounded-xl border border-slate-100 px-4 py-3 text-sm font-medium text-slate-700 transition hover:border-brand-200 hover:bg-brand-50/40">
                                查看德育分排行榜
                                <Trophy class="h-4 w-4 text-amber-500" />
                            </Link>
                            <Link href="/announcements" class="flex items-center justify-between rounded-xl border border-slate-100 px-4 py-3 text-sm font-medium text-slate-700 transition hover:border-brand-200 hover:bg-brand-50/40">
                                查看公告
                                <Megaphone class="h-4 w-4 text-slate-400" />
                            </Link>
                        </div>
                    </Card>

                    <Card title="公告">
                        <div v-if="announcements.length" class="space-y-3">
                            <button
                                v-for="item in announcements.slice(0, 3)"
                                :key="item.id"
                                type="button"
                                class="block w-full text-left"
                                @click="selectedAnnouncement = item"
                            >
                                <p class="truncate text-sm text-slate-700">{{ item.title }}</p>
                                <p class="mt-0.5 text-[11px] text-slate-400">{{ formatDate(item.published_at) }}</p>
                            </button>
                        </div>
                        <EmptyState v-else title="暂无公告" />
                    </Card>
                </div>
            </div>
        </template>

        <template v-else>
            <div class="mb-4 grid grid-cols-2 gap-3 lg:grid-cols-4">
                <StatCard label="在校学生" :value="overview.student_count" hint="启用状态" />
                <StatCard label="待审核" :value="overview.pending_applications" hint="个人 + 集体" tone="amber" />
                <StatCard label="待处理申诉" :value="overview.pending_appeals" hint="扣分申诉" tone="rose" />
                <StatCard label="累计加分" :value="`+${overview.add_total}`" hint="当前学期" tone="emerald" />
            </div>

            <div class="grid grid-cols-1 gap-4 xl:grid-cols-3">
                <Card class="xl:col-span-2" title="待审核个人申请">
                    <template #actions>
                        <Link href="/admin/review" class="text-xs font-medium text-brand-600 hover:text-brand-700">
                            去审核
                        </Link>
                    </template>
                    <div v-if="pendingApplications.length" class="divide-y divide-slate-100">
                        <div
                            v-for="item in pendingApplications"
                            :key="item.id"
                            class="flex items-center justify-between gap-3 py-3 first:pt-0 last:pb-0"
                        >
                            <div class="flex min-w-0 items-center gap-3">
                                <span class="flex h-9 w-9 shrink-0 items-center justify-center rounded-full bg-slate-100 text-[11px] font-semibold text-slate-500">
                                    {{ item.user?.name?.slice(0, 1) }}
                                </span>
                                <div class="min-w-0">
                                    <p class="truncate text-sm font-medium text-slate-800">{{ item.user?.name }} <span class="font-normal text-slate-400">{{ item.user?.student_id }}</span></p>
                                    <p class="truncate text-xs text-slate-400">{{ item.category?.name }} · {{ item.description }}</p>
                                </div>
                            </div>
                            <div class="flex shrink-0 items-center gap-2">
                                <span class="text-sm font-semibold text-slate-700">{{ signedScore(item.score) }}</span>
                                <Badge status="pending" />
                            </div>
                        </div>
                    </div>
                    <EmptyState v-else title="没有待审核的个人申请" />
                </Card>

                <Card title="待审核集体申请">
                    <div v-if="pendingGroups.length" class="space-y-3">
                        <div v-for="item in pendingGroups" :key="item.id" class="rounded-xl border border-slate-100 px-3.5 py-3">
                            <p class="truncate text-sm font-medium text-slate-800">{{ item.title }}</p>
                            <p class="mt-1 text-xs text-slate-400">
                                {{ item.teacher?.name }} · {{ item.members_count }} 人 · {{ item.category?.name }}
                            </p>
                        </div>
                    </div>
                    <EmptyState v-else title="没有待审核的集体申请" />
                </Card>

                <Card title="待处理扣分申诉">
                    <template #actions>
                        <Link href="/admin/appeals" class="text-xs font-medium text-brand-600 hover:text-brand-700">去处理</Link>
                    </template>
                    <div v-if="pendingAppeals.length" class="space-y-3">
                        <div v-for="item in pendingAppeals" :key="item.id" class="rounded-xl border border-slate-100 px-3.5 py-3">
                            <div class="flex items-start justify-between gap-2">
                                <div class="min-w-0">
                                    <p class="truncate text-sm font-medium text-slate-800">{{ item.user?.name }}</p>
                                    <p class="truncate text-xs text-slate-400">{{ item.score_record?.category?.name }} · {{ item.user?.student_id }}</p>
                                </div>
                                <span class="shrink-0 text-sm font-semibold text-rose-600">{{ item.score_record?.score }}</span>
                            </div>
                            <p class="mt-1 line-clamp-1 text-xs text-slate-500">{{ item.reason }}</p>
                        </div>
                    </div>
                    <EmptyState v-else title="没有待处理申诉" />
                </Card>

                <Card class="xl:col-span-2" title="排行榜前 5 名">
                    <template #actions>
                        <Link href="/leaderboard" class="text-xs font-medium text-brand-600 hover:text-brand-700">查看完整榜单</Link>
                    </template>
                    <div v-if="topStudents.length" class="divide-y divide-slate-100">
                        <div
                            v-for="(item, index) in topStudents"
                            :key="item.id"
                            class="flex items-center justify-between gap-3 py-2.5 first:pt-0 last:pb-0"
                        >
                            <div class="flex min-w-0 items-center gap-3">
                                <span
                                    :class="[
                                        'flex h-7 w-7 shrink-0 items-center justify-center rounded-lg text-xs font-semibold',
                                        index === 0 ? 'bg-amber-100 text-amber-700' : index === 1 ? 'bg-slate-200 text-slate-600' : index === 2 ? 'bg-orange-100 text-orange-700' : 'bg-slate-50 text-slate-400',
                                    ]"
                                >
                                    {{ index + 1 }}
                                </span>
                                <div class="min-w-0">
                                    <p class="truncate text-sm font-medium text-slate-800">{{ item.name }}</p>
                                    <p class="text-xs text-slate-400">{{ item.student_id }} · {{ item.class_name }}</p>
                                </div>
                            </div>
                            <span class="text-sm font-semibold text-slate-800">{{ item.total_score }} 分</span>
                        </div>
                    </div>
                    <EmptyState v-else title="暂无成绩数据" />
                </Card>

                <div class="grid grid-cols-1 gap-4 xl:col-span-3 xl:grid-cols-2">
                    <Card title="类别分布">
                        <div v-if="categoryStats.length" class="space-y-3">
                            <div v-for="item in categoryStats" :key="item.name">
                                <div class="flex items-center justify-between text-xs">
                                    <span class="text-slate-600">{{ item.name }} <span class="text-slate-400">×{{ item.count }}</span></span>
                                    <span :class="item.score >= 0 ? 'font-semibold text-emerald-600' : 'font-semibold text-rose-600'">
                                        {{ signedScore(item.score) }}
                                    </span>
                                </div>
                                <div class="mt-1.5 h-1.5 overflow-hidden rounded-full bg-slate-100">
                                    <div
                                        :class="['h-full rounded-full', item.type === 'deduct' ? 'bg-rose-400' : 'bg-brand-500']"
                                        :style="{ width: `${(item.count / maxCategoryCount) * 100}%` }"
                                    />
                                </div>
                            </div>
                        </div>
                        <EmptyState v-else title="暂无成绩记录" />
                    </Card>

                    <Card title="最新公告">
                        <div v-if="announcements.length" class="space-y-3">
                            <button
                                v-for="item in announcements.slice(0, 3)"
                                :key="item.id"
                                type="button"
                                class="block w-full text-left"
                                @click="selectedAnnouncement = item"
                            >
                                <p class="truncate text-sm text-slate-700">{{ item.title }}</p>
                                <p class="mt-0.5 text-[11px] text-slate-400">{{ formatDate(item.published_at) }}</p>
                            </button>
                        </div>
                        <EmptyState v-else title="暂无公告" />
                    </Card>
                </div>
            </div>
        </template>

        <Modal
            :open="!!popupAnnouncement"
            :title="popupAnnouncement?.title ?? ''"
            :description="popupAnnouncement ? `${popupAnnouncement.author ?? ''} · ${formatDate(popupAnnouncement.published_at, true)}` : ''"
            size="lg"
            @update:open="closePopup"
        >
            <div v-if="popupAnnouncement?.is_important" class="mb-3 inline-flex items-center gap-1 rounded-full bg-amber-50 px-2.5 py-1 text-xs font-medium text-amber-700 ring-1 ring-inset ring-amber-200">
                <Megaphone class="h-3 w-3" />
                重要公告
            </div>
            <div
                v-if="popupAnnouncement?.content"
                class="announcement-html"
                v-html="sanitizeHtml(popupAnnouncement.content)"
            />
            <p v-else class="text-sm text-slate-400">暂无内容</p>

            <template #footer>
                <div class="flex flex-wrap items-center justify-between gap-3">
                    <Link href="/announcements" class="text-sm font-medium text-brand-600 hover:text-brand-700" @click="closePopup">
                        查看全部公告
                    </Link>
                    <button type="button" class="btn-primary" @click="closePopup">我知道了</button>
                </div>
            </template>
        </Modal>

        <Modal
            :open="!!selectedAnnouncement"
            :title="selectedAnnouncement?.title ?? ''"
            :description="selectedAnnouncement ? `${selectedAnnouncement.author ?? ''} · ${formatDate(selectedAnnouncement.published_at, true)}` : ''"
            size="lg"
            @update:open="selectedAnnouncement = null"
        >
            <div
                v-if="selectedAnnouncement?.content"
                class="announcement-html"
                v-html="sanitizeHtml(selectedAnnouncement.content)"
            />
            <p v-else class="text-sm text-slate-400">暂无内容</p>
        </Modal>
    </AuthenticatedLayout>
</template>
