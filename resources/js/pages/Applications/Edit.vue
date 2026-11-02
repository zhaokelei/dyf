<script setup>
import { computed, ref } from 'vue';
import { Head, Link, useForm } from '@inertiajs/vue3';
import { ArrowLeft, Info, RotateCcw, Save, Trash2 } from 'lucide-vue-next';
import AuthenticatedLayout from '@/layouts/AuthenticatedLayout.vue';
import PageHeader from '@/components/ui/PageHeader.vue';
import Card from '@/components/ui/Card.vue';
import Badge from '@/components/ui/Badge.vue';
import Field from '@/components/ui/Field.vue';
import FileInput from '@/components/ui/FileInput.vue';
import Button from '@/components/ui/Button.vue';
import { formatDate } from '@/lib/format';

const props = defineProps({
    application: { type: Object, required: true },
    today: { type: String, default: '' },
});

const existing = computed(() => props.application.evidences ?? []);
const keptIds = ref(existing.value.map((file) => file.id));
const newFiles = ref([]);

const form = useForm({
    activity_name: props.application.activity_name ?? '',
    applied_on: String(props.application.applied_on ?? '').slice(0, 10),
    description: props.application.description ?? '',
    keep_evidence: [...keptIds.value],
    evidence: [],
});

const totalEvidence = computed(() => keptIds.value.length + newFiles.value.length);
const evidenceError = computed(
    () => form.errors.evidence || form.errors['evidence.0'] || form.errors.keep_evidence || '',
);
const willResubmit = computed(() => ['rejected', 'withdrawn'].includes(props.application.status));

function previewUrl(file) {
    return `/applications/${props.application.id}/evidences/${file.id}/preview`;
}

function toggleKeep(file) {
    keptIds.value = keptIds.value.includes(file.id)
        ? keptIds.value.filter((id) => id !== file.id)
        : [...keptIds.value, file.id];
}

function onFiles(files) {
    newFiles.value = files;
    form.evidence = files;
}

function submit() {
    form.transform((data) => ({
        ...data,
        keep_evidence: [...keptIds.value],
        evidence: newFiles.value,
    })).put(`/applications/${props.application.id}`, {
        forceFormData: true,
        preserveScroll: true,
    });
}
</script>

