import { ref } from 'vue';
import { router } from '@inertiajs/vue3';

export const pageSizeOptions = [
    { value: '10', label: '10 条' },
    { value: '20', label: '20 条' },
    { value: '50', label: '50 条' },
    { value: '100', label: '100 条' },
    { value: 'all', label: '全部' },
];

export function usePageSize(initial = '20') {
    const pageSize = ref(String(initial ?? '20'));

    function changePageSize(value) {
        pageSize.value = String(value);

        const params = Object.fromEntries(new URLSearchParams(window.location.search));
        params.per_page = pageSize.value;
        params.page = '1';
        params.personal_page = '1';
        params.group_page = '1';

        router.get(window.location.pathname, params, {
            preserveState: true,
            preserveScroll: true,
        });
    }

    return { pageSize, pageSizeOptions, changePageSize };
}
