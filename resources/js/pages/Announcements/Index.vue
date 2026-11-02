<script setup>
import { computed, ref } from 'vue';
import { Head, router, useForm, usePage } from '@inertiajs/vue3';
import { Megaphone, Pencil, Plus, Trash2 } from 'lucide-vue-next';
import AuthenticatedLayout from '@/layouts/AuthenticatedLayout.vue';
import PageHeader from '@/components/ui/PageHeader.vue';
import Card from '@/components/ui/Card.vue';
import EmptyState from '@/components/ui/EmptyState.vue';
import Pagination from '@/components/ui/Pagination.vue';
import Modal from '@/components/ui/Modal.vue';
import ConfirmDialog from '@/components/ui/ConfirmDialog.vue';
import Field from '@/components/ui/Field.vue';
import Button from '@/components/ui/Button.vue';
import Toggle from '@/components/ui/Toggle.vue';
import { formatDate } from '@/lib/format';
import { sanitizeHtml } from '@/lib/html';
import { usePageSize } from '@/composables/usePageSize';

const props = defineProps({
    announcements: { type: Object, required: true },
    perPage: { type: [String, Number], default: '10' },
});

const { pageSize, pageSizeOptions, changePageSize } = usePageSize(props.perPage);

const page = usePage();
const isAdmin = computed(() => page.props.auth.user.role === 'admin');
const selected = ref(null);
const modalOpen = ref(false);
const editing = ref(null);
const deleteTarget = ref(null);
const actionLoading = ref(false);
const contentMode = ref('edit');

const form = useForm({
    title: '',
    content: '',
    is_important: false,
    is_popup: false,
});

function openCreate() {
    editing.value = null;
    contentMode.value = 'edit';
    form.clearErrors();
    form.defaults({ title: '', content: '', is_important: false, is_popup: false });
    form.reset();
    modalOpen.value = true;
}

function openEdit(item) {
    editing.value = item;
    contentMode.value = 'edit';
    form.clearErrors();
    form.defaults({
        title: item.title,
        content: item.content,
        is_important: item.is_important,
        is_popup: item.is_popup,
    });
    form.reset();
    selected.value = null;
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
        form.put(`/admin/announcements/${editing.value.id}`, options);
    } else {
        form.post('/admin/announcements', options);
    }
}

function destroy() {
    if (!deleteTarget.value) return;

    actionLoading.value = true;
    router.delete(`/admin/announcements/${deleteTarget.value.id}`, {
        preserveScroll: true,
        onFinish: () => {
            actionLoading.value = false;
            deleteTarget.value = null;
            selected.value = null;
        },
    });
}

function changePage(url) {
    router.get(url, {}, { preserveState: true, preserveScroll: true });
}
</script>

