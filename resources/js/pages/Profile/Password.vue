<script setup>
import { Head, useForm, usePage } from '@inertiajs/vue3';
import { KeyRound, ShieldCheck } from 'lucide-vue-next';
import AuthenticatedLayout from '@/layouts/AuthenticatedLayout.vue';
import PageHeader from '@/components/ui/PageHeader.vue';
import Card from '@/components/ui/Card.vue';
import Field from '@/components/ui/Field.vue';
import Button from '@/components/ui/Button.vue';

const page = usePage();
const form = useForm({
    current_password: '',
    password: '',
    password_confirmation: '',
});

function submit() {
    form.put('/profile/password', {
        preserveScroll: true,
        onSuccess: () => form.reset(),
    });
}
</script>

<template>
    <Head title="修改密码" />

    <AuthenticatedLayout>
        <PageHeader title="修改密码" description="建议使用字母、数字与符号组合的强密码" />

        <div class="grid grid-cols-1 gap-4 lg:grid-cols-3">
            <Card class="lg:col-span-2" title="账号安全">
                <form class="space-y-4" @submit.prevent="submit">
                    <Field label="当前密码" :error="form.errors.current_password" required>
                        <input v-model="form.current_password" type="password" autocomplete="current-password" class="input" />
                    </Field>
                    <Field label="新密码" :error="form.errors.password" required hint="至少 8 位">
                        <input v-model="form.password" type="password" autocomplete="new-password" class="input" />
                    </Field>
                    <Field label="确认新密码" :error="form.errors.password_confirmation" required>
                        <input v-model="form.password_confirmation" type="password" autocomplete="new-password" class="input" />
                    </Field>

                    <div class="flex justify-end border-t border-slate-100 pt-4">
                        <Button type="submit" :loading="form.processing">
                            <ShieldCheck class="h-4 w-4" />
                            更新密码
                        </Button>
                    </div>
                </form>
            </Card>

            <Card title="当前账号">
                <div class="flex items-center gap-3">
                    <span class="flex h-11 w-11 items-center justify-center rounded-full bg-slate-900 text-sm font-semibold text-white">
                        {{ page.props.auth.user.name.slice(0, 1) }}
                    </span>
                    <div>
                        <p class="text-sm font-semibold text-slate-800">{{ page.props.auth.user.name }}</p>
                        <p class="text-xs text-slate-400">{{ page.props.auth.user.role_label }} · {{ page.props.auth.user.username }}</p>
                    </div>
                </div>
                <div class="mt-5 flex items-start gap-2 rounded-xl bg-slate-50 px-4 py-3 text-xs leading-relaxed text-slate-500">
                    <KeyRound class="mt-0.5 h-4 w-4 shrink-0" />
                    修改密码后，系统会退出当前账号在所有设备上的登录，需要使用新密码重新登录。
                </div>
            </Card>
        </div>
    </AuthenticatedLayout>
</template>
