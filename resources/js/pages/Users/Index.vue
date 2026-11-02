<script setup>
import { computed, ref, watch } from 'vue';
import { Head, Link, router, useForm, usePage } from '@inertiajs/vue3';
import { KeyRound, Pencil, Plus, Search, ShieldCheck, Trash2, Upload, UserCheck, UserX } from 'lucide-vue-next';
import AuthenticatedLayout from '@/layouts/AuthenticatedLayout.vue';
import PageHeader from '@/components/ui/PageHeader.vue';
import Card from '@/components/ui/Card.vue';
import StatCard from '@/components/ui/StatCard.vue';
import Badge from '@/components/ui/Badge.vue';
import EmptyState from '@/components/ui/EmptyState.vue';
import Pagination from '@/components/ui/Pagination.vue';
import Modal from '@/components/ui/Modal.vue';
import ConfirmDialog from '@/components/ui/ConfirmDialog.vue';
import Field from '@/components/ui/Field.vue';
import FileInput from '@/components/ui/FileInput.vue';
import Button from '@/components/ui/Button.vue';
import { formatDate } from '@/lib/format';
import { usePageSize } from '@/composables/usePageSize';

const props = defineProps({
    users: { type: Object, required: true },
    stats: { type: Object, required: true },
    filterOptions: { type: Object, default: () => ({}) },
    colleges: { type: Array, default: () => [] },
    majors: { type: Array, default: () => [] },
    classes: { type: Array, default: () => [] },
    canCreate: { type: Boolean, default: false },
    creatableRoles: { type: Array, default: () => [] },
    filters: { type: Object, default: () => ({}) },
    scopeLabel: { type: String, default: '全校' },
    perPage: { type: [String, Number], default: '20' },
});

const { pageSize, pageSizeOptions, changePageSize } = usePageSize(props.perPage);

const page = usePage();
const permissions = computed(() => page.props.permissions ?? {});
const isSuper = computed(() => permissions.value.is_admin);
const isCollegeAdmin = computed(() => permissions.value.is_college_admin);
const isClassAdmin = computed(() => permissions.value.is_class_admin);

const roleLabels = {
    student: '学生',
    teacher: '教师',
    admin: '管理员',
    college_admin: '学院管理员',
    class_admin: '班级管理员',
};

const modalOpen = ref(false);
const editing = ref(null);
const importOpen = ref(false);
const toggleTarget = ref(null);
const resetTarget = ref(null);
const deleteTarget = ref(null);
const actionLoading = ref(false);

const userForm = useForm({
    role: 'student',
    name: '',
    username: '',
    student_id: '',
    employee_id: '',
    class_name: '',
    class_id: '',
    college: '',
    college_id: '',
    major_id: '',
    grade: '',
    teacher_college: '',
    teacher_college_id: '',
    password: '',
    is_active: true,
});

const importForm = useForm({ file: null });

const modalTitle = computed(() => (editing.value ? '编辑用户' : '新增用户'));
const pageTitle = computed(() => (isClassAdmin.value ? '学生管理' : '用户管理'));
const isStudentRole = computed(() => userForm.role === 'student');
const isTeacherRole = computed(() => userForm.role === 'teacher');

const roleOptions = computed(() => {
    if (editing.value && ['admin', 'college_admin', 'class_admin'].includes(editing.value.role)) {
        return [{ value: editing.value.role, label: roleLabels[editing.value.role] }];
    }

    const values = [...(props.creatableRoles ?? [])];

    if (editing.value && !values.includes(editing.value.role)) {
        values.push(editing.value.role);
    }

    return values.map((value) => ({ value, label: roleLabels[value] ?? value }));
});

const filterRoles = computed(() => {
    if (isSuper.value) return Object.entries(roleLabels).map(([value, label]) => ({ value, label }));
    if (isCollegeAdmin.value) return ['student', 'teacher', 'class_admin'].map((value) => ({ value, label: roleLabels[value] }));
    return [{ value: 'student', label: '学生' }];
});

const majorOptions = computed(() =>
    props.majors.filter((item) => !userForm.college_id || item.college_id === userForm.college_id),
);

