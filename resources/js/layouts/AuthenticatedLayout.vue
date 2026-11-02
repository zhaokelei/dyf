<script setup>
import { computed, markRaw, onBeforeUnmount, onMounted, ref, watch } from 'vue';
import { Link, router, usePage } from '@inertiajs/vue3';
import {
    AlertCircle, BarChart3, CalendarRange, ChartNoAxesColumnIncreasing, CheckCircle2,
    ChevronDown, ChevronRight, ChevronsLeft, ChevronsRight, ClipboardCheck, FileText,
    KeyRound, LayoutDashboard, LogOut, Maximize, Megaphone, Menu, Minimize, Palette,
    PlusCircle, RefreshCw, School, ScrollText, Settings, ShieldAlert, Table2, Tags, Trophy, Users, X,
} from 'lucide-vue-next';

const page = usePage();
const user = computed(() => page.props.auth.user);
const appSettings = computed(() => page.props.appSettings ?? {});
const permissions = computed(() => page.props.permissions ?? {});
const scopeInfo = computed(() => page.props.scopeInfo ?? { college_names: [], class_names: [], grade_scopes: [] });
const currentPath = computed(() => page.url.split('?')[0]);

const mobileOpen = ref(false);
const userMenuOpen = ref(false);
const themeOpen = ref(false);
const collapsed = ref(false);
const isFullscreen = ref(false);
const activeTheme = ref('blue');
const tags = ref([]);
const toasts = ref([]);
let toastId = 0;

const THEME_KEY = 'dyf.theme';
const COLLAPSE_KEY = 'dyf.sidebar.collapsed';
const TAGS_KEY = 'dyf.page.tags';

const palettes = {
    blue: { 50: '#ecf5ff', 100: '#d9ecff', 200: '#c6e2ff', 300: '#a0cfff', 400: '#79bbff', 500: '#409eff', 600: '#337ecc', 700: '#2b6cb0', 800: '#1d4f91', 900: '#153b70' },
    green: { 50: '#e8f8f0', 100: '#d1f0e0', 200: '#a3e1c1', 300: '#75d2a2', 400: '#47c383', 500: '#18a058', 600: '#138a4a', 700: '#0f733d', 800: '#0b5c30', 900: '#084523' },
    purple: { 50: '#f3ecff', 100: '#e7d9ff', 200: '#cfb3ff', 300: '#b78cff', 400: '#9f66ff', 500: '#722ed1', 600: '#6326b8', 700: '#541f9e', 800: '#451985', 900: '#36136c' },
    red: { 50: '#fdecef', 100: '#fbd9df', 200: '#f7b3bf', 300: '#f38d9f', 400: '#ef677f', 500: '#d03050', 600: '#b82846', 700: '#a0203c', 800: '#881832', 900: '#701028' },
    orange: { 50: '#fef4e6', 100: '#fde9cc', 200: '#fbd399', 300: '#f9bd66', 400: '#f7a733', 500: '#f0a020', 600: '#d88c18', 700: '#c07810', 800: '#a86408', 900: '#905000' },
};
const paletteNames = { blue: '拂晓蓝', green: '极光绿', purple: '酱紫', red: '胭脂红', orange: '日暮橙' };

