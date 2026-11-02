<script setup>
import { computed, ref, watch } from 'vue';
import { Head, Link, router, usePage } from '@inertiajs/vue3';
import { CalendarRange, Download, Medal, Search, Trophy } from 'lucide-vue-next';
import AuthenticatedLayout from '@/layouts/AuthenticatedLayout.vue';
import PageHeader from '@/components/ui/PageHeader.vue';
import Card from '@/components/ui/Card.vue';
import EmptyState from '@/components/ui/EmptyState.vue';
import Pagination from '@/components/ui/Pagination.vue';
import PeriodSelect from '@/components/ui/PeriodSelect.vue';
import { signedScore } from '@/lib/format';

const props = defineProps({
    leaderboard: { type: Object, required: true },
    period: { type: Object, required: true },
    ranking: { type: Object, required: true },
    filterOptions: { type: Object, default: () => ({}) },
    filters: { type: Object, default: () => ({}) },
    scopeLabel: { type: String, default: '全校总榜' },
});

const page = usePage();
const isAdmin = computed(() => page.props.auth.user.role === 'admin');

const rankPeriod = ref(props.ranking.rank_period);
const startDate = ref(props.ranking.start_date ?? '');
const endDate = ref(props.ranking.end_date ?? '');

const scope = ref(props.filters.scope ?? 'all');
const collegeId = ref(props.filters.college_id ?? '');
const initialClass = (props.filterOptions.classes ?? []).find(
    (item) => String(item.id) === String(props.filters.class_id ?? ''),
);
const majorId = ref(props.filters.major_id ?? initialClass?.major_id ?? '');
const grade = ref(props.filters.grade ?? '');
const classId = ref(props.filters.class_id ?? '');
const search = ref(props.filters.search ?? '');
const perPage = ref(String(props.filters.per_page ?? props.leaderboard.per_page ?? '20'));
const pageSizeOptions = [
    { value: '10', label: '10 条' },
    { value: '20', label: '20 条' },
    { value: '50', label: '50 条' },
    { value: '100', label: '100 条' },
    { value: 'all', label: '全部' },
];

const rankModes = [
    { key: 'all', label: '总排行' },
    { key: 'week', label: '本周' },
    { key: 'month', label: '本月' },
    { key: 'custom', label: '自定义' },
];
const scopeModes = [
    { key: 'all', label: '总榜' },
    { key: 'college', label: '学院榜' },
    { key: 'grade', label: '年级榜' },
    { key: 'class', label: '班级榜' },
];

const isAllMode = computed(() => props.ranking.rank_period === 'all');
const topThree = computed(() => (props.leaderboard.current_page === 1 ? props.leaderboard.data.slice(0, 3) : []));
const startRank = computed(() => (props.leaderboard.current_page - 1) * props.leaderboard.per_page);

const colleges = computed(() => props.filterOptions.colleges ?? []);
const allMajors = computed(() => props.filterOptions.majors ?? []);
const allClasses = computed(() => props.filterOptions.classes ?? []);
const majorChoices = computed(() =>
    allMajors.value.filter((item) => !collegeId.value || item.college_id === collegeId.value),
);
const gradeChoices = computed(() => {
    const values = allClasses.value
        .filter((item) => !collegeId.value || item.college_id === collegeId.value)
        .filter((item) => !majorId.value || item.major_id === majorId.value)
        .map((item) => item.grade)
        .filter(Boolean);

    return [...new Set(values)].sort();
});
const classChoices = computed(() => {
    if (!majorId.value) {
        return [];
    }

    return allClasses.value.filter(
        (item) =>
            (!collegeId.value || item.college_id === collegeId.value) &&
            item.major_id === majorId.value,
    );
});
const canPickClass = computed(() => Boolean(majorId.value));

watch(collegeId, () => {
    if (majorId.value && !majorChoices.value.some((item) => item.id === majorId.value)) {
        majorId.value = '';
    }
    if (grade.value && !gradeChoices.value.includes(grade.value)) {
        grade.value = '';
    }
    if (classId.value && !classChoices.value.some((item) => item.id === classId.value)) {
        classId.value = '';
    }
});

watch(majorId, () => {
    if (grade.value && !gradeChoices.value.includes(grade.value)) {
        grade.value = '';
    }
    if (classId.value && !classChoices.value.some((item) => item.id === classId.value)) {
        classId.value = '';
    }
});

