<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * Estate standard §6: the notification grant banner at the top of the page.
 *
 * The project has no Dusk setup, so the in-browser half (banner shows for an
 * unsubscribed device, "Not now" hides it) is not driven here. These cover the
 * server half the banner stands on: a signed-in page hands the browser the
 * signed-in user and the VAPID key, and "Turn on notifications" lands the
 * device's subscription on that user.
 */
class NotificationGrantTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_signed_in_page_gives_the_banner_what_it_needs(): void
    {
        config(['webpush.vapid.public_key' => 'test-vapid-public-key']);
        $user = User::factory()->create();

        $this->actingAs($user)
            ->get(route('campaigns.index'))
            ->assertOk()
            ->assertSee('<meta name="vapid-public-key" content="test-vapid-public-key">', false)
            ->assertInertia(fn (Assert $page) => $page
                ->where('auth.user.id', $user->id));
    }

    public function test_turning_notifications_on_subscribes_this_device(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->from(route('campaigns.index'))
            ->post(route('push.store'), [
                'endpoint' => 'https://push.example.test/device-1',
                'keys' => ['p256dh' => 'p256dh-key', 'auth' => 'auth-key'],
            ])
            ->assertRedirect(route('campaigns.index'));

        $this->assertSame(
            ['https://push.example.test/device-1'],
            $user->pushSubscriptions()->pluck('endpoint')->all(),
        );
    }

    public function test_guests_cannot_subscribe(): void
    {
        $this->post(route('push.store'), [
            'endpoint' => 'https://push.example.test/device-1',
            'keys' => ['p256dh' => 'p256dh-key', 'auth' => 'auth-key'],
        ])->assertRedirect(route('login'));
    }
}
