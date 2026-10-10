<?php

namespace Tests\Feature\Auth;

use App\Actions\Fortify\CreateNewUser;
use App\Models\User;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Illuminate\Validation\ValidationException;
use Laravel\Fortify\Features;
use Tests\TestCase;

/**
 * Phones capitalise the first letter of an email field, and SQLite's `=` is
 * case-sensitive. Every way an email reaches the users table must treat
 * `Emily@Example.com` and `emily@example.com` as the same address.
 */
class EmailCaseInsensitivityTest extends TestCase
{
    use RefreshDatabase;

    public function test_login_accepts_a_capitalised_email(): void
    {
        User::factory()->create(['email' => 'emily@example.com']);

        $this->post(route('login.store'), [
            'email' => ' Emily@Example.com ',
            'password' => 'password',
        ]);

        $this->assertAuthenticated();
    }

    public function test_reset_link_is_sent_for_a_capitalised_email(): void
    {
        $this->skipUnlessFortifyHas(Features::resetPasswords());

        Notification::fake();

        $user = User::factory()->create(['email' => 'emily@example.com']);

        $this->post(route('password.email'), ['email' => 'Emily@Example.com']);

        Notification::assertSentTo($user, ResetPassword::class);
    }

    public function test_registration_stores_the_email_in_lower_case(): void
    {
        $this->skipUnlessFortifyHas(Features::registration());

        $this->post(route('register.store'), [
            'name' => 'Emily',
            'email' => 'Emily@Example.com',
            'password' => 'password',
            'password_confirmation' => 'password',
        ]);

        $this->assertAuthenticated();
        $this->assertSame('emily@example.com', User::sole()->email);
    }

    public function test_create_user_action_lower_cases_and_rejects_a_case_duplicate(): void
    {
        $user = app(CreateNewUser::class)->create([
            'name' => 'Emily',
            'email' => ' Emily@Example.com ',
            'password' => 'password',
            'password_confirmation' => 'password',
        ]);

        $this->assertSame('emily@example.com', $user->email);

        $this->expectException(ValidationException::class);

        app(CreateNewUser::class)->create([
            'name' => 'Imposter',
            'email' => 'EMILY@example.com',
            'password' => 'password',
            'password_confirmation' => 'password',
        ]);
    }

    public function test_profile_update_lower_cases_the_email(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->patch(route('profile.update'), [
                'name' => 'Emily',
                'email' => ' New@Example.com ',
            ])
            ->assertSessionHasNoErrors();

        $this->assertSame('new@example.com', $user->refresh()->email);
    }

    public function test_profile_update_with_a_recapitalised_email_keeps_verification(): void
    {
        $user = User::factory()->create(['email' => 'emily@example.com']);

        $this->actingAs($user)
            ->patch(route('profile.update'), [
                'name' => 'Emily',
                'email' => 'Emily@Example.com',
            ])
            ->assertSessionHasNoErrors();

        $user->refresh();

        $this->assertSame('emily@example.com', $user->email);
        $this->assertNotNull($user->email_verified_at);
    }

    public function test_profile_update_rejects_another_users_email_in_any_case(): void
    {
        User::factory()->create(['email' => 'taken@example.com']);
        $user = User::factory()->create();

        $this->actingAs($user)
            ->patch(route('profile.update'), [
                'name' => 'Emily',
                'email' => 'Taken@Example.com',
            ])
            ->assertSessionHasErrors('email');
    }
}