function params(overrides = {}) {
    const data = {
        period: props.period.value,
        rank_period: rankPeriod.value,
        scope: scope.value,
        college_id: scope.value === 'all' ? null : (collegeId.value || null),
        major_id: majorId.value || null,
        grade: scope.value === 'grade' ? (grade.value || null) : null,
        class_id: scope.value === 'class' ? (classId.value || null) : null,
        search: search.value || null,
        per_page: perPage.value,
        ...overrides,
    };

    if ((data.rank_period ?? rankPeriod.value) === 'custom') {
        data.start_date = startDate.value;
        data.end_date = endDate.value;
    }

    return data;
}

function visit(overrides = {}) {
    router.get('/leaderboard', params(overrides), { preserveState: true, preserveScroll: true });
}

function changePeriod(value) {
    visit({ period: value });
}

function setRank(mode) {
    rankPeriod.value = mode;

    if (mode !== 'custom') {
        visit({ rank_period: mode });
    }
}

function setScope(mode) {
    scope.value = mode;

    if (mode === 'all') {
        collegeId.value = '';
        grade.value = '';
        classId.value = '';
    } else if (mode === 'college') {
        grade.value = '';
        classId.value = '';
    } else if (mode === 'grade') {
        classId.value = '';
    }

    visit({
        scope: mode,
        college_id: mode === 'all' ? null : (collegeId.value || null),
        grade: mode === 'grade' ? (grade.value || null) : null,
        class_id: mode === 'class' ? (classId.value || null) : null,
    });
}

function applyScopeChange() {
    if (majorId.value && !majorChoices.value.some((item) => item.id === majorId.value)) {
        majorId.value = '';
    }
    if (grade.value && !gradeChoices.value.includes(grade.value)) {
        grade.value = '';
    }
    if (classId.value && !classChoices.value.some((item) => item.id === classId.value)) {
        classId.value = '';
    }

    applyFilters();
}

function applyFilters() {
    visit({
        college_id: scope.value === 'all' ? null : (collegeId.value || null),
        major_id: majorId.value || null,
        grade: scope.value === 'grade' ? (grade.value || null) : null,
        class_id: scope.value === 'class' ? (classId.value || null) : null,
        search: search.value || null,
    });
}

function applyCustom() {
    visit({ rank_period: 'custom' });
}

function resetFilters() {
    scope.value = 'all';
    collegeId.value = '';
    majorId.value = '';
    grade.value = '';
    classId.value = '';
    search.value = '';
    router.get('/leaderboard', {
        period: props.period.value,
        rank_period: rankPeriod.value,
        scope: 'all',
        per_page: perPage.value,
    }, { preserveState: true, preserveScroll: true });
}

function changePage(url) {
    router.get(url, {}, { preserveState: true, preserveScroll: true });
}

function changePageSize(value) {
    perPage.value = String(value);
    visit({ per_page: value, page: 1 });
}

const exportUrl = computed(() => {
    const query = new URLSearchParams();
    Object.entries(params()).forEach(([key, value]) => {
        if (value !== null && value !== undefined && value !== '') {
            query.set(key, value);
        }
    });

    return `/admin/leaderboard/export?${query.toString()}`;
});
</script>

