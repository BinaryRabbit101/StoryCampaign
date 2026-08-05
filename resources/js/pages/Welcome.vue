<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import { dashboard, login, register } from '@/routes';
</script>

<template>
    <Head title="Welcome" />
    <div
        class="flex min-h-screen flex-col items-center bg-[#FDFDFC] p-6 text-[#1b1b18] lg:justify-center lg:p-8 dark:bg-[#0a0a0a]"
    >
        <header
            class="mb-6 w-full max-w-[335px] text-sm not-has-[nav]:hidden lg:max-w-4xl"
        >
            <nav class="flex items-center justify-end gap-4">
                <Link
                    v-if="$page.props.auth.user"
                    :href="dashboard()"
                    class="inline-block rounded-sm border border-[#19140035] px-5 py-1.5 text-sm leading-normal text-[#1b1b18] hover:border-[#1915014a] dark:border-[#3E3E3A] dark:text-[#EDEDEC] dark:hover:border-[#62605b]"
                >
                    Dashboard
                </Link>
                <template v-else>
                    <Link
                        :href="login()"
                        class="inline-block rounded-sm border border-transparent px-5 py-1.5 text-sm leading-normal text-[#1b1b18] hover:border-[#19140035] dark:text-[#EDEDEC] dark:hover:border-[#3E3E3A]"
                    >
                        Log in
                    </Link>
                    <Link
                        :href="register()"
                        class="inline-block rounded-sm border border-[#19140035] px-5 py-1.5 text-sm leading-normal text-[#1b1b18] hover:border-[#1915014a] dark:border-[#3E3E3A] dark:text-[#EDEDEC] dark:hover:border-[#62605b]"
                    >
                        Register
                    </Link>
                </template>
            </nav>
        </header>

        <main class="flex w-full flex-1 flex-col items-center justify-center text-center">
            <div class="max-w-xl space-y-6">
                <h1 class="font-serif text-4xl font-medium tracking-tight md:text-6xl">
                    StoryCampaign
                </h1>
                <p class="text-lg text-[#706f6c] dark:text-[#A1A09A]">
                    Interview-built campaigns your group can actually play through —
                    tell it who your characters are, and it writes the chapters as you go.
                </p>
                <div class="pt-4">
                    <Link
                        :href="$page.props.auth.user ? dashboard() : login()"
                        class="inline-flex items-center rounded-full bg-[#1b1b18] px-8 py-3 text-lg font-medium text-white transition-all hover:scale-105 hover:bg-black active:scale-95 dark:bg-[#eeeeec] dark:text-[#1C1C1A] dark:hover:bg-white"
                    >
                        {{ $page.props.auth.user ? 'Enter' : 'Log in' }}
                    </Link>
                </div>
            </div>
        </main>
    </div>
</template>
