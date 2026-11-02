<script setup>
import { computed, ref } from 'vue';
import { Head, Link, router } from '@inertiajs/vue3';
import { ArrowLeft, Download, RotateCcw } from 'lucide-vue-next';
import AuthenticatedLayout from '@/layouts/AuthenticatedLayout.vue';
import PageHeader from '@/components/ui/PageHeader.vue';
import Card from '@/components/ui/Card.vue';
import Badge from '@/components/ui/Badge.vue';
import ConfirmDialog from '@/components/ui/ConfirmDialog.vue';
import { formatDate, groupScoreRange, signedScore } from '@/lib/format';

const props = defineProps({
    application: { type: Object, required: true },
});

const showWithdraw = ref(false);
const withdrawing = ref(false);

const scoreBounds = computed(() => {
    const values = (props.application.members ?? [])
        .map((member) => Number(member.final_score ?? member.score))
        .filter((value) => Number.isFinite(value));

    return values.length ? { min: Math.min(...values), max: Math.max(...values) } : { min: null, max: null };
});

function withdraw() {
    withdrawing.value = true;
    router.post(`/teacher/group-applications/${props.application.id}/withdraw`, {}, {
        preserveScroll: true,
        onFinish: () => {
            withdrawing.value = false;
            showWithdraw.value = false;
        },
    });
}
</script>

<template>
    <Head :title="application.title" />

    <AuthenticatedLayout>
        <PageHeader :title="application.title" description="集体申请详情与成员明细">
            <template #actions>
                <Link href="/teacher/group-applications" class="btn-secondary">
                    <ArrowLeft class="h-4 w-4" />
                    返回列表
                </Link>
                <button
                    v-if="['pending', 'rejected'].includes(application.status)"
                    type="button"
                    class="btn-ghost"
                    @click="showWithdraw = true"
                >
                    <RotateCcw class="h-4 w-4" />
                    撤回申请
                </button>
            </template>
        </PageHeader>

        <div class="grid grid-cols-1 gap-4 lg:grid-cols-3">
            <Card class="lg:col-span-2" title="申请信息">
                <div class="flex flex-wrap items-center gap-2">
                    <Badge :status="application.status" />
                    <Badge :status="application.category?.type" />
                    <span class="text-xs text-slate-400">{{ application.semester?.name }}</span>
                </div>
                <p class="mt-4 whitespace-pre-line text-sm leading-relaxed text-slate-600">{{ application.description }}</p>

                <dl class="mt-5 grid grid-cols-1 gap-3 border-t border-slate-100 pt-4 text-sm sm:grid-cols-2">
                    <div>
                        <dt class="text-xs text-slate-400">提交教师</dt>
                        <dd class="mt-0.5 font-medium text-slate-800">{{ application.teacher?.name }}（{{ application.teacher?.employee_id }}）</dd>
                    </div>
                    <div>
                        <dt class="text-xs text-slate-400">提交时间</dt>
                        <dd class="mt-0.5 text-slate-700">{{ formatDate(application.created_at, true) }}</dd>
                    </div>
                    <div>
                        <dt class="text-xs text-slate-400">类别</dt>
                        <dd class="mt-0.5 text-slate-700">{{ application.category?.name }}</dd>
                    </div>
                    <div>
                        <dt class="text-xs text-slate-400">成员人数</dt>
                        <dd class="mt-0.5 text-slate-700">{{ application.members?.length ?? 0 }} 人</dd>
                    </div>
                    <div>
                        <dt class="text-xs text-slate-400">分值</dt>
                        <dd class="mt-0.5 text-slate-700">{{ groupScoreRange(scoreBounds.min, scoreBounds.max) }}</dd>
                    </div>
                    <div v-if="application.reviewer">
                        <dt class="text-xs text-slate-400">审核人</dt>
                        <dd class="mt-0.5 text-slate-700">{{ application.reviewer.name }}</dd>
                    </div>
                    <div v-if="application.reviewed_at">
                        <dt class="text-xs text-slate-400">审核时间</dt>
                        <dd class="mt-0.5 text-slate-700">{{ formatDate(application.reviewed_at, true) }}</dd>
                    </div>
                </dl>

                <p v-if="application.review_comment" class="mt-4 rounded-xl bg-slate-50 px-4 py-3 text-sm text-slate-600">
                    审核意见：{{ application.review_comment }}
                </p>

                <a
                    v-if="application.evidence_path"
                    :href="`/teacher/group-applications/${application.id}/evidence`"
                    class="mt-4 inline-flex items-center gap-1.5 text-sm font-medium text-brand-600 hover:text-brand-700"
                >
                    <Download class="h-4 w-4" />
                    下载证明材料
                </a>
            </Card>

            <Card title="成员分值" description="审核通过后自动生成成绩">
                <div class="space-y-2">
                    <div
                        v-for="member in application.members"
                        :key="member.id"
                        class="flex items-center justify-between gap-3 rounded-xl border border-slate-100 px-3.5 py-2.5"
                    >
                        <div class="min-w-0">
                            <p class="truncate text-sm font-medium text-slate-700">{{ member.student?.name }}</p>
                            <p class="text-xs text-slate-400">{{ member.student?.student_id }} · {{ member.student?.class_name }}</p>
                        </div>
                        <span class="shrink-0 text-sm font-semibold text-slate-800">
                            {{ signedScore(member.final_score ?? member.score) }} 分
                        </span>
                    </div>
                </div>
            </Card>
        </div>

        <Card class="mt-4" title="成员明细" :padding="false">
            <div class="table-wrap">
                <table class="data-table">
                    <thead>
                        <tr>
                            <th>学号</th>
                            <th>姓名</th>
                            <th>班级</th>
                            <th>书院</th>
                            <th>年级</th>
                            <th class="text-right">提交分值</th>
                            <th class="text-right">核定分值</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="member in application.members" :key="member.id">
                            <td class="font-mono text-xs text-slate-500">{{ member.student?.student_id }}</td>
                            <td class="font-medium text-slate-700">{{ member.student?.name }}</td>
                            <td>{{ member.student?.class_name }}</td>
                            <td>{{ member.student?.college }}</td>
                            <td>{{ member.student?.grade }}</td>
                            <td class="text-right">{{ signedScore(member.score) }}</td>
                            <td class="text-right font-semibold text-slate-800">
                                {{ member.final_score !== null ? signedScore(member.final_score) : '—' }}
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </Card>

        <ConfirmDialog
            :open="showWithdraw"
            title="撤回集体申请"
            message="确认撤回这条集体申请吗？撤回后需要重新提交。"
            confirm-text="确认撤回"
            danger
            :loading="withdrawing"
            @update:open="showWithdraw = false"
            @confirm="withdraw"
        />
    </AuthenticatedLayout>
</template>
