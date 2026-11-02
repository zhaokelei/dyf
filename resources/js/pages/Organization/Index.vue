<script setup>
import { computed, ref } from 'vue';
import { Head, router, useForm } from '@inertiajs/vue3';
import { Building2, GraduationCap, Plus, Trash2, UserPlus, X } from 'lucide-vue-next';
import AuthenticatedLayout from '@/layouts/AuthenticatedLayout.vue';
import PageHeader from '@/components/ui/PageHeader.vue';
import Card from '@/components/ui/Card.vue';
import Badge from '@/components/ui/Badge.vue';
import EmptyState from '@/components/ui/EmptyState.vue';
import Modal from '@/components/ui/Modal.vue';
import ConfirmDialog from '@/components/ui/ConfirmDialog.vue';
import Field from '@/components/ui/Field.vue';
import Button from '@/components/ui/Button.vue';
import UserPicker from '@/components/ui/UserPicker.vue';

const props = defineProps({
    colleges: { type: Array, default: () => [] },
    assignableUsers: { type: Array, default: () => [] },
    canManageColleges: { type: Boolean, default: false },
    canAssignCollegeAdmins: { type: Boolean, default: false },
    canManageMajors: { type: Boolean, default: false },
    canManageClasses: { type: Boolean, default: false },
    canAssignClassAdmins: { type: Boolean, default: false },
    canAssignGradeAdmins: { type: Boolean, default: false },
    canAssignClassOfficers: { type: Boolean, default: false },
});

const collegeModal = ref(false);
const editingCollege = ref(null);
const majorModal = ref(false);
const editingMajor = ref(null);
const classModal = ref(false);
const editingClass = ref(null);
const collegeAdminModal = ref(false);
const classAdminModal = ref(false);
const counselorModal = ref(false);
const counselorCollege = ref(null);
const gradeAdminModal = ref(false);
const deleteCollegeTarget = ref(null);
const deleteMajorTarget = ref(null);
const deleteClassTarget = ref(null);
const revokeCollegeAdminTarget = ref(null);
const revokeClassAdminTarget = ref(null);
const revokeGradeAdminTarget = ref(null);
const officerModal = ref(false);
const officerClass = ref(null);
const revokeOfficerTarget = ref(null);
const actionLoading = ref(false);

const collegeForm = useForm({ name: '', code: '', description: '', is_active: true });
const majorForm = useForm({ college_id: '', name: '', code: '', is_active: true });
const classForm = useForm({ college_id: '', major_id: '', name: '', grade: '', is_active: true });
const collegeAdminForm = useForm({ user_id: '', college_id: '' });
const classAdminForm = useForm({ user_id: '', class_id: '' });
const counselorForm = useForm({ user_id: '', college_id: '', class_ids: [] });
const gradeAdminForm = useForm({ user_id: '', major_id: '', grade: '' });
const officerForm = useForm({ user_id: '', class_id: '', title: '班长' });

const totalStudents = computed(() =>
    props.colleges.reduce((total, college) => total + college.classes.reduce((sum, item) => sum + item.users_count, 0), 0),
);
const totalClasses = computed(() => props.colleges.reduce((total, college) => total + college.classes.length, 0));
const totalMajors = computed(() => props.colleges.reduce((total, college) => total + college.majors.length, 0));

const selectedClass = computed(() => {
    for (const college of props.colleges) {
        const found = college.classes.find((item) => item.id === classAdminForm.class_id);
        if (found) return { ...found, college_name: college.name, college_id: college.id };
    }
    return null;
});
const classAdminCandidates = computed(() =>
    selectedClass.value
        ? props.assignableUsers.filter((user) => user.college_id === selectedClass.value.college_id)
        : [],
);

const counselorCandidates = computed(() =>
    counselorCollege.value
        ? props.assignableUsers.filter(
            (user) => user.college_id === counselorCollege.value.id && ['teacher', 'class_admin'].includes(user.role),
        )
        : [],
);
const counselorClasses = computed(() => counselorCollege.value?.classes ?? []);

const selectedGradeMajor = computed(() => {
    for (const college of props.colleges) {
        const found = college.majors.find((item) => item.id === gradeAdminForm.major_id);
        if (found) return { ...found, college_id: college.id, college_name: college.name };
    }
    return null;
});
const gradeOptions = computed(() => {
    if (!selectedGradeMajor.value) return [];
    const grades = props.colleges
        .flatMap((college) => college.classes)
        .filter((item) => item.major_id === selectedGradeMajor.value.id)
        .map((item) => item.grade)
        .filter(Boolean);

    return [...new Set(grades)];
});
const gradeAdminCandidates = computed(() =>
    selectedGradeMajor.value
        ? props.assignableUsers.filter((user) => user.college_id === selectedGradeMajor.value.college_id)
        : [],
);

