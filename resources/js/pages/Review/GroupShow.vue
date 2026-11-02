<script setup>
import { computed, reactive, ref } from 'vue';
import { Head, Link, router, useForm, usePage } from '@inertiajs/vue3';
import { ArrowLeft, CheckCircle2, Trash2, XCircle } from 'lucide-vue-next';
import AuthenticatedLayout from '@/layouts/AuthenticatedLayout.vue';
import PageHeader from '@/components/ui/PageHeader.vue';
import Card from '@/components/ui/Card.vue';
import Badge from '@/components/ui/Badge.vue';
import Field from '@/components/ui/Field.vue';
import Button from '@/components/ui/Button.vue';
import ConfirmDialog from '@/components/ui/ConfirmDialog.vue';
import EvidenceGallery from '@/components/ui/EvidenceGallery.vue';
import { formatDate, groupScoreRange, signedScore } from '@/lib/format';
import { groupEvidenceFiles } from '@/lib/evidence';

const page = usePage();
const canReviewGroups = computed(() => page.props.permissions?.can_review_groups ?? false);
const processing = ref(false);
const cancelOpen = ref(false);
const cancelReason = ref('');
const cancelProcessing = ref(false);
const pageErrors = computed(() => Object.values(page.props.errors ?? {}).filter(Boolean));

const props = defineProps({
    application: { type: Object, required: true },
    officerMode: { type: Boolean, default: false },
    canDelete: { type: Boolean, default: false },
});

const deleteOpen = ref(false);
const deleting = ref(false);

const form = useForm({
    review_comment: '',
});

const scores = reactive({});

props.application.members.forEach((member) => {
    scores[member.id] = member.final_score ?? member.score;
});

const members = computed(() => props.application.members ?? []);
const scoreBounds = computed(() => {
    const values = members.value
        .map((member) => Number(member.final_score ?? member.score))
        .filter((value) => Number.isFinite(value));

    return values.length ? { min: Math.min(...values), max: Math.max(...values) } : { min: null, max: null };
});
const isPending = computed(() => props.application.status === 'pending');
const isApproved = computed(() => props.application.status === 'approved');
const isStudentOrigin = computed(() => props.application.origin === 'student');
const submitter = computed(() => (isStudentOrigin.value ? props.application.initiator : props.application.teacher));
const evidenceFiles = computed(() => groupEvidenceFiles(props.application));
const reviewBase = computed(() => (props.officerMode ? '/officer/review' : '/admin/review'));

function approve() {
    processing.value = true;
    router.put(`/admin/group-applications/${props.application.id}/approve`, {
        final_scores: { ...scores },
        review_comment: form.review_comment,
    }, {
        preserveScroll: true,
        onFinish: () => { processing.value = false; },
    });
}

function reject() {
    processing.value = true;
    router.put(`/admin/group-applications/${props.application.id}/reject`, {
        review_comment: form.review_comment,
    }, {
        preserveScroll: true,
        onFinish: () => { processing.value = false; },
    });
}

function openCancel() {
    cancelReason.value = '';
    cancelProcessing.value = false;
    cancelOpen.value = true;
}

function submitCancel() {
    cancelProcessing.value = true;
    router.put(`/admin/group-applications/${props.application.id}/cancel`, {
        cancel_reason: cancelReason.value,
    }, {
        preserveScroll: true,
        onFinish: () => {
            cancelProcessing.value = false;
            cancelOpen.value = false;
        },
    });
}

function submitDelete() {
    deleting.value = true;
    router.delete(`/admin/group-applications/${props.application.id}`, {
        preserveScroll: true,
        onFinish: () => {
            deleting.value = false;
            deleteOpen.value = false;
        },
    });
}
</script>