<template>
    <Head :title="`修改申请 · ${application.activity_name || application.category?.name}`" />

    <AuthenticatedLayout>
        <PageHeader title="修改申请" description="仅可修改标题、说明、日期与证明材料；审核通过后不可修改">
            <template #actions>
                <Link href="/applications" class="btn-secondary">
                    <ArrowLeft class="h-4 w-4" />
                    返回我的申请
                </Link>
            </template>
        </PageHeader>

        <form class="grid grid-cols-1 gap-4 lg:grid-cols-3" @submit.prevent="submit">
            <div class="min-w-0 space-y-4 lg:col-span-2">
                <Card title="申请信息">
                    <div class="flex flex-wrap items-center gap-2">
                        <Badge :status="application.status" />
                        <Badge :status="application.category?.type" />
                        <span class="text-xs text-slate-400">{{ application.category?.name }} · {{ application.semester?.name }}</span>
                    </div>

                    <div v-if="willResubmit" class="mt-3 rounded-xl border border-amber-200 bg-amber-50 px-3.5 py-3 text-xs leading-relaxed text-amber-700">
                        修改已{{ application.status === 'rejected' ? '驳回' : '撤回' }}的申请后，将重新提交审核。
                    </div>

                    <div class="mt-4 space-y-4">
                        <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                            <Field label="活动名称" :error="form.errors.activity_name" required>
                                <input v-model="form.activity_name" type="text" maxlength="200" class="input" />
                            </Field>
                            <Field label="申请日期" :error="form.errors.applied_on" required>
                                <input v-model="form.applied_on" type="date" :max="today" class="input" />
                            </Field>
                        </div>

                        <Field label="申请说明" :error="form.errors.description" required>
                            <textarea v-model="form.description" rows="5" maxlength="500" class="input resize-none" />
                            <p class="mt-1 text-right text-[11px] text-slate-400">{{ form.description.length }} / 500</p>
                        </Field>
                    </div>
                </Card>

                <Card title="证明材料" description="修改后需保留 1–3 张图片，可移除已有图片或补充新图片">
                    <div v-if="existing.length" class="mb-4 grid grid-cols-1 gap-3 sm:grid-cols-2">
                        <div
                            v-for="file in existing"
                            :key="file.id"
                            class="min-w-0 overflow-hidden rounded-xl border bg-slate-100 transition"
                            :class="keptIds.includes(file.id) ? 'border-slate-200' : 'border-dashed border-rose-200 opacity-60'"
                        >
                            <div class="relative aspect-[4/3] w-full">
                                <img :src="previewUrl(file)" :alt="file.name" class="absolute inset-0 h-full w-full object-contain" loading="lazy" />
                            </div>
                            <div class="flex items-center justify-between gap-3 border-t border-slate-200 bg-white px-3 py-2">
                                <span class="truncate text-xs text-slate-500">{{ file.name }}</span>
                                <button
                                    type="button"
                                    class="inline-flex shrink-0 items-center gap-1 text-xs font-medium"
                                    :class="keptIds.includes(file.id) ? 'text-rose-600 hover:text-rose-700' : 'text-brand-600 hover:text-brand-700'"
                                    @click="toggleKeep(file)"
                                >
                                    <RotateCcw v-if="!keptIds.includes(file.id)" class="h-3.5 w-3.5" />
                                    <Trash2 v-else class="h-3.5 w-3.5" />
                                    {{ keptIds.includes(file.id) ? '移除' : '恢复' }}
                                </button>
                            </div>
                        </div>
                    </div>

                    <Field label="补充图片" :error="evidenceError" hint="新上传图片与原保留图片合计 1–3 张，单张不超过 10MB">
                        <FileInput
                            accept=".jpg,.jpeg,.png,.webp"
                            hint="点击选择要补充的证明材料"
                            multiple
                            @change="onFiles"
                        />
                    </Field>

                    <p class="mt-3 text-xs" :class="totalEvidence < 1 || totalEvidence > 3 ? 'text-rose-600' : 'text-slate-400'">
                        当前共 {{ totalEvidence }} 张证明材料{{ totalEvidence > 3 ? '，超出 3 张上限' : '' }}
                    </p>
                </Card>
            </div>

            <div class="space-y-4">
                <Card title="提交修改">
                    <dl class="space-y-3 text-sm">
                        <div class="flex items-center justify-between gap-3">
                            <dt class="text-slate-500">类别</dt>
                            <dd class="font-medium text-slate-800">{{ application.category?.name }}</dd>
                        </div>
                        <div class="flex items-center justify-between gap-3">
                            <dt class="text-slate-500">申请分值</dt>
                            <dd class="font-medium text-slate-800">{{ application.score }} 分</dd>
                        </div>
                        <div class="flex items-center justify-between gap-3">
                            <dt class="text-slate-500">提交时间</dt>
                            <dd class="text-slate-700">{{ formatDate(application.created_at, true) }}</dd>
                        </div>
                        <div v-if="application.reviewed_at" class="flex items-center justify-between gap-3">
                            <dt class="text-slate-500">上次审核</dt>
                            <dd class="text-slate-700">{{ formatDate(application.reviewed_at, true) }}</dd>
                        </div>
                    </dl>

                    <p v-if="application.review_comment" class="mt-4 rounded-xl bg-slate-50 px-3.5 py-3 text-xs leading-relaxed text-slate-500">
                        上次审核意见：{{ application.review_comment }}
                    </p>

                    <div class="mt-4 rounded-xl bg-slate-50 px-3.5 py-3 text-xs leading-relaxed text-slate-500">
                        <p class="flex items-start gap-1.5">
                            <Info class="mt-0.5 h-3.5 w-3.5 shrink-0" />
                            类别与申请分值不可修改；审核通过后记录将锁定。
                        </p>
                    </div>

                    <Button
                        type="submit"
                        block
                        size="lg"
                        class="mt-4"
                        :loading="form.processing"
                        :disabled="totalEvidence < 1 || totalEvidence > 3"
                    >
                        <Save class="h-4 w-4" />
                        保存修改
                    </Button>
                </Card>
            </div>
        </form>
    </AuthenticatedLayout>
</template>