const navItems = computed(() => {
    const role = user.value?.role;

    if (role === 'student') {
        const items = [
            { label: '工作台', href: '/dashboard', icon: markRaw(LayoutDashboard) },
        ];

        if (permissions.value.is_class_officer) {
            items.push({ label: '班干部审批', href: '/officer/review', icon: markRaw(ClipboardCheck) });
        }

        items.push(
            { label: '我的德育分', href: '/my-scores', icon: markRaw(ChartNoAxesColumnIncreasing) },
            { label: '我的申请', href: '/applications', icon: markRaw(FileText) },
            { label: '提交申请', href: '/applications/create', icon: markRaw(PlusCircle) },
            { label: '发起集体申请', href: '/applications/group/create', icon: markRaw(Users) },
            { label: '扣分申诉', href: '/appeals', icon: markRaw(ShieldAlert) },
            { label: '公告', href: '/announcements', icon: markRaw(Megaphone) },
        );

        return items;
    }

    if (role === 'teacher') {
        return [
            { label: '工作台', href: '/dashboard', icon: markRaw(LayoutDashboard) },
            { label: '集体申请', href: '/teacher/group-applications', icon: markRaw(ClipboardCheck) },
            { label: '发起集体申请', href: '/teacher/group-applications/create', icon: markRaw(PlusCircle) },
            { label: '排行榜', href: '/leaderboard', icon: markRaw(Trophy) },
            { label: '公告', href: '/announcements', icon: markRaw(Megaphone) },
        ];
    }

    if (role === 'college_admin') {
        return [
            { label: '工作台', href: '/dashboard', icon: markRaw(LayoutDashboard) },
            { label: '审核中心', href: '/admin/review', icon: markRaw(ClipboardCheck) },
            { label: '扣分申诉', href: '/admin/appeals', icon: markRaw(ShieldAlert) },
            { label: '排行榜', href: '/leaderboard', icon: markRaw(Trophy) },
            { label: '成绩管理', href: '/admin/records', icon: markRaw(Table2) },
            { label: '数据统计', href: '/admin/statistics', icon: markRaw(BarChart3) },
            { label: '用户管理', href: '/admin/users', icon: markRaw(Users) },
            { label: '组织管理', href: '/admin/organization', icon: markRaw(School) },
            { label: '公告', href: '/announcements', icon: markRaw(Megaphone) },
        ];
    }

    if (role === 'class_admin') {
        return [
            { label: '工作台', href: '/dashboard', icon: markRaw(LayoutDashboard) },
            { label: '审核中心', href: '/admin/review', icon: markRaw(ClipboardCheck) },
            { label: '扣分申诉', href: '/admin/appeals', icon: markRaw(ShieldAlert) },
            { label: '排行榜', href: '/leaderboard', icon: markRaw(Trophy) },
            { label: '成绩管理', href: '/admin/records', icon: markRaw(Table2) },
            { label: '数据统计', href: '/admin/statistics', icon: markRaw(BarChart3) },
            { label: '学生管理', href: '/admin/users', icon: markRaw(Users) },
            { label: '公告', href: '/announcements', icon: markRaw(Megaphone) },
        ];
    }

    return [
        { label: '工作台', href: '/dashboard', icon: markRaw(LayoutDashboard) },
        { label: '审核中心', href: '/admin/review', icon: markRaw(ClipboardCheck) },
        { label: '扣分申诉', href: '/admin/appeals', icon: markRaw(ShieldAlert) },
        { label: '排行榜', href: '/leaderboard', icon: markRaw(Trophy) },
        { label: '成绩管理', href: '/admin/records', icon: markRaw(Table2) },
        { label: '数据统计', href: '/admin/statistics', icon: markRaw(BarChart3) },
        { label: '用户管理', href: '/admin/users', icon: markRaw(Users) },
        { label: '组织管理', href: '/admin/organization', icon: markRaw(School) },
        { label: '类别管理', href: '/admin/categories', icon: markRaw(Tags) },
        { label: '学期管理', href: '/admin/semesters', icon: markRaw(CalendarRange) },
        { label: '公告管理', href: '/announcements', icon: markRaw(Megaphone) },
        { label: '审计日志', href: '/admin/audit-logs', icon: markRaw(ScrollText) },
        { label: '系统设置', href: '/admin/settings', icon: markRaw(Settings) },
    ];
});

const bottomItems = computed(() => navItems.value.slice(0, 4));
const initials = computed(() => (user.value?.name ?? '?').slice(0, 1));

function isActive(href) {
    if (href === '/applications/create' || href === '/applications/group/create' || href === '/teacher/group-applications/create') {
        return currentPath.value === href;
    }

    if (href === '/applications') {
        return currentPath.value === href
            || (currentPath.value.startsWith('/applications/group/') && currentPath.value !== '/applications/group/create');
    }

    return currentPath.value === href || currentPath.value.startsWith(href + '/');
}

