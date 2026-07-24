<?php

namespace Tests\Unit;

use App\Services\Claude\ClaudeCli;
use RuntimeException;
use Tests\TestCase;

/**
 * The CLI's JSON discipline is the seam every Claude-facing service leans on.
 * A single malformed generation must not cost the player their turn — so the
 * parser survives fences, trailing prose and raw line breaks, and retries once.
 */
class ClaudeCliJsonTest extends TestCase
{
    private function cli(string ...$responses): ClaudeCli
    {
        return new class(...$responses) extends ClaudeCli
        {
            /** @var list<string> */
            public array $prompts = [];

            /** @var list<string> */
            private array $responses;

            public function __construct(string ...$responses)
            {
                $this->responses = $responses;
            }

            public function prompt(string $prompt, ?string $systemPrompt = null): string
            {
                $this->prompts[] = $prompt;

                return array_shift($this->responses) ?? '';
            }
        };
    }

    public function test_it_decodes_a_fenced_object(): void
    {
        $cli = $this->cli("```json\n{\"reply\": \"hello\"}\n```");

        $this->assertSame(['reply' => 'hello'], $cli->promptForJson('go'));
    }

    public function test_it_ignores_prose_before_and_after_the_object(): void
    {
        $cli = $this->cli("Here you go:\n{\"a\": {\"b\": 1}}\nHope that helps! (nested} braces in prose)");

        $this->assertSame(['a' => ['b' => 1]], $cli->promptForJson('go'));
    }

    public function test_it_repairs_raw_line_breaks_inside_strings(): void
    {
        $cli = $this->cli("{\"prologue\": \"First line.\n\nSecond line.\"}");

        $this->assertSame(
            ['prologue' => "First line.\n\nSecond line."],
            $cli->promptForJson('go'),
        );
    }

    public function test_it_does_not_mistake_a_nested_brace_for_the_end_of_a_truncated_object(): void
    {
        // Truncated mid-prologue: the greedy old match closed on the nested
        // brace and decoded to junk. Nothing usable here — it must retry.
        $truncated = '{"character": {"name": "Thicket"}, "prologue": "Before she had a name, she';
        $cli = $this->cli($truncated, '{"prologue": "Before she had a name, she was rumor."}');

        $this->assertSame(['prologue' => 'Before she had a name, she was rumor.'], $cli->promptForJson('go'));
        $this->assertCount(2, $cli->prompts);
        $this->assertStringContainsString('could not be parsed', $cli->prompts[1]);
    }

    public function test_it_throws_after_the_retry_also_fails(): void
    {
        $cli = $this->cli('not json at all', 'still not json');
        $before = glob(storage_path('logs/claude-unparseable-*.txt')) ?: [];

        $this->expectException(RuntimeException::class);

        try {
            $cli->promptForJson('go');
        } finally {
            $this->assertCount(2, $cli->prompts);

            // The whole response lands on disk for diagnosis; drop this run's.
            $dumps = array_diff(glob(storage_path('logs/claude-unparseable-*.txt')) ?: [], $before);
            $this->assertCount(1, $dumps);
            $this->assertSame('still not json', file_get_contents((string) reset($dumps)));
            array_map('unlink', $dumps);
        }
    }
}
