<script setup lang="ts">
import { Head } from '@inertiajs/vue3';
import AmbientBackdrop from '@/components/game/AmbientBackdrop.vue';

interface BookChapter {
    number: number;
    kind: 'prologue' | 'chapter' | 'chronicle' | 'coda';
    intent_line: string | null;
    body: string;
}

/** A keepsake the tale left behind — inert, and here only to be read. */
interface BookMemento {
    name: string;
    line: string;
    chapter: number | null;
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
        mementos: BookMemento[];
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
            :id="`chapter-${chapter.number}`"
            :key="`${chapter.kind}-${chapter.number}`"
            class="mt-14 scroll-mt-8"
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

        <!-- The closing section: what the tale left behind, in the order it
             happened, each pointing back at the chapter it came out of. An
             empty shelf gets no section at all — a heading over nothing is
             worse than silence. -->
        <section v-if="book.mementos.length" class="mt-20">
            <h2
                class="text-center text-sm tracking-[0.25em] text-muted-foreground uppercase"
            >
                What you carried home
            </h2>
            <ul class="mt-6 space-y-5">
                <li
                    v-for="(keepsake, i) in book.mementos"
                    :key="`${keepsake.name}-${i}`"
                    class="text-center"
                >
                    <p class="font-serif">{{ keepsake.name }}</p>
                    <p class="mt-1 text-sm text-muted-foreground italic">
                        {{ keepsake.line }}
                    </p>
                    <a
                        v-if="keepsake.chapter !== null"
                        :href="`#chapter-${keepsake.chapter}`"
                        class="mt-1 inline-block text-xs text-muted-foreground underline decoration-dotted underline-offset-4"
                    >
                        — chapter {{ keepsake.chapter }}
                    </a>
                </li>
            </ul>
        </section>

        <p
            v-if="!book.chapters.length"
            class="mt-16 text-center text-sm text-muted-foreground"
        >
            The pages are still blank. Go live a little.
        </p>
    </div>
</template>