const classOptions = computed(() => {
    const inCollege = props.classes.filter(
        (item) => !userForm.college_id || item.college_id === userForm.college_id,
    );
    const inMajor = inCollege.filter((item) => !userForm.major_id || item.major_id === userForm.major_id);

    return inMajor.length ? inMajor : inCollege;
});

watch(
    () => userForm.major_id,
    () => {
        if (userForm.class_id && !classOptions.value.some((item) => item.id === userForm.class_id)) {
            userForm.class_id = '';
        }
    },
);

watch(
    () => userForm.college_id,
    () => {
        if (userForm.class_id && !classOptions.value.some((item) => item.id === userForm.class_id)) {
            userForm.class_id = '';
        }

        if (userForm.major_id && !majorOptions.value.some((item) => item.id === userForm.major_id)) {
            userForm.major_id = '';
        }
    },
);

watch(
    () => userForm.class_id,
    (value) => {
        const selected = props.classes.find((item) => item.id === value);

        if (selected) {
            userForm.college_id = selected.college_id;
            userForm.major_id = selected.major_id;
            userForm.class_name = selected.name;
            userForm.grade = selected.grade ?? '';
        } else {
            userForm.class_name = '';
            userForm.grade = '';
        }
    },
);

function openCreate() {
    editing.value = null;
    userForm.clearErrors();
    userForm.defaults({
        role: props.creatableRoles[0] ?? 'student',
        name: '',
        username: '',
        student_id: '',
        employee_id: '',
        class_name: '',
        class_id: '',
        college: '',
        college_id: props.colleges[0]?.id ?? '',
        major_id: '',
        grade: '',
        teacher_college: '',
        teacher_college_id: props.colleges[0]?.id ?? '',
        password: '',
        is_active: true,
    });
    userForm.reset();
    modalOpen.value = true;
}

function openEdit(user) {
    editing.value = user;
    userForm.clearErrors();
    userForm.defaults({
        role: user.role,
        name: user.name,
        username: user.username,
        student_id: user.student_id ?? '',
        employee_id: user.employee_id ?? '',
        class_name: user.class_name ?? '',
        class_id: user.class_id ?? '',
        college: user.college ?? '',
        college_id: user.college_id ?? '',
        major_id: user.major_id ?? '',
        grade: user.grade ?? '',
        teacher_college: user.teacher_college ?? '',
        teacher_college_id: user.teacher_college_id ?? user.college_id ?? '',
        password: '',
        is_active: user.is_active,
    });
    userForm.reset();
    modalOpen.value = true;
}

function submitUser() {
    const options = {
        preserveScroll: true,
        onSuccess: () => {
            modalOpen.value = false;
        },
    };

    if (editing.value) {
        userForm.put(`/admin/users/${editing.value.id}`, options);
    } else {
        userForm.post('/admin/users', options);
    }
}

function submitImport() {
    importForm.post('/admin/users/import', {
        forceFormData: true,
        preserveScroll: true,
        onSuccess: () => {
            importOpen.value = false;
            importForm.reset();
        },
    });
}

function toggleUser() {
    if (!toggleTarget.value) return;

    actionLoading.value = true;
    router.post(`/admin/users/${toggleTarget.value.id}/toggle`, {}, {
        preserveScroll: true,
        onFinish: () => {
            actionLoading.value = false;
            toggleTarget.value = null;
        },
    });
}

function resetPassword() {
    if (!resetTarget.value) return;

    actionLoading.value = true;
    router.post(`/admin/users/${resetTarget.value.id}/reset-password`, {}, {
        preserveScroll: true,
        onFinish: () => {
            actionLoading.value = false;
            resetTarget.value = null;
        },
    });
}

function deleteUser() {
    if (!deleteTarget.value) return;

    actionLoading.value = true;
    router.delete(`/admin/users/${deleteTarget.value.id}`, {
        preserveScroll: true,
        onFinish: () => {
            actionLoading.value = false;
            deleteTarget.value = null;
        },
    });
}

function changeFilter(event) {
    const form = event.target.closest('form') ?? event.target;
    const data = Object.fromEntries(new FormData(form).entries());
    data.per_page = pageSize.value;
    router.get('/admin/users', data, { preserveState: true, preserveScroll: true });
}

