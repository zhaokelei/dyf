<script setup>
import { Head, useForm } from '@inertiajs/vue3';
import { Info, Save } from 'lucide-vue-next';
import AuthenticatedLayout from '@/layouts/AuthenticatedLayout.vue';
import PageHeader from '@/components/ui/PageHeader.vue';
import Card from '@/components/ui/Card.vue';
import Field from '@/components/ui/Field.vue';
import Button from '@/components/ui/Button.vue';
import Toggle from '@/components/ui/Toggle.vue';

const props = defineProps({
    settings: { type: Object, required: true },
});

const form = useForm({
    base_score: props.settings.base_score,
    system_name: props.settings.system_name,
    registration_enabled: props.settings.registration_enabled,
    registration_requires_approval: props.settings.registration_requires_approval,
    teacher_registration_enabled: props.settings.teacher_registration_enabled,
    teacher_registration_code: props.settings.teacher_registration_code,
});

function generateTeacherCode() {
    const alphabet = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';
    form.teacher_registration_code = Array.from(
        { length: 10 },
        () => alphabet[Math.floor(Math.random() * alphabet.length)],
    ).join('');
}

function submit() {
    form.put('/admin/settings', { preserveScroll: true });
}
</script>

<template>
    <Head title="系统设置" />

    <AuthenticatedLayout>
        <PageHeader title="系统设置" description="配置基础分、学生/教师注册与系统显示名称" />

        <form class="grid grid-cols-1 gap-4 lg:grid-cols-3" @submit.prevent="submit">
            <Card class="lg:col-span-2" title="基础设置">
                <div class="space-y-4">
                    <Field label="系统名称" :error="form.errors.system_name" required hint="显示在登录页和侧边栏">
                        <input v-model="form.system_name" type="text" maxlength="100" class="input" />
                    </Field>

                    <Field label="学生基础分" :error="form.errors.base_score" required hint="每位学生的起始德育分，修改后排行榜与个人总分实时生效">
                        <input v-model="form.base_score" type="number" min="0" max="1000" class="input" />
                    </Field>

                    <div class="space-y-3 border-t border-slate-100 pt-4">
                        <div class="flex items-center justify-between gap-4 rounded-xl border border-white/60 bg-white/60 px-4 py-3 backdrop-blur">
                            <div>
                                <p class="text-sm font-medium text-slate-700">开放学生自主注册</p>
                                <p class="mt-0.5 text-xs text-slate-400">关闭后学生注册入口将不可用</p>
                            </div>
                            <Toggle v-model="form.registration_enabled" />
                        </div>
                        <div class="flex items-center justify-between gap-4 rounded-xl border border-white/60 bg-white/60 px-4 py-3 backdrop-blur">
                            <div>
                                <p class="text-sm font-medium text-slate-700">注册需要管理员审核</p>
                                <p class="mt-0.5 text-xs text-slate-400">开启后新注册账号默认禁用，需管理员启用</p>
                            </div>
                            <Toggle v-model="form.registration_requires_approval" :disabled="!form.registration_enabled" />
                        </div>
                    </div>

                    <div class="space-y-3 border-t border-slate-100 pt-4">
                        <div class="flex items-center justify-between gap-4 rounded-xl border border-white/60 bg-white/60 px-4 py-3 backdrop-blur">
                            <div>
                                <p class="text-sm font-medium text-slate-700">开放教师自主注册</p>
                                <p class="mt-0.5 text-xs text-slate-400">教师凭工号、学院和邀请码注册，注册成功后账号立即启用</p>
                            </div>
                            <Toggle v-model="form.teacher_registration_enabled" />
                        </div>

                        <Field
                            label="教师注册邀请码"
                            :error="form.errors.teacher_registration_code"
                            hint="只发给本校教师，不要公开；持有邀请码注册的教师账号会直接启用"
                        >
                            <div class="flex gap-2">
                                <input v-model="form.teacher_registration_code" type="text" class="input font-mono tracking-wider" placeholder="至少 6 位字母或数字" />
                                <Button type="button" variant="secondary" class="shrink-0 whitespace-nowrap" @click="generateTeacherCode">随机生成</Button>
                            </div>
                        </Field>

                        <p class="text-xs leading-relaxed text-slate-400">
                            教师注册只需选择所属学院，不绑定具体班级；邀请码仅用于校验教师身份，注册成功后即可直接登录。
                        </p>
                    </div>

                    <div class="flex justify-end border-t border-slate-100 pt-4">
                        <Button type="submit" :loading="form.processing">
                            <Save class="h-4 w-4" />
                            保存设置
                        </Button>
                    </div>
                </div>
            </Card>

            <Card title="计分规则">
                <div class="flex items-start gap-2 rounded-xl bg-brand-50/60 px-4 py-3 text-sm text-brand-800">
                    <Info class="mt-0.5 h-4 w-4 shrink-0" />
                    <div class="space-y-2 leading-relaxed">
                        <p class="font-medium">总分 = 基础分 + 累计加分 - 累计扣分</p>
                        <p class="text-xs text-brand-700/80">当前规则不设总分上限，最低为 0 分。排行榜按总分降序排列。</p>
                    </div>
                </div>
                <ul class="mt-4 space-y-2 text-xs leading-relaxed text-slate-500">
                    <li>· 学生提交申请时填写分值，管理员审核时可修正核定分值。</li>
                    <li>· 扣分类别的申请会自动按负分计入总分。</li>
                    <li>· 类别、参考分值与单次上限可在“类别管理”中调整。</li>
                    <li>· 所有审核、成绩与账号操作都会写入审计日志。</li>
                </ul>
            </Card>
        </form>
    </AuthenticatedLayout>
</template>