<template>
    <Head title="排行榜" />

    <AuthenticatedLayout>
        <PageHeader title="德育分排行榜" :description="`${period.label} · ${scopeLabel} · ${ranking.label}${isAllMode ? '，按累计总分排序' : '，按本期净分排序'}`">
            <template #actions>
                <a v-if="isAdmin" :href="exportUrl" class="btn-secondary">
                    <Download class="h-4 w-4" />
                    导出榜单
                </a>
            </template>
        </PageHeader>

        <Card class="mb-4">
            <div class="flex flex-wrap items-center gap-2">
                <PeriodSelect :model-value="period.value" @update:model-value="changePeriod" />

                <div class="flex rounded-lg bg-slate-100 p-1">
                    <button
                        v-for="mode in rankModes"
                        :key="mode.key"
                        type="button"
                        :class="[
                            'rounded-md px-3 py-1.5 text-xs font-medium transition',
                            ranking.rank_period === mode.key ? 'bg-white text-brand-700 shadow-sm' : 'text-slate-500 hover:text-slate-700',
                        ]"
                        @click="setRank(mode.key)"
                    >
                        {{ mode.label }}
                    </button>
                </div>

                <template v-if="ranking.rank_period === 'custom'">
                    <div class="flex items-center gap-1.5">
                        <CalendarRange class="h-4 w-4 text-slate-400" />
                        <input v-model="startDate" type="date" class="input !w-auto !py-2" />
                        <span class="text-slate-400">至</span>
                        <input v-model="endDate" type="date" class="input !w-auto !py-2" />
                        <button type="button" class="btn-secondary !py-2" @click="applyCustom">应用</button>
                    </div>
                </template>
            </div>

            <div class="mt-3 flex flex-wrap items-center gap-2 border-t border-slate-100 pt-3">
                <span class="text-xs font-medium text-slate-500">排行范围</span>
                <div class="flex rounded-lg bg-slate-100 p-1">
                    <button
                        v-for="mode in scopeModes"
                        :key="mode.key"
                        type="button"
                        :class="[
                            'rounded-md px-3 py-1.5 text-xs font-medium transition',
                            scope === mode.key ? 'bg-white text-brand-700 shadow-sm' : 'text-slate-500 hover:text-slate-700',
                        ]"
                        @click="setScope(mode.key)"
                    >
                        {{ mode.label }}
                    </button>
                </div>
                <span class="rounded-full bg-brand-50 px-2.5 py-1 text-xs font-medium text-brand-700">{{ scopeLabel }}</span>
                <span class="text-xs text-slate-400">共 {{ leaderboard.total }} 名学生</span>
            </div>

            <div class="mt-3 flex flex-wrap items-center gap-2 border-t border-slate-100 pt-3">
                <select v-if="scope !== 'all'" v-model="collegeId" class="input !w-auto min-w-[120px]" @change="applyScopeChange">
                    <option value="">全部学院</option>
                    <option v-for="college in colleges" :key="college.id" :value="college.id">{{ college.name }}</option>
                </select>
                <select v-if="scope === 'grade'" v-model="grade" class="input !w-auto min-w-[110px]" @change="applyFilters">
                    <option value="">全部年级</option>
                    <option v-for="item in gradeChoices" :key="item" :value="item">{{ item }}</option>
                </select>
                <select v-model="majorId" class="input !w-auto min-w-[130px]" @change="applyScopeChange">
                    <option value="">全部专业</option>
                    <option v-for="major in majorChoices" :key="major.id" :value="major.id">{{ major.name }}</option>
                </select>
                <select
                    v-if="scope === 'class'"
                    v-model="classId"
                    class="input !w-auto min-w-[180px] disabled:cursor-not-allowed disabled:bg-slate-50 disabled:text-slate-400"
                    :disabled="!canPickClass"
                    @change="applyFilters"
                >
                    <option value="">{{ canPickClass ? '全部班级' : '请先选择专业' }}</option>
                    <option v-for="item in classChoices" :key="item.id" :value="item.id">
                        {{ item.name }}（{{ item.grade }}）
                    </option>
                </select>
                <div class="relative">
                    <Search class="pointer-events-none absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 text-slate-400" />
                    <input v-model="search" type="text" placeholder="姓名或学号" class="input !w-40 !pl-9" @keyup.enter="applyFilters" />
                </div>
                <button type="button" class="btn-secondary" @click="applyFilters">筛选</button>
                <button type="button" class="btn-ghost" @click="resetFilters">重置</button>
            </div>
        </Card>

        <Card :padding="false">
            <div v-if="topThree.length" class="grid grid-cols-1 gap-3 border-b border-slate-100 px-5 py-5 sm:grid-cols-3">
                <div
                    v-for="(item, index) in topThree"
                    :key="item.id"
                    :class="[
                        'relative overflow-hidden rounded-xl border px-4 py-4',
                        index === 0 ? 'border-amber-200 bg-gradient-to-br from-amber-50 to-white' : 'border-slate-200 bg-white',
                    ]"
                >
                    <div class="flex items-center justify-between">
                        <span :class="['flex h-9 w-9 items-center justify-center rounded-lg text-sm font-semibold', index === 0 ? 'bg-amber-400 text-white' : index === 1 ? 'bg-slate-300 text-white' : 'bg-orange-300 text-white']">
                            {{ index + 1 }}
                        </span>
                        <component :is="index === 0 ? Trophy : Medal" :class="['h-5 w-5', index === 0 ? 'text-amber-500' : 'text-slate-300']" />
                    </div>
                    <p class="mt-3 truncate text-sm font-semibold text-slate-800">{{ item.name }}</p>
                    <p class="truncate text-xs text-slate-400">{{ item.student_id }} · {{ item.class_name }}</p>
                    <p class="mt-3 text-2xl font-semibold text-slate-900">
                        {{ isAllMode ? item.total_score : item.period_score }}
                        <span class="ml-1 text-xs font-normal text-slate-400">{{ isAllMode ? '总分' : '本期净分' }}</span>
                    </p>
                </div>
            </div>

            <div v-if="leaderboard.data.length" class="divide-y divide-slate-100 md:hidden">
                <div v-for="(item, index) in leaderboard.data" :key="item.id" class="flex items-center gap-3 px-4 py-3.5">
                    <span :class="['flex h-8 w-8 shrink-0 items-center justify-center rounded-lg text-xs font-semibold', startRank + index < 3 ? 'bg-amber-100 text-amber-700' : 'bg-slate-100 text-slate-500']">
                        {{ startRank + index + 1 }}
                    </span>
                    <div class="min-w-0 flex-1">
                        <Link v-if="isAdmin" :href="`/admin/students/${item.id}`" class="block truncate text-sm font-medium text-slate-800">{{ item.name }}</Link>
                        <span v-else class="block truncate text-sm font-medium text-slate-800">{{ item.name }}</span>
                        <p class="truncate text-xs text-slate-400">{{ item.student_id }} · {{ item.class_name }}</p>
                    </div>
                    <div class="shrink-0 text-right">
                        <p class="text-base font-semibold text-slate-900">{{ isAllMode ? item.total_score : item.period_score }} 分</p>
                        <p class="text-[11px] text-slate-400">
                            <template v-if="isAllMode">
                                <span class="text-emerald-600">+{{ item.add_score }}</span>
                                <span class="mx-0.5">/</span>
                                <span class="text-rose-600">-{{ item.deduct_score }}</span>
                            </template>
                            <template v-else>本期 · 累计 {{ item.total_score }}</template>
                        </p>
                    </div>
                </div>
            </div>

            <div v-if="leaderboard.data.length" class="table-wrap hidden md:block">
                <table class="data-table">
                    <thead>
                        <tr>
                            <th class="w-16">排名</th>
                            <th>学生</th>
                            <th class="hidden sm:table-cell">班级</th>
                            <th class="hidden lg:table-cell">学院 / 专业 / 年级</th>
                            <template v-if="isAllMode">
                                <th class="text-right">基础分</th>
                                <th class="text-right">加分</th>
                                <th class="text-right">扣分</th>
                                <th class="text-right">总分</th>
                            </template>
                            <template v-else>
                                <th class="text-right">本期加分</th>
                                <th class="text-right">本期扣分</th>
                                <th class="text-right">本期净分</th>
                                <th class="text-right">累计总分</th>
                            </template>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="(item, index) in leaderboard.data" :key="item.id">
                            <td>
                                <span :class="['inline-flex h-7 min-w-7 items-center justify-center rounded-lg px-1.5 text-xs font-semibold', startRank + index < 3 ? 'bg-amber-100 text-amber-700' : 'bg-slate-100 text-slate-500']">
                                    {{ startRank + index + 1 }}
                                </span>
                            </td>
                            <td>
                                <Link v-if="isAdmin" :href="`/admin/students/${item.id}`" class="font-medium text-slate-800 hover:text-brand-600">{{ item.name }}</Link>
                                <span v-else class="font-medium text-slate-800">{{ item.name }}</span>
                                <p class="text-xs text-slate-400">{{ item.student_id }}</p>
                            </td>
                            <td class="hidden whitespace-nowrap sm:table-cell">{{ item.class_name }}</td>
                            <td class="hidden whitespace-nowrap lg:table-cell">{{ item.college }} · {{ item.major }} · {{ item.grade }}</td>
                            <template v-if="isAllMode">
                                <td class="text-right text-slate-500">{{ item.base_score }}</td>
                                <td class="text-right font-medium text-emerald-600">{{ signedScore(item.add_score) }}</td>
                                <td class="text-right font-medium text-rose-600">-{{ item.deduct_score }}</td>
                                <td class="text-right text-base font-semibold text-slate-900">{{ item.total_score }}</td>
                            </template>
                            <template v-else>
                                <td class="text-right font-medium text-emerald-600">{{ signedScore(item.period_add_score) }}</td>
                                <td class="text-right font-medium text-rose-600">-{{ item.period_deduct_score }}</td>
                                <td class="text-right text-base font-semibold" :class="item.period_score >= 0 ? 'text-slate-900' : 'text-rose-600'">
                                    {{ signedScore(item.period_score) }}
                                </td>
                                <td class="text-right text-slate-500">{{ item.total_score }}</td>
                            </template>
                        </tr>
                    </tbody>
                </table>
            </div>
            <EmptyState v-else title="当前范围暂无排行数据" description="请调整排行范围、学期或周期后再试" />

            <Pagination
                :paginator="leaderboard"
                label="名学生"
                :page-size="perPage"
                :page-size-options="pageSizeOptions"
                show-page-size
                @change="changePage"
                @size-change="changePageSize"
            />
        </Card>
    </AuthenticatedLayout>
</template>
