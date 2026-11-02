<script setup>
import { computed, ref } from 'vue';
import { Head, router, useForm } from '@inertiajs/vue3';
import { Pencil, Plus, Tags, Trash2 } from 'lucide-vue-next';
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
    categories: { type: Array, default: () => [] },
});

const modalOpen = ref(false);
const editing = ref(null);
const deleteTarget = ref(null);
const actionLoading = ref(false);

const form = useForm({
    name: '',
    type: 'add',
    description: '',
    default_score: '',
    max_score: '',
    sort_order: 0,
    is_active: true,
});

const categories = computed(() => props.categories);

function openCreate() {
    editing.value = null;
    form.clearErrors();
    form.defaults({
        name: '',
        type: 'add',
        description: '',
        default_score: '',
        max_score: '',
        sort_order: 0,
        is_active: true,
    });
    form.reset();
    modalOpen.value = true;
}

function openEdit(category) {
    editing.value = category;
    form.clearErrors();
    form.defaults({
        name: category.name,
        type: category.type,
        description: category.description ?? '',
        default_score: category.default_score ?? '',
        max_score: category.max_score ?? '',
        sort_order: category.sort_order ?? 0,
        is_active: category.is_active,
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
        form.put(`/admin/categories/${editing.value.id}`, options);
    } else {
        form.post('/admin/categories', options);
    }
}

function destroy() {
    if (!deleteTarget.value) return;

    actionLoading.value = true;
    router.delete(`/admin/categories/${deleteTarget.value.id}`, {
        preserveScroll: true,
        onFinish: () => {
            actionLoading.value = false;
            deleteTarget.value = null;
        },
    });
}
</script>

<template>
    <Head title="类别管理" />

    <AuthenticatedLayout>
        <PageHeader title="类别管理" description="配置加分与扣分类别、参考分值与单次上限">
            <template #actions>
                <button type="button" class="btn-primary" @click="openCreate">
                    <Plus class="h-4 w-4" />
                    新增类别
                </button>
            </template>
        </PageHeader>

        <div class="mb-4 flex items-start gap-3 rounded-xl border border-brand-100 bg-brand-50/60 px-4 py-3 text-sm text-brand-800">
            <Tags class="mt-0.5 h-4 w-4 shrink-0" />
            <p class="leading-relaxed">
                学生申请时从启用的类别中选择；分值由学生填写、管理员审核时核定。
                <span class="font-medium">单次上限留空表示不限制</span>，扣分类别会按负分计入总分。
            </p>
        </div>

        <Card :padding="false">
            <div v-if="categories.length" class="table-wrap">
                <table class="data-table">
                    <thead>
                        <tr>
                            <th>类别名称</th>
                            <th>类型</th>
                            <th class="hidden md:table-cell">说明</th>
                            <th class="text-right">参考分值</th>
                            <th class="text-right">单次上限</th>
                            <th class="text-right">排序</th>
                            <th>状态</th>
                            <th class="hidden lg:table-cell">使用情况</th>
                            <th class="text-right">操作</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="category in categories" :key="category.id">
                            <td class="font-medium text-slate-800">{{ category.name }}</td>
                            <td><Badge :status="category.type" /></td>
                            <td class="hidden max-w-[260px] md:table-cell">
                                <span class="line-clamp-2 text-slate-500">{{ category.description || '—' }}</span>
                            </td>
                            <td class="text-right text-slate-600">{{ category.default_score ?? '—' }}</td>
                            <td class="text-right text-slate-600">{{ category.max_score ?? '不限' }}</td>
                            <td class="text-right text-slate-400">{{ category.sort_order }}</td>
                            <td><Badge :status="category.is_active ? 'active' : 'inactive'" /></td>
                            <td class="hidden whitespace-nowrap lg:table-cell">
                                <span class="text-xs text-slate-400">申请 {{ category.applications_count }} · 记录 {{ category.records_count }}</span>
                            </td>
                            <td>
                                <div class="flex items-center justify-end gap-1">
                                    <button type="button" class="btn-ghost !px-2.5 !py-1.5" title="编辑" @click="openEdit(category)">
                                        <Pencil class="h-4 w-4" />
                                    </button>
                                    <button type="button" class="btn-ghost !px-2.5 !py-1.5 hover:!text-rose-600" title="删除" @click="deleteTarget = category">
                                        <Trash2 class="h-4 w-4" />
                                    </button>
                                </div>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
            <EmptyState v-else title="还没有配置类别" description="至少添加一个加分类别后才能接收申请">
                <button type="button" class="btn-primary" @click="openCreate">新增类别</button>
            </EmptyState>
        </Card>

        <Modal :open="modalOpen" :title="editing ? '编辑类别' : '新增类别'" @update:open="modalOpen = false">
            <form class="space-y-4" @submit.prevent="submit">
                <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                    <Field label="类别名称" :error="form.errors.name" required>
                        <input v-model="form.name" type="text" maxlength="50" class="input" placeholder="如 校级活动" />
                    </Field>
                    <Field label="类型" :error="form.errors.type" required>
                        <select v-model="form.type" class="input">
                            <option value="add">加分</option>
                            <option value="deduct">扣分</option>
                        </select>
                    </Field>
                </div>

                <Field label="说明" :error="form.errors.description">
                    <textarea v-model="form.description" rows="3" maxlength="500" class="input resize-none" placeholder="说明该类别适用的活动范围" />
                </Field>

                <div class="grid grid-cols-1 gap-4 sm:grid-cols-3">
                    <Field label="参考分值" :error="form.errors.default_score" hint="选填，申请时自动带出">
                        <input v-model="form.default_score" type="number" min="0" step="0.5" inputmode="decimal" class="input" />
                    </Field>
                    <Field label="单次上限" :error="form.errors.max_score" hint="留空不限制，支持 0.5 分">
                        <input v-model="form.max_score" type="number" min="0.5" step="0.5" inputmode="decimal" class="input" />
                    </Field>
                    <Field label="排序" :error="form.errors.sort_order" hint="数字越小越靠前">
                        <input v-model="form.sort_order" type="number" min="0" class="input" />
                    </Field>
                </div>

                <label class="flex items-center gap-2 text-sm text-slate-600">
                    <input v-model="form.is_active" type="checkbox" class="h-4 w-4 rounded border-slate-300 text-brand-600" />
                    启用类别
                </label>

                <div class="flex justify-end gap-2 border-t border-slate-100 pt-4">
                    <Button variant="secondary" @click="modalOpen = false">取消</Button>
                    <Button type="submit" :loading="form.processing">{{ editing ? '保存修改' : '创建类别' }}</Button>
                </div>
            </form>
        </Modal>

        <ConfirmDialog
            :open="!!deleteTarget"
            title="删除类别"
            :message="`确认删除“${deleteTarget?.name ?? ''}”吗？已有申请或成绩的类别无法删除，可改为停用。`"
            confirm-text="确认删除"
            danger
            :loading="actionLoading"
            @update:open="deleteTarget = null"
            @confirm="destroy"
        />
    </AuthenticatedLayout>
</template>