const officerCandidates = computed(() =>
    officerClass.value
        ? props.assignableUsers.filter(
            (user) => user.role === 'student' && user.class_id === officerClass.value.id,
        )
        : [],
);

const officerTitles = ['班长', '副班长', '团支书', '学习委员', '生活委员', '体育委员', '文艺委员', '纪律委员', '心理委员'];

function openCollegeModal(college = null) {
    editingCollege.value = college;
    collegeForm.clearErrors();
    collegeForm.defaults({
        name: college?.name ?? '',
        code: college?.code ?? '',
        description: college?.description ?? '',
        is_active: college?.is_active ?? true,
    });
    collegeForm.reset();
    collegeModal.value = true;
}

function submitCollege() {
    const options = { preserveScroll: true, onSuccess: () => { collegeModal.value = false; } };
    if (editingCollege.value) {
        collegeForm.put(`/admin/organization/colleges/${editingCollege.value.id}`, options);
    } else {
        collegeForm.post('/admin/organization/colleges', options);
    }
}

function openMajorModal(college, major = null) {
    editingMajor.value = major;
    majorForm.clearErrors();
    majorForm.defaults({
        college_id: college.id,
        name: major?.name ?? '',
        code: major?.code ?? '',
        is_active: major?.is_active ?? true,
    });
    majorForm.reset();
    majorModal.value = true;
}

function submitMajor() {
    const options = { preserveScroll: true, onSuccess: () => { majorModal.value = false; } };
    if (editingMajor.value) {
        majorForm.put(`/admin/organization/majors/${editingMajor.value.id}`, options);
    } else {
        majorForm.post('/admin/organization/majors', options);
    }
}

function openClassModal(college, item = null) {
    editingClass.value = item;
    classForm.clearErrors();
    classForm.defaults({
        college_id: college.id,
        major_id: item?.major_id ?? college.majors[0]?.id ?? '',
        name: item?.name ?? '',
        grade: item?.grade ?? '',
        is_active: item?.is_active ?? true,
    });
    classForm.reset();
    classModal.value = true;
}

function submitClass() {
    const options = { preserveScroll: true, onSuccess: () => { classModal.value = false; } };
    if (editingClass.value) {
        classForm.put(`/admin/organization/classes/${editingClass.value.id}`, options);
    } else {
        classForm.post('/admin/organization/classes', options);
    }
}

function openCollegeAdminModal(college) {
    collegeAdminForm.clearErrors();
    collegeAdminForm.defaults({ user_id: '', college_id: college.id });
    collegeAdminForm.reset();
    collegeAdminModal.value = true;
}

function submitCollegeAdmin() {
    collegeAdminForm.post('/admin/organization/college-admins', {
        preserveScroll: true,
        onSuccess: () => { collegeAdminModal.value = false; },
    });
}

function openClassAdminModal(college, item) {
    classAdminForm.clearErrors();
    classAdminForm.defaults({ user_id: '', class_id: item.id });
    classAdminForm.reset();
    classAdminModal.value = true;
}

function submitClassAdmin() {
    classAdminForm.post('/admin/organization/class-admins', {
        preserveScroll: true,
        onSuccess: () => { classAdminModal.value = false; },
    });
}

function openCounselorModal(college) {
    counselorCollege.value = college;
    counselorForm.clearErrors();
    counselorForm.defaults({ user_id: '', college_id: college.id, class_ids: [] });
    counselorForm.reset();
    counselorModal.value = true;
}

function syncCounselorClasses() {
    const userId = counselorForm.user_id;
    counselorForm.class_ids = counselorClasses.value
        .filter((item) => item.admins.some((admin) => admin.id === userId))
        .map((item) => item.id);
}

function submitCounselor() {
    counselorForm.post('/admin/organization/class-admins/sync', {
        preserveScroll: true,
        onSuccess: () => { counselorModal.value = false; },
    });
}

function openGradeAdminModal(college, major) {
    gradeAdminForm.clearErrors();
    gradeAdminForm.defaults({
        user_id: '',
        major_id: major.id,
        grade: props.colleges
            .flatMap((item) => item.classes)
            .filter((item) => item.major_id === major.id && item.grade)
            .map((item) => item.grade)[0] ?? '',
    });
    gradeAdminForm.reset();
    gradeAdminModal.value = true;
}

function submitGradeAdmin() {
    gradeAdminForm.post('/admin/organization/grade-admins', {
        preserveScroll: true,
        onSuccess: () => { gradeAdminModal.value = false; },
    });
}

