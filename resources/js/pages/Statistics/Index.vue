<script setup>
import { computed } from 'vue';
import { Head, Link, router } from '@inertiajs/vue3';
import { Download, TrendingDown, TrendingUp, Users } from 'lucide-vue-next';
import AuthenticatedLayout from '@/layouts/AuthenticatedLayout.vue';
import PageHeader from '@/components/ui/PageHeader.vue';
import Card from '@/components/ui/Card.vue';
import StatCard from '@/components/ui/StatCard.vue';
import EmptyState from '@/components/ui/EmptyState.vue';
import PeriodSelect from '@/components/ui/PeriodSelect.vue';
import { formatMonth, signedScore } from '@/lib/format';

const props = defineProps({
    overview: { type: Object, required: true },
    categoryStats: { type: Array, default: () => [] },
    collegeStats: { type: Array, default: () => [] },
    monthlyTrend: { type: Array, default: () => [] },
    topStudents: { type: Array, default: () => [] },
    period: { type: Object, required: true },
});

const maxCategory = computed(() => Math.max(1, ...props.categoryStats.map((item) => item.count)));
const maxCollege = computed(() => Math.max(1, ...props.collegeStats.map((item) => Math.abs(item.score))));
const maxMonth = computed(() => Math.max(1, ...props.monthlyTrend.map((item) => item.total)));

function changePeriod(value) {
    router.get('/admin/statistics', { period: value }, {
        preserveState: true,
        preserveScroll: true,
    });
}
</script>

