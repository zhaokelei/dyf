<script setup>
import { computed, ref, watch } from 'vue';
import { Head, Link, useForm } from '@inertiajs/vue3';
import { GraduationCap } from 'lucide-vue-next';
import GuestLayout from '@/layouts/GuestLayout.vue';
import Field from '@/components/ui/Field.vue';
import Button from '@/components/ui/Button.vue';

const props = defineProps({
    enabled: { type: Boolean, default: true },
    hasClasses: { type: Boolean, default: false },
    colleges: { type: Array, default: () => [] },
    classes: { type: Array, default: () => [] },
    requiresApproval: { type: Boolean, default: false },
});

const form = useForm({
    student_id: '',
    name: '',
    college_id: '',
    major_id: '',
    grade: '',
    class_id: '',
    password: '',
    password_confirmation: '',
});

const selectedCollege = computed(() => props.colleges.find((item) => item.id === form.college_id) ?? null);
const majors = computed(() => selectedCollege.value?.majors ?? []);
const gradeOptions = computed(() => {
    const grades = props.classes
        .filter((item) => item.college_id === form.college_id && item.major_id === form.major_id)
        .map((item) => item.grade)
        .filter(Boolean);

    return [...new Set(grades)];
});
const classOptions = computed(() =>
    props.classes.filter(
        (item) =>
            item.college_id === form.college_id &&
            item.major_id === form.major_id &&
            (!form.grade || item.grade === form.grade),
    ),
);

watch(
    () => form.college_id,
    () => {
        form.major_id = '';
        form.grade = '';
        form.class_id = '';
    },
);

watch(
    () => form.major_id,
    () => {
        form.grade = '';
        form.class_id = '';
    },
);

watch(
    () => form.grade,
    () => {
        form.class_id = '';
    },
);

function submit() {
    form.post('/register/student', {
        preserveScroll: true,
        onFinish: () => form.reset('password', 'password_confirmation'),
    });
}
</script>

<template>
    <Head title="学生注册" />

    <GuestLayout title="学生注册" subtitle="使用本人学号注册，学院、专业、年级与班级须与学校信息一致">
        <div v-if="!enabled" class="rounded-xl border border-amber-200 bg-amber-50 px-4 py-4 text-sm leading-relaxed text-amber-700">
            学生自主注册暂未开放，请联系学院管理员或学校管理员。
        </div>

        <div v-else-if="!hasClasses" class="rounded-xl border border-amber-200 bg-amber-50 px-4 py-4 text-sm leading-relaxed text-amber-700">
            当前暂无可选班级，学生注册暂时无法完成。请联系管理员在“组织管理”中新增班级后再注册。
        </div>

        <form v-else class="space-y-4" @submit.prevent="submit">
            <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                <Field label="学号" :error="form.errors.student_id" required hint="学号即登录账号">
                    <input v-model="form.student_id" type="text" autocomplete="username" class="input" placeholder="请输入学号" />
                </Field>
                <Field label="姓名" :error="form.errors.name" required>
                    <input v-model="form.name" type="text" class="input" placeholder="请输入姓名" />
                </Field>
            </div>

            <Field label="学院 / 书院" :error="form.errors.college_id" required>
                <select v-model="form.college_id" class="input">
                    <option value="">请选择学院</option>
                    <option v-for="college in colleges" :key="college.id" :value="college.id">{{ college.name }}</option>
                </select>
            </Field>

            <Field label="专业" :error="form.errors.major_id" required>
                <select v-model="form.major_id" class="input" :disabled="!form.college_id">
                    <option value="">请选择专业</option>
                    <option v-for="major in majors" :key="major.id" :value="major.id">{{ major.name }}</option>
                </select>
            </Field>

            <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                <Field label="年级" :error="form.errors.grade" required>
                    <select v-model="form.grade" class="input" :disabled="!form.major_id">
                        <option value="">请选择年级</option>
                        <option v-for="grade in gradeOptions" :key="grade" :value="grade">{{ grade }}</option>
                    </select>
                </Field>
                <Field label="班级" :error="form.errors.class_id" required>
                    <select v-model="form.class_id" class="input" :disabled="!form.grade">
                        <option value="">请选择班级</option>
                        <option v-for="item in classOptions" :key="item.id" :value="item.id">{{ item.name }}</option>
                    </select>
                </Field>
            </div>

            <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                <Field label="密码" :error="form.errors.password" required hint="至少 8 位">
                    <input v-model="form.password" type="password" autocomplete="new-password" class="input" />
                </Field>
                <Field label="确认密码" :error="form.errors.password_confirmation" required>
                    <input v-model="form.password_confirmation" type="password" autocomplete="new-password" class="input" />
                </Field>
            </div>

            <div v-if="requiresApproval" class="flex items-start gap-2 rounded-xl bg-slate-50 px-4 py-3 text-xs leading-relaxed text-slate-500">
                <GraduationCap class="mt-0.5 h-4 w-4 shrink-0" />
                注册后需要管理员审核通过才能登录，请确保所填信息真实准确。
            </div>

            <Button type="submit" block size="lg" :loading="form.processing">注册并登录</Button>
        </form>

        <p class="mt-5 text-center text-sm text-slate-500">
            已有账号？
            <Link href="/login" class="font-medium text-brand-600 hover:text-brand-700">返回登录</Link>
            <span class="mx-1 text-slate-300">·</span>
            <Link href="/register" class="font-medium text-brand-600 hover:text-brand-700">切换身份</Link>
        </p>
    </GuestLayout>
</template>
