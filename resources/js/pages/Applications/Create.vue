<script setup>
import { computed } from 'vue';
import { Head, Link, useForm, usePage } from '@inertiajs/vue3';
import { AlertTriangle, ArrowLeft, CalendarRange, Check, Info } from 'lucide-vue-next';
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
    selectedCategory: { type: Number, default: null },
    volunteer: { type: Object, default: () => ({}) },
});

const page = usePage();

const now = new Date();
const today = `${now.getFullYear()}-${String(now.getMonth() + 1).padStart(2, '0')}-${String(now.getDate()).padStart(2, '0')}`;

const initialCategory = props.categories.find(
    (item) => item.id === props.selectedCategory && !(item.code === 'volunteer' && props.volunteer?.limit_reached),
) ?? null;

const form = useForm({
    category_id: initialCategory?.id ?? '',
    activity_name: '',
    applied_on: today,
    score: initialCategory?.code === 'volunteer' ? 0 : '',
    description: '',
    evidence: [],
});

const selected = computed(() => props.categories.find((item) => item.id === form.category_id) ?? null);
const isVolunteerSelected = computed(() => selected.value?.code === 'volunteer');
const volunteerLimitReached = computed(() => Boolean(props.volunteer?.limit_reached));

function selectCategory(category) {
    if (category.code === 'volunteer' && volunteerLimitReached.value) {
        return;
    }

    form.category_id = category.id;

    if (category.code === 'volunteer') {
        form.score = 0;
    } else if (category.default_score) {
        form.score = category.default_score;
    }
}

function submit() {
    form.post('/applications', {
        forceFormData: true,
        preserveScroll: true,
        onSuccess: () => form.reset(),
    });
}
</script>

