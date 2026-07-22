<?php

namespace App\Services\Claude;

use Illuminate\Support\Facades\Log;
use RuntimeException;
use Symfony\Component\Process\Process;

/**
 * Shells out to the Claude CLI. Each invocation is stateless: the prompt
 * carries everything the run needs (world state, logs, bible excerpts) and
 * the caller writes results back through the engine.
 */
class ClaudeCli
{
    public function prompt(string $prompt, ?string $systemPrompt = null): string
    {
        $command = [
            config('game.claude.binary'),
            '-p',
            '--model', config('game.claude.model'),
            '--output-format', 'text',
        ];

        if ($systemPrompt !== null) {
            $command[] = '--append-system-prompt';
            $command[] = $systemPrompt;
        }

        $process = new Process($command, base_path(), null, $prompt, (int) config('game.claude.timeout'));
        $process->run();

        if (! $process->isSuccessful()) {
            Log::error('Claude CLI failed', ['exit' => $process->getExitCode(), 'stderr' => $process->getErrorOutput()]);
            throw new RuntimeException('Claude CLI failed: '.$process->getErrorOutput());
        }

        return trim($process->getOutput());
    }

    /**
     * Prompt for a JSON object; tolerates fenced or prefixed output.
     *
     * @return array<string, mixed>
     */
    public function promptForJson(string $prompt, ?string $systemPrompt = null): array
    {
        $raw = $this->prompt($prompt, $systemPrompt);

        $json = preg_match('/\{.*\}/s', $raw, $matches) ? $matches[0] : $raw;
        $decoded = json_decode($json, true);

        if (! is_array($decoded)) {
            Log::error('Claude CLI returned unparseable JSON', ['raw' => mb_substr($raw, 0, 2000)]);
            throw new RuntimeException('Claude CLI returned unparseable JSON.');
        }

        return $decoded;
    }
}
