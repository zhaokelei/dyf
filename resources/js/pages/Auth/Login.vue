<script setup>
import { ref } from 'vue';
import { Head, Link, useForm } from '@inertiajs/vue3';
import { Eye, EyeOff, LockKeyhole, UserRound } from 'lucide-vue-next';
import GuestLayout from '@/layouts/GuestLayout.vue';
import Field from '@/components/ui/Field.vue';
import Button from '@/components/ui/Button.vue';

const form = useForm({
    account: '',
    password: '',
    remember: false,
});

const showPassword = ref(false);

function submit() {
    form.post('/login', {
        onFinish: () => form.reset('password'),
    });
}
</script>

<template>
    <Head title="登录" />

    <GuestLayout title="欢迎回来" subtitle="使用学号、工号或管理员账号登录" mobile-intro>
        <form class="space-y-4" @submit.prevent="submit">
            <Field label="学号 / 工号" :error="form.errors.account" required>
                <div class="relative">
                    <UserRound class="pointer-events-none absolute left-3.5 top-1/2 h-4 w-4 -translate-y-1/2 text-slate-400" />
                    <input
                        v-model="form.account"
                        type="text"
                        inputmode="text"
                        autocomplete="username"
                        placeholder="请输入学号或工号"
                        class="input !pl-10"
                        autofocus
                    />
                </div>
            </Field>

            <Field label="密码" :error="form.errors.password" required>
                <div class="relative">
                    <LockKeyhole class="pointer-events-none absolute left-3.5 top-1/2 h-4 w-4 -translate-y-1/2 text-slate-400" />
                    <input
                        v-model="form.password"
                        :type="showPassword ? 'text' : 'password'"
                        autocomplete="current-password"
                        placeholder="请输入密码"
                        class="input !pl-10 !pr-11"
                    />
                    <button
                        type="button"
                        class="absolute right-2 top-1/2 flex h-8 w-8 -translate-y-1/2 items-center justify-center rounded-lg text-slate-400 transition hover:bg-slate-100 hover:text-slate-600"
                        @click="showPassword = !showPassword"
                    >
                        <component :is="showPassword ? EyeOff : Eye" class="h-4 w-4" />
                    </button>
                </div>
            </Field>

            <div class="flex items-center justify-between text-sm">
                <label class="flex cursor-pointer items-center gap-2 text-slate-500">
                    <input v-model="form.remember" type="checkbox" class="h-4 w-4 rounded border-slate-300 text-brand-600 focus:ring-brand-500" />
                    记住我
                </label>
                <span class="text-xs text-slate-400">忘记密码请联系管理员</span>
            </div>

            <Button type="submit" block size="lg" :loading="form.processing">登录</Button>
        </form>

        <p
            v-if="$page.props.appSettings?.registration_enabled || $page.props.appSettings?.teacher_registration_enabled"
            class="mt-5 text-center text-sm text-slate-500"
        >
            还没有账号？
            <Link href="/register" class="font-medium text-brand-600 hover:text-brand-700">注册账号</Link>
        </p>

        <div class="mt-6 rounded-xl border border-slate-200 bg-white/70 px-4 py-3 text-xs leading-relaxed text-slate-500">
            <p class="font-medium text-slate-700">登录说明</p>
            <p class="mt-1">学生使用学号登录，教师使用工号登录；如账号未启用或忘记密码，请联系学校管理员。</p>
        </div>
    </GuestLayout>
</template>