<template>
    <Head title="提交申请" />

    <AuthenticatedLayout>
        <PageHeader title="提交申请" description="选择活动类别并上传证明材料，提交后等待管理员审核">
            <template #actions>
                <Link href="/applications" class="btn-secondary">
                    <ArrowLeft class="h-4 w-4" />
                    返回我的申请
                </Link>
            </template>
        </PageHeader>

        <div v-if="!currentSemester" class="mb-4 flex items-start gap-3 rounded-xl border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-700">
            <AlertTriangle class="mt-0.5 h-4 w-4 shrink-0" />
            系统尚未设置当前学期，暂时无法提交申请，请联系管理员。
        </div>

        <form class="grid grid-cols-1 gap-4 lg:grid-cols-3" @submit.prevent="submit">
            <div class="space-y-4 lg:col-span-2">
                <Card title="选择类别" description="根据活动级别选择对应类别，扣分类别请谨慎选择">
                    <div v-if="categories.length" class="grid grid-cols-1 gap-3 sm:grid-cols-2">
                        <button
                            v-for="category in categories"
                            :key="category.id"
                            type="button"
                            :disabled="category.code === 'volunteer' && volunteerLimitReached"
                            :class="[
                                'relative rounded-xl border p-4 text-left transition',
                                category.code === 'volunteer' && volunteerLimitReached
                                    ? 'cursor-not-allowed border-slate-200 bg-slate-50 opacity-70'
                                    : form.category_id === category.id
                                        ? 'border-brand-500 bg-brand-50/40 ring-2 ring-brand-500/15'
                                        : 'border-slate-200 hover:border-slate-300 hover:bg-slate-50/60',
                            ]"
                            @click="selectCategory(category)"
                        >
                            <span
                                v-if="form.category_id === category.id"
                                class="absolute right-3 top-3 flex h-5 w-5 items-center justify-center rounded-full bg-brand-600 text-white"
                            >
                                <Check class="h-3 w-3" />
                            </span>
                            <div class="flex flex-wrap items-center gap-2">
                                <span class="text-sm font-semibold text-slate-800">{{ category.name }}</span>
                                <Badge :status="category.type" />
                                <span v-if="category.code === 'volunteer' && volunteerLimitReached" class="rounded-full bg-rose-100 px-2 py-0.5 text-[11px] font-medium text-rose-600">
                                    已达上限
                                </span>
                            </div>
                            <p class="mt-1.5 text-xs leading-relaxed text-slate-500">{{ category.description || '暂无说明' }}</p>
                            <p v-if="category.code === 'volunteer'" class="mt-2 text-[11px] text-amber-600">
                                本学期已通过 {{ volunteer.approved_count ?? 0 }}/{{ volunteer.required_count ?? 4 }} 次
                                <span v-if="volunteerLimitReached"> · 已达上限，不可再申请</span>
                                <span v-else> · 满 {{ volunteer.required_count ?? 4 }} 次自动加 {{ volunteer.bonus_score ?? 6 }} 分</span>
                            </p>
                            <p v-else class="mt-2 text-[11px] text-slate-400">
                                <span v-if="category.default_score">参考分值 {{ category.default_score }} 分</span>
                                <span v-if="category.max_score"> · 单次上限 {{ category.max_score }} 分</span>
                                <span v-if="!category.default_score && !category.max_score">分值由学生填写，管理员审核时核定</span>
                            </p>
                        </button>
                    </div>
                    <p v-else class="text-sm text-slate-500">暂无可申请的类别，请联系管理员。</p>
                    <p v-if="form.errors.category_id" class="mt-2 text-xs text-rose-600">{{ form.errors.category_id }}</p>
                </Card>

                <Card title="申请信息">
                    <div class="space-y-4">
                        <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                            <Field label="活动名称" :error="form.errors.activity_name" required hint="请填写参加的具体活动或事项">
                                <input
                                    v-model="form.activity_name"
                                    type="text"
                                    maxlength="200"
                                    placeholder="例如：2026 年校级暑期社会实践"
                                    class="input"
                                />
                            </Field>

                            <Field label="申请日期" :error="form.errors.applied_on" required hint="精确到日期">
                                <input v-model="form.applied_on" type="date" :max="today" class="input" />
                            </Field>
                        </div>

                        <Field v-if="!isVolunteerSelected" label="申请分值" :error="form.errors.score" required :hint="selected?.max_score ? `该类别单次最多 ${selected.max_score} 分` : '支持 0.5 分，如 5 或 4.5'">
                            <div class="relative">
                                <input
                                    v-model="form.score"
                                    type="number"
                                    min="0.5"
                                    step="0.5"
                                    inputmode="decimal"
                                    placeholder="例如 5 或 4.5"
                                    class="input !pr-10"
                                />
                                <span class="absolute right-3.5 top-1/2 -translate-y-1/2 text-xs text-slate-400">分</span>
                            </div>
                        </Field>

                        <div v-else class="rounded-xl border border-amber-200 bg-amber-50 px-3.5 py-3 text-xs leading-relaxed text-amber-700">
                            <p class="font-medium">志愿服务申请固定为 0 分，无需填写分值。</p>
                            <p class="mt-1">
                                本学期已通过 {{ volunteer.approved_count ?? 0 }}/{{ volunteer.required_count ?? 4 }} 次，
                                满 {{ volunteer.required_count ?? 4 }} 次后系统自动加 {{ volunteer.bonus_score ?? 6 }} 分；
                                达到上限后不可再申请，新学期自动重置。
                            </p>
                        </div>

                        <Field label="申请说明" :error="form.errors.description" required hint="请补充活动时间及本人参与情况">
                            <textarea
                                v-model="form.description"
                                rows="4"
                                maxlength="500"
                                placeholder="例如：参加 2026 年校级暑期社会实践，担任小组负责人，完成全部任务。"
                                class="input resize-none"
                            />
                            <p class="mt-1 text-right text-[11px] text-slate-400">{{ form.description.length }} / 500</p>
                        </Field>

                        <Field label="证明材料" :error="form.errors.evidence || form.errors['evidence.0']" required hint="上传 1–3 张 JPG、PNG、WebP 图片，单张不超过 10MB">
                            <FileInput
                                accept=".jpg,.jpeg,.png,.webp"
                                hint="点击选择证明材料（最少 1 张，最多 3 张）"
                                multiple
                                @change="form.evidence = $event"
                            />
                        </Field>
                    </div>
                </Card>
            </div>

            <div class="space-y-4">
                <Card title="提交信息">
                    <dl class="space-y-3 text-sm">
                        <div class="flex items-center justify-between gap-3">
                            <dt class="text-slate-500">申请人</dt>
                            <dd class="font-medium text-slate-800">{{ page.props.auth.user.name }}</dd>
                        </div>
                        <div class="flex items-center justify-between gap-3">
                            <dt class="text-slate-500">学号</dt>
                            <dd class="text-slate-700">{{ page.props.auth.user.student_id }}</dd>
                        </div>
                        <div class="flex items-center justify-between gap-3">
                            <dt class="text-slate-500">班级</dt>
                            <dd class="text-slate-700">{{ page.props.auth.user.class_name || '—' }}</dd>
                        </div>
                        <div class="flex items-center justify-between gap-3">
                            <dt class="text-slate-500">学年</dt>
                            <dd class="flex items-center gap-1.5 font-medium text-slate-800">
                                <CalendarRange class="h-3.5 w-3.5 text-slate-400" />
                                {{ currentSemester?.name ?? '未设置' }}
                            </dd>
                        </div>
                        <div class="flex items-center justify-between gap-3">
                            <dt class="text-slate-500">类别</dt>
                            <dd class="font-medium text-slate-800">{{ selected?.name ?? '未选择' }}</dd>
                        </div>
                        <div class="flex items-center justify-between gap-3">
                            <dt class="text-slate-500">活动名称</dt>
                            <dd class="max-w-[65%] truncate text-right font-medium text-slate-800">{{ form.activity_name || '未填写' }}</dd>
                        </div>
                        <div class="flex items-center justify-between gap-3">
                            <dt class="text-slate-500">申请日期</dt>
                            <dd class="font-medium text-slate-800">{{ form.applied_on || '未填写' }}</dd>
                        </div>
                        <div class="flex items-center justify-between gap-3">
                            <dt class="text-slate-500">申请分值</dt>
                            <dd class="text-lg font-semibold text-slate-900">{{ isVolunteerSelected ? 0 : (form.score || 0) }} 分</dd>
                        </div>
                    </dl>

                    <div class="mt-4 rounded-xl bg-slate-50 px-3.5 py-3 text-xs leading-relaxed text-slate-500">
                        <p class="flex items-start gap-1.5">
                            <Info class="mt-0.5 h-3.5 w-3.5 shrink-0" />
                            提交后可在“我的申请”中撤回；管理员审核通过后将自动计入当前学期德育分。
                        </p>
                    </div>

                    <Button type="submit" block size="lg" class="mt-4" :loading="form.processing" :disabled="!currentSemester || !form.category_id">
                        提交申请
                    </Button>
                </Card>
            </div>
        </form>
    </AuthenticatedLayout>
</template>