<template>
    <Head :title="`审核 · ${application.title}`" />

    <AuthenticatedLayout>
        <PageHeader
            :title="application.title"
            :description="officerMode ? '本班集体申请详情' : '集体申请审核与成员分值核定'"
        >
            <template #actions>
                <button v-if="canDelete" type="button" class="btn-ghost text-rose-600 hover:text-rose-700" @click="deleteOpen = true">
                    <Trash2 class="h-4 w-4" />
                    删除记录
                </button>
                <Link :href="reviewBase" class="btn-secondary">
                    <ArrowLeft class="h-4 w-4" />
                    返回审核中心
                </Link>
            </template>
        </PageHeader>

        <div v-if="pageErrors.length" class="mb-4 rounded-xl border border-rose-200 bg-rose-50 px-4 py-3 text-sm text-rose-700">
            {{ pageErrors[0] }}
        </div>

        <div class="grid grid-cols-1 gap-4 lg:grid-cols-3">
            <Card class="min-w-0 lg:col-span-2" title="申请信息">
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

            <Card v-if="isPending && canReviewGroups" title="审核操作" description="通过后为每位成员生成成绩">
                <Field label="审核意见" :error="form.errors.review_comment" hint="可选，驳回原因可以留空">
                    <textarea v-model="form.review_comment" rows="4" class="input resize-none" placeholder="填写审核说明或驳回原因（可留空）" />
                </Field>

                <div class="mt-4 space-y-2">
                    <Button block size="lg" :loading="processing" @click="approve">
                        <CheckCircle2 class="h-4 w-4" />
                        通过并计分（{{ members.length }} 人）
                    </Button>
                    <Button block variant="danger" :loading="processing" @click="reject">
                        <XCircle class="h-4 w-4" />
                        驳回申请
                    </Button>
                </div>
            </Card>

            <Card v-else-if="isPending" title="只读查看">
                <div class="flex items-start gap-2 rounded-xl bg-amber-50 px-4 py-3 text-sm text-amber-700">
                    {{ officerMode
                        ? '班干部可查看本班集体申请，审核操作由学院管理员完成。'
                        : '辅导员可查看涉及所管辖班级的集体申请，审核操作由学院管理员完成。' }}
                </div>
            </Card>

            <Card v-else title="审核结果">
                <div class="flex items-center gap-2">
                    <Badge :status="application.status" />
                    <span class="text-sm text-slate-500">{{ formatDate(application.reviewed_at, true) }}</span>
                </div>
                <p class="mt-2 text-sm text-slate-500">审核人：{{ application.reviewer?.name ?? '—' }}</p>
                <p v-if="application.review_comment" class="mt-3 text-sm leading-relaxed text-slate-600">
                    {{ application.review_comment }}
                </p>
                <p v-else class="mt-3 text-sm text-slate-400">无审核意见</p>

                <div v-if="isApproved && canReviewGroups" class="mt-4 rounded-xl border border-rose-200 bg-rose-50 px-4 py-3">
                    <p class="text-xs leading-relaxed text-rose-700">
                        取消通过后，将为每位成员扣回已生成的分值，申请退回待审核状态。
                    </p>
                    <Button variant="danger" class="mt-2.5" @click="openCancel">
                        <XCircle class="h-4 w-4" />
                        取消通过并扣回分值
                    </Button>
                </div>
            </Card>
        </div>

        <Card class="mt-4" title="成员分值核定" :description="isPending ? '可直接修改每位成员的核定分值' : '审核通过后的最终分值'" :padding="false">
            <div class="table-wrap">
                <table class="data-table">
                    <thead>
                        <tr>
                            <th>学号</th>
                            <th>姓名</th>
                            <th class="hidden sm:table-cell">班级</th>
                            <th class="hidden md:table-cell">书院</th>
                            <th class="text-right">提交分值</th>
                            <th class="w-32 text-right">核定分值</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="member in members" :key="member.id">
                            <td class="font-mono text-xs text-slate-500">{{ member.student?.student_id }}</td>
                            <td class="font-medium text-slate-700">{{ member.student?.name }}</td>
                            <td class="hidden sm:table-cell">{{ member.student?.class_name }}</td>
                            <td class="hidden md:table-cell">{{ member.student?.college }}</td>
                            <td class="text-right">{{ signedScore(member.score) }}</td>
                            <td class="text-right">
                                <input
                                    v-if="isPending && canReviewGroups"
                                    v-model.number="scores[member.id]"
                                    type="number"
                                    min="0.5"
                                    step="0.5"
                                    inputmode="decimal"
                                    class="input !w-24 !px-2.5 !py-1.5 text-right text-sm"
                                />
                                <span v-else class="font-semibold text-slate-800">
                                    {{ member.final_score !== null ? signedScore(member.final_score) : '—' }}
                                </span>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </Card>

        <ConfirmDialog
            :open="cancelOpen"
            title="取消集体申请通过"
            :message="`确认取消“${application.title}”的通过状态吗？${members.length} 名成员已生成的分值将全部扣回，申请退回待审核。`"
            confirm-text="取消通过并扣回分值"
            danger
            :loading="cancelProcessing"
            @update:open="cancelOpen = false"
            @confirm="submitCancel"
        >
            <div class="mt-3">
                <Field label="取消原因" hint="可选，将记录在申请与操作日志中">
                    <textarea v-model="cancelReason" rows="3" maxlength="400" class="input resize-none" placeholder="填写取消原因（可留空）" />
                </Field>
            </div>
        </ConfirmDialog>

        <ConfirmDialog
            :open="deleteOpen"
            title="删除集体申请记录"
            :message="`确认删除“${application.title}”吗？${isApproved ? `${members.length} 名成员已生成的记录将全部扣回，` : ''}删除后不可恢复。`"
            confirm-text="确认删除"
            danger
            :loading="deleting"
            @update:open="deleteOpen = false"
            @confirm="submitDelete"
        />
    </AuthenticatedLayout>
</template>
