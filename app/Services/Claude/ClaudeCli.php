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
    /** Why the last decode attempt failed — logged so the next break is diagnosable. */
    private string $lastParseError = 'unknown';

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

        // Explicit HOME lets the CLI locate ~/.claude auth even when invoked
        // by a user without a login environment (php-fpm pool, cron); the
        // oauth token covers boxes authenticated via `claude setup-token`.
        $env = array_filter([
            'HOME' => config('game.claude.home'),
            'CLAUDE_CODE_OAUTH_TOKEN' => $this->oauthToken(),
        ]) ?: null;

        $process = new Process($command, base_path(), $env, $prompt, (int) config('game.claude.timeout'));
        $process->run();

        if (! $process->isSuccessful()) {
            // BOTH streams. The CLI reports auth failures on stdout, so a log
            // line carrying only stderr says `"stderr":""` and explains
            // nothing — which is how "Invalid bearer token" hid behind 151
            // identical, contentless errors while the game sat dead.
            $stdout = trim($process->getOutput());
            $stderr = trim($process->getErrorOutput());
            $detail = trim($stderr."\n".$stdout) ?: '(no output on either stream)';

            if ($this->isAuthFailure($detail)) {
                Log::error('Claude CLI rejected the credential — narration and evolution are down until it is replaced', [
                    'exit' => $process->getExitCode(),
                    'detail' => $detail,
                    'token_source' => $this->tokenSource(),
                ]);

                throw new ClaudeAuthException('Claude CLI authentication failed: '.$detail);
            }

            Log::error('Claude CLI failed', [
                'exit' => $process->getExitCode(),
                'stderr' => $stderr,
                'stdout' => $stdout,
            ]);

            throw new RuntimeException('Claude CLI failed: '.$detail);
        }

        return trim($process->getOutput());
    }

    /**
     * The credential, preferring the shared file over the baked-in literal.
     *
     * Read at call time, never cached: `config:cache` freezes whatever .env
     * held at build time, and a token that stopped matching the one on disk
     * is indistinguishable from a token that expired. A file read costs
     * nothing next to the CLI run it precedes.
     */
    private function oauthToken(): ?string
    {
        $path = config('game.claude.oauth_token_file');

        if (is_string($path) && $path !== '' && is_readable($path)) {
            // First line only: an editor's trailing newline must not become
            // part of the bearer token.
            $token = trim((string) strtok((string) file_get_contents($path), "\r\n"));

            if ($token !== '') {
                return $token;
            }

            Log::warning('Claude token file is present but empty', ['path' => $path]);
        }

        return config('game.claude.oauth_token');
    }

    /** Which credential the last run used — the first question when auth breaks. */
    private function tokenSource(): string
    {
        $path = config('game.claude.oauth_token_file');

        if (is_string($path) && $path !== '' && is_readable($path)) {
            return "file:{$path}";
        }

        if ($path) {
            return "file:{$path} (UNREADABLE — fell back to CLAUDE_OAUTH_TOKEN)";
        }

        return config('game.claude.oauth_token') ? 'env:CLAUDE_OAUTH_TOKEN' : 'none (relying on ~/.claude credentials)';
    }

    /** A refused credential, as opposed to a run that merely went wrong. */
    private function isAuthFailure(string $output): bool
    {
        foreach (['invalid bearer token', 'not logged in', 'please run /login', 'failed to authenticate',
            'authentication_error', 'oauth token has expired', 'api error: 401', 'api error: 403'] as $needle) {
            if (str_contains(mb_strtolower($output), $needle)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Nudge for the retry run. Long prose generations occasionally come back
     * fenced-and-chatty, truncated, or with real line breaks inside strings;
     * one malformed generation should never cost the player their turn.
     */
    private const RAW_JSON_NUDGE = <<<'NUDGE'

        Your previous response could not be parsed. Respond again with ONLY the raw JSON
        object: no code fences, no commentary before or after it, no literal line breaks
        inside string values (write \n instead), and nothing omitted or abbreviated.
        NUDGE;

    /**
     * Prompt for a JSON object; tolerates fenced, prefixed or trailing prose,
     * and retries once before giving up.
     *
     * @return array<string, mixed>
     */
    public function promptForJson(string $prompt, ?string $systemPrompt = null): array
    {
        $raw = $this->prompt($prompt, $systemPrompt);
        $decoded = $this->decodeObject($raw);

        if ($decoded !== null) {
            return $decoded;
        }

        Log::warning('Claude CLI returned unparseable JSON; retrying once', [
            'error' => $this->lastParseError,
            'length' => mb_strlen($raw),
            'tail' => mb_substr($raw, -300),
        ]);

        $raw = $this->prompt($prompt.self::RAW_JSON_NUDGE, $systemPrompt);
        $decoded = $this->decodeObject($raw);

        if ($decoded !== null) {
            return $decoded;
        }

        Log::error('Claude CLI returned unparseable JSON', [
            'error' => $this->lastParseError,
            'length' => mb_strlen($raw),
            'raw' => mb_substr($raw, 0, 2000),
            'dump' => $this->dumpRaw($raw),
        ]);

        throw new RuntimeException('Claude CLI returned unparseable JSON.');
    }

    /**
     * Pull the first complete JSON object out of a CLI response and decode it.
     * Null means nothing usable was in there.
     *
     * @return array<string, mixed>|null
     */
    private function decodeObject(string $raw): ?array
    {
        $json = $this->firstBalancedObject($raw);

        if ($json === null) {
            $this->lastParseError = 'no complete JSON object in output (truncated?)';

            return null;
        }

        $decoded = json_decode($json, true);

        // Real line breaks inside a prose string are the common malformation;
        // escaping them costs nothing when the JSON was already valid.
        if (! is_array($decoded)) {
            $decoded = json_decode($this->escapeControlChars($json), true);
        }

        if (is_array($decoded)) {
            return $decoded;
        }

        $this->lastParseError = json_last_error_msg();

        return null;
    }

    /**
     * Scan for the first brace-balanced object, ignoring braces inside string
     * literals. Trailing commentary and closing fences fall away; a truncated
     * response yields null rather than a nested fragment that decodes to junk.
     */
    private function firstBalancedObject(string $text): ?string
    {
        $start = strpos($text, '{');

        if ($start === false) {
            return null;
        }

        $depth = 0;
        $inString = false;
        $escaped = false;
        $length = strlen($text);

        for ($i = $start; $i < $length; $i++) {
            $char = $text[$i];

            if ($inString) {
                if ($escaped) {
                    $escaped = false;
                } elseif ($char === '\\') {
                    $escaped = true;
                } elseif ($char === '"') {
                    $inString = false;
                }

                continue;
            }

            if ($char === '"') {
                $inString = true;
            } elseif ($char === '{') {
                $depth++;
            } elseif ($char === '}' && --$depth === 0) {
                return substr($text, $start, $i - $start + 1);
            }
        }

        return null;
    }

    /** Escape raw control characters that appear inside string literals. */
    private function escapeControlChars(string $json): string
    {
        $out = '';
        $inString = false;
        $escaped = false;
        $length = strlen($json);

        for ($i = 0; $i < $length; $i++) {
            $char = $json[$i];

            if ($inString && ! $escaped && ord($char) < 0x20) {
                $out .= match ($char) {
                    "\n" => '\\n',
                    "\r" => '\\r',
                    "\t" => '\\t',
                    default => sprintf('\\u%04x', ord($char)),
                };

                continue;
            }

            $out .= $char;

            if ($escaped) {
                $escaped = false;
            } elseif ($inString && $char === '\\') {
                $escaped = true;
            } elseif ($char === '"') {
                $inString = ! $inString;
            }
        }

        return $out;
    }

    /** Keep the whole response on disk — the log excerpt never shows the break. */
    private function dumpRaw(string $raw): string
    {
        $path = storage_path('logs/claude-unparseable-'.now()->format('Ymd-His').'-'.substr(md5($raw), 0, 6).'.txt');

        try {
            file_put_contents($path, $raw);
        } catch (\Throwable $e) {
            return 'unwritable: '.$e->getMessage();
        }

        return $path;
    }
}