<template>
    <Head title="公告" />

    <AuthenticatedLayout>
        <PageHeader title="公告" description="查看系统通知与重要事项">
            <template v-if="isAdmin" #actions>
                <button type="button" class="btn-primary" @click="openCreate">
                    <Plus class="h-4 w-4" />
                    发布公告
                </button>
            </template>
        </PageHeader>

        <Card :padding="false">
            <div v-if="announcements.data.length" class="divide-y divide-slate-100">
                <article v-for="item in announcements.data" :key="item.id" class="px-5 py-4">
                    <div class="flex items-start gap-3">
                        <span
                            :class="[
                                'mt-0.5 flex h-9 w-9 shrink-0 items-center justify-center rounded-xl',
                                item.is_important ? 'bg-amber-50 text-amber-600' : 'bg-brand-50 text-brand-600',
                            ]"
                        >
                            <Megaphone class="h-4 w-4" />
                        </span>
                        <div class="min-w-0 flex-1">
                            <button type="button" class="block w-full text-left" @click="selected = item">
                                <div class="flex flex-wrap items-center gap-2">
                                    <h2 class="break-words text-sm font-semibold text-slate-800">{{ item.title }}</h2>
                                    <span v-if="item.is_important" class="rounded-full bg-amber-50 px-2 py-0.5 text-[11px] font-medium text-amber-700 ring-1 ring-inset ring-amber-200">重要</span>
                                    <span v-if="item.is_popup" class="rounded-full bg-brand-50 px-2 py-0.5 text-[11px] font-medium text-brand-700 ring-1 ring-inset ring-brand-200">首页弹窗</span>
                                </div>
                                <p class="mt-1 line-clamp-2 text-sm text-slate-500 [overflow-wrap:anywhere]">{{ item.excerpt }}</p>
                                <p class="mt-1.5 text-xs text-slate-400">
                                    {{ item.author }} · {{ formatDate(item.published_at, true) }}
                                </p>
                            </button>
                        </div>
                        <div v-if="isAdmin" class="flex shrink-0 items-center gap-1">
                            <button type="button" class="btn-ghost !px-2.5 !py-1.5" title="编辑" @click="openEdit(item)">
                                <Pencil class="h-4 w-4" />
                            </button>
                            <button type="button" class="btn-ghost !px-2.5 !py-1.5 hover:!text-rose-600" title="删除" @click="deleteTarget = item">
                                <Trash2 class="h-4 w-4" />
                            </button>
                        </div>
                    </div>
                </article>
            </div>
            <EmptyState v-else title="暂无公告" description="管理员发布公告后会显示在这里" />

            <Pagination
                :paginator="announcements"
                label="条公告"
                :page-size="pageSize"
                :page-size-options="pageSizeOptions"
                show-page-size
                @change="changePage"
                @size-change="changePageSize"
            />
        </Card>

        <Modal
            :open="!!selected"
            :title="selected?.title ?? ''"
            :description="selected ? `${selected.author ?? ''} · ${formatDate(selected.published_at, true)}` : ''"
            size="lg"
            @update:open="selected = null"
        >
            <div v-if="selected?.content" class="announcement-html" v-html="sanitizeHtml(selected.content)" />
            <p v-else class="text-sm text-slate-400">暂无内容</p>
        </Modal>

        <Modal :open="modalOpen" :title="editing ? '编辑公告' : '发布公告'" @update:open="modalOpen = false">
            <form class="space-y-4" @submit.prevent="submit">
                <Field label="标题" :error="form.errors.title" required>
                    <input v-model="form.title" type="text" maxlength="200" class="input" />
                </Field>

                <div>
                    <div class="mb-1.5 flex items-center justify-between gap-3">
                        <span class="flex items-center gap-1 text-sm font-medium text-slate-700">
                            内容
                            <span class="text-rose-500">*</span>
                        </span>
                        <div class="inline-flex rounded-lg bg-slate-100 p-0.5">
                            <button
                                type="button"
                                :class="[
                                    'rounded-md px-2.5 py-1 text-xs font-medium transition',
                                    contentMode === 'edit' ? 'bg-white text-slate-800 shadow-sm' : 'text-slate-500 hover:text-slate-700',
                                ]"
                                @click="contentMode = 'edit'"
                            >
                                编辑
                            </button>
                            <button
                                type="button"
                                :class="[
                                    'rounded-md px-2.5 py-1 text-xs font-medium transition',
                                    contentMode === 'preview' ? 'bg-white text-slate-800 shadow-sm' : 'text-slate-500 hover:text-slate-700',
                                ]"
                                @click="contentMode = 'preview'"
                            >
                                预览
                            </button>
                        </div>
                    </div>
                    <textarea
                        v-if="contentMode === 'edit'"
                        v-model="form.content"
                        rows="10"
                        maxlength="20000"
                        class="input resize-y font-mono text-xs leading-relaxed"
                        placeholder="支持 HTML，例如：<p>请于本周五前完成申报。</p>"
                    />
                    <div v-else class="min-h-56 rounded-xl border border-slate-200 bg-slate-50/60 px-4 py-3">
                        <div v-if="form.content" class="announcement-html" v-html="sanitizeHtml(form.content)" />
                        <p v-else class="text-sm text-slate-400">暂无可预览内容</p>
                    </div>
                    <span v-if="form.errors.content" class="mt-1.5 block text-xs text-rose-600">
                        {{ form.errors.content }}
                    </span>
                    <span v-else class="mt-1.5 block text-xs text-slate-400">
                        支持 HTML 标签与内联样式（渐变、圆角、阴影、布局等），脚本、外部资源与危险样式会被自动过滤。
                    </span>
                </div>

                <div class="space-y-2">
                    <div class="flex items-center justify-between gap-4 rounded-xl border border-slate-200 px-4 py-3">
                        <div>
                            <p class="text-sm font-medium text-slate-700">标记为重要公告</p>
                            <p class="mt-0.5 text-xs text-slate-400">在公告列表中优先展示</p>
                        </div>
                        <Toggle v-model="form.is_important" />
                    </div>
                    <div class="flex items-center justify-between gap-4 rounded-xl border border-slate-200 px-4 py-3">
                        <div>
                            <p class="text-sm font-medium text-slate-700">首页自动弹出</p>
                            <p class="mt-0.5 text-xs text-slate-400">学生进入工作台时自动展示最新一条弹出公告</p>
                        </div>
                        <Toggle v-model="form.is_popup" />
                    </div>
                </div>

                <div class="flex justify-end gap-2 border-t border-slate-100 pt-4">
                    <Button variant="secondary" @click="modalOpen = false">取消</Button>
                    <Button type="submit" :loading="form.processing">{{ editing ? '保存修改' : '发布公告' }}</Button>
                </div>
            </form>
        </Modal>

        <ConfirmDialog
            :open="!!deleteTarget"
            title="删除公告"
            :message="`确认删除“${deleteTarget?.title ?? ''}”吗？`"
            confirm-text="确认删除"
            danger
            :loading="actionLoading"
            @update:open="deleteTarget = null"
            @confirm="destroy"
        />
    </AuthenticatedLayout>
</template>
