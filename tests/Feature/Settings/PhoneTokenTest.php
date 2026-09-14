<?php

namespace Tests\Feature\Settings;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class PhoneTokenTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_phone_page_shows_no_key_until_one_is_generated(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->get(route('phone.edit'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('settings/Phone')
                ->where('phone.token', null)
                ->where('phone.feeds', [])
            );
    }

    public function test_generating_a_key_mints_it_and_the_feed_link_carries_it(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->post(route('phone.token.regenerate'))
            ->assertRedirect(route('phone.edit'));

        $token = $user->fresh()->widget_token;
        $this->assertNotNull($token);
        $this->assertSame(48, strlen($token));

        $this->actingAs($user)
            ->get(route('phone.edit'))
            ->assertInertia(fn (Assert $page) => $page
                ->where('phone.token', $token)
                ->where('phone.feeds.0.url', route('api.widget.status', ['token' => $token]))
            );

        $this->getJson(route('api.widget.status', ['token' => $token]))->assertOk();
    }

    public function test_generating_again_rolls_the_key_and_the_old_one_stops_working(): void
    {
        $user = User::factory()->create();
        $old = $user->regenerateWidgetToken();

        $this->actingAs($user)->post(route('phone.token.regenerate'))->assertRedirect();

        $new = $user->fresh()->widget_token;
        $this->assertNotSame($old, $new);

        $this->getJson(route('api.widget.status', ['token' => $old]))->assertStatus(401);
        $this->getJson(route('api.widget.status', ['token' => $new]))->assertOk();
    }

    public function test_the_legacy_widget_token_endpoint_still_returns_the_same_key(): void
    {
        $user = User::factory()->create();
        $token = $user->regenerateWidgetToken();

        $this->actingAs($user)->post(route('widget.token'))->assertOk()->assertJson(['token' => $token]);
    }

    public function test_revoking_clears_the_key(): void
    {
        $user = User::factory()->create();
        $old = $user->regenerateWidgetToken();

        $this->actingAs($user)
            ->delete(route('phone.token.revoke'))
            ->assertRedirect(route('phone.edit'));

        $this->assertNull($user->fresh()->widget_token);
        $this->getJson(route('api.widget.status', ['token' => $old]))->assertStatus(401);
    }

    public function test_guests_cannot_touch_the_key(): void
    {
        $this->get(route('phone.edit'))->assertRedirect(route('login'));
        $this->post(route('phone.token.regenerate'))->assertRedirect(route('login'));
        $this->delete(route('phone.token.revoke'))->assertRedirect(route('login'));
    }
}
