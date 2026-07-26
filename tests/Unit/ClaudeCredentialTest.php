<?php

namespace Tests\Unit;

use App\Services\Claude\ClaudeCli;
use ReflectionMethod;
use Tests\TestCase;

/**
 * Where the credential comes from, and what happens when it is refused.
 *
 * Both halves are scar tissue. A token pasted into .env is frozen into the
 * config cache, so the value the app runs on can drift away from the value on
 * disk with nothing to show for it; and the CLI reports a refused token on
 * STDOUT, so a wrapper that logs only stderr records the outage as an empty
 * string. Together those cost a campaign an evening.
 */
class ClaudeCredentialTest extends TestCase
{
    private function token(): ?string
    {
        $method = new ReflectionMethod(ClaudeCli::class, 'oauthToken');

        return $method->invoke(app(ClaudeCli::class));
    }

    private function isAuthFailure(string $output): bool
    {
        return (new ReflectionMethod(ClaudeCli::class, 'isAuthFailure'))
            ->invoke(app(ClaudeCli::class), $output);
    }

    public function test_the_token_file_outranks_the_baked_in_literal(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'claude-token');
        file_put_contents($path, "from-the-file\n");

        config([
            'game.claude.oauth_token' => 'from-the-env',
            'game.claude.oauth_token_file' => $path,
        ]);

        $this->assertSame('from-the-file', $this->token());

        // Rotation is one edit to one file, and the very next run uses it —
        // no config:cache step in between to forget.
        file_put_contents($path, "rotated\n");
        $this->assertSame('rotated', $this->token());

        unlink($path);
    }

    public function test_a_trailing_newline_never_becomes_part_of_the_bearer_token(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'claude-token');
        file_put_contents($path, "  spaced-token  \n\n");
        config(['game.claude.oauth_token_file' => $path, 'game.claude.oauth_token' => null]);

        $this->assertSame('spaced-token', $this->token());

        unlink($path);
    }

    public function test_an_unreadable_or_empty_file_falls_back_rather_than_going_dark(): void
    {
        config([
            'game.claude.oauth_token' => 'from-the-env',
            'game.claude.oauth_token_file' => '/no/such/token/file',
        ]);
        $this->assertSame('from-the-env', $this->token());

        $empty = tempnam(sys_get_temp_dir(), 'claude-token');
        file_put_contents($empty, "\n");
        config(['game.claude.oauth_token_file' => $empty]);
        $this->assertSame('from-the-env', $this->token());

        unlink($empty);
    }

    public function test_it_recognizes_the_cli_ways_of_saying_the_credential_is_refused(): void
    {
        // Every one of these arrives on stdout, with an empty stderr.
        $this->assertTrue($this->isAuthFailure('Failed to authenticate. API Error: 401 Invalid bearer token'));
        $this->assertTrue($this->isAuthFailure('Not logged in · Please run /login'));
        $this->assertTrue($this->isAuthFailure('OAuth token has expired'));

        // An ordinary bad run must stay retryable — only a refused credential
        // is worth stopping the sweep over.
        $this->assertFalse($this->isAuthFailure('Error: prompt too long'));
        $this->assertFalse($this->isAuthFailure(''));
    }
}
