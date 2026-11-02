<script setup>
import { computed, ref } from 'vue';
import { Head, Link, useForm, usePage } from '@inertiajs/vue3';
import { AlertTriangle, ArrowLeft, CalendarRange, Check, Info, Search, Users } from 'lucide-vue-next';
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
    classmates: { type: Array, default: () => [] },
    classInfo: { type: Object, default: () => ({}) },
});

const page = usePage();

const now = new Date();
const today = `${now.getFullYear()}-${String(now.getMonth() + 1).padStart(2, '0')}-${String(now.getDate()).padStart(2, '0')}`;

const form = useForm({
    category_id: '',
    title: '',
    applied_on: today,
    score: '',
    description: '',
    member_ids: [],
    evidence: [],
});

const memberQuery = ref('');

const selected = computed(() => props.categories.find((item) => item.id === form.category_id) ?? null);
const filteredClassmates = computed(() => {
    const keyword = memberQuery.value.trim().toLowerCase();

    if (!keyword) {
        return props.classmates;
    }

    return props.classmates.filter((item) => (
        item.name?.toLowerCase().includes(keyword) || String(item.student_id ?? '').includes(keyword)
    ));
});
const selectedCount = computed(() => form.member_ids.length + 1);
const allFilteredSelected = computed(
    () => filteredClassmates.value.length > 0
        && filteredClassmates.value.every((item) => form.member_ids.includes(item.id)),
);

function selectCategory(category) {
    form.category_id = category.id;

    if (category.default_score) {
        form.score = category.default_score;
    }
}

function toggleMember(id) {
    form.member_ids = form.member_ids.includes(id)
        ? form.member_ids.filter((value) => value !== id)
        : [...form.member_ids, id];
}

function toggleAllFiltered() {
    if (allFilteredSelected.value) {
        const ids = filteredClassmates.value.map((item) => item.id);
        form.member_ids = form.member_ids.filter((value) => !ids.includes(value));
        return;
    }

    const merged = new Set([...form.member_ids, ...filteredClassmates.value.map((item) => item.id)]);
    form.member_ids = [...merged];
}

function submit() {
    form.post('/applications/group', {
        forceFormData: true,
        preserveScroll: true,
    });
}
</script>

