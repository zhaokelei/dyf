<script setup>
import { computed } from 'vue';
import { Head, Link, useForm } from '@inertiajs/vue3';
import { AlertTriangle, ArrowLeft, Check, Download, Info } from 'lucide-vue-next';
import AuthenticatedLayout from '@/layouts/AuthenticatedLayout.vue';
import PageHeader from '@/components/ui/PageHeader.vue';
import Card from '@/components/ui/Card.vue';
import Badge from '@/components/ui/Badge.vue';
import Field from '@/components/ui/Field.vue';
import FileInput from '@/components/ui/FileInput.vue';
import Button from '@/components/ui/Button.vue';

const props = defineProps({
    categories: { type: Array, default: () => [] },
    currentSemester: { type: Object, default: null },
});

const form = useForm({
    title: '',
    description: '',
    category_id: '',
    members_file: null,
    evidence: null,
});

const selected = computed(() => props.categories.find((item) => item.id === form.category_id) ?? null);

const memberErrors = computed(() => {
    const errors = form.errors.members_file;

    return Array.isArray(errors) ? errors : errors ? [errors] : [];
});

function submit() {
    form.post('/teacher/group-applications', {
        forceFormData: true,
        preserveScroll: true,
    });
}
</script>

<template>
    <Head title="发起集体申请" />

    <AuthenticatedLayout>
        <PageHeader title="发起集体申请" description="上传成员名单，一次为多名学生录入同一活动分数">
            <template #actions>
                <Link href="/teacher/group-applications" class="btn-secondary">
                    <ArrowLeft class="h-4 w-4" />
                    返回列表
                </Link>
                <a href="/teacher/group-applications/template" class="btn-secondary">
                    <Download class="h-4 w-4" />
                    下载名单模板
                </a>
            </template>
        </PageHeader>

        <div v-if="!currentSemester" class="mb-4 flex items-start gap-3 rounded-xl border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-700">
            <AlertTriangle class="mt-0.5 h-4 w-4 shrink-0" />
            系统尚未设置当前学期，暂时无法提交集体申请，请联系管理员。
        </div>

        <form class="grid grid-cols-1 gap-4 lg:grid-cols-3" @submit.prevent="submit">
            <div class="space-y-4 lg:col-span-2">
                <Card title="活动信息">
                    <div class="space-y-4">
                        <Field label="活动名称" :error="form.errors.title" required>
                            <input v-model="form.title" type="text" maxlength="200" placeholder="例如：2026 年秋季校级志愿服务活动" class="input" />
                        </Field>

                        <Field label="活动说明" :error="form.errors.description" required>
                            <textarea
                                v-model="form.description"
                                rows="4"
                                maxlength="2000"
                                placeholder="请说明活动背景、时间、参与要求等。"
                                class="input resize-none"
                            />
                        </Field>

                        <Field label="计分类别" :error="form.errors.category_id" required>
                            <div class="grid grid-cols-1 gap-2 sm:grid-cols-2">
                                <button
                                    v-for="category in categories"
                                    :key="category.id"
                                    type="button"
                                    :class="[
                                        'flex items-center justify-between rounded-xl border px-4 py-3 text-left transition',
                                        form.category_id === category.id
                                            ? 'border-brand-500 bg-brand-50/40 ring-2 ring-brand-500/15'
                                            : 'border-slate-200 hover:border-slate-300',
                                    ]"
                                    @click="form.category_id = category.id"
                                >
                                    <span>
                                        <span class="text-sm font-medium text-slate-800">{{ category.name }}</span>
                                        <span class="mt-0.5 block text-xs text-slate-400">{{ category.description }}</span>
                                    </span>
                                    <span class="flex items-center gap-2">
                                        <Badge :status="category.type" />
                                        <Check v-if="form.category_id === category.id" class="h-4 w-4 text-brand-600" />
                                    </span>
                                </button>
                            </div>
                        </Field>
                    </div>
                </Card>

                <Card title="成员名单" description="支持 Excel（.xlsx/.xls）与 CSV，表头需包含学号、姓名、分值">
                    <Field :error="memberErrors[0]" required>
                        <FileInput
                            accept=".xlsx,.xls,.csv"
                            hint="选择成员名单文件（必填）"
                            @change="form.members_file = $event"
                        />
                    </Field>

                    <div v-if="memberErrors.length" class="mt-3 rounded-xl border border-rose-200 bg-rose-50 px-4 py-3 text-xs text-rose-600">
                        <p class="font-medium">名单存在以下问题：</p>
                        <ul class="mt-1.5 list-disc space-y-1 pl-4">
                            <li v-for="(error, index) in memberErrors.slice(0, 10)" :key="index">{{ error }}</li>
                        </ul>
                    </div>

                    <div class="mt-3 flex items-start gap-2 rounded-xl bg-slate-50 px-3.5 py-3 text-xs leading-relaxed text-slate-500">
                        <Info class="mt-0.5 h-3.5 w-3.5 shrink-0" />
                        <span>名单中的学号必须与学生账号一致；分值支持 0.5 的倍数（如 0.5、1、1.5），扣分类别将自动按负分计入。文件内重复学号会被拒绝。</span>
                    </div>
                </Card>

                <Card title="证明材料" description="可选，支持 PDF / JPG / PNG">
                    <Field :error="form.errors.evidence">
                        <FileInput accept=".pdf,.jpg,.jpeg,.png" hint="选择活动通知、签到表等证明材料（可选）" @change="form.evidence = $event" />
                    </Field>
                </Card>
            </div>

            <div class="space-y-4">
                <Card title="提交预览">
                    <dl class="space-y-3 text-sm">
                        <div class="flex items-center justify-between gap-3">
                            <dt class="text-slate-500">提交教师</dt>
                            <dd class="font-medium text-slate-800">{{ $page.props.auth.user.name }}</dd>
                        </div>
                        <div class="flex items-center justify-between gap-3">
                            <dt class="text-slate-500">工号</dt>
                            <dd class="text-slate-700">{{ $page.props.auth.user.employee_id || '—' }}</dd>
                        </div>
                        <div class="flex items-center justify-between gap-3">
                            <dt class="text-slate-500">学期</dt>
                            <dd class="font-medium text-slate-800">{{ currentSemester?.name ?? '未设置' }}</dd>
                        </div>
                        <div class="flex items-center justify-between gap-3">
                            <dt class="text-slate-500">类别</dt>
                            <dd class="font-medium text-slate-800">{{ selected?.name ?? '未选择' }}</dd>
                        </div>
                        <div class="flex items-center justify-between gap-3">
                            <dt class="text-slate-500">成员名单</dt>
                            <dd class="text-slate-700">{{ form.members_file?.name ?? '未选择' }}</dd>
                        </div>
                    </dl>

                    <Button type="submit" block size="lg" class="mt-4" :loading="form.processing" :disabled="!currentSemester">
                        提交集体申请
                    </Button>
                    <p class="mt-2 text-center text-[11px] text-slate-400">提交后需管理员审核，通过后自动为成员生成成绩</p>
                </Card>
            </div>
        </form>
    </AuthenticatedLayout>
</template>
