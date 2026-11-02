<script setup>
import { computed } from 'vue';
import { Head, Link, router } from '@inertiajs/vue3';
import { Download, FileText, ShieldAlert, TrendingDown, TrendingUp } from 'lucide-vue-next';
import AuthenticatedLayout from '@/layouts/AuthenticatedLayout.vue';
import PageHeader from '@/components/ui/PageHeader.vue';
import Card from '@/components/ui/Card.vue';
import Badge from '@/components/ui/Badge.vue';
import EmptyState from '@/components/ui/EmptyState.vue';
import ScoreSummary from '@/components/ScoreSummary.vue';
import PeriodSelect from '@/components/ui/PeriodSelect.vue';
import { formatDate, signedScore, sourceLabels } from '@/lib/format';

const props = defineProps({
    summary: { type: Object, required: true },
    period: { type: Object, required: true },
});

const exportUrl = computed(() => `/my-scores/export?period=${encodeURIComponent(props.period.value)}`);

function changePeriod(value) {
    router.get('/my-scores', value ? { period: value } : {}, {
        preserveState: true,
        preserveScroll: true,
    });
}
</script>

<template>
    <Head title="我的德育分" />

    <AuthenticatedLayout>
        <PageHeader title="我的德育分" :description="`${period.label} · 基础分 + 加分 - 扣分`">
            <template #actions>
                <PeriodSelect :model-value="period.value" @update:model-value="changePeriod" />
                <a :href="exportUrl" class="btn-secondary">
                    <Download class="h-4 w-4" />
                    导出明细
                </a>
                <Link href="/appeals" class="btn-secondary">
                    <ShieldAlert class="h-4 w-4" />
                    扣分申诉
                </Link>
                <Link href="/applications/create" class="btn-primary">
                    <FileText class="h-4 w-4" />
                    提交申请
                </Link>
            </template>
        </PageHeader>

        <div class="grid grid-cols-1 gap-4 lg:grid-cols-3">
            <div class="lg:col-span-1">
                <ScoreSummary
                    :base="summary.base_score"
                    :add="summary.add_score"
                    :deduct="summary.deduct_score"
                    :total="summary.total_score"
                />
            </div>

            <Card class="lg:col-span-2" title="类别汇总" :description="`${period.label} 的加分与扣分统计`">
                <div v-if="summary.category_breakdown.length" class="grid grid-cols-1 gap-3 sm:grid-cols-2">
                    <div v-for="item in summary.category_breakdown" :key="item.name" class="rounded-xl border border-slate-100 px-4 py-3.5">
                        <div class="flex items-center justify-between gap-2">
                            <div class="flex min-w-0 items-center gap-2">
                                <component :is="item.type === 'deduct' ? TrendingDown : TrendingUp" :class="['h-4 w-4 shrink-0', item.type === 'deduct' ? 'text-rose-500' : 'text-emerald-500']" />
                                <span class="truncate text-sm font-medium text-slate-700">{{ item.name }}</span>
                            </div>
                            <span :class="['text-sm font-semibold', item.score >= 0 ? 'text-emerald-600' : 'text-rose-600']">
                                {{ signedScore(item.score) }}
                            </span>
                        </div>
                        <p class="mt-1.5 text-xs text-slate-400">共 {{ item.count }} 条记录</p>
                    </div>
                </div>
                <EmptyState v-else title="当前范围暂无成绩" description="提交申请并通过审核后，成绩会显示在这里">
                    <Link href="/applications/create" class="btn-primary">提交第一条申请</Link>
                </EmptyState>
            </Card>
        </div>

        <Card class="mt-4" title="成绩明细" :description="`有效记录 ${summary.record_count} 条${summary.revoked_count ? `，已撤销 ${summary.revoked_count} 条` : ''}`" :padding="false">
            <div v-if="summary.records.length" class="divide-y divide-slate-100 px-4 md:hidden">
                <div v-for="record in summary.records" :key="record.id" :class="['px-1 py-3.5', record.is_revoked ? 'opacity-55' : '']">
                    <div class="flex items-start justify-between gap-3">
                        <div class="min-w-0">
                            <div class="flex flex-wrap items-center gap-1.5">
                                <span class="text-sm font-medium text-slate-800">{{ record.category }}</span>
                                <Badge :status="record.category_type" />
                                <Badge v-if="record.is_revoked" status="withdrawn">已撤销</Badge>
                            </div>
                            <p class="mt-0.5 text-xs text-slate-400">
                                {{ formatDate(record.applied_on) }} · {{ sourceLabels[record.source_type] ?? record.source_type }} · {{ record.semester }}
                            </p>
                        </div>
                        <span :class="['shrink-0 text-base font-semibold', record.is_revoked ? 'text-slate-400 line-through' : record.score >= 0 ? 'text-emerald-600' : 'text-rose-600']">
                            {{ signedScore(record.score) }}
                        </span>
                    </div>
                    <p class="mt-1.5 text-xs leading-relaxed text-slate-500">{{ record.description }}</p>
                    <p v-if="record.revoke_reason" class="mt-1 text-[11px] text-slate-400">{{ record.revoke_reason }}</p>
                </div>
            </div>

            <div v-if="summary.records.length" class="table-wrap hidden md:block">
                <table class="data-table">
                    <thead>
                        <tr>
                            <th>上报日期</th>
                            <th>类别</th>
                            <th>类型</th>
                            <th class="text-right">分值</th>
                            <th>来源</th>
                            <th>学期</th>
                            <th>状态</th>
                            <th>说明</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="record in summary.records" :key="record.id" :class="record.is_revoked ? 'opacity-55' : ''">
                            <td class="whitespace-nowrap text-slate-400">{{ formatDate(record.applied_on) }}</td>
                            <td class="font-medium text-slate-700">{{ record.category }}</td>
                            <td><Badge :status="record.category_type" /></td>
                            <td :class="['whitespace-nowrap text-right font-semibold', record.is_revoked ? 'text-slate-400 line-through' : record.score >= 0 ? 'text-emerald-600' : 'text-rose-600']">
                                {{ signedScore(record.score) }}
                            </td>
                            <td class="whitespace-nowrap text-slate-500">{{ sourceLabels[record.source_type] ?? record.source_type }}</td>
                            <td class="whitespace-nowrap text-slate-500">{{ record.semester }}</td>
                            <td>
                                <Badge v-if="record.is_revoked" status="withdrawn">已撤销</Badge>
                                <Badge v-else status="active">有效</Badge>
                            </td>
                            <td class="max-w-[280px] truncate text-slate-500">{{ record.description }}</td>
                        </tr>
                    </tbody>
                </table>
            </div>
            <EmptyState v-else title="暂无成绩明细" />
        </Card>
    </AuthenticatedLayout>
</template>