function sectionForPath(path) {
    if (path.startsWith('/admin/students/')) return '成绩管理';
    if (path.startsWith('/admin/review/groups/')) return '审核中心';
    if (path.startsWith('/teacher/group-applications/')) return '集体申请';
    if (path.startsWith('/profile/')) return '个人设置';

    return navItems.value.find((item) => isActive(item.href))?.label ?? appSettings.value.name;
}

const currentSection = computed(() => sectionForPath(currentPath.value));
const currentSemester = computed(() => appSettings.value.current_semester ?? '未设置学期');
const scopeLabel = computed(() => {
    if (permissions.value.is_admin) return '全校';

    if (permissions.value.is_college_admin) {
        return scopeInfo.value.college_names?.length ? scopeInfo.value.college_names.join('、') : '未授权学院';
    }

    const gradeLabels = (scopeInfo.value.grade_scopes ?? []).map((scope) => scope.label);
    const names = gradeLabels.length ? gradeLabels : (scopeInfo.value.class_names ?? []);

    return names.length ? [...new Set(names)].join('、') : '未授权范围';
});

function loadTags() {
    try {
        const parsed = JSON.parse(sessionStorage.getItem(TAGS_KEY) || '[]');
        return Array.isArray(parsed) ? parsed.filter((tag) => tag?.href && tag?.label) : [];
    } catch {
        return [];
    }
}

function saveTags() {
    try {
        sessionStorage.setItem(TAGS_KEY, JSON.stringify(tags.value.slice(-12)));
    } catch {
        // Ignore storage failures.
    }
}

function syncTag() {
    const href = currentPath.value;
    if (!tags.value.some((tag) => tag.href === href)) {
        tags.value.push({ label: currentSection.value, href });
        tags.value = tags.value.slice(-12);
    }
    saveTags();
}

function closeTag(tag) {
    const index = tags.value.findIndex((item) => item.href === tag.href);
    if (index === -1) return;

    const wasActive = currentPath.value === tag.href;
    tags.value.splice(index, 1);
    saveTags();

    if (wasActive) {
        const next = tags.value[index - 1] ?? tags.value[index] ?? { href: '/dashboard' };
        router.visit(next.href);
    }
}

function applyTheme(name) {
    const palette = palettes[name] ?? palettes.blue;
    Object.entries(palette).forEach(([shade, color]) => {
        document.documentElement.style.setProperty(`--color-brand-${shade}`, color);
    });
    activeTheme.value = name;
    themeOpen.value = false;
    try {
        localStorage.setItem(THEME_KEY, name);
    } catch {
        // Ignore storage failures.
    }
}

function toggleCollapse() {
    collapsed.value = !collapsed.value;
    try {
        localStorage.setItem(COLLAPSE_KEY, collapsed.value ? '1' : '0');
    } catch {
        // Ignore storage failures.
    }
}

function toggleFullscreen() {
    if (!document.fullscreenElement) {
        document.documentElement.requestFullscreen?.();
    } else {
        document.exitFullscreen?.();
    }
}

function refreshPage() {
    router.reload({ preserveScroll: true });
}

function pushToast(type, message) {
    const id = ++toastId;
    toasts.value.push({ id, type, message });
    window.setTimeout(() => {
        toasts.value = toasts.value.filter((toast) => toast.id !== id);
    }, 4500);
}

function logout() {
    router.post('/logout');
}

const onFullscreen = () => {
    isFullscreen.value = !!document.fullscreenElement;
};

watch(() => page.props.flash, (flash) => {
    if (!flash) return;
    if (flash.success) pushToast('success', flash.success);
    if (flash.error) pushToast('error', flash.error);
}, { deep: true, immediate: true });

