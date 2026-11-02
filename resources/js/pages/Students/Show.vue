<script setup>
import { Head, Link, router } from '@inertiajs/vue3';
import { ArrowLeft } from 'lucide-vue-next';
import AuthenticatedLayout from '@/layouts/AuthenticatedLayout.vue';
import PageHeader from '@/components/ui/PageHeader.vue';
import Card from '@/components/ui/Card.vue';
import Badge from '@/components/ui/Badge.vue';
import EmptyState from '@/components/ui/EmptyState.vue';
import ScoreSummary from '@/components/ScoreSummary.vue';
import PeriodSelect from '@/components/ui/PeriodSelect.vue';
import { formatDate, signedScore, sourceLabels } from '@/lib/format';

const props = defineProps({
    student: { type: Object, required: true },
    summary: { type: Object, required: true },
    period: { type: Object, required: true },
});

function changePeriod(value) {
    router.get(`/admin/students/${props.student.id}`, { period: value }, {
        preserveState: true,
        preserveScroll: true,
    });
}
</script>

<template>
    <Head :title="`${student.name} · 德育分明细`" />

    <AuthenticatedLayout>
        <PageHeader :title="`${student.name} 的德育分`" description="学生个人成绩明细与类别汇总">
            <template #actions>
                <PeriodSelect :model-value="period.value" @update:model-value="changePeriod" />
                <Link href="/leaderboard" class="btn-secondary">
                    <ArrowLeft class="h-4 w-4" />
                    返回排行榜
                </Link>
            </template>
        </PageHeader>

        <div class="grid grid-cols-1 gap-4 lg:grid-cols-3">
            <ScoreSummary
                :base="summary.base_score"
                :add="summary.add_score"
                :deduct="summary.deduct_score"
                :total="summary.total_score"
            />

            <Card class="lg:col-span-2" title="学生信息">
                <dl class="grid grid-cols-1 gap-4 text-sm sm:grid-cols-2 lg:grid-cols-3">
                    <div>
                        <dt class="text-xs text-slate-400">姓名</dt>
                        <dd class="mt-1 font-medium text-slate-800">{{ student.name }}</dd>
                    </div>
                    <div>
                        <dt class="text-xs text-slate-400">学号</dt>
                        <dd class="mt-1 font-mono text-slate-700">{{ student.student_id }}</dd>
                    </div>
                    <div>
                        <dt class="text-xs text-slate-400">班级</dt>
                        <dd class="mt-1 text-slate-700">{{ student.class_name || '—' }}</dd>
                    </div>
                    <div>
                        <dt class="text-xs text-slate-400">书院</dt>
                        <dd class="mt-1 text-slate-700">{{ student.college || '—' }}</dd>
                    </div>
                    <div>
                        <dt class="text-xs text-slate-400">年级</dt>
                        <dd class="mt-1 text-slate-700">{{ student.grade || '—' }}</dd>
                    </div>
                    <div>
                        <dt class="text-xs text-slate-400">记录数</dt>
                        <dd class="mt-1 text-slate-700">{{ summary.record_count }} 条</dd>
                    </div>
                </dl>

                <div v-if="summary.category_breakdown.length" class="mt-5 grid grid-cols-1 gap-3 border-t border-slate-100 pt-4 sm:grid-cols-2">
                    <div v-for="item in summary.category_breakdown" :key="item.name" class="flex items-center justify-between rounded-xl bg-slate-50 px-3.5 py-2.5">
                        <span class="text-sm text-slate-600">{{ item.name }}</span>
                        <span :class="['text-sm font-semibold', item.score >= 0 ? 'text-emerald-600' : 'text-rose-600']">{{ signedScore(item.score) }}</span>
                    </div>
                </div>
            </Card>
        </div>

        <Card class="mt-4" title="成绩明细" :padding="false">
            <div v-if="summary.records.length" class="table-wrap">
                <table class="data-table">
                    <thead>
                        <tr>
                            <th>上报日期</th>
                            <th>类别</th>
                            <th>类型</th>
                            <th>状态</th>
                            <th class="text-right">分值</th>
                            <th>来源</th>
                            <th class="hidden md:table-cell">说明</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="record in summary.records" :key="record.id">
                            <td class="whitespace-nowrap text-slate-400">{{ formatDate(record.applied_on) }}</td>
                            <td class="font-medium text-slate-700">{{ record.category }}</td>
                            <td><Badge :status="record.category_type" /></td>
                            <td>
                                <Badge v-if="record.is_revoked" status="withdrawn">已撤销</Badge>
                                <Badge v-else status="active">有效</Badge>
                            </td>
                            <td :class="['text-right font-semibold', record.is_revoked ? 'text-slate-400 line-through' : record.score >= 0 ? 'text-emerald-600' : 'text-rose-600']">
                                {{ signedScore(record.score) }}
                            </td>
                            <td class="whitespace-nowrap text-slate-500">{{ sourceLabels[record.source_type] ?? record.source_type }}</td>
                            <td class="hidden max-w-[320px] md:table-cell">
                                <span class="line-clamp-2 text-slate-500">{{ record.description }}</span>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
            <EmptyState v-else title="该学年暂无成绩记录" />
        </Card>
    </AuthenticatedLayout>
</template>
