<script setup>
import { ref } from 'vue';
import { Head, router } from '@inertiajs/vue3';
import { Search } from 'lucide-vue-next';
import AuthenticatedLayout from '@/layouts/AuthenticatedLayout.vue';
import PageHeader from '@/components/ui/PageHeader.vue';
import Card from '@/components/ui/Card.vue';
import Badge from '@/components/ui/Badge.vue';
import EmptyState from '@/components/ui/EmptyState.vue';
import Pagination from '@/components/ui/Pagination.vue';
import Modal from '@/components/ui/Modal.vue';
import { formatDate } from '@/lib/format';
import { usePageSize } from '@/composables/usePageSize';

const props = defineProps({
    logs: { type: Object, required: true },
    actions: { type: Array, default: () => [] },
    filters: { type: Object, default: () => ({}) },
    perPage: { type: [String, Number], default: '20' },
});

const { pageSize, pageSizeOptions, changePageSize } = usePageSize(props.perPage);

const selected = ref(null);

const actionLabels = {
    submit_application: '提交个人申请',
    withdraw_application: '撤回个人申请',
    approve_application: '通过个人申请',
    reject_application: '驳回个人申请',
    submit_group_application: '提交集体申请',
    withdraw_group_application: '撤回集体申请',
    approve_group_application: '通过集体申请',
    reject_group_application: '驳回集体申请',
    create_user: '新增用户',
    update_user: '编辑用户',
    enable_user: '启用账号',
    disable_user: '禁用账号',
    reset_password: '重置密码',
    import_users: '批量导入用户',
    create_category: '新增类别',
    update_category: '编辑类别',
    delete_category: '删除类别',
    create_semester: '新增学期',
    set_current_semester: '设置当前学期',
    delete_semester: '删除学期',
    create_announcement: '发布公告',
    update_announcement: '编辑公告',
    delete_announcement: '删除公告',
    create_score_record: '录入成绩',
    update_score_record: '修改成绩',
    delete_score_record: '撤销成绩',
    update_settings: '修改系统设置',
    create_college: '新增学院',
    update_college: '编辑学院',
    delete_college: '删除学院',
    create_class: '新增班级',
    update_class: '编辑班级',
    delete_class: '删除班级',
    assign_college_admin: '授权学院管理员',
    revoke_college_admin: '撤销学院管理员',
    assign_class_admin: '授权班级管理员',
    revoke_class_admin: '撤销班级管理员',
    submit_appeal: '提交扣分申诉',
    approve_appeal: '通过扣分申诉',
    reject_appeal: '驳回扣分申诉',
};

function label(action) {
    return actionLabels[action] ?? action;
}

function changeFilter(event) {
    const form = event.target.closest('form') ?? event.target;
    const data = Object.fromEntries(new FormData(form).entries());
    data.per_page = pageSize.value;
    router.get('/admin/audit-logs', data, { preserveState: true, preserveScroll: true });
}

function changePage(url) {
    router.get(url, {}, { preserveState: true, preserveScroll: true });
}
</script>

<template>
    <Head title="审计日志" />

    <AuthenticatedLayout>
        <PageHeader title="审计日志" description="关键操作留痕，便于追溯申请、审核与账号变更" />

        <Card :padding="false">
            <form class="flex flex-wrap items-center gap-2 border-b border-slate-100 px-5 py-4" @submit.prevent="changeFilter">
                <select name="action" class="input !w-auto min-w-[160px]" :value="filters.action ?? ''">
                    <option value="">全部操作</option>
                    <option v-for="action in actions" :key="action" :value="action">{{ label(action) }}</option>
                </select>
                <div class="relative">
                    <Search class="pointer-events-none absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 text-slate-400" />
                    <input name="search" :value="filters.search ?? ''" placeholder="操作人或动作" class="input !w-44 !pl-9" />
                </div>
                <button type="submit" class="btn-secondary">筛选</button>
                <a href="/admin/audit-logs" class="btn-ghost">重置</a>
            </form>

            <div v-if="logs.data.length" class="table-wrap">
                <table class="data-table">
                    <thead>
                        <tr>
                            <th class="hidden sm:table-cell">时间</th>
                            <th>操作人</th>
                            <th>操作</th>
                            <th class="hidden md:table-cell">对象</th>
                            <th class="hidden lg:table-cell">IP</th>
                            <th class="text-right">详情</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="log in logs.data" :key="log.id">
                            <td class="hidden whitespace-nowrap text-xs text-slate-400 sm:table-cell">{{ formatDate(log.created_at, true) }}</td>
                            <td>
                                <p class="font-medium text-slate-700">{{ log.user?.name ?? '系统' }}</p>
                                <p class="text-xs text-slate-400 sm:hidden">{{ formatDate(log.created_at, true) }}</p>
                            </td>
                            <td>
                                <Badge :status="''">{{ label(log.action) }}</Badge>
                            </td>
                            <td class="hidden whitespace-nowrap text-xs text-slate-500 md:table-cell">
                                {{ log.target_type ? `${log.target_type} #${log.target_id ?? ''}` : '—' }}
                            </td>
                            <td class="hidden whitespace-nowrap font-mono text-xs text-slate-400 lg:table-cell">{{ log.ip }}</td>
                            <td class="text-right">
                                <button
                                    v-if="log.meta"
                                    type="button"
                                    class="btn-secondary !px-3 !py-1.5 text-xs"
                                    @click="selected = log"
                                >
                                    查看
                                </button>
                                <span v-else class="text-xs text-slate-300">—</span>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
            <EmptyState v-else title="暂无审计日志" />

            <Pagination
                :paginator="logs"
                label="条日志"
                :page-size="pageSize"
                :page-size-options="pageSizeOptions"
                show-page-size
                @change="changePage"
                @size-change="changePageSize"
            />
        </Card>

        <Modal
            :open="!!selected"
            :title="selected ? label(selected.action) : ''"
            :description="selected ? `${selected.user?.name ?? '系统'} · ${formatDate(selected.created_at, true)}` : ''"
            size="md"
            @update:open="selected = null"
        >
            <pre class="overflow-x-auto rounded-xl bg-slate-900 p-4 text-xs leading-relaxed text-slate-200">{{ JSON.stringify(selected?.meta, null, 2) }}</pre>
        </Modal>
    </AuthenticatedLayout>
</template>
