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
- No images. No similes, no metaphors, no personification — not one, not an earned one. If a detail matters, state it as a fact ("the light killed her night vision"), never as a comparison ("went out like a snuffed lamp").
- Detail is rationed. Name a thing in a word or two and move on — no inventories of a room, no textures, colors, rust, or lighting effects. A thing earns more than its name only when the extra words change what someone could DO with it: a gap wide enough to jump, a shelf tall enough to hide behind. If a sentence is about how a place looks rather than what someone did there, cut it.
- People talk. When characters face each other, give them direct speech in quotes — short lines in their own voice, not descriptions of a conversation happening.
- Nobody reads out a menu. A character may press, threaten, plead, or demand an answer, but never lays the reader's options out as a list — no "you have two choices", no "A or B — which is it?", no counting off the ways forward. People in the world don't know the reader is choosing; they want something and say so.
- Two materials carry the page: what people say and what they do. What is concretely there gets named only when someone says, does, or is about to do something with it. No mood-writing, no abstractions about silence, weight, or fate standing in for events.

Wrong register: "The light came on overhead, a strip bolted along the ceiling, and it buzzed white and merciless into eyes built for dead engines and darker decks."
Right register: "The overhead light came on and killed her night vision."
Wrong register: "It had closed the way a door closes on purpose, drawn into a frame gone soft and orange with rust."
Right register: "Someone had shut the hatch behind her."
Wrong dialogue: "'You have two choices,' she said. 'The ladder or the gate. Which will it be?'"
Right dialogue: "'The gate's mine,' she said, and set her boot against it. 'Try the ladder if you're in a hurry to die.'"
STYLE;
    }
}