function changePage(url) {
    router.get(url, {}, { preserveState: true, preserveScroll: true });
}
</script>

<template>
    <Head :title="pageTitle" />

    <AuthenticatedLayout>
        <PageHeader :title="pageTitle" :description="`${scopeLabel} · 管理学生、教师与管理员账号`">
            <template #actions>
                <button v-if="permissions.can_import_users" type="button" class="btn-secondary" @click="importOpen = true">
                    <Upload class="h-4 w-4" />
                    导入用户
                </button>
                <button v-if="canCreate" type="button" class="btn-primary" @click="openCreate">
                    <Plus class="h-4 w-4" />
                    新增用户
                </button>
            </template>
        </PageHeader>

        <div class="mb-4 grid grid-cols-2 gap-3 lg:grid-cols-4">
            <StatCard label="学生" :value="stats.students" tone="brand" />
            <StatCard label="教师" :value="stats.teachers" tone="emerald" />
            <StatCard label="管理员" :value="stats.managers" tone="amber" />
            <StatCard label="已禁用" :value="stats.inactive" tone="rose" />
        </div>

        <Link
            v-if="stats.pending_teachers"
            href="/admin/users?role=teacher&status=inactive"
            class="mb-4 flex items-center justify-between gap-3 rounded-xl border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-800 transition hover:bg-amber-100"
        >
            <span>有 {{ stats.pending_teachers }} 名教师账号处于停用状态，核对工号和姓名后可启用。</span>
            <span class="shrink-0 font-medium">去审核 →</span>
        </Link>

        <Card :padding="false">
            <form class="flex flex-wrap items-center gap-2 border-b border-slate-100 px-5 py-4" @submit.prevent="changeFilter">
                <select name="role" class="input !w-auto min-w-[110px]" :value="filters.role ?? ''">
                    <option value="">全部角色</option>
                    <option v-for="item in filterRoles" :key="item.value" :value="item.value">{{ item.label }}</option>
                </select>
                <select name="status" class="input !w-auto min-w-[100px]" :value="filters.status ?? ''">
                    <option value="">全部状态</option>
                    <option value="active">启用</option>
                    <option value="inactive">禁用</option>
                </select>
                <select name="college" class="input !w-auto min-w-[110px]" :value="filters.college ?? ''">
                    <option value="">全部学院</option>
                    <option v-for="college in filterOptions.colleges" :key="college" :value="college">{{ college }}</option>
                </select>
                <select name="major_id" class="input !w-auto min-w-[110px]" :value="filters.major_id ?? ''">
                    <option value="">全部专业</option>
                    <option v-for="major in majors" :key="major.id" :value="major.id">{{ major.name }}</option>
                </select>
                <select name="grade" class="input !w-auto min-w-[100px]" :value="filters.grade ?? ''">
                    <option value="">全部年级</option>
                    <option v-for="grade in filterOptions.grades" :key="grade" :value="grade">{{ grade }}</option>
                </select>
                <select name="class_name" class="input !w-auto min-w-[100px]" :value="filters.class_name ?? ''">
                    <option value="">全部班级</option>
                    <option v-for="className in filterOptions.classes" :key="className" :value="className">{{ className }}</option>
                </select>
                <div class="relative">
                    <Search class="pointer-events-none absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 text-slate-400" />
                    <input name="search" :value="filters.search ?? ''" placeholder="姓名 / 账号 / 学号" class="input !w-44 !pl-9" />
                </div>
                <button type="submit" class="btn-secondary">筛选</button>
                <Link href="/admin/users" class="btn-ghost">重置</Link>
            </form>

            <div v-if="users.data.length" class="divide-y divide-slate-100 px-4 md:hidden">
                <div v-for="user in users.data" :key="user.id" class="py-3.5">
                    <div class="flex items-center gap-3">
                        <span class="flex h-9 w-9 shrink-0 items-center justify-center rounded-full bg-slate-100 text-[11px] font-semibold text-slate-500">
                            {{ user.name?.slice(0, 1) }}
                        </span>
                        <div class="min-w-0 flex-1">
                            <div class="flex flex-wrap items-center gap-1.5">
                                <span class="text-sm font-semibold text-slate-800">{{ user.name }}</span>
                                <Badge :status="user.role" />
                                <Badge :status="user.is_active ? 'active' : 'inactive'" />
                            </div>
                            <p class="text-xs text-slate-400">
                                {{ user.student_id || user.employee_id || user.username }}
                                <span v-if="user.college"> · {{ user.college }}</span>
                                <span v-if="user.major?.name"> · {{ user.major.name }}</span>
                                <span v-if="user.class_name"> · {{ user.class_name }}</span>
                            </p>
                        </div>
                    </div>
                    <div class="mt-2 flex items-center gap-1.5">
                        <button type="button" class="btn-secondary !px-3 !py-1.5 text-xs" @click="openEdit(user)">
                            <Pencil class="h-3.5 w-3.5" />
                            编辑
                        </button>
                        <button type="button" class="btn-secondary !px-3 !py-1.5 text-xs" @click="resetTarget = user">
                            <KeyRound class="h-3.5 w-3.5" />
                            重置密码
                        </button>
                        <button type="button" :class="['btn-ghost !px-3 !py-1.5 text-xs', user.is_active ? 'hover:!text-rose-600' : 'hover:!text-emerald-600']" @click="toggleTarget = user">
                            <component :is="user.is_active ? UserX : UserCheck" class="h-3.5 w-3.5" />
                            {{ user.is_active ? '禁用' : '启用' }}
                        </button>
                        <button v-if="isSuper && user.id !== page.props.auth.user.id" type="button" class="btn-ghost !px-3 !py-1.5 text-xs hover:!text-rose-600" @click="deleteTarget = user">
                            <Trash2 class="h-3.5 w-3.5" />
                            删除
                        </button>
                    </div>
                </div>
            </div>

            <div v-if="users.data.length" class="table-wrap hidden md:block">
                <table class="data-table">
                    <thead>
                        <tr>
                            <th>用户</th>
                            <th>角色</th>
                            <th class="hidden sm:table-cell">学号 / 工号</th>
                            <th class="hidden lg:table-cell">学院 / 班级</th>
                            <th>状态</th>
                            <th class="hidden xl:table-cell">最后登录</th>
                            <th class="text-right">操作</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="user in users.data" :key="user.id">
                            <td>
                                <div class="flex items-center gap-2.5">
                                    <span class="flex h-9 w-9 shrink-0 items-center justify-center rounded-full bg-slate-100 text-[11px] font-semibold text-slate-500">
                                        {{ user.name?.slice(0, 1) }}
                                    </span>
                                    <div class="min-w-0">
                                        <p class="truncate font-medium text-slate-800">{{ user.name }}</p>
                                        <p class="truncate text-xs text-slate-400">{{ user.username }}</p>
                                    </div>
                                </div>
                            </td>
                            <td><Badge :status="user.role" /></td>
                            <td class="hidden whitespace-nowrap font-mono text-xs text-slate-500 sm:table-cell">{{ user.student_id || user.employee_id || '—' }}</td>
                            <td class="hidden whitespace-nowrap lg:table-cell">
                                {{ user.college || user.teacher_college || '—' }}
                                <span v-if="user.major?.name"> · {{ user.major.name }}</span>
                                <span v-if="user.class_name"> · {{ user.class_name }}</span>
                            </td>
                            <td><Badge :status="user.is_active ? 'active' : 'inactive'" /></td>
                            <td class="hidden whitespace-nowrap text-xs text-slate-400 xl:table-cell">{{ user.last_login_at ? formatDate(user.last_login_at, true) : '从未登录' }}</td>
                            <td>
                                <div class="flex items-center justify-end gap-1">
                                    <button type="button" class="btn-ghost !px-2.5 !py-1.5" title="编辑" @click="openEdit(user)">
                                        <Pencil class="h-4 w-4" />
                                    </button>
                                    <button type="button" class="btn-ghost !px-2.5 !py-1.5" title="重置密码" @click="resetTarget = user">
                                        <KeyRound class="h-4 w-4" />
                                    </button>
                                    <button type="button" :class="['btn-ghost !px-2.5 !py-1.5', user.is_active ? 'hover:!text-rose-600' : 'hover:!text-emerald-600']" :title="user.is_active ? '禁用' : '启用'" @click="toggleTarget = user">
                                        <component :is="user.is_active ? UserX : UserCheck" class="h-4 w-4" />
                                    </button>
                                    <button v-if="isSuper && user.id !== page.props.auth.user.id" type="button" class="btn-ghost !px-2.5 !py-1.5 hover:!text-rose-600" title="删除用户" @click="deleteTarget = user">
                                        <Trash2 class="h-4 w-4" />
                                    </button>
                                </div>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
            <EmptyState v-else title="没有符合条件的用户" />

            <Pagination
                :paginator="users"
                label="名用户"
                :page-size="pageSize"
                :page-size-options="pageSizeOptions"
                show-page-size
                @change="changePage"
                @size-change="changePageSize"
            />
        </Card>

        <Modal :open="modalOpen" :title="modalTitle" description="账号、角色与组织信息将用于申请、审核和排行榜" @update:open="modalOpen = false">
            <form class="space-y-4" @submit.prevent="submitUser">
                <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                    <Field label="角色" :error="userForm.errors.role" required>
                        <select v-model="userForm.role" class="input" :disabled="roleOptions.length === 1">
                            <option v-for="item in roleOptions" :key="item.value" :value="item.value">{{ item.label }}</option>
                        </select>
                    </Field>
                    <Field label="姓名" :error="userForm.errors.name" required>
                        <input v-model="userForm.name" type="text" class="input" />
                    </Field>
                </div>

                <Field label="登录账号" :error="userForm.errors.username" required hint="建议与学号/工号一致">
                    <input v-model="userForm.username" type="text" class="input" />
                </Field>

                <template v-if="isStudentRole">
                    <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                        <Field label="学号" :error="userForm.errors.student_id">
                            <input v-model="userForm.student_id" type="text" class="input" />
                        </Field>
                        <Field label="所属学院" :error="userForm.errors.college_id" required>
                            <select v-model="userForm.college_id" class="input" :disabled="isClassAdmin || colleges.length === 1">
                                <option value="">请选择学院</option>
                                <option v-for="college in colleges" :key="college.id" :value="college.id">{{ college.name }}</option>
                            </select>
                        </Field>
                        <Field label="专业" :error="userForm.errors.major_id">
                            <select v-model="userForm.major_id" class="input" :disabled="isClassAdmin || !userForm.college_id">
                                <option value="">请选择专业</option>
                                <option v-for="major in majorOptions" :key="major.id" :value="major.id">{{ major.name }}</option>
                            </select>
                        </Field>
                        <Field label="班级" :error="userForm.errors.class_id" hint="可直接调整所属班级，年级会自动带出">
                            <select v-model="userForm.class_id" class="input">
                                <option value="">未分班</option>
                                <option v-for="item in classOptions" :key="item.id" :value="item.id">{{ item.name }}{{ item.grade ? `（${item.grade}）` : '' }}</option>
                            </select>
                        </Field>
                        <Field label="年级" :error="userForm.errors.grade" hint="选择班级后自动带出">
                            <input v-model="userForm.grade" type="text" class="input" disabled />
                        </Field>
                    </div>
                </template>

                <template v-else-if="isTeacherRole">
                    <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                        <Field label="工号" :error="userForm.errors.employee_id">
                            <input v-model="userForm.employee_id" type="text" class="input" />
                        </Field>
                        <Field label="所属学院" :error="userForm.errors.teacher_college_id">
                            <select v-model="userForm.teacher_college_id" class="input" :disabled="isClassAdmin || colleges.length === 1">
                                <option value="">请选择学院</option>
                                <option v-for="college in colleges" :key="college.id" :value="college.id">{{ college.name }}</option>
                            </select>
                        </Field>
                    </div>
                </template>

                <div v-if="roleOptions.length === 1 && ['admin', 'college_admin', 'class_admin'].includes(userForm.role)" class="rounded-xl bg-slate-50 px-4 py-3 text-xs leading-relaxed text-slate-500">
                    管理员角色与授权范围请在“组织管理”中调整，此处仅修改基础信息。
                </div>

                <Field label="密码" :error="userForm.errors.password" :hint="editing ? '留空表示不修改密码' : '留空则使用系统默认初始密码'">
                    <input v-model="userForm.password" type="password" autocomplete="new-password" class="input" placeholder="至少 8 位" />
                </Field>

                <label class="flex items-center gap-2 text-sm text-slate-600">
                    <input v-model="userForm.is_active" type="checkbox" class="h-4 w-4 rounded border-slate-300 text-brand-600" />
                    启用账号
                </label>

                <div class="flex justify-end gap-2 border-t border-slate-100 pt-4">
                    <Button variant="secondary" @click="modalOpen = false">取消</Button>
                    <Button type="submit" :loading="userForm.processing">
                        <ShieldCheck class="h-4 w-4" />
                        {{ editing ? '保存修改' : '创建用户' }}
                    </Button>
                </div>
            </form>
        </Modal>

        <Modal v-if="permissions.can_import_users" :open="importOpen" title="批量导入用户" description="支持 Excel（.xlsx/.xls）与 CSV；按工作表名、文件名或“角色”列识别学生、教师、审核端" @update:open="importOpen = false">
            <div class="space-y-4">
                <FileInput accept=".xlsx,.xls,.csv" hint="选择用户信息表" @change="importForm.file = $event" />
                <p v-if="importForm.errors.file" class="text-xs text-rose-600">{{ importForm.errors.file }}</p>
                <div class="rounded-xl bg-slate-50 px-4 py-3 text-xs leading-relaxed text-slate-500">
                    <p class="font-medium text-slate-600">格式要求</p>
                    <p class="mt-1">工作表名包含“学生”“教师”或“审核/管理”即按该角色导入；CSV 按文件名识别，也可增加“角色”列按行区分。表头支持学号/工号、姓名、学院、专业、班级、年级。已存在的账号只更新资料，不会重置密码。</p>
                    <a href="/admin/users/import/template" class="mt-2 inline-flex items-center gap-1 font-medium text-indigo-600 hover:text-indigo-700">
                        下载导入模板
                    </a>
                </div>
                <div class="flex justify-end gap-2">
                    <Button variant="secondary" @click="importOpen = false">取消</Button>
                    <Button :loading="importForm.processing" :disabled="!importForm.file" @click="submitImport">
                        <Upload class="h-4 w-4" />
                        开始导入
                    </Button>
                </div>
            </div>
        </Modal>

        <ConfirmDialog :open="!!toggleTarget" :title="toggleTarget?.is_active ? '禁用账号' : '启用账号'" :message="`确认${toggleTarget?.is_active ? '禁用' : '启用'}“${toggleTarget?.name ?? ''}”的账号吗？${toggleTarget?.is_active ? '禁用后该用户将无法登录。' : ''}`" :confirm-text="toggleTarget?.is_active ? '确认禁用' : '确认启用'" :danger="toggleTarget?.is_active" :loading="actionLoading" @update:open="toggleTarget = null" @confirm="toggleUser" />

        <ConfirmDialog :open="!!resetTarget" title="重置密码" :message="`将“${resetTarget?.name ?? ''}”的密码重置为登录账号（${resetTarget?.student_id || resetTarget?.employee_id || resetTarget?.username}），重置后该账号在所有设备上的登录都会失效，需重新登录。确定继续吗？`" confirm-text="确认重置" :loading="actionLoading" @update:open="resetTarget = null" @confirm="resetPassword" />
        <ConfirmDialog :open="!!deleteTarget" title="删除用户" :message="`确认删除“${deleteTarget?.name ?? ''}”（${deleteTarget?.student_id || deleteTarget?.employee_id || deleteTarget?.username}）吗？该用户的申请、成绩、申诉及班级授权将一并删除，且无法恢复。`" confirm-text="确认删除" danger :loading="actionLoading" @update:open="deleteTarget = null" @confirm="deleteUser" />
    </AuthenticatedLayout>
</template>
