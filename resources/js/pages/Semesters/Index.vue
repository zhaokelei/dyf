<script setup>
import { computed, ref } from 'vue';
import { Head, router, useForm } from '@inertiajs/vue3';
import { CalendarCheck2, CalendarRange, Plus, Trash2 } from 'lucide-vue-next';
import AuthenticatedLayout from '@/layouts/AuthenticatedLayout.vue';
import PageHeader from '@/components/ui/PageHeader.vue';
import Card from '@/components/ui/Card.vue';
import Badge from '@/components/ui/Badge.vue';
import EmptyState from '@/components/ui/EmptyState.vue';
import Modal from '@/components/ui/Modal.vue';
import ConfirmDialog from '@/components/ui/ConfirmDialog.vue';
import Field from '@/components/ui/Field.vue';
import Button from '@/components/ui/Button.vue';

const props = defineProps({
    semesters: { type: Array, default: () => [] },
});

const modalOpen = ref(false);
const currentTarget = ref(null);
const deleteTarget = ref(null);
const actionLoading = ref(false);

const form = useForm({
    academic_year: '',
    term: 1,
    start_date: '',
    end_date: '',
    create_both: true,
});

const grouped = computed(() => {
    const groups = {};
    props.semesters.forEach((semester) => {
        groups[semester.academic_year] = groups[semester.academic_year] ?? [];
        groups[semester.academic_year].push(semester);
    });

    return Object.entries(groups)
        .sort(([a], [b]) => b.localeCompare(a))
        .map(([year, items]) => ({
            year,
            semesters: items.sort((a, b) => a.term - b.term),
        }));
});

function openCreate() {
    form.reset();
    form.clearErrors();
    modalOpen.value = true;
}

function submit() {
    form.post('/admin/semesters', {
        preserveScroll: true,
        onSuccess: () => {
            modalOpen.value = false;
            form.reset();
        },
    });
}

function setCurrent() {
    if (!currentTarget.value) return;

    actionLoading.value = true;
    router.post(`/admin/semesters/${currentTarget.value.id}/current`, {}, {
        preserveScroll: true,
        onFinish: () => {
            actionLoading.value = false;
            currentTarget.value = null;
        },
    });
}

function destroy() {
    if (!deleteTarget.value) return;

    actionLoading.value = true;
    router.delete(`/admin/semesters/${deleteTarget.value.id}`, {
        preserveScroll: true,
        onFinish: () => {
            actionLoading.value = false;
            deleteTarget.value = null;
        },
    });
}
</script>

