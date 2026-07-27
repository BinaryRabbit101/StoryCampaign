<?php

namespace App\Services\Claude;

/**
 * The house prose register, shared by every prompt whose output the reader
 * actually reads (chapters, prologues, the coda). One authority instead of
 * a style paragraph drifting per prompt: the pages of one book must sound
 * like one book.
 */
class ProseStyle
{
    public static function rules(): string
    {
        return <<<'STYLE'
## Prose register (holds for every sentence)
- Plain and literal. Say what happened in direct words; the reader must never have to decode an image to learn a fact.
- At most ONE image in the whole piece, and only if it EARNS its place by revealing a concrete fact (what a scar implies, what a sound gives away). Decorative comparisons are banned outright — never "the way a man watches weather he can't outrun"; write what he actually did.
- People talk. When characters face each other, give them direct speech in quotes — short lines in their own voice, not descriptions of a conversation happening.
- Three materials only: what people say, what they do, and what is concretely there. No mood-writing, no abstractions about silence, weight, or fate standing in for events.

Wrong register: "He stayed low behind his guard, eyes on her the way a man watches weather he can't outrun — no opening in him, no answer to what she'd just thrown at him, nothing at all."
Right register: "He kept his guard up and said nothing. His feet stayed planted; his eyes stayed on her. 'Prove it,' he said."
STYLE;
    }
}
