<script setup>
import { computed, ref } from 'vue';
import { Head, Link, router, useForm } from '@inertiajs/vue3';
import { Pencil, Plus, RotateCcw, Search, Trash2 } from 'lucide-vue-next';
import AuthenticatedLayout from '@/layouts/AuthenticatedLayout.vue';
import PageHeader from '@/components/ui/PageHeader.vue';
import Card from '@/components/ui/Card.vue';
import Badge from '@/components/ui/Badge.vue';
import EmptyState from '@/components/ui/EmptyState.vue';
import Pagination from '@/components/ui/Pagination.vue';
import Modal from '@/components/ui/Modal.vue';
import ConfirmDialog from '@/components/ui/ConfirmDialog.vue';
import Field from '@/components/ui/Field.vue';
import Button from '@/components/ui/Button.vue';
import { formatDate, signedScore, sourceLabels } from '@/lib/format';
import { usePageSize } from '@/composables/usePageSize';

const props = defineProps({
    records: { type: Object, required: true },
    categories: { type: Array, default: () => [] },
    semesters: { type: Array, default: () => [] },
    students: { type: Array, default: () => [] },
    filters: { type: Object, default: () => ({}) },
    perPage: { type: [String, Number], default: '20' },
    canManageManual: { type: Boolean, default: false },
});

const { pageSize, pageSizeOptions, changePageSize } = usePageSize(props.perPage);
const modalOpen = ref(false);
const editing = ref(null);
const deleteTarget = ref(null);
const hardDeleteTarget = ref(null);
const actionLoading = ref(false);

const now = new Date();
const today = `${now.getFullYear()}-${String(now.getMonth() + 1).padStart(2, '0')}-${String(now.getDate()).padStart(2, '0')}`;

const form = useForm({
    user_id: '',
    category_id: '',
    semester_id: '',
    applied_on: today,
    score: '',
    description: '',
});

const selectedCategory = computed(() => props.categories.find((item) => item.id === form.category_id) ?? null);

function openCreate() {
    editing.value = null;
    form.clearErrors();
    form.defaults({
        user_id: '',
        category_id: '',
        semester_id: props.semesters.find((semester) => semester.is_current)?.id ?? '',
        applied_on: today,
        score: '',
        description: '',
    });
    form.reset();
    modalOpen.value = true;
}

function openEdit(record) {
    editing.value = record;
    form.clearErrors();
    form.defaults({
        user_id: record.user_id,
        category_id: record.category_id,
        semester_id: record.semester_id,
        applied_on: record.applied_on ? String(record.applied_on).slice(0, 10) : today,
        score: Math.abs(record.score),
        description: record.description ?? '',
    });
    form.reset();
    modalOpen.value = true;
}

function submit() {
    const options = {
        preserveScroll: true,
        onSuccess: () => {
            modalOpen.value = false;
        },
    };

    if (editing.value) {
        form.put(`/admin/records/${editing.value.id}`, options);
    } else {
        form.post('/admin/records', options);
    }
}

function destroy() {
    if (!deleteTarget.value) return;

    actionLoading.value = true;
    router.delete(`/admin/records/${deleteTarget.value.id}`, {
        preserveScroll: true,
        onFinish: () => {
            actionLoading.value = false;
            deleteTarget.value = null;
        },
    });
}

function forceDestroy() {
    if (!hardDeleteTarget.value) return;

    actionLoading.value = true;
    router.delete(`/admin/records/${hardDeleteTarget.value.id}/force`, {
        preserveScroll: true,
        onFinish: () => {
            actionLoading.value = false;
            hardDeleteTarget.value = null;
        },
    });
}

function changeFilter(event) {
    const form = event.target.closest('form') ?? event.target;
    const data = Object.fromEntries(new FormData(form).entries());
    data.per_page = pageSize.value;
    router.get('/admin/records', data, { preserveState: true, preserveScroll: true });
}