<template>
    <Head title="学期管理" />

    <AuthenticatedLayout>
        <PageHeader title="学期管理" description="按学期记录成绩，学年成绩由两个学期汇总得出">
            <template #actions>
                <button type="button" class="btn-primary" @click="openCreate">
                    <Plus class="h-4 w-4" />
                    新增学期
                </button>
            </template>
        </PageHeader>

        <div class="mb-4 flex items-start gap-3 rounded-xl border border-brand-100 bg-brand-50/60 px-4 py-3 text-sm text-brand-800">
            <CalendarRange class="mt-0.5 h-4 w-4 shrink-0" />
            <p class="leading-relaxed">
                每个学年包含第一学期与第二学期，成绩分别保留。需要学年成绩时，在排行榜或数据统计中选择“学年汇总”即可聚合两个学期。
            </p>
        </div>

        <div v-if="grouped.length" class="space-y-4">
            <Card v-for="group in grouped" :key="group.year" :padding="false" :title="`${group.year} 学年`" :description="`${group.semesters.length} 个学期`">
                <div class="table-wrap">
                    <table class="data-table">
                        <thead>
                            <tr>
                                <th>学期</th>
                                <th>起止日期</th>
                                <th>状态</th>
                                <th class="text-right">个人申请</th>
                                <th class="text-right">集体申请</th>
                                <th class="text-right">成绩记录</th>
                                <th class="text-right">操作</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr v-for="semester in group.semesters" :key="semester.id">
                                <td class="font-medium text-slate-800">{{ semester.name }}</td>
                                <td class="whitespace-nowrap text-xs text-slate-500">
                                    {{ semester.start_date || '未设置' }} ~ {{ semester.end_date || '未设置' }}
                                </td>
                                <td>
                                    <Badge v-if="semester.is_current" status="active">当前学期</Badge>
                                    <span v-else class="text-xs text-slate-400">历史学期</span>
                                </td>
                                <td class="text-right text-slate-600">{{ semester.applications }}</td>
                                <td class="text-right text-slate-600">{{ semester.groups }}</td>
                                <td class="text-right text-slate-600">{{ semester.records }}</td>
                                <td>
                                    <div class="flex items-center justify-end gap-2">
                                        <button v-if="!semester.is_current" type="button" class="btn-secondary !px-3 !py-1.5 text-xs" @click="currentTarget = semester">
                                            <CalendarCheck2 class="h-3.5 w-3.5" />
                                            设为当前
                                        </button>
                                        <button v-if="!semester.is_current" type="button" class="btn-ghost !px-2.5 !py-1.5 hover:!text-rose-600" title="删除学期" @click="deleteTarget = semester">
                                            <Trash2 class="h-4 w-4" />
                                        </button>
                                    </div>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </Card>
        </div>
        <Card v-else>
            <EmptyState title="尚未创建学期" description="创建学年与学期并设为当前后即可开始接收申请">
                <button type="button" class="btn-primary" @click="openCreate">新增学期</button>
            </EmptyState>
        </Card>

        <Modal :open="modalOpen" title="新增学期" description="可一次创建该学年的两个学期" @update:open="modalOpen = false">
            <form class="space-y-4" @submit.prevent="submit">
                <Field label="学年" :error="form.errors.academic_year" required hint="如 2026-2027，也支持 2026">
                    <input v-model="form.academic_year" type="text" placeholder="2026-2027" class="input" />
                </Field>

                <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                    <Field label="学期" :error="form.errors.term" required>
                        <select v-model.number="form.term" class="input" :disabled="form.create_both">
                            <option :value="1">第一学期</option>
                            <option :value="2">第二学期</option>
                        </select>
                    </Field>
                    <Field label="创建方式">
                        <label class="flex h-[42px] items-center gap-2 rounded-xl border border-slate-200 px-3.5 text-sm text-slate-600">
                            <input v-model="form.create_both" type="checkbox" class="h-4 w-4 rounded border-slate-300 text-brand-600" />
                            同时创建两个学期
                        </label>
                    </Field>
                </div>

                <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                    <Field label="开始日期" :error="form.errors.start_date">
                        <input v-model="form.start_date" type="date" class="input" />
                    </Field>
                    <Field label="结束日期" :error="form.errors.end_date">
                        <input v-model="form.end_date" type="date" class="input" />
                    </Field>
                </div>

                <div class="flex justify-end gap-2 border-t border-slate-100 pt-4">
                    <Button variant="secondary" @click="modalOpen = false">取消</Button>
                    <Button type="submit" :loading="form.processing">创建学期</Button>
                </div>
            </form>
        </Modal>

        <ConfirmDialog
            :open="!!currentTarget"
            title="设为当前学期"
            :message="`确认将 ${currentTarget?.name ?? ''} 设为当前学期吗？之后的申请默认记入该学期。`"
            confirm-text="确认设置"
            :loading="actionLoading"
            @update:open="currentTarget = null"
            @confirm="setCurrent"
        />

        <ConfirmDialog
            :open="!!deleteTarget"
            title="删除学期"
            :message="`确认删除 ${deleteTarget?.name ?? ''} 吗？已有申请或成绩的学期无法删除。`"
            confirm-text="确认删除"
            danger
            :loading="actionLoading"
            @update:open="deleteTarget = null"
            @confirm="destroy"
        />
    </AuthenticatedLayout>
</template>
