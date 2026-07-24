<script setup lang="ts">
import { Head } from '@inertiajs/vue3';
import AmbientBackdrop from '@/components/game/AmbientBackdrop.vue';

interface BookChapter {
    number: number;
    kind: 'prologue' | 'chapter' | 'chronicle' | 'coda';
    intent_line: string | null;
    body: string;
}

const props = defineProps<{
    campaign: { id: number; name: string; status: string };
    book: {
        title: string;
        back_cover: string | null;
        started_at: string | null;
        ended_at: string | null;
        ended_early: boolean;
        character: string | null;
        chapters: BookChapter[];
    };
}>();

function heading(chapter: BookChapter): string | null {
    switch (chapter.kind) {
        case 'prologue':
            return 'Prologue';
        case 'coda':
            return 'Coda';
        case 'chronicle':
            return null;
        default:
            return `Chapter ${chapter.number}`;
    }
}
</script>

<template>
    <Head :title="book.title" />

    <div class="relative isolate mx-auto w-full max-w-xl flex-1 p-4 pb-24">
        <AmbientBackdrop />

        <!-- Title page -->
        <div
            class="sc-rise border-b border-sidebar-border/50 py-16 text-center"
        >
            <p
                class="sc-twinkle mb-6 text-violet-500/60 select-none"
                aria-hidden="true"
            >
                ✦
            </p>
            <h1 class="font-serif text-3xl">{{ book.title }}</h1>
            <p v-if="book.character" class="mt-2 text-muted-foreground italic">
                the tale of {{ book.character }}
            </p>
            <p class="mt-8 text-xs text-muted-foreground">
                {{ book.started_at }} — {{ book.ended_at ?? 'ongoing' }}
                <span v-if="book.ended_early"
                    ><br />ended where the teller chose to leave it</span
                >
            </p>
            <p
                v-if="book.back_cover"
                class="mx-auto mt-6 max-w-sm text-sm text-muted-foreground italic"
            >
                {{ book.back_cover }}
            </p>
            <a
                :href="`/campaigns/${campaign.id}/book/download`"
                class="mt-8 inline-block rounded-md border border-input px-4 py-2 text-sm transition hover:border-violet-500/60 hover:bg-accent active:scale-95"
            >
                Download as a document
            </a>
        </div>

        <!-- Chapters -->
        <article
            v-for="chapter in book.chapters"
            :key="`${chapter.kind}-${chapter.number}`"
            class="mt-14"
        >
            <h2
                v-if="heading(chapter)"
                class="text-center text-sm tracking-[0.25em] text-muted-foreground uppercase"
            >
                {{ heading(chapter) }}
            </h2>
            <p
                v-else
                class="sc-twinkle text-center tracking-[0.5em] text-violet-500/60"
            >
                ✦ ✦ ✦
            </p>

            <p
                v-if="chapter.intent_line"
                class="mt-3 text-center text-sm text-muted-foreground italic"
            >
                {{ chapter.intent_line }}
            </p>

            <div
                class="sc-dropcap mt-5 space-y-3 font-serif leading-relaxed whitespace-pre-wrap"
            >
                {{ chapter.body }}
            </div>
        </article>

        <p
            v-if="!book.chapters.length"
            class="mt-16 text-center text-sm text-muted-foreground"
        >
            The pages are still blank. Go live a little.
        </p>
    </div>
</template>