watch(() => page.url, () => {
    mobileOpen.value = false;
    userMenuOpen.value = false;
    themeOpen.value = false;
    syncTag();
}, { immediate: true });

onMounted(() => {
    try {
        collapsed.value = localStorage.getItem(COLLAPSE_KEY) === '1';
        activeTheme.value = localStorage.getItem(THEME_KEY) || 'blue';
    } catch {
        // Ignore storage failures.
    }
    applyTheme(activeTheme.value);
    tags.value = loadTags();
    syncTag();
    document.addEventListener('fullscreenchange', onFullscreen);
});

onBeforeUnmount(() => {
    document.removeEventListener('fullscreenchange', onFullscreen);
});
</script>

<template>
    <div class="min-h-screen bg-[#f0f2f5]">
        <aside :class="['app-sidebar fixed inset-y-0 left-0 z-40 hidden flex-col bg-[#0f172a] transition-all duration-300 lg:flex', collapsed ? 'w-[68px]' : 'w-60']">
            <div :class="['flex h-[60px] shrink-0 items-center border-b border-white/10', collapsed ? 'justify-center px-2' : 'px-4']">
                <img v-if="collapsed" src="/brand/crest-white-112.png" width="112" height="112" alt="郑州工商学院" class="h-9 w-9 object-contain" />
                <img v-else src="/brand/logo-white-480.png" width="480" height="123" alt="郑州工商学院" class="h-8 w-auto max-w-[180px] object-contain object-left" />
            </div>
            <div v-if="!collapsed" class="border-b border-white/5 px-5 pb-3 pt-2.5">
                <p class="text-[11px] tracking-[0.14em] text-white/35">德育分管理系统</p>
            </div>
            <nav class="flex-1 space-y-0.5 overflow-y-auto px-2 py-3">
                <Link
                    v-for="item in navItems"
                    :key="item.href"
                    :href="item.href"
                    :title="collapsed ? item.label : ''"
                    :class="[
                        'group relative flex items-center rounded-lg text-[13px] font-medium transition-all duration-200',
                        collapsed ? 'justify-center px-0 py-2.5' : 'gap-3 px-3 py-2.5',
                        isActive(item.href) ? 'bg-brand-500/15 text-white' : 'text-slate-400 hover:bg-white/5 hover:text-white',
                    ]"
                >
                    <span v-if="isActive(item.href)" class="absolute left-0 top-1/2 h-5 w-0.5 -translate-y-1/2 rounded-full bg-brand-400" />
                    <component :is="item.icon" class="h-[18px] w-[18px] shrink-0" />
                    <span v-if="!collapsed" class="truncate">{{ item.label }}</span>
                </Link>
            </nav>
            <div class="border-t border-white/10 p-2.5">
                <div :class="['flex items-center rounded-lg px-2 py-2', collapsed ? 'justify-center' : 'gap-3']">
                    <span class="flex h-9 w-9 shrink-0 items-center justify-center rounded-full bg-brand-500/20 text-xs font-semibold text-brand-200">{{ initials }}</span>
                    <template v-if="!collapsed">
                        <div class="min-w-0 flex-1">
                            <p class="truncate text-sm font-medium text-white">{{ user?.name }}</p>
                            <p class="text-[11px] text-white/40">{{ user?.role_label }}</p>
                        </div>
                        <button type="button" class="flex h-8 w-8 items-center justify-center rounded-lg text-white/40 transition hover:bg-white/10 hover:text-white" title="退出登录" @click="logout">
                            <LogOut class="h-4 w-4" />
                        </button>
                    </template>
                </div>
            </div>
        </aside>

        <div :class="['transition-all duration-300', collapsed ? 'lg:pl-[68px]' : 'lg:pl-60']">
            <header class="sticky top-0 z-30 border-b border-slate-200 bg-white">
                <div class="flex h-[60px] items-center justify-between gap-3 px-3 sm:px-5">
                    <div class="flex min-w-0 items-center gap-2">
                        <button type="button" class="flex h-9 w-9 items-center justify-center rounded-md text-slate-500 transition hover:bg-slate-100 lg:hidden" @click="mobileOpen = true">
                            <Menu class="h-5 w-5" />
                        </button>
                        <button type="button" class="hidden h-9 w-9 items-center justify-center rounded-md text-slate-500 transition hover:bg-slate-100 lg:flex" :title="collapsed ? '展开菜单' : '收起菜单'" @click="toggleCollapse">
                            <component :is="collapsed ? ChevronsRight : ChevronsLeft" class="h-[18px] w-[18px]" />
                        </button>
                        <nav class="hidden items-center gap-1.5 text-sm sm:flex">
                            <span class="text-slate-400">首页</span>
                            <ChevronRight class="h-3.5 w-3.5 text-slate-300" />
                            <span class="font-medium text-slate-700">{{ currentSection }}</span>
                        </nav>
                        <p class="truncate text-sm font-medium text-slate-800 sm:hidden">{{ currentSection }}</p>
                    </div>

                    <div class="flex items-center gap-1">
                        <span class="mr-1 hidden items-center gap-1.5 rounded-md bg-brand-50 px-2.5 py-1.5 text-xs font-medium text-brand-700 md:inline-flex">
                            <CalendarRange class="h-3.5 w-3.5" />
                            {{ currentSemester }}
                        </span>
                        <span v-if="permissions.is_manager" class="mr-1 hidden items-center gap-1.5 rounded-md bg-slate-100 px-2.5 py-1.5 text-xs font-medium text-slate-600 xl:inline-flex">
                            <School class="h-3.5 w-3.5" />
                            {{ scopeLabel }}
                        </span>
                        <button type="button" class="flex h-9 w-9 items-center justify-center rounded-md text-slate-500 transition hover:bg-slate-100" title="刷新页面" @click="refreshPage">
                            <RefreshCw class="h-[18px] w-[18px]" />
                        </button>
                        <button type="button" class="hidden h-9 w-9 items-center justify-center rounded-md text-slate-500 transition hover:bg-slate-100 sm:flex" :title="isFullscreen ? '退出全屏' : '全屏'" @click="toggleFullscreen">
                            <component :is="isFullscreen ? Minimize : Maximize" class="h-[18px] w-[18px]" />
                        </button>
                        <div class="relative">
                            <button type="button" class="flex h-9 w-9 items-center justify-center rounded-md text-slate-500 transition hover:bg-slate-100" title="主题色" @click="themeOpen = !themeOpen">
                                <Palette class="h-[18px] w-[18px]" />
                            </button>
                            <div v-if="themeOpen" class="fixed inset-0 z-30" @click="themeOpen = false" />
                            <Transition enter-active-class="transition duration-150 ease-out" enter-from-class="translate-y-1 opacity-0" leave-active-class="transition duration-100 ease-in" leave-to-class="translate-y-1 opacity-0">
                                <div v-if="themeOpen" class="absolute right-0 top-full z-40 mt-2 w-56 rounded-xl border border-slate-200 bg-white p-3 shadow-lg">
                                    <p class="mb-2 text-xs font-medium text-slate-500">主题色</p>
                                    <div class="grid grid-cols-5 gap-2">
                                        <button v-for="(palette, name) in palettes" :key="name" type="button" :title="paletteNames[name]" :class="['flex h-8 items-center justify-center rounded-lg ring-2 transition', activeTheme === name ? 'ring-slate-900 ring-offset-2' : 'ring-transparent']" :style="{ backgroundColor: palette[500] }" @click="applyTheme(name)">
                                            <CheckCircle2 v-if="activeTheme === name" class="h-4 w-4 text-white" />
                                        </button>
                                    </div>
                                    <p class="mt-2 text-center text-[11px] text-slate-400">{{ paletteNames[activeTheme] }}</p>
                                </div>
                            </Transition>
                        </div>

                        <div class="relative ml-1">
                            <button type="button" class="flex items-center gap-2 rounded-md px-1.5 py-1.5 transition hover:bg-slate-100" @click="userMenuOpen = !userMenuOpen">
                                <span class="flex h-8 w-8 items-center justify-center rounded-full bg-brand-600 text-[11px] font-semibold text-white">{{ initials }}</span>
                                <span class="hidden text-sm text-slate-700 sm:block">{{ user?.name }}</span>
                                <ChevronDown class="hidden h-4 w-4 text-slate-400 sm:block" />
                            </button>
                            <div v-if="userMenuOpen" class="fixed inset-0 z-30" @click="userMenuOpen = false" />
                            <Transition enter-active-class="transition duration-150 ease-out" enter-from-class="translate-y-1 opacity-0" leave-active-class="transition duration-100 ease-in" leave-to-class="translate-y-1 opacity-0">
                                <div v-if="userMenuOpen" class="absolute right-0 top-full z-40 mt-2 w-52 overflow-hidden rounded-xl border border-slate-200 bg-white py-1 shadow-lg">
                                    <div class="border-b border-slate-100 px-3 py-2.5">
                                        <p class="truncate text-sm font-medium text-slate-800">{{ user?.name }}</p>
                                        <p class="text-xs text-slate-400">{{ user?.student_id || user?.employee_id || user?.username }}</p>
                                    </div>
                                    <Link href="/profile/password" class="flex items-center gap-2 px-3 py-2.5 text-sm text-slate-600 transition hover:bg-slate-50">
                                        <KeyRound class="h-4 w-4" />
                                        修改密码
                                    </Link>
                                    <button type="button" class="flex w-full items-center gap-2 px-3 py-2.5 text-sm text-rose-600 transition hover:bg-rose-50" @click="logout">
                                        <LogOut class="h-4 w-4" />
                                        退出登录
                                    </button>
                                </div>
                            </Transition>
                        </div>
                    </div>
                </div>

                <div v-if="tags.length" class="flex h-9 items-center gap-1.5 overflow-x-auto border-t border-slate-100 bg-white px-3 sm:px-5">
                    <Link v-for="tag in tags" :key="tag.href" :href="tag.href" :class="['inline-flex h-7 shrink-0 items-center gap-1.5 rounded border px-2.5 text-xs transition', currentPath === tag.href ? 'border-brand-300 bg-brand-50 text-brand-700' : 'border-slate-200 bg-white text-slate-500 hover:text-slate-700']">
                        <span :class="['h-1.5 w-1.5 rounded-full', currentPath === tag.href ? 'bg-brand-500' : 'bg-slate-300']" />
                        {{ tag.label }}
                        <button v-if="tag.href !== '/dashboard'" type="button" class="rounded p-0.5 text-slate-400 transition hover:bg-slate-100 hover:text-slate-600" @click.prevent.stop="closeTag(tag)">
                            <X class="h-3 w-3" />
                        </button>
                    </Link>
                </div>
            </header>

            <main class="mx-auto w-full max-w-[1500px] px-3 pb-24 pt-4 sm:px-5 lg:px-6 lg:pb-8">
                <Transition name="page" mode="out-in" appear>
                    <div :key="currentPath" class="page-shell">
                        <slot />
                    </div>
                </Transition>
            </main>
        </div>

        <nav class="fixed inset-x-0 bottom-0 z-40 border-t border-slate-200 bg-white/95 pb-[env(safe-area-inset-bottom)] backdrop-blur lg:hidden">
            <div class="grid grid-cols-5">
                <Link v-for="item in bottomItems" :key="item.href" :href="item.href" :class="['flex flex-col items-center gap-1 py-2.5 text-[10px] font-medium transition', isActive(item.href) ? 'text-brand-600' : 'text-slate-400']">
                    <component :is="item.icon" class="h-5 w-5" />
                    {{ item.label }}
                </Link>
                <button type="button" class="flex flex-col items-center gap-1 py-2.5 text-[10px] font-medium text-slate-400" @click="mobileOpen = true">
                    <Menu class="h-5 w-5" />
                    更多
                </button>
            </div>
        </nav>

        <Teleport to="body">
            <Transition enter-active-class="transition duration-200 ease-out" enter-from-class="opacity-0" leave-active-class="transition duration-150 ease-in" leave-to-class="opacity-0">
                <div v-if="mobileOpen" class="fixed inset-0 z-50 lg:hidden">
                    <div class="absolute inset-0 bg-slate-900/40 backdrop-blur-sm" @click="mobileOpen = false" />
                    <Transition appear enter-active-class="transition duration-250 ease-out" enter-from-class="-translate-x-full" leave-active-class="transition duration-200 ease-in" leave-to-class="-translate-x-full">
                        <aside v-if="mobileOpen" class="absolute inset-y-0 left-0 flex w-72 flex-col bg-[#0f172a] p-3">
                            <div class="flex h-14 items-center justify-between px-2">
                                <img src="/brand/logo-white-480.png" width="480" height="123" alt="郑州工商学院" class="h-8 w-auto max-w-[190px] object-contain object-left" />
                                <button type="button" class="flex h-9 w-9 items-center justify-center rounded-lg text-white/50 hover:bg-white/10 hover:text-white" @click="mobileOpen = false">
                                    <X class="h-5 w-5" />
                                </button>
                            </div>
                            <nav class="mt-2 flex-1 space-y-0.5 overflow-y-auto">
                                <Link v-for="item in navItems" :key="item.href" :href="item.href" :class="['flex items-center gap-3 rounded-lg px-3 py-2.5 text-sm font-medium transition', isActive(item.href) ? 'bg-brand-500/15 text-white' : 'text-slate-400 hover:bg-white/5 hover:text-white']">
                                    <component :is="item.icon" class="h-[18px] w-[18px]" />
                                    {{ item.label }}
                                </Link>
                            </nav>
                            <div class="border-t border-white/10 pt-3">
                                <div class="flex items-center gap-3 px-2 pb-2">
                                    <span class="flex h-9 w-9 items-center justify-center rounded-full bg-brand-500/20 text-xs font-semibold text-brand-200">{{ initials }}</span>
                                    <div class="min-w-0 flex-1">
                                        <p class="truncate text-sm font-medium text-white">{{ user?.name }}</p>
                                        <p class="text-[11px] text-white/40">{{ user?.role_label }}</p>
                                    </div>
                                    <button type="button" class="text-white/40 hover:text-white" @click="logout">
                                        <LogOut class="h-4 w-4" />
                                    </button>
                                </div>
                            </div>
                        </aside>
                    </Transition>
                </div>
            </Transition>
        </Teleport>

        <div class="pointer-events-none fixed inset-x-0 top-3 z-[70] flex flex-col items-center gap-2 px-4 sm:left-auto sm:right-5 sm:top-auto sm:bottom-5 sm:items-end">
            <TransitionGroup enter-active-class="transition duration-300 ease-[cubic-bezier(0.34,1.56,0.64,1)]" enter-from-class="translate-y-3 scale-95 opacity-0" enter-to-class="translate-y-0 scale-100 opacity-100" leave-active-class="transition duration-150 ease-in" leave-from-class="opacity-100" leave-to-class="translate-y-1 scale-95 opacity-0">
                <div v-for="toast in toasts" :key="toast.id" :class="['pointer-events-auto flex w-full max-w-sm items-start gap-2.5 rounded-xl border px-4 py-3 text-sm shadow-lg backdrop-blur', toast.type === 'error' ? 'border-rose-200 bg-rose-50/95 text-rose-700' : 'border-emerald-200 bg-emerald-50/95 text-emerald-700']">
                    <component :is="toast.type === 'error' ? AlertCircle : CheckCircle2" class="mt-0.5 h-4 w-4 shrink-0" />
                    <span class="leading-relaxed">{{ toast.message }}</span>
                </div>
            </TransitionGroup>
        </div>
    </div>
</template>