<template>
    <Head title="数据统计" />

    <AuthenticatedLayout>
        <PageHeader title="数据统计" description="按学期或学年汇总查看申请、类别、书院与月度趋势">
            <template #actions>
                <PeriodSelect :model-value="period.value" @update:model-value="changePeriod" />
                <Link :href="`/admin/leaderboard/export?period=${encodeURIComponent(period.value)}`" class="btn-secondary">
                    <Download class="h-4 w-4" />
                    导出榜单
                </Link>
            </template>
        </PageHeader>

        <div class="mb-4 grid grid-cols-2 gap-3 lg:grid-cols-4">
            <StatCard label="在校学生" :value="overview.student_count" hint="启用账号">
                <template #icon><Users class="h-5 w-5" /></template>
            </StatCard>
            <StatCard label="成绩记录" :value="overview.record_count" hint="当前范围" tone="slate" />
            <StatCard label="累计加分" :value="`+${overview.add_total}`" tone="emerald">
                <template #icon><TrendingUp class="h-5 w-5" /></template>
            </StatCard>
            <StatCard label="累计扣分" :value="`-${overview.deduct_total}`" tone="rose">
                <template #icon><TrendingDown class="h-5 w-5" /></template>
            </StatCard>
        </div>

        <div class="grid grid-cols-1 gap-4 xl:grid-cols-3">
            <Card class="xl:col-span-2" title="类别分布" description="按记录数排序">
                <div v-if="categoryStats.length" class="space-y-4">
                    <div v-for="item in categoryStats" :key="item.name">
                        <div class="flex items-center justify-between text-sm">
                            <span class="text-slate-600">{{ item.name }}</span>
                            <span class="text-xs text-slate-400">
                                {{ item.count }} 条 ·
                                <span :class="item.score >= 0 ? 'text-emerald-600' : 'text-rose-600'">{{ signedScore(item.score) }} 分</span>
                            </span>
                        </div>
                        <div class="mt-1.5 h-2 overflow-hidden rounded-full bg-slate-100">
                            <div
                                :class="['h-full rounded-full', item.type === 'deduct' ? 'bg-rose-400' : 'bg-brand-500']"
                                :style="{ width: `${(item.count / maxCategory) * 100}%` }"
                            />
                        </div>
                    </div>
                </div>
                <EmptyState v-else title="暂无类别数据" />
            </Card>

            <Card title="月度申请趋势" description="最近 12 个月">
                <div v-if="monthlyTrend.length" class="flex h-48 items-end gap-2">
                    <div v-for="item in monthlyTrend" :key="item.month" class="flex min-w-0 flex-1 flex-col items-center gap-1.5">
                        <span class="text-[10px] text-slate-400">{{ item.total }}</span>
                        <div
                            class="w-full rounded-t-md bg-gradient-to-t from-brand-600 to-brand-400"
                            :style="{ height: `${Math.max(6, (item.total / maxMonth) * 140)}px` }"
                        />
                        <span class="whitespace-nowrap text-[9px] text-slate-400">{{ formatMonth(item.month).replace('年', '/').replace('月', '') }}</span>
                    </div>
                </div>
                <EmptyState v-else title="暂无申请数据" />
            </Card>

            <Card title="书院分布" description="按累计净分">
                <div v-if="collegeStats.length" class="space-y-3">
                    <div v-for="item in collegeStats" :key="item.name">
                        <div class="flex items-center justify-between text-xs">
                            <span class="text-slate-600">{{ item.name }} <span class="text-slate-400">{{ item.students }} 人</span></span>
                            <span class="font-semibold text-slate-700">{{ signedScore(item.score) }}</span>
                        </div>
                        <div class="mt-1.5 h-1.5 overflow-hidden rounded-full bg-slate-100">
                            <div class="h-full rounded-full bg-emerald-400" :style="{ width: `${(Math.abs(item.score) / maxCollege) * 100}%` }" />
                        </div>
                    </div>
                </div>
                <EmptyState v-else title="暂无书院数据" />
            </Card>

            <Card class="xl:col-span-2" title="德育分前十名" :padding="false">
                <div v-if="topStudents.length" class="divide-y divide-slate-100 px-4 md:hidden">
                    <div v-for="(item, index) in topStudents" :key="item.id" class="flex items-center gap-3 py-3">
                        <span class="flex h-7 w-7 shrink-0 items-center justify-center rounded-lg bg-slate-100 text-xs font-semibold text-slate-500">
                            {{ index + 1 }}
                        </span>
                        <div class="min-w-0 flex-1">
                            <Link :href="`/admin/students/${item.id}`" class="block truncate text-sm font-medium text-slate-800">{{ item.name }}</Link>
                            <p class="truncate text-xs text-slate-400">{{ item.student_id }} · {{ item.class_name }}</p>
                        </div>
                        <span class="shrink-0 text-base font-semibold text-slate-900">{{ item.total_score }} 分</span>
                    </div>
                </div>
                <div v-if="topStudents.length" class="table-wrap hidden md:block">
                    <table class="data-table">
                        <thead>
                            <tr>
                                <th class="w-16">排名</th>
                                <th>学生</th>
                                <th class="hidden sm:table-cell">班级</th>
                                <th class="text-right">加分</th>
                                <th class="text-right">扣分</th>
                                <th class="text-right">总分</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr v-for="(item, index) in topStudents" :key="item.id">
                                <td>
                                    <span class="inline-flex h-7 min-w-7 items-center justify-center rounded-lg bg-slate-100 px-1.5 text-xs font-semibold text-slate-500">
                                        {{ index + 1 }}
                                    </span>
                                </td>
                                <td>
                                    <Link :href="`/admin/students/${item.id}`" class="font-medium text-slate-800 hover:text-brand-600">{{ item.name }}</Link>
                                    <p class="text-xs text-slate-400">{{ item.student_id }}</p>
                                </td>
                                <td class="hidden sm:table-cell">{{ item.class_name }}</td>
                                <td class="text-right text-emerald-600">{{ signedScore(item.add_score) }}</td>
                                <td class="text-right text-rose-600">-{{ item.deduct_score }}</td>
                                <td class="text-right text-base font-semibold text-slate-900">{{ item.total_score }}</td>
                            </tr>
                        </tbody>
                    </table>
                </div>
                <EmptyState v-else title="暂无排行数据" />
            </Card>
        </div>
    </AuthenticatedLayout>
</template>