function openOfficerModal(college, item) {
    officerClass.value = { ...item, college_name: college.name };
    officerForm.clearErrors();
    officerForm.defaults({ user_id: '', class_id: item.id, title: '班长' });
    officerForm.reset();
    officerModal.value = true;
}

function submitOfficer() {
    officerForm.post('/admin/organization/class-officers', {
        preserveScroll: true,
        onSuccess: () => { officerModal.value = false; },
    });
}

function confirmRevokeOfficer() {
    const target = revokeOfficerTarget.value;
    if (!target) return;
    actionLoading.value = true;
    router.delete(`/admin/organization/class-officers/${target.user.id}/${target.class.id}`, {
        preserveScroll: true,
        onFinish: () => { actionLoading.value = false; revokeOfficerTarget.value = null; },
    });
}

function confirmDeleteCollege() {
    if (!deleteCollegeTarget.value) return;
    actionLoading.value = true;
    router.delete(`/admin/organization/colleges/${deleteCollegeTarget.value.id}`, {
        preserveScroll: true,
        onFinish: () => { actionLoading.value = false; deleteCollegeTarget.value = null; },
    });
}

function confirmDeleteMajor() {
    if (!deleteMajorTarget.value) return;
    actionLoading.value = true;
    router.delete(`/admin/organization/majors/${deleteMajorTarget.value.id}`, {
        preserveScroll: true,
        onFinish: () => { actionLoading.value = false; deleteMajorTarget.value = null; },
    });
}

function confirmDeleteClass() {
    if (!deleteClassTarget.value) return;
    actionLoading.value = true;
    router.delete(`/admin/organization/classes/${deleteClassTarget.value.id}`, {
        preserveScroll: true,
        onFinish: () => { actionLoading.value = false; deleteClassTarget.value = null; },
    });
}

function confirmRevokeCollegeAdmin() {
    const target = revokeCollegeAdminTarget.value;
    if (!target) return;
    actionLoading.value = true;
    router.delete(`/admin/organization/college-admins/${target.user.id}/${target.college.id}`, {
        preserveScroll: true,
        onFinish: () => { actionLoading.value = false; revokeCollegeAdminTarget.value = null; },
    });
}

function confirmRevokeClassAdmin() {
    const target = revokeClassAdminTarget.value;
    if (!target) return;
    actionLoading.value = true;
    router.delete(`/admin/organization/class-admins/${target.user.id}/${target.class.id}`, {
        preserveScroll: true,
        onFinish: () => { actionLoading.value = false; revokeClassAdminTarget.value = null; },
    });
}

function confirmRevokeGradeAdmin() {
    if (!revokeGradeAdminTarget.value) return;
    actionLoading.value = true;
    router.delete(`/admin/organization/grade-admins/${revokeGradeAdminTarget.value.id}`, {
        preserveScroll: true,
        onFinish: () => { actionLoading.value = false; revokeGradeAdminTarget.value = null; },
    });
}
</script>

