<script setup lang="ts">
import { Form, Head } from '@inertiajs/vue3';
import { Check, Copy, RefreshCw, Smartphone, Trash2 } from '@lucide/vue';
import { ref } from 'vue';
import PhoneController from '@/actions/App/Http/Controllers/Settings/PhoneController';
import Heading from '@/components/Heading.vue';
import { Button } from '@/components/ui/button';
import { Label } from '@/components/ui/label';
import { edit } from '@/routes/phone';

type Feed = { label: string; url: string };

type Props = {
    phone: {
        token: string | null;
        feeds: Feed[];
    };
};

const props = defineProps<Props>();

defineOptions({
    layout: {
        breadcrumbs: [
            {
                title: 'Phone',
                href: edit(),
            },
        ],
    },
});

// Which copy button is showing its tick, if any.
const copied = ref<string | null>(null);

async function copy(field: string, value: string | null): Promise<void> {
    if (!value) {
        return;
    }

    try {
        await navigator.clipboard.writeText(value);
        copied.value = field;
        window.setTimeout(() => {
            // Only clear our own tick, so a second copy inside the two seconds
            // isn't cut short by the first one's timer.
            if (copied.value === field) {
                copied.value = null;
            }
        }, 2000);
    } catch {
        copied.value = null;
    }
}

function confirmRotate(): boolean {
    if (!props.phone.token) {
        return true;
    }

    return window.confirm(
        'Generate a new key? The current one stops working immediately — the widget will need the new key pasted in.',
    );
}

function confirmRevoke(): boolean {
    return window.confirm(
        'Revoke the phone key? The widget will stop updating until you generate a new one.',
    );
}
</script>

<template>
    <Head title="Phone" />

    <h1 class="sr-only">Phone settings</h1>

    <!--
        One panel for one key: the Scriptable status widget carries it, so
        there is one thing to paste and one button that takes it back.
    -->
    <div class="space-y-6" data-test="phone-key-panel">
        <Heading
            variant="small"
            title="Your phone's key"
            description="One private key for the home-screen widget"
        />

        <template v-if="props.phone.token">
            <div
                v-for="feed in props.phone.feeds"
                :key="feed.label"
                class="grid gap-2"
            >
                <Label :for="`feed-${feed.label}`">{{ feed.label }} link</Label>
                <div class="flex flex-wrap items-center gap-2">
                    <code
                        :id="`feed-${feed.label}`"
                        class="min-w-0 flex-1 rounded-md border bg-muted px-3 py-2 font-mono text-xs break-all"
                        data-test="feed-url"
                    >
                        {{ feed.url }}
                    </code>
                    <Button
                        variant="outline"
                        size="icon"
                        type="button"
                        :aria-label="`Copy ${feed.label} link`"
                        @click="copy(feed.label, feed.url)"
                    >
                        <Check v-if="copied === feed.label" />
                        <Copy v-else />
                    </Button>
                </div>
            </div>
            <p class="text-sm text-muted-foreground">
                Paste the feed link into the Scriptable widget's
                <code class="font-mono">CONFIG</code>. The key is already in it.
            </p>

            <div class="grid gap-2">
                <Label for="phone-token">The key on its own</Label>
                <div class="flex flex-wrap items-center gap-2">
                    <code
                        id="phone-token"
                        class="min-w-0 flex-1 rounded-md border bg-muted px-3 py-2 font-mono text-xs break-all"
                        data-test="phone-token"
                    >
                        {{ props.phone.token }}
                    </code>
                    <Button
                        variant="outline"
                        size="icon"
                        type="button"
                        aria-label="Copy key"
                        data-test="copy-phone-token-button"
                        @click="copy('token', props.phone.token)"
                    >
                        <Check v-if="copied === 'token'" />
                        <Copy v-else />
                    </Button>
                </div>
                <p class="text-sm text-muted-foreground">
                    Goes on the feed URL as
                    <code class="font-mono">?token=</code>. Anyone holding it
                    can read your campaign status, so treat it like a password.
                </p>
            </div>
        </template>

        <p v-else class="text-sm text-muted-foreground" data-test="no-key">
            No key yet. Generate one when you are ready to set the widget up on
            your phone.
        </p>

        <div class="flex flex-wrap gap-2">
            <Form
                v-bind="PhoneController.regenerate.form()"
                :options="{ preserveScroll: true }"
                v-slot="{ processing }"
                :on-before="confirmRotate"
            >
                <Button
                    type="submit"
                    :variant="props.phone.token ? 'outline' : 'default'"
                    :disabled="processing"
                    data-test="regenerate-phone-token-button"
                >
                    <RefreshCw v-if="props.phone.token" />
                    <Smartphone v-else />
                    {{
                        props.phone.token
                            ? 'Generate a new key'
                            : 'Generate phone key'
                    }}
                </Button>
            </Form>

            <Form
                v-if="props.phone.token"
                v-bind="PhoneController.revoke.form()"
                :options="{ preserveScroll: true }"
                v-slot="{ processing }"
                :on-before="confirmRevoke"
            >
                <Button
                    type="submit"
                    variant="destructive"
                    :disabled="processing"
                    data-test="revoke-phone-token-button"
                >
                    <Trash2 />
                    Revoke
                </Button>
            </Form>
        </div>

        <p v-if="props.phone.token" class="text-sm text-muted-foreground">
            Generating a new key revokes this one straight away, and it is the
            same key everywhere — the widget shows its error card until you
            paste the new one in.
        </p>
    </div>
</template>
