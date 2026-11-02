<script setup>
import { computed } from 'vue';
import { Head, Link } from '@inertiajs/vue3';
import { ArrowRight, GraduationCap, ShieldCheck, UserRound } from 'lucide-vue-next';
import GuestLayout from '@/layouts/GuestLayout.vue';

const props = defineProps({
    studentEnabled: { type: Boolean, default: false },
    teacherEnabled: { type: Boolean, default: false },
    hasClasses: { type: Boolean, default: false },
});

const studentAvailable = computed(() => props.studentEnabled);
const studentNote = computed(() => {
    if (!props.studentEnabled) return '学生自主注册暂未开放';
    if (!props.hasClasses) return '当前暂无班级，可进入查看；请联系管理员导入班级后再提交注册';
    return '使用学号注册，选择学院、专业、年级和班级';
});
</script>

<template>
    <Head title="注册账号" />

    <GuestLayout title="注册账号" subtitle="请选择注册身份，学生和教师使用不同的注册流程">
        <div class="space-y-3">
            <component
                :is="studentAvailable ? Link : 'div'"
                :href="studentAvailable ? '/register/student' : undefined"
                :class="[
                    'group flex items-start gap-4 rounded-2xl border px-4 py-4 transition',
                    studentAvailable
                        ? 'border-slate-200 bg-white hover:border-brand-300 hover:shadow-md'
                        : 'cursor-not-allowed border-slate-200 bg-slate-50/80 opacity-70',
                ]"
            >
                <span class="flex h-11 w-11 shrink-0 items-center justify-center rounded-xl bg-brand-50 text-brand-600">
                    <GraduationCap class="h-5 w-5" />
                </span>
                <span class="min-w-0 flex-1">
                    <span class="flex items-center justify-between gap-3">
                        <span class="text-sm font-semibold text-slate-900">学生注册</span>
                        <ArrowRight v-if="studentAvailable" class="h-4 w-4 text-slate-300 transition group-hover:text-brand-500" />
                        <span v-else class="rounded-full bg-slate-100 px-2 py-0.5 text-[11px] text-slate-500">暂不可用</span>
                    </span>
                    <span class="mt-1 block text-xs leading-relaxed text-slate-500">{{ studentNote }}</span>
                    <span class="mt-2 block text-[11px] text-slate-400">学生注册不需要邀请码</span>
                </span>
            </component>

            <component
                :is="teacherEnabled ? Link : 'div'"
                :href="teacherEnabled ? '/register/teacher' : undefined"
                :class="[
                    'group flex items-start gap-4 rounded-2xl border px-4 py-4 transition',
                    teacherEnabled
                        ? 'border-slate-200 bg-white hover:border-brand-300 hover:shadow-md'
                        : 'cursor-not-allowed border-slate-200 bg-slate-50/80 opacity-70',
                ]"
            >
                <span class="flex h-11 w-11 shrink-0 items-center justify-center rounded-xl bg-amber-50 text-amber-600">
                    <ShieldCheck class="h-5 w-5" />
                </span>
                <span class="min-w-0 flex-1">
                    <span class="flex items-center justify-between gap-3">
                        <span class="text-sm font-semibold text-slate-900">教师注册</span>
                        <ArrowRight v-if="teacherEnabled" class="h-4 w-4 text-slate-300 transition group-hover:text-brand-500" />
                        <span v-else class="rounded-full bg-slate-100 px-2 py-0.5 text-[11px] text-slate-500">暂未开放</span>
                    </span>
                    <span class="mt-1 block text-xs leading-relaxed text-slate-500">使用工号注册，只需选择学院和专业，不绑定具体班级</span>
                    <span class="mt-2 block text-[11px] text-amber-600">教师注册需要邀请码，并需管理员审核启用</span>
                </span>
            </component>
        </div>

        <div class="mt-6 flex items-start gap-2 rounded-xl bg-slate-50 px-4 py-3 text-xs leading-relaxed text-slate-500">
            <UserRound class="mt-0.5 h-4 w-4 shrink-0" />
            学生使用学号登录；教师使用工号登录。请按真实身份注册，避免影响成绩和审核权限。
        </div>

        <p class="mt-5 text-center text-sm text-slate-500">
            已有账号？
            <Link href="/login" class="font-medium text-brand-600 hover:text-brand-700">返回登录</Link>
        </p>
    </GuestLayout>
</template>
