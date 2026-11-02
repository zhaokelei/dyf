<script setup>
import { Head, Link, useForm } from '@inertiajs/vue3';
import { GraduationCap, ShieldCheck } from 'lucide-vue-next';
import GuestLayout from '@/layouts/GuestLayout.vue';
import Field from '@/components/ui/Field.vue';
import Button from '@/components/ui/Button.vue';

const props = defineProps({
    enabled: { type: Boolean, default: false },
    colleges: { type: Array, default: () => [] },
    requiresApproval: { type: Boolean, default: true },
});

const form = useForm({
    employee_id: '',
    name: '',
    college_id: '',
    invite_code: '',
    password: '',
    password_confirmation: '',
});

function submit() {
    form.post('/register/teacher', {
        preserveScroll: true,
        onFinish: () => form.reset('password', 'password_confirmation'),
    });
}
</script>

<template>
    <Head title="教师注册" />

    <GuestLayout title="教师注册" subtitle="教师账号归属到学院，具体班级由学院管理员分配">
        <div v-if="!enabled" class="rounded-xl border border-amber-200 bg-amber-50 px-4 py-4 text-sm leading-relaxed text-amber-700">
            教师自主注册暂未开放，请联系学校管理员。
        </div>

        <form v-else class="space-y-4" @submit.prevent="submit">
            <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                <Field label="工号" :error="form.errors.employee_id" required hint="工号即登录账号">
                    <input v-model="form.employee_id" type="text" autocomplete="username" class="input" placeholder="请输入工号" />
                </Field>
                <Field label="姓名" :error="form.errors.name" required>
                    <input v-model="form.name" type="text" class="input" placeholder="请输入姓名" />
                </Field>
            </div>

            <Field label="所属学院" :error="form.errors.college_id" required hint="教师账号只归属到学院，无需选择专业">
                <select v-model="form.college_id" class="input">
                    <option value="">请选择学院</option>
                    <option v-for="college in colleges" :key="college.id" :value="college.id">{{ college.name }}</option>
                </select>
            </Field>

            <Field label="教师邀请码" :error="form.errors.invite_code" required hint="向学校管理员获取，仅用于教师身份校验">
                <input v-model="form.invite_code" type="text" autocomplete="off" class="input font-mono tracking-wider" placeholder="请输入教师邀请码" />
            </Field>

            <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                <Field label="密码" :error="form.errors.password" required hint="至少 8 位">
                    <input v-model="form.password" type="password" autocomplete="new-password" class="input" />
                </Field>
                <Field label="确认密码" :error="form.errors.password_confirmation" required>
                    <input v-model="form.password_confirmation" type="password" autocomplete="new-password" class="input" />
                </Field>
            </div>

            <div class="flex items-start gap-2 rounded-xl bg-slate-50 px-4 py-3 text-xs leading-relaxed text-slate-500">
                <ShieldCheck class="mt-0.5 h-4 w-4 shrink-0" />
                教师注册必须填写邀请码，用于校验教师身份；注册成功后账号立即启用，可直接用工号和密码登录。
            </div>

            <div class="flex items-start gap-2 rounded-xl bg-brand-50/70 px-4 py-3 text-xs leading-relaxed text-brand-700">
                <GraduationCap class="mt-0.5 h-4 w-4 shrink-0" />
                教师账号只归属到学院；如需管理班级，由系统管理员或学院管理员在组织管理中配置为辅导员并授权具体班级。
            </div>

            <Button type="submit" block size="lg" :loading="form.processing">提交教师注册申请</Button>

            <p class="text-center text-sm text-slate-500">
                已有账号？
                <Link href="/login" class="font-medium text-brand-600 hover:text-brand-700">返回登录</Link>
                <span class="mx-1 text-slate-300">·</span>
                <Link href="/register" class="font-medium text-brand-600 hover:text-brand-700">切换身份</Link>
            </p>
        </form>
    </GuestLayout>
</template>
