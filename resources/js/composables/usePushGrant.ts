import { usePage } from '@inertiajs/vue3';
import { computed, onMounted, ref } from 'vue';
import {
    currentPushSubscription,
    enablePush,
    pushSubscribed,
    pushSupported,
} from '@/lib/push';

/**
 * What the notification grant banner (estate standard §6) shows on this
 * device: the offer, the iPhone install hint, the blocked hint, or nothing.
 */
export type PushGrantState = 'hidden' | 'offer' | 'install' | 'blocked';

const SNOOZE_KEY = 'storycampaign.pushGrant.snoozedUntil';
const SNOOZE_MS = 7 * 24 * 60 * 60 * 1000;

function snoozed(): boolean {
    try {
        const until = Number(window.localStorage.getItem(SNOOZE_KEY));

        return Number.isFinite(until) && until > Date.now();
    } catch {
        return false;
    }
}

function snooze(): void {
    try {
        window.localStorage.setItem(SNOOZE_KEY, String(Date.now() + SNOOZE_MS));
    } catch {
        // Storage blocked: "Not now" still hides it for this visit.
    }
}

export function isIPhone(): boolean {
    return (
        /iPhone|iPad|iPod/.test(navigator.userAgent) ||
        (navigator.platform === 'MacIntel' && navigator.maxTouchPoints > 1)
    );
}

function installed(): boolean {
    return (
        window.matchMedia?.('(display-mode: standalone)').matches === true ||
        (navigator as Navigator & { standalone?: boolean }).standalone === true
    );
}

async function evaluate(): Promise<PushGrantState> {
    if (snoozed()) {
        return 'hidden';
    }

    if (!('PushManager' in window)) {
        // Safari in a tab on iPhone has no push; the home-screen app does.
        return isIPhone() && !installed() ? 'install' : 'hidden';
    }

    if (!pushSupported()) {
        return 'hidden';
    }

    if (Notification.permission === 'denied') {
        return 'blocked';
    }

    if (
        Notification.permission === 'granted' &&
        (await currentPushSubscription())
    ) {
        return 'hidden';
    }

    return 'offer';
}

export function usePushGrant() {
    const page = usePage();
    const checked = ref<PushGrantState>('hidden');
    const dismissed = ref(false);
    const busy = ref(false);

    const state = computed<PushGrantState>(() => {
        if (!page.props.auth?.user || dismissed.value || pushSubscribed.value) {
            return 'hidden';
        }

        return checked.value;
    });

    onMounted(async () => {
        checked.value = await evaluate();
    });

    async function turnOn(): Promise<void> {
        busy.value = true;

        try {
            if (!(await enablePush()) && Notification.permission === 'denied') {
                checked.value = 'blocked';
            }
        } finally {
            busy.value = false;
        }
    }

    function notNow(): void {
        snooze();
        dismissed.value = true;
    }

    return { state, busy, turnOn, notNow };
}
