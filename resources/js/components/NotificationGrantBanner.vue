<script setup lang="ts">
import { Bell, BellOff } from '@lucide/vue';
import { computed } from 'vue';
import { Button } from '@/components/ui/button';
import { isIPhone, usePushGrant } from '@/composables/usePushGrant';

// Estate standard §6: the notification grant sits at the top of the page.
const { state, busy, turnOn, notNow } = usePushGrant();

const blockedHint = computed(() =>
    isIPhone()
        ? 'Notifications are blocked. To allow them: iPhone Settings → Notifications → StoryCampaign.'
        : "Notifications are blocked. To allow them, open this site's settings in your browser (the icon left of the address), allow Notifications, then reload.",
);
</script>

<template>
    <div
        v-if="state !== 'hidden'"
        class="px-4 pt-4"
        data-test="notification-grant"
    >
        <section
            aria-label="Notifications"
            class="mx-auto flex w-full max-w-2xl flex-col gap-3 rounded-xl border border-sidebar-border/70 bg-background/60 p-4 text-base backdrop-blur-sm sm:flex-row sm:items-center dark:border-sidebar-border"
        >
            <div class="flex flex-1 items-start gap-3">
                <BellOff
                    v-if="state === 'blocked'"
                    class="mt-0.5 size-5 shrink-0 text-muted-foreground"
                    aria-hidden="true"
                />
                <Bell
                    v-else
                    class="mt-0.5 size-5 shrink-0 text-violet-500 dark:text-violet-400"
                    aria-hidden="true"
                />
                <p v-if="state === 'offer'">
                    Get a notification on this device when your next chapter is
                    ready.
                </p>
                <p v-else-if="state === 'install'">
                    To get notifications on iPhone: Share → Add to Home Screen,
                    then open the app from there.
                </p>
                <p v-else>{{ blockedHint }}</p>
            </div>
            <div class="flex flex-wrap gap-2 sm:shrink-0">
                <Button
                    v-if="state === 'offer'"
                    class="h-11 px-5 text-base"
                    :disabled="busy"
                    @click="turnOn"
                >
                    Turn on notifications
                </Button>
                <Button
                    variant="ghost"
                    class="h-11 px-4 text-base"
                    data-test="notification-grant-not-now"
                    @click="notNow"
                >
                    Not now
                </Button>
            </div>
        </section>
    </div>
</template>