<template>
    <Head title="发起集体申请" />

    <AuthenticatedLayout>
        <PageHeader title="发起集体申请" description="选择本班同学共同参与，审核通过后每位成员各获得一次申请分值">
            <template #actions>
                <Link href="/applications" class="btn-secondary">
                    <ArrowLeft class="h-4 w-4" />
                    返回我的申请
                </Link>
            </template>
        </PageHeader>

        <div v-if="!currentSemester" class="mb-4 flex items-start gap-3 rounded-xl border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-700">
            <AlertTriangle class="mt-0.5 h-4 w-4 shrink-0" />
            系统尚未设置当前学期，暂时无法提交集体申请，请联系管理员。
        </div>
        <div v-else-if="!classInfo.class_id" class="mb-4 flex items-start gap-3 rounded-xl border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-700">
            <AlertTriangle class="mt-0.5 h-4 w-4 shrink-0" />
            当前账号尚未分班，无法发起集体申请，请联系管理员。
        </div>

        <form class="grid grid-cols-1 gap-4 lg:grid-cols-3" @submit.prevent="submit">
            <div class="space-y-4 lg:col-span-2">
                <Card title="选择类别" description="志愿服务不能用于集体申请，请通过个人申请逐次提交">
                    <div v-if="categories.length" class="grid grid-cols-1 gap-3 sm:grid-cols-2">
                        <button
                            v-for="category in categories"
                            :key="category.id"
                            type="button"
                            :class="[
                                'relative rounded-xl border p-4 text-left transition',
                                form.category_id === category.id
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
                            </div>
                            <p class="mt-1.5 text-xs leading-relaxed text-slate-500">{{ category.description || '暂无说明' }}</p>
                            <p class="mt-2 text-[11px] text-slate-400">
                                <span v-if="category.default_score">参考分值 {{ category.default_score }} 分</span>
                                <span v-if="category.max_score"> · 单次上限 {{ category.max_score }} 分</span>
                                <span v-if="!category.default_score && !category.max_score">分值由发起人填写，审核时核定</span>
                            </p>
                        </button>
                    </div>
                    <p v-else class="text-sm text-slate-500">暂无可申请的类别，请联系管理员。</p>
                    <p v-if="form.errors.category_id" class="mt-2 text-xs text-rose-600">{{ form.errors.category_id }}</p>
                </Card>

                <Card title="集体信息">
                    <div class="space-y-4">
                        <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                            <Field label="活动名称" :error="form.errors.title" required hint="请填写集体参加的具体活动">
                                <input v-model="form.title" type="text" maxlength="200" placeholder="例如：2026 年暑期社会实践" class="input" />
                            </Field>
                            <Field label="活动日期" :error="form.errors.applied_on" required>
                                <input v-model="form.applied_on" type="date" :max="today" class="input" />
                            </Field>
                        </div>

                        <Field
                            label="集体分值（每人）"
                            :error="form.errors.score"
                            required
                            :hint="selected?.max_score ? `该类别单次最多 ${selected.max_score} 分，审核通过后每位成员各计一次` : '支持 0.5 分，审核通过后每位成员各计一次'"
                        >
                            <div class="relative">
                                <input v-model="form.score" type="number" min="0.5" step="0.5" inputmode="decimal" placeholder="例如 2 或 1.5" class="input !pr-10" />
                                <span class="absolute right-3.5 top-1/2 -translate-y-1/2 text-xs text-slate-400">分</span>
                            </div>
                        </Field>

                        <Field label="活动说明" :error="form.errors.description" required hint="请说明活动内容、时间及集体参与情况">
                            <textarea
                                v-model="form.description"
                                rows="4"
                                maxlength="2000"
                                placeholder="例如：小组成员共同参加暑期社会实践，完成全部调研任务。"
                                class="input resize-none"
                            />
                            <p class="mt-1 text-right text-[11px] text-slate-400">{{ form.description.length }} / 2000</p>
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

                <Card title="选择集体成员" :description="`仅可选择本班同学，发起人自动加入，共 ${selectedCount} 人`">
                    <div class="flex flex-wrap items-center gap-2">
                        <div class="relative min-w-[200px] flex-1">
                            <Search class="pointer-events-none absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 text-slate-400" />
                            <input v-model="memberQuery" type="search" placeholder="搜索姓名或学号" class="input !pl-9" />
                        </div>
                        <button type="button" class="btn-secondary" @click="toggleAllFiltered">
                            {{ allFilteredSelected ? '取消全选' : '全选当前结果' }}
                        </button>
                    </div>

                    <div v-if="filteredClassmates.length" class="mt-3 grid grid-cols-1 gap-2 sm:grid-cols-2">
                        <button
                            v-for="item in filteredClassmates"
                            :key="item.id"
                            type="button"
                            :class="[
                                'flex items-center justify-between gap-3 rounded-xl border px-3.5 py-2.5 text-left transition',
                                form.member_ids.includes(item.id)
                                    ? 'border-brand-400 bg-brand-50/50'
                                    : 'border-slate-200 hover:border-slate-300 hover:bg-slate-50',
                            ]"
                            @click="toggleMember(item.id)"
                        >
                            <span class="min-w-0">
                                <span class="block truncate text-sm font-medium text-slate-800">{{ item.name }}</span>
                                <span class="block text-xs text-slate-400">{{ item.student_id }} · {{ item.class_name }}</span>
                            </span>
                            <span :class="[
                                'flex h-5 w-5 shrink-0 items-center justify-center rounded-full border',
                                form.member_ids.includes(item.id)
                                    ? 'border-brand-600 bg-brand-600 text-white'
                                    : 'border-slate-300 text-transparent',
                            ]">
                                <Check class="h-3 w-3" />
                            </span>
                        </button>
                    </div>
                    <p v-else class="mt-3 rounded-xl bg-slate-50 px-4 py-3 text-sm text-slate-500">
                        {{ classmates.length ? '没有匹配的同学' : '本班暂无可选择的同学，请联系管理员核对班级信息' }}
                    </p>
                    <p v-if="form.errors.member_ids" class="mt-2 text-xs text-rose-600">{{ form.errors.member_ids }}</p>
                    <p v-if="form.errors['member_ids.0']" class="mt-2 text-xs text-rose-600">{{ form.errors['member_ids.0'] }}</p>
                </Card>
            </div>

            <div class="space-y-4">
                <Card title="提交信息">
                    <dl class="space-y-3 text-sm">
                        <div class="flex items-center justify-between gap-3">
                            <dt class="text-slate-500">发起人</dt>
                            <dd class="font-medium text-slate-800">{{ page.props.auth.user.name }}</dd>
                        </div>
                        <div class="flex items-center justify-between gap-3">
                            <dt class="text-slate-500">学号</dt>
                            <dd class="text-slate-700">{{ page.props.auth.user.student_id }}</dd>
                        </div>
                        <div class="flex items-center justify-between gap-3">
                            <dt class="text-slate-500">班级</dt>
                            <dd class="text-slate-700">{{ classInfo.class_name || page.props.auth.user.class_name || '—' }}</dd>
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
                            <dd class="max-w-[65%] truncate text-right font-medium text-slate-800">{{ form.title || '未填写' }}</dd>
                        </div>
                        <div class="flex items-center justify-between gap-3">
                            <dt class="text-slate-500">集体人数</dt>
                            <dd class="flex items-center gap-1.5 font-medium text-slate-800">
                                <Users class="h-3.5 w-3.5 text-slate-400" />
                                {{ selectedCount }} 人
                            </dd>
                        </div>
                        <div class="flex items-center justify-between gap-3">
                            <dt class="text-slate-500">每人分值</dt>
                            <dd class="text-lg font-semibold text-slate-900">{{ form.score || 0 }} 分</dd>
                        </div>
                    </dl>

                    <div class="mt-4 rounded-xl bg-slate-50 px-3.5 py-3 text-xs leading-relaxed text-slate-500">
                        <p class="flex items-start gap-1.5">
                            <Info class="mt-0.5 h-3.5 w-3.5 shrink-0" />
                            审核通过后，发起人与所选成员将各自获得一次申请分值。待审核或已驳回的申请可由发起人撤回。
                        </p>
                    </div>

                    <Button
                        type="submit"
                        block
                        size="lg"
                        class="mt-4"
                        :loading="form.processing"
                        :disabled="!currentSemester || !classInfo.class_id || !form.category_id || form.member_ids.length === 0"
                    >
                        提交集体申请
                    </Button>
                </Card>
            </div>
        </form>
    </AuthenticatedLayout>
</template>
