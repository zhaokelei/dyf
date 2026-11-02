<script setup>
import { computed, ref } from 'vue';
import { Link, usePage } from '@inertiajs/vue3';
import { ArrowRight } from 'lucide-vue-next';

const props = defineProps({
    title: { type: String, default: '' },
    subtitle: { type: String, default: '' },
    mobileIntro: { type: Boolean, default: false },
});

const page = usePage();
const baseScore = computed(() => page.props.appSettings?.base_score ?? 40);
const flash = computed(() => page.props.flash ?? {});
const currentPath = computed(() => page.url.split('?')[0]);
const introVisible = ref(!(flash.value.success || flash.value.error));
const introLeaving = ref(false);
let introTimer = null;

function openLogin() {
    introLeaving.value = true;
    window.clearTimeout(introTimer);
    introTimer = window.setTimeout(() => {
        introVisible.value = false;
        introLeaving.value = false;
    }, 360);
}

function showIntro() {
    window.clearTimeout(introTimer);
    introLeaving.value = false;
    introVisible.value = true;
}

const introOpen = computed(() => props.mobileIntro && introVisible.value && !introLeaving.value);
</script>

<template>
    <div class="flex min-h-screen min-h-dvh flex-col lg:flex-row">
        <div
            v-if="introVisible"
            :class="[
                'intro-panel fixed inset-0 z-40 flex min-h-dvh flex-col overflow-y-auto overflow-x-hidden bg-slate-900 px-6 pb-6 pt-8 text-white lg:hidden',
                introLeaving ? 'intro-panel-leave' : '',
            ]"
        >
            <div class="pointer-events-none absolute inset-0 overflow-hidden">
                <div class="absolute -right-24 -top-24 h-72 w-72 rounded-full bg-brand-600/25 blur-3xl" />
                <div class="absolute -bottom-28 -left-20 h-64 w-64 rounded-full bg-slate-500/20 blur-3xl" />
            </div>

                <div class="auth-reveal relative flex items-center gap-3" style="--d: 0s">
                    <img
                        src="/brand/crest-168.png"
                        width="168"
                        height="168"
                        alt="郑州工商学院校徽"
                        class="h-11 w-11 object-contain"
                    />
                    <div>
                        <p class="text-base font-semibold tracking-wide">郑州工商学院</p>
                        <p class="mt-0.5 text-[11px] tracking-[0.16em] text-white/45">德育分管理系统</p>
                    </div>
                </div>

                <div class="auth-reveal relative flex flex-1 flex-col justify-center py-8" style="--d: 0.08s">
                    <p class="text-[9px] font-medium uppercase tracking-[0.14em] text-white/45">Zhengzhou Technology and Business University</p>
                    <h1 class="mt-3 text-[32px] font-semibold leading-[1.22] tracking-tight">
                        记录德行点滴<br />赋能成长育人
                    </h1>
                    <p class="mt-4 max-w-sm text-sm leading-relaxed text-white/60">
                        规范化管理学期德育材料，汇聚申报、审批、统计、归档流程，实现德育过程全留痕。
                    </p>
                    <div class="mt-7 grid grid-cols-3 gap-2.5 text-center">
                        <div class="rounded-2xl bg-white/5 px-2 py-3.5 ring-1 ring-white/10">
                            <p class="text-xl font-semibold tracking-tight">{{ baseScore }}</p>
                            <p class="mt-1 text-[11px] font-medium text-white/80">立德筑基</p>
                            <p class="mt-0.5 text-[10px] text-white/40">基础分</p>
                        </div>
                        <div class="rounded-2xl bg-white/5 px-2 py-3.5 ring-1 ring-white/10">
                            <p class="text-xl font-semibold tracking-tight">两学期</p>
                            <p class="mt-1 text-[11px] font-medium text-white/80">学期贯通</p>
                            <p class="mt-0.5 text-[10px] text-white/40">每学年两学期</p>
                        </div>
                        <div class="rounded-2xl bg-white/5 px-2 py-3.5 ring-1 ring-white/10">
                            <p class="text-xl font-semibold tracking-tight">100%</p>
                            <p class="mt-1 text-[11px] font-medium text-white/80">全程留痕</p>
                            <p class="mt-0.5 text-[10px] text-white/40">申报 · 审批 · 归档</p>
                        </div>
                    </div>
                </div>

                <div class="auth-reveal relative space-y-4" style="--d: 0.16s">
                    <button
                        type="button"
                        class="btn-primary flex h-14 w-full items-center justify-center gap-2 rounded-2xl bg-brand-600 text-base font-medium text-white shadow-lg shadow-brand-900/40 transition active:scale-[0.985]"
                        @click="openLogin"
                    >
                        登录
                        <ArrowRight class="h-4 w-4" />
                    </button>
                    <p class="text-center text-[11px] text-white/35">© {{ new Date().getFullYear() }} 郑州工商学院 · 德育分管理系统</p>
                </div>
            </div>

        <div class="relative hidden w-[44%] flex-col justify-between overflow-hidden bg-slate-900 p-12 text-white lg:flex">
            <div class="absolute -right-24 -top-24 h-80 w-80 rounded-full bg-brand-600/25 blur-3xl" />
            <div class="absolute -bottom-24 -left-16 h-72 w-72 rounded-full bg-slate-500/20 blur-3xl" />

            <Link href="/" class="auth-reveal relative flex flex-col gap-3" style="--d: 0s">
                <img
                    src="/brand/logo-white-480.png"
                    width="480"
                    height="123"
                    alt="郑州工商学院"
                    class="h-10 w-auto max-w-[220px] object-contain object-left"
                />
                <span class="text-xs tracking-[0.16em] text-white/45">德育分管理系统</span>
            </Link>

            <div class="auth-reveal relative max-w-md" style="--d: 0.08s">
                <p class="text-xs font-medium uppercase tracking-[0.2em] text-white/50">Zhengzhou Technology and Business University</p>
                <h1 class="mt-4 text-4xl font-semibold leading-tight tracking-tight">
                    记录德行点滴<br />赋能成长育人
                </h1>
                <p class="mt-5 text-sm leading-relaxed text-white/60">
                    规范化管理学期德育材料，汇聚申报、审批、统计、归档流程，实现德育过程全留痕。
                </p>
                <div class="mt-8 grid grid-cols-3 gap-3 text-center">
                    <div class="rounded-2xl bg-white/5 px-3 py-4 ring-1 ring-white/10">
                        <p class="text-2xl font-semibold tracking-tight">{{ baseScore }}</p>
                        <p class="mt-1 text-xs font-medium text-white/80">立德筑基</p>
                        <p class="mt-0.5 text-[10px] text-white/40">基础分</p>
                    </div>
                    <div class="rounded-2xl bg-white/5 px-3 py-4 ring-1 ring-white/10">
                        <p class="text-2xl font-semibold tracking-tight">两学期</p>
                        <p class="mt-1 text-xs font-medium text-white/80">学期贯通</p>
                        <p class="mt-0.5 text-[10px] text-white/40">每学年两学期</p>
                    </div>
                    <div class="rounded-2xl bg-white/5 px-3 py-4 ring-1 ring-white/10">
                        <p class="text-2xl font-semibold tracking-tight">100%</p>
                        <p class="mt-1 text-xs font-medium text-white/80">全程留痕</p>
                        <p class="mt-0.5 text-[10px] text-white/40">申报 · 审批 · 归档</p>
                    </div>
                </div>
            </div>

            <p class="auth-reveal relative text-xs text-white/40" style="--d: 0.16s">© {{ new Date().getFullYear() }} 郑州工商学院 · 德育分管理系统</p>
        </div>

        <div :class="['flex flex-1 items-center justify-center bg-slate-50 px-5 py-10 sm:px-8', introOpen ? 'hidden lg:flex' : 'flex']">
            <div :class="['w-full max-w-md', props.mobileIntro && !introOpen ? 'mobile-auth-in' : '']">
                <div class="mb-8 flex items-center gap-3 lg:hidden">
                    <img src="/brand/crest-168.png" width="168" height="168" alt="郑州工商学院校徽" class="h-11 w-11 object-contain" />
                    <div>
                        <p class="text-base font-semibold text-slate-900">郑州工商学院</p>
                        <p class="text-xs text-slate-400">德育分管理系统</p>
                    </div>
                    <button
                        v-if="props.mobileIntro"
                        type="button"
                        class="ml-auto rounded-full border border-slate-200 px-3 py-1.5 text-xs text-slate-500 transition active:scale-95"
                        @click="showIntro"
                    >
                        查看介绍
                    </button>
                </div>

                <div class="mb-7 hidden items-center gap-4 lg:flex">
                    <img src="/brand/crest-168.png" width="168" height="168" alt="郑州工商学院校徽" class="h-14 w-14 object-contain" />
                    <div>
                        <p class="text-lg font-semibold tracking-tight text-slate-900">郑州工商学院</p>
                        <p class="text-xs text-slate-400">Zhengzhou Technology and Business University</p>
                    </div>
                </div>

                <Transition
                    enter-active-class="transition duration-300 ease-[cubic-bezier(0.16,1,0.3,1)]"
                    enter-from-class="-translate-y-2 scale-[0.99] opacity-0"
                    leave-active-class="transition duration-150 ease-in"
                    leave-to-class="-translate-y-1 opacity-0"
                >
                    <div
                        v-if="flash.success || flash.error"
                        :class="['mb-5 rounded-xl border px-4 py-3 text-sm leading-relaxed', flash.error ? 'border-rose-200 bg-rose-50 text-rose-700' : 'border-emerald-200 bg-emerald-50 text-emerald-700']"
                    >
                        {{ flash.error || flash.success }}
                    </div>
                </Transition>

                <h2 class="text-2xl font-semibold tracking-tight text-slate-900">{{ title }}</h2>
                <p v-if="subtitle" class="mt-1.5 text-sm text-slate-500">{{ subtitle }}</p>
                <div class="mt-7">
                    <Transition name="page" mode="out-in" appear>
                        <div :key="currentPath" class="page-shell">
                            <slot />
                        </div>
                    </Transition>
                </div>
            </div>
        </div>
    </div>
</template>