function changePage(url) {
    router.get(url, {}, { preserveState: true, preserveScroll: true });
}
</script>

<template>
    <Head title="成绩管理" />

    <AuthenticatedLayout>
        <PageHeader title="成绩管理" description="查看与撤销成绩记录；系统管理员可手工录入并永久删除录入记录">
            <template #actions>
                <button v-if="canManageManual" type="button" class="btn-primary" @click="openCreate">
                    <Plus class="h-4 w-4" />
                    录入成绩
                </button>
            </template>
        </PageHeader>

        <Card :padding="false">
            <form class="flex flex-wrap items-center gap-2 border-b border-slate-100 px-5 py-4" @submit.prevent="changeFilter">
                <select name="category_id" class="input !w-auto min-w-[120px]" :value="filters.category_id ?? ''">
                    <option value="">全部类别</option>
                    <option v-for="category in categories" :key="category.id" :value="category.id">{{ category.name }}</option>
                </select>
                <select name="semester_id" class="input !w-auto min-w-[130px]" :value="filters.semester_id ?? ''">
                    <option value="">全部学期</option>
                    <option v-for="semester in semesters" :key="semester.id" :value="semester.id">{{ semester.name }}</option>
                </select>
                <select name="source_type" class="input !w-auto min-w-[120px]" :value="filters.source_type ?? ''">
                    <option value="">全部来源</option>
                    <option value="personal">个人申请</option>
                    <option value="group">集体申请</option>
                    <option value="manual">管理员录入</option>
                </select>
                <div class="relative">
                    <Search class="pointer-events-none absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 text-slate-400" />
                    <input name="search" :value="filters.search ?? ''" placeholder="姓名或学号" class="input !w-40 !pl-9" />
                </div>
                <button type="submit" class="btn-secondary">筛选</button>
                <Link href="/admin/records" class="btn-ghost">重置</Link>
            </form>

            <div v-if="records.data.length" class="divide-y divide-slate-100 px-4 md:hidden">
                <div v-for="record in records.data" :key="record.id" class="py-3.5">
                    <div class="flex items-start justify-between gap-3">
                        <div class="min-w-0">
                            <div class="flex flex-wrap items-center gap-1.5">
                                <Link :href="`/admin/students/${record.user_id}`" class="text-sm font-semibold text-slate-800">
                                    {{ record.user?.name }}
                                </Link>
                                <Badge :status="record.category?.type" />
                                <Badge v-if="record.revoked_at" status="withdrawn">已撤销</Badge>
                            </div>
                            <p class="text-xs text-slate-400">
                                {{ record.user?.student_id }} · {{ record.user?.class_name }} · {{ record.semester?.name }}
                            </p>
                        </div>
                        <span :class="['shrink-0 text-base font-semibold', record.revoked_at ? 'text-slate-400 line-through' : record.score >= 0 ? 'text-emerald-600' : 'text-rose-600']">
                            {{ signedScore(record.score) }}
                        </span>
                    </div>
                    <p class="mt-1 text-sm text-slate-600">{{ record.category?.name }}</p>
                    <p class="mt-0.5 line-clamp-2 text-xs leading-relaxed text-slate-500">{{ record.description || '无说明' }}</p>
                    <p class="mt-1 text-[11px] text-slate-400">
                        上报 {{ formatDate(record.applied_on) }} · {{ sourceLabels[record.source_type] ?? record.source_type }}
                    </p>
                    <div class="mt-2 flex items-center gap-1.5">
                        <button v-if="!record.revoked_at && record.source_type === 'manual' && canManageManual" type="button" class="btn-secondary !px-3 !py-1.5 text-xs" @click="openEdit(record)">
                            <Pencil class="h-3.5 w-3.5" />
                            编辑
                        </button>
                        <button v-if="!record.revoked_at" type="button" class="btn-ghost !px-3 !py-1.5 text-xs hover:!text-rose-600" @click="deleteTarget = record">
                            <RotateCcw class="h-3.5 w-3.5" />
                            撤销
                        </button>
                        <button v-if="canManageManual && record.source_type === 'manual'" type="button" class="btn-ghost !px-3 !py-1.5 text-xs text-rose-600 hover:!text-rose-700" @click="hardDeleteTarget = record">
                            <Trash2 class="h-3.5 w-3.5" />
                            永久删除
                        </button>
                    </div>
                </div>
            </div>

            <div v-if="records.data.length" class="table-wrap hidden md:block">
                <table class="data-table">
                    <thead>
                        <tr>
                            <th>学生</th>
                            <th>类别</th>
                            <th class="text-right">分值</th>
                            <th class="hidden sm:table-cell">学期</th>
                            <th class="hidden md:table-cell">来源</th>
                            <th>状态</th>
                            <th class="hidden lg:table-cell">说明</th>
                            <th class="hidden xl:table-cell">录入人</th>
                            <th class="hidden xl:table-cell">上报日期</th>
                            <th class="text-right">操作</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="record in records.data" :key="record.id">
                            <td>
                                <Link :href="`/admin/students/${record.user_id}`" class="font-medium text-slate-800 hover:text-brand-600">
                                    {{ record.user?.name }}
                                </Link>
                                <p class="text-xs text-slate-400">{{ record.user?.student_id }} · {{ record.user?.class_name }}</p>
                            </td>
                            <td>
                                <div class="flex items-center gap-1.5">
                                    <span class="whitespace-nowrap text-slate-700">{{ record.category?.name }}</span>
                                    <Badge :status="record.category?.type" />
                                </div>
                            </td>
                            <td :class="['text-right font-semibold', record.revoked_at ? 'text-slate-400 line-through' : record.score >= 0 ? 'text-emerald-600' : 'text-rose-600']">
                                {{ signedScore(record.score) }}
                            </td>
                            <td class="hidden whitespace-nowrap sm:table-cell">{{ record.semester?.name }}</td>
                            <td class="hidden whitespace-nowrap text-slate-500 md:table-cell">
                                {{ sourceLabels[record.source_type] ?? record.source_type }}
                            </td>
                            <td>
                                <Badge v-if="record.revoked_at" status="withdrawn">已撤销</Badge>
                                <Badge v-else status="active">有效</Badge>
                            </td>
                            <td class="hidden max-w-[240px] lg:table-cell">
                                <span class="line-clamp-2 text-slate-500">{{ record.description || '—' }}</span>
                            </td>
                            <td class="hidden whitespace-nowrap text-slate-500 xl:table-cell">{{ record.creator?.name || '系统' }}</td>
                            <td class="hidden whitespace-nowrap text-xs text-slate-400 xl:table-cell">
                                <p class="text-slate-600">{{ formatDate(record.applied_on) }}</p>
                                <p class="text-[11px] text-slate-400">录入 {{ formatDate(record.created_at) }}</p>
                            </td>
                            <td>
                                <div class="flex items-center justify-end gap-1">
                                    <button v-if="!record.revoked_at && record.source_type === 'manual' && canManageManual" type="button" class="btn-ghost !px-2.5 !py-1.5" title="编辑" @click="openEdit(record)">
                                        <Pencil class="h-4 w-4" />
                                    </button>
                                    <button v-if="!record.revoked_at" type="button" class="btn-ghost !px-2.5 !py-1.5 hover:!text-rose-600" title="撤销记录（保留记录）" @click="deleteTarget = record">
                                        <RotateCcw class="h-4 w-4" />
                                    </button>
                                    <button v-if="canManageManual && record.source_type === 'manual'" type="button" class="btn-ghost !px-2.5 !py-1.5 text-rose-600 hover:!text-rose-700" title="永久删除（所有人都看不到）" @click="hardDeleteTarget = record">
                                        <Trash2 class="h-4 w-4" />
                                    </button>
                                </div>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
            <EmptyState v-else title="暂无成绩记录" description="审核通过申请或手工录入后会显示在这里" />

            <Pagination
                :paginator="records"
                label="条记录"
                :page-size="pageSize"
                :page-size-options="pageSizeOptions"
                show-page-size
                @change="changePage"
                @size-change="changePageSize"
            />
        </Card>

        <Modal :open="modalOpen" :title="editing ? '编辑成绩记录' : '录入成绩'" @update:open="modalOpen = false">
            <form class="space-y-4" @submit.prevent="submit">
                <Field label="学生" :error="form.errors.user_id" required>
                    <select v-model="form.user_id" class="input">
                        <option value="" disabled>请选择学生</option>
                        <option v-for="student in students" :key="student.id" :value="student.id">
                            {{ student.name }} · {{ student.student_id }} · {{ student.class_name }}
                        </option>
                    </select>
                </Field>

                <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                    <Field label="类别" :error="form.errors.category_id" required>
                        <select v-model="form.category_id" class="input">
                            <option value="" disabled>请选择类别</option>
                            <option v-for="category in categories" :key="category.id" :value="category.id">
                                {{ category.name }}（{{ category.type === 'deduct' ? '扣分' : '加分' }}）
                            </option>
                        </select>
                    </Field>
                    <Field label="学期" :error="form.errors.semester_id" required>
                        <select v-model="form.semester_id" class="input">
                            <option value="" disabled>请选择学年</option>
                            <option v-for="semester in semesters" :key="semester.id" :value="semester.id">{{ semester.name }}</option>
                        </select>
                    </Field>
                </div>

                <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                    <Field label="上报日期" :error="form.errors.applied_on" required hint="与活动实际发生日期一致">
                        <input v-model="form.applied_on" type="date" :max="today" class="input" />
                    </Field>
                    <Field
                        label="分值"
                        :error="form.errors.score"
                        required
                        :hint="selectedCategory ? `当前类别为${selectedCategory.type === 'deduct' ? '扣分' : '加分'}，系统会自动${selectedCategory.type === 'deduct' ? '按负分' : '按正分'}计入` : '支持 0.5 分，如 5 或 4.5'"
                    >
                        <input v-model="form.score" type="number" min="0.5" step="0.5" inputmode="decimal" class="input" />
                    </Field>
                </div>

                <Field label="说明" :error="form.errors.description" required>
                    <textarea v-model="form.description" rows="3" maxlength="500" class="input resize-none" placeholder="说明本次加减分原因" />
                </Field>

                <div class="flex justify-end gap-2 border-t border-slate-100 pt-4">
                    <Button variant="secondary" @click="modalOpen = false">取消</Button>
                    <Button type="submit" :loading="form.processing">{{ editing ? '保存修改' : '确认录入' }}</Button>
                </div>
            </form>
        </Modal>

        <ConfirmDialog
            :open="!!deleteTarget"
            title="撤销成绩记录"
            :message="`确认撤销“${deleteTarget?.user?.name ?? ''}”的这条${deleteTarget?.category?.name ?? ''}记录（${signedScore(deleteTarget?.score ?? 0)} 分）吗？撤销后该分值将从总分中移除，记录会保留以便追溯。`"
            confirm-text="确认撤销"
            danger
            :loading="actionLoading"
            @update:open="deleteTarget = null"
            @confirm="destroy"
        />

        <ConfirmDialog
            :open="!!hardDeleteTarget"
            title="永久删除手工录入记录"
            :message="`确认永久删除“${hardDeleteTarget?.user?.name ?? ''}”的这条${hardDeleteTarget?.category?.name ?? ''}记录（${signedScore(hardDeleteTarget?.score ?? 0)} 分）吗？删除后学生、班干部、辅导员和管理员都将看不到这条记录，且不可恢复。`"
            confirm-text="永久删除"
            danger
            :loading="actionLoading"
            @update:open="hardDeleteTarget = null"
            @confirm="forceDestroy"
        />
    </AuthenticatedLayout>
</template>
