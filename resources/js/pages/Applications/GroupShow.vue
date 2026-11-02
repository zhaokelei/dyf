<script setup>
import { computed, ref } from 'vue';
import { Head, Link, router } from '@inertiajs/vue3';
import { ArrowLeft, RotateCcw } from 'lucide-vue-next';
import AuthenticatedLayout from '@/layouts/AuthenticatedLayout.vue';
import PageHeader from '@/components/ui/PageHeader.vue';
import Card from '@/components/ui/Card.vue';
import Badge from '@/components/ui/Badge.vue';
import ConfirmDialog from '@/components/ui/ConfirmDialog.vue';
import EvidenceGallery from '@/components/ui/EvidenceGallery.vue';
import { formatDate, groupScoreRange, signedScore } from '@/lib/format';
import { groupEvidenceFiles } from '@/lib/evidence';

const props = defineProps({
    application: { type: Object, required: true },
    canWithdraw: { type: Boolean, default: false },
    isInitiator: { type: Boolean, default: false },
});

const withdrawOpen = ref(false);
const withdrawing = ref(false);

const members = computed(() => props.application.members ?? []);
const evidenceFiles = computed(() => groupEvidenceFiles(props.application));
const isStudentOrigin = computed(() => props.application.origin === 'student');
const submitter = computed(() => (isStudentOrigin.value ? props.application.initiator : props.application.teacher));
const isApproved = computed(() => props.application.status === 'approved');
const scoreBounds = computed(() => {
    const values = members.value
        .map((member) => Number(member.final_score ?? member.score))
        .filter((value) => Number.isFinite(value));

    return values.length ? { min: Math.min(...values), max: Math.max(...values) } : { min: null, max: null };
});

function submitWithdraw() {
    withdrawing.value = true;
    router.post(`/applications/group/${props.application.id}/withdraw`, {}, {
        preserveScroll: true,
        onFinish: () => {
            withdrawing.value = false;
            withdrawOpen.value = false;
        },
    });
}
</script>

<template>
    <Head :title="application.title" />

    <AuthenticatedLayout>
        <PageHeader :title="application.title" :description="isStudentOrigin ? '学生集体申请详情' : '教师集体申请详情'">
            <template #actions>
                <Link href="/applications" class="btn-secondary">
                    <ArrowLeft class="h-4 w-4" />
                    返回我的申请
                </Link>
            </template>
        </PageHeader>

        <div class="grid grid-cols-1 gap-4 lg:grid-cols-3">
            <div class="min-w-0 space-y-4 lg:col-span-2">
                <Card title="申请信息">
                    <div class="flex flex-wrap items-center gap-2">
                        <Badge :status="application.status" />
                        <Badge :status="application.category?.type" />
                        <span class="text-xs text-slate-400">{{ application.semester?.name }}</span>
                    </div>
                    <p class="mt-4 whitespace-pre-line text-sm leading-relaxed text-slate-600">{{ application.description }}</p>

                    <dl class="mt-5 grid grid-cols-1 gap-3 border-t border-slate-100 pt-4 text-sm sm:grid-cols-2">
                        <div>
                            <dt class="text-xs text-slate-400">{{ isStudentOrigin ? '发起学生' : '提交教师' }}</dt>
                            <dd class="mt-0.5 font-medium text-slate-800">
                                {{ submitter?.name }}
                                <span class="ml-1 text-xs font-normal text-slate-400">
                                    {{ isStudentOrigin ? submitter?.student_id : submitter?.employee_id }}
                                </span>
                            </dd>
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
                            <dd class="mt-0.5 text-slate-700">{{ members.length }} 人</dd>
                        </div>
                        <div>
                            <dt class="text-xs text-slate-400">分值</dt>
                            <dd class="mt-0.5 text-slate-700">{{ groupScoreRange(scoreBounds.min, scoreBounds.max) }}</dd>
                        </div>
                        <div v-if="application.applied_on">
                            <dt class="text-xs text-slate-400">活动日期</dt>
                            <dd class="mt-0.5 text-slate-700">{{ formatDate(application.applied_on) }}</dd>
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

                    <EvidenceGallery v-if="evidenceFiles.length" class="mt-4" :files="evidenceFiles" />
                </Card>

                <Card title="成员分值" :description="isApproved ? '审核通过后每位成员各计一次核定分值' : '审核通过后，每位成员将各获得一次申请分值'" :padding="false">
                    <div class="table-wrap">
                        <table class="data-table">
                            <thead>
                                <tr>
                                    <th>学号</th>
                                    <th>姓名</th>
                                    <th class="hidden sm:table-cell">班级</th>
                                    <th class="text-right">提交分值</th>
                                    <th class="text-right">核定分值</th>
                                </tr>
                            </thead>
                            <tbody>
                                <tr v-for="member in members" :key="member.id">
                                    <td class="font-mono text-xs text-slate-500">{{ member.student?.student_id }}</td>
                                    <td>
                                        <span class="font-medium text-slate-700">{{ member.student?.name }}</span>
                                        <span v-if="application.initiator_id === member.student_id" class="ml-1.5 rounded-full bg-brand-50 px-2 py-0.5 text-[11px] font-medium text-brand-600">发起人</span>
                                    </td>
                                    <td class="hidden sm:table-cell">{{ member.student?.class_name }}</td>
                                    <td class="text-right">{{ signedScore(member.score) }}</td>
                                    <td class="text-right font-semibold text-slate-800">
                                        {{ member.final_score !== null ? signedScore(member.final_score) : '—' }}
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </Card>
            </div>

            <div class="space-y-4">
                <Card title="申请状态">
                    <div class="flex items-center gap-2">
                        <Badge :status="application.status" />
                        <span class="text-sm text-slate-500">{{ formatDate(application.reviewed_at, true) }}</span>
                    </div>
                    <p class="mt-3 text-xs leading-relaxed text-slate-500">
                        <template v-if="application.status === 'pending'">申请正在等待管理员审核，审核通过后成员分值将自动计入当前学期。</template>
                        <template v-else-if="application.status === 'approved'">申请已通过，每位成员的核定分值均已计入当前学期德育分。</template>
                        <template v-else-if="application.status === 'rejected'">申请已被驳回，可根据审核意见调整后重新发起。</template>
                        <template v-else>申请已撤回。</template>
                    </p>

                    <button
                        v-if="canWithdraw && isInitiator"
                        type="button"
                        class="mt-4 inline-flex items-center gap-1 text-xs font-medium text-slate-400 transition hover:text-rose-600"
                        @click="withdrawOpen = true"
                    >
                        <RotateCcw class="h-3.5 w-3.5" />
                        撤回集体申请
                    </button>
                </Card>
            </div>
        </div>

        <ConfirmDialog
            :open="withdrawOpen"
            title="撤回集体申请"
            :message="`确认撤回“${application.title}”吗？撤回后需要重新发起。`"
            confirm-text="确认撤回"
            danger
            :loading="withdrawing"
            @update:open="withdrawOpen = false"
            @confirm="submitWithdraw"
        />
    </AuthenticatedLayout>
</template>