<template>
    <Head title="组织管理" />

    <AuthenticatedLayout>
        <PageHeader title="组织管理" description="学院 → 专业 → 年级 → 班级，以及分级管理员授权">
            <template #actions>
                <button v-if="canManageColleges" type="button" class="btn-primary" @click="openCollegeModal()">
                    <Plus class="h-4 w-4" />
                    新增学院
                </button>
            </template>
        </PageHeader>

        <div class="mb-4 grid grid-cols-3 gap-3">
            <div class="card px-4 py-4">
                <p class="text-xs text-slate-400">学院 / 书院</p>
                <p class="mt-1 text-2xl font-semibold text-slate-900">{{ colleges.length }}</p>
            </div>
            <div class="card px-4 py-4">
                <p class="text-xs text-slate-400">专业</p>
                <p class="mt-1 text-2xl font-semibold text-slate-900">{{ totalMajors }}</p>
            </div>
            <div class="card px-4 py-4">
                <p class="text-xs text-slate-400">班级 / 学生</p>
                <p class="mt-1 text-2xl font-semibold text-slate-900">{{ totalClasses }} / {{ totalStudents }}</p>
            </div>
        </div>

        <div class="mb-4 rounded-xl border border-brand-100 bg-brand-50/60 px-4 py-3 text-sm leading-relaxed text-brand-800">
            管理规则：校级管理员负责学院、专业、班级和学院管理员；学院管理员负责本学院专业、班级，并统一配置辅导员管理哪些班级。专业仅用于查询、统计和排行榜筛选，不单独设置专业管理员。辅导员可按班级授权，也可按“专业 + 年级”批量授权，自动覆盖该年级下全部班级。
        </div>

        <div v-if="colleges.length" class="space-y-4">
            <Card v-for="college in colleges" :key="college.id" :padding="false">
                <div class="flex flex-wrap items-start justify-between gap-3 border-b border-slate-100 px-5 py-4">
                    <div class="flex items-start gap-3">
                        <span class="flex h-11 w-11 items-center justify-center rounded-xl bg-brand-50 text-brand-600">
                            <Building2 class="h-5 w-5" />
                        </span>
                        <div>
                            <div class="flex flex-wrap items-center gap-2">
                                <h2 class="text-base font-semibold text-slate-900">{{ college.name }}</h2>
                                <Badge :status="college.is_active ? 'active' : 'inactive'" />
                                <span v-if="college.code" class="rounded bg-slate-100 px-2 py-0.5 text-[11px] text-slate-500">{{ college.code }}</span>
                            </div>
                            <p class="mt-1 text-xs text-slate-400">{{ college.majors.length }} 个专业 · {{ college.classes.length }} 个班级</p>
                            <p v-if="college.description" class="mt-1 text-xs text-slate-500">{{ college.description }}</p>
                        </div>
                    </div>
                    <div class="flex flex-wrap items-center gap-2">
                        <button v-if="canAssignCollegeAdmins" type="button" class="btn-secondary !px-3 !py-1.5 text-xs" @click="openCollegeAdminModal(college)">
                            <UserPlus class="h-3.5 w-3.5" />
                            授权学院管理员
                        </button>
                        <button v-if="canAssignClassAdmins" type="button" class="btn-secondary !px-3 !py-1.5 text-xs" @click="openCounselorModal(college)">
                            <UserPlus class="h-3.5 w-3.5" />
                            统一配置辅导员
                        </button>
                        <button v-if="canManageColleges" type="button" class="btn-ghost !px-3 !py-1.5 text-xs" @click="openCollegeModal(college)">编辑</button>
                        <button v-if="canManageColleges" type="button" class="btn-ghost !px-2.5 !py-1.5 hover:!text-rose-600" title="删除学院" @click="deleteCollegeTarget = college">
                            <Trash2 class="h-4 w-4" />
                        </button>
                    </div>
                </div>

                <div class="border-b border-slate-100 px-5 py-3">
                    <p class="mb-2 text-xs font-medium text-slate-500">学院管理员</p>
                    <div v-if="college.admins.length" class="flex flex-wrap gap-2">
                        <span v-for="admin in college.admins" :key="admin.id" class="inline-flex items-center gap-1.5 rounded-full bg-indigo-50 px-2.5 py-1 text-xs text-indigo-700">
                            {{ admin.name }}（{{ admin.username }}）
                            <button v-if="canAssignCollegeAdmins" type="button" class="text-indigo-400 hover:text-indigo-700" @click="revokeCollegeAdminTarget = { user: admin, college }">
                                <X class="h-3 w-3" />
                            </button>
                        </span>
                    </div>
                    <p v-else class="text-xs text-slate-400">尚未授权学院管理员</p>
                </div>

                <div class="border-b border-slate-100 px-5 py-3">
                    <div class="mb-2 flex items-center justify-between">
                        <p class="text-xs font-medium text-slate-500">专业与年级辅导员</p>
                        <button v-if="canManageMajors" type="button" class="btn-secondary !px-3 !py-1.5 text-xs" @click="openMajorModal(college)">
                            <Plus class="h-3.5 w-3.5" />
                            新增专业
                        </button>
                    </div>

                    <div v-if="college.majors.length" class="space-y-2">
                        <div v-for="major in college.majors" :key="major.id" class="rounded-xl border border-slate-100 px-3.5 py-2.5">
                            <div class="flex flex-wrap items-center justify-between gap-2">
                                <div class="flex flex-wrap items-center gap-2">
                                    <span class="text-sm font-medium text-slate-800">{{ major.name }}</span>
                                    <span v-if="major.code" class="text-[11px] text-slate-400">{{ major.code }}</span>
                                    <Badge v-if="!major.is_active" status="inactive" />
                                </div>
                                <div class="flex items-center gap-1">
                                    <button v-if="canAssignGradeAdmins" type="button" class="btn-secondary !px-3 !py-1.5 text-xs" @click="openGradeAdminModal(college, major)">
                                        配置年级辅导员
                                    </button>
                                    <button v-if="canManageMajors" type="button" class="btn-ghost !px-2.5 !py-1.5 text-xs" @click="openMajorModal(college, major)">编辑</button>
                                    <button v-if="canManageMajors" type="button" class="btn-ghost !px-2.5 !py-1.5 hover:!text-rose-600" title="删除专业" @click="deleteMajorTarget = major">
                                        <Trash2 class="h-4 w-4" />
                                    </button>
                                </div>
                            </div>
                            <div v-if="college.grade_admins.filter((scope) => scope.major?.id === major.id).length" class="mt-2 flex flex-wrap gap-1.5">
                                <span
                                    v-for="scope in college.grade_admins.filter((item) => item.major?.id === major.id)"
                                    :key="scope.id"
                                    class="inline-flex items-center gap-1 rounded-full bg-violet-50 px-2 py-0.5 text-[11px] text-violet-700"
                                >
                                    {{ scope.grade }} · {{ scope.user?.name }}
                                    <button v-if="canAssignGradeAdmins" type="button" class="text-violet-400 hover:text-violet-700" @click="revokeGradeAdminTarget = scope">
                                        <X class="h-3 w-3" />
                                    </button>
                                </span>
                            </div>
                        </div>
                    </div>
                    <p v-else class="text-xs text-slate-400">尚未设置专业</p>
                </div>

                <div class="px-5 py-3">
                    <div class="mb-2 flex items-center justify-between">
                        <p class="text-xs font-medium text-slate-500">班级列表</p>
                        <button v-if="canManageClasses" type="button" class="btn-secondary !px-3 !py-1.5 text-xs" @click="openClassModal(college)">
                            <Plus class="h-3.5 w-3.5" />
                            新增班级
                        </button>
                    </div>

                    <div v-if="college.classes.length" class="divide-y divide-slate-100">
                        <div v-for="item in college.classes" :key="item.id" class="flex flex-wrap items-center justify-between gap-3 py-3">
                            <div class="min-w-0">
                                <div class="flex flex-wrap items-center gap-2">
                                    <GraduationCap class="h-4 w-4 text-slate-400" />
                                    <span class="text-sm font-medium text-slate-800">{{ item.name }}</span>
                                    <span class="text-xs text-slate-400">{{ item.major_name }} · {{ item.grade }}</span>
                                    <Badge v-if="!item.is_active" status="inactive" />
                                    <span class="text-xs text-slate-400">{{ item.users_count }} 人</span>
                                </div>
                                <div class="mt-1 flex flex-wrap gap-1.5">
                                    <span v-for="admin in item.admins" :key="admin.id" class="inline-flex items-center gap-1 rounded-full bg-teal-50 px-2 py-0.5 text-[11px] text-teal-700">
                                        {{ admin.name }}
                                        <button v-if="canAssignClassAdmins" type="button" class="text-teal-400 hover:text-teal-700" @click="revokeClassAdminTarget = { user: admin, class: item }">
                                            <X class="h-3 w-3" />
                                        </button>
                                    </span>
                                    <span v-for="officer in item.officers" :key="`officer-${officer.id}`" class="inline-flex items-center gap-1 rounded-full bg-amber-50 px-2 py-0.5 text-[11px] text-amber-700">
                                        {{ officer.name }} · {{ officer.title }}
                                        <button v-if="canAssignClassOfficers" type="button" class="text-amber-400 hover:text-amber-700" @click="revokeOfficerTarget = { user: officer, class: item }">
                                            <X class="h-3 w-3" />
                                        </button>
                                    </span>
                                </div>
                            </div>
                            <div class="flex items-center gap-1">
                                <button v-if="canAssignClassAdmins" type="button" class="btn-secondary !px-3 !py-1.5 text-xs" @click="openClassAdminModal(college, item)">
                                    配置辅导员
                                </button>
                                <button v-if="canAssignClassOfficers" type="button" class="btn-secondary !px-3 !py-1.5 text-xs" @click="openOfficerModal(college, item)">
                                    配置班干部
                                </button>
                                <button v-if="canManageClasses" type="button" class="btn-ghost !px-3 !py-1.5 text-xs" @click="openClassModal(college, item)">编辑</button>
                                <button v-if="canManageClasses" type="button" class="btn-ghost !px-2.5 !py-1.5 hover:!text-rose-600" title="删除班级" @click="deleteClassTarget = item">
                                    <Trash2 class="h-4 w-4" />
                                </button>
                            </div>
                        </div>
                    </div>
                    <EmptyState v-else title="暂无班级" description="先新增专业，再新增班级" />
                </div>
            </Card>
        </div>
        <Card v-else>
            <EmptyState title="暂无可管理的学院" description="请联系超级管理员授权学院" />
        </Card>

        <Modal :open="collegeModal" :title="editingCollege ? '编辑学院' : '新增学院'" @update:open="collegeModal = false">
            <form class="space-y-4" @submit.prevent="submitCollege">
                <Field label="学院名称" :error="collegeForm.errors.name" required>
                    <input v-model="collegeForm.name" type="text" class="input" />
                </Field>
                <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                    <Field label="学院编码" :error="collegeForm.errors.code">
                        <input v-model="collegeForm.code" type="text" class="input" />
                    </Field>
                    <Field label="状态">
                        <label class="flex h-[42px] items-center gap-2 rounded-xl border border-slate-200 px-3.5 text-sm text-slate-600">
                            <input v-model="collegeForm.is_active" type="checkbox" class="h-4 w-4 rounded border-slate-300 text-brand-600" />
                            启用
                        </label>
                    </Field>
                </div>
                <Field label="说明" :error="collegeForm.errors.description">
                    <textarea v-model="collegeForm.description" rows="3" class="input resize-none" />
                </Field>
                <div class="flex justify-end gap-2 border-t border-slate-100 pt-4">
                    <Button variant="secondary" @click="collegeModal = false">取消</Button>
                    <Button type="submit" :loading="collegeForm.processing">保存</Button>
                </div>
            </form>
        </Modal>

        <Modal :open="majorModal" :title="editingMajor ? '编辑专业' : '新增专业'" @update:open="majorModal = false">
            <form class="space-y-4" @submit.prevent="submitMajor">
                <Field label="所属学院">
                    <input :value="colleges.find((item) => item.id === majorForm.college_id)?.name" type="text" class="input" disabled />
                </Field>
                <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                    <Field label="专业名称" :error="majorForm.errors.name" required>
                        <input v-model="majorForm.name" type="text" class="input" />
                    </Field>
                    <Field label="专业编码" :error="majorForm.errors.code">
                        <input v-model="majorForm.code" type="text" class="input" />
                    </Field>
                </div>
                <label class="flex items-center gap-2 text-sm text-slate-600">
                    <input v-model="majorForm.is_active" type="checkbox" class="h-4 w-4 rounded border-slate-300 text-brand-600" />
                    启用专业
                </label>
                <div class="flex justify-end gap-2 border-t border-slate-100 pt-4">
                    <Button variant="secondary" @click="majorModal = false">取消</Button>
                    <Button type="submit" :loading="majorForm.processing">保存</Button>
                </div>
            </form>
        </Modal>

        <Modal :open="classModal" :title="editingClass ? '编辑班级' : '新增班级'" @update:open="classModal = false">
            <form class="space-y-4" @submit.prevent="submitClass">
                <Field label="所属学院">
                    <input :value="colleges.find((item) => item.id === classForm.college_id)?.name" type="text" class="input" disabled />
                </Field>
                <Field label="所属专业" :error="classForm.errors.major_id" required>
                    <select v-model="classForm.major_id" class="input">
                        <option value="">请选择专业</option>
                        <option v-for="major in colleges.find((item) => item.id === classForm.college_id)?.majors ?? []" :key="major.id" :value="major.id">{{ major.name }}</option>
                    </select>
                </Field>
                <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                    <Field label="班级名称" :error="classForm.errors.name" required>
                        <input v-model="classForm.name" type="text" class="input" />
                    </Field>
                    <Field label="年级" :error="classForm.errors.grade" required>
                        <input v-model="classForm.grade" type="text" placeholder="如 2027级" class="input" />
                    </Field>
                </div>
                <label class="flex items-center gap-2 text-sm text-slate-600">
                    <input v-model="classForm.is_active" type="checkbox" class="h-4 w-4 rounded border-slate-300 text-brand-600" />
                    启用班级
                </label>
                <div class="flex justify-end gap-2 border-t border-slate-100 pt-4">
                    <Button variant="secondary" @click="classModal = false">取消</Button>
                    <Button type="submit" :loading="classForm.processing">保存</Button>
                </div>
            </form>
        </Modal>

        <Modal :open="collegeAdminModal" title="授权学院管理员" description="管理员将获得该学院内的数据管理权限" @update:open="collegeAdminModal = false">
            <form class="space-y-4" @submit.prevent="submitCollegeAdmin">
                <Field label="用户" :error="collegeAdminForm.errors.user_id" required hint="从全校学生或教师中搜索选择">
                    <UserPicker v-model="collegeAdminForm.user_id" :users="assignableUsers" placeholder="搜索姓名、工号或学号" />
                </Field>
                <p v-if="collegeAdminForm.errors.college_id" class="text-xs text-rose-600">{{ collegeAdminForm.errors.college_id }}</p>
                <div class="flex justify-end gap-2 border-t border-slate-100 pt-4">
                    <Button variant="secondary" @click="collegeAdminModal = false">取消</Button>
                    <Button type="submit" :loading="collegeAdminForm.processing">授权</Button>
                </div>
            </form>
        </Modal>

        <Modal :open="classAdminModal" title="授权班级辅导员" :description="selectedClass ? `${selectedClass.college_name} · ${selectedClass.name}` : ''" @update:open="classAdminModal = false">
            <form class="space-y-4" @submit.prevent="submitClassAdmin">
                <Field label="用户" :error="classAdminForm.errors.user_id" required hint="仅可选择该班级所属学院内的用户">
                    <UserPicker v-model="classAdminForm.user_id" :users="classAdminCandidates" placeholder="搜索本学院教师、辅导员或学生" />
                </Field>
                <p v-if="classAdminForm.errors.class_id" class="text-xs text-rose-600">{{ classAdminForm.errors.class_id }}</p>
                <div class="flex justify-end gap-2 border-t border-slate-100 pt-4">
                    <Button variant="secondary" @click="classAdminModal = false">取消</Button>
                    <Button type="submit" :loading="classAdminForm.processing">授权</Button>
                </div>
            </form>
        </Modal>

        <Modal :open="officerModal" title="配置班干部" :description="officerClass ? `${officerClass.college_name} · ${officerClass.name}` : ''" @update:open="officerModal = false">
            <form class="space-y-4" @submit.prevent="submitOfficer">
                <Field label="本班学生" :error="officerForm.errors.user_id" required hint="班干部保留学生身份，仍可正常提交德育分申请">
                    <UserPicker v-model="officerForm.user_id" :users="officerCandidates" placeholder="搜索本班学生姓名或学号" />
                </Field>
                <Field label="职务" :error="officerForm.errors.title" required>
                    <select v-model="officerForm.title" class="input">
                        <option v-for="title in officerTitles" :key="title" :value="title">{{ title }}</option>
                    </select>
                </Field>
                <p v-if="officerForm.errors.class_id" class="text-xs text-rose-600">{{ officerForm.errors.class_id }}</p>
                <p class="rounded-lg bg-amber-50 px-3 py-2 text-xs leading-relaxed text-amber-700">
                    班干部可以在学生页面进入「班干部审批」，审核本班同学的个人申请（不能审核自己的申请）。学生的提交申请、查看德育分等功能不受影响。
                </p>
                <div class="flex justify-end gap-2 border-t border-slate-100 pt-4">
                    <Button variant="secondary" @click="officerModal = false">取消</Button>
                    <Button type="submit" :loading="officerForm.processing">授权</Button>
                </div>
            </form>
        </Modal>

        <Modal :open="gradeAdminModal" title="授权年级辅导员" :description="selectedGradeMajor ? `${selectedGradeMajor.college_name} · ${selectedGradeMajor.name}` : ''" @update:open="gradeAdminModal = false">
            <form class="space-y-4" @submit.prevent="submitGradeAdmin">
                <Field label="年级" :error="gradeAdminForm.errors.grade" required hint="授权后自动覆盖该专业该年级下全部班级">
                    <select v-model="gradeAdminForm.grade" class="input">
                        <option value="">请选择年级</option>
                        <option v-for="grade in gradeOptions" :key="grade" :value="grade">{{ grade }}</option>
                    </select>
                </Field>
                <Field label="用户" :error="gradeAdminForm.errors.user_id" required hint="仅可选择该学院内的用户">
                    <UserPicker v-model="gradeAdminForm.user_id" :users="gradeAdminCandidates" placeholder="搜索本学院教师、辅导员或学生" />
                </Field>
                <p v-if="gradeAdminForm.errors.major_id" class="text-xs text-rose-600">{{ gradeAdminForm.errors.major_id }}</p>
                <div class="flex justify-end gap-2 border-t border-slate-100 pt-4">
                    <Button variant="secondary" @click="gradeAdminModal = false">取消</Button>
                    <Button type="submit" :loading="gradeAdminForm.processing">授权年级</Button>
                </div>
            </form>
        </Modal>

        <Modal
            :open="counselorModal"
            title="统一配置辅导员班级"
            :description="counselorCollege ? `${counselorCollege.name} · 勾选该辅导员负责的班级` : ''"
            @update:open="counselorModal = false"
        >
            <form class="space-y-4" @submit.prevent="submitCounselor">
                <Field label="辅导员" :error="counselorForm.errors.user_id" required hint="从本学院的教师或已有辅导员中选择">
                    <UserPicker
                        v-model="counselorForm.user_id"
                        :users="counselorCandidates"
                        placeholder="搜索本学院教师或辅导员"
                        @change="syncCounselorClasses"
                    />
                </Field>

                <Field label="负责班级" :error="counselorForm.errors.class_ids" hint="可多选；保存后会覆盖该辅导员原有的班级授权">
                    <div class="max-h-64 space-y-1 overflow-y-auto rounded-xl border border-slate-200 bg-white/70 p-2">
                        <label
                            v-for="item in counselorClasses"
                            :key="item.id"
                            class="flex cursor-pointer items-center gap-2.5 rounded-lg px-2.5 py-2 text-sm text-slate-600 transition hover:bg-slate-50"
                        >
                            <input
                                v-model="counselorForm.class_ids"
                                type="checkbox"
                                :value="item.id"
                                :disabled="!counselorForm.user_id"
                                class="h-4 w-4 rounded border-slate-300 text-brand-600"
                            />
                            <span class="font-medium text-slate-700">{{ item.name }}</span>
                            <span class="text-xs text-slate-400">{{ item.major_name }} · {{ item.grade }}</span>
                        </label>
                        <p v-if="!counselorClasses.length" class="px-2 py-4 text-center text-xs text-slate-400">
                            本学院暂无班级，请先在学院下新增班级。
                        </p>
                    </div>
                </Field>

                <p class="text-xs leading-relaxed text-slate-400">
                    已选 {{ counselorForm.class_ids.length }} 个班。保存后只覆盖班级授权，“按专业 + 年级”的年级授权不受影响。
                </p>

                <div class="flex justify-end gap-2 border-t border-slate-100 pt-4">
                    <Button variant="secondary" @click="counselorModal = false">取消</Button>
                    <Button type="submit" :loading="counselorForm.processing">保存配置</Button>
                </div>
            </form>
        </Modal>

        <ConfirmDialog :open="!!deleteCollegeTarget" title="删除学院" :message="`确认删除“${deleteCollegeTarget?.name ?? ''}”吗？学院下仍有用户或班级时无法删除。`" confirm-text="确认删除" danger :loading="actionLoading" @update:open="deleteCollegeTarget = null" @confirm="confirmDeleteCollege" />
        <ConfirmDialog :open="!!deleteMajorTarget" title="删除专业" :message="`确认删除“${deleteMajorTarget?.name ?? ''}”吗？专业下仍有班级或学生时无法删除。`" confirm-text="确认删除" danger :loading="actionLoading" @update:open="deleteMajorTarget = null" @confirm="confirmDeleteMajor" />
        <ConfirmDialog :open="!!deleteClassTarget" title="删除班级" :message="`确认删除“${deleteClassTarget?.name ?? ''}”吗？班级下仍有学生时无法删除。`" confirm-text="确认删除" danger :loading="actionLoading" @update:open="deleteClassTarget = null" @confirm="confirmDeleteClass" />
        <ConfirmDialog :open="!!revokeCollegeAdminTarget" title="撤销学院管理员" :message="`确认撤销“${revokeCollegeAdminTarget?.user?.name ?? ''}”的 ${revokeCollegeAdminTarget?.college?.name ?? ''} 学院管理员授权吗？`" confirm-text="确认撤销" danger :loading="actionLoading" @update:open="revokeCollegeAdminTarget = null" @confirm="confirmRevokeCollegeAdmin" />
        <ConfirmDialog :open="!!revokeClassAdminTarget" title="撤销班级辅导员" :message="`确认撤销“${revokeClassAdminTarget?.user?.name ?? ''}”的 ${revokeClassAdminTarget?.class?.name ?? ''} 班级授权吗？`" confirm-text="确认撤销" danger :loading="actionLoading" @update:open="revokeClassAdminTarget = null" @confirm="confirmRevokeClassAdmin" />
        <ConfirmDialog :open="!!revokeGradeAdminTarget" title="撤销年级辅导员" :message="`确认撤销“${revokeGradeAdminTarget?.user?.name ?? ''}”的 ${revokeGradeAdminTarget?.major?.name ?? ''} ${revokeGradeAdminTarget?.grade ?? ''} 年级授权吗？`" confirm-text="确认撤销" danger :loading="actionLoading" @update:open="revokeGradeAdminTarget = null" @confirm="confirmRevokeGradeAdmin" />
        <ConfirmDialog :open="!!revokeOfficerTarget" title="卸任班干部" :message="`确认卸任“${revokeOfficerTarget?.user?.name ?? ''}”的 ${revokeOfficerTarget?.class?.name ?? ''} ${revokeOfficerTarget?.user?.title ?? ''}职务吗？卸任后其学生身份与申请功能不受影响。`" confirm-text="确认卸任" danger :loading="actionLoading" @update:open="revokeOfficerTarget = null" @confirm="confirmRevokeOfficer" />
    </AuthenticatedLayout>
</template>
