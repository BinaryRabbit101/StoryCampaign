<?php

namespace App\Game;

/**
 * Where a vignette stops. The engine narrates forward until one of these
 * fires, then halts and returns fresh contextual cards. Guiding principle:
 * stop when the player's input would genuinely change what happens next.
 */
enum BranchTrigger: string
{
    case IntentComplete = 'intent_complete';     // 1. stated intent resolved
    case NewThreat = 'new_threat';               // 2. something new enters mid-scene
    case MeaningfulFork = 'meaningful_fork';     // 3. 2+ legal paths, real stakes
    case ResourceThreshold = 'resource_threshold'; // 4. a meter crossed a critical line
    case FailurePivot = 'failure_pivot';         // 5. a failure opened a new situation
    case IrreversibleGate = 'irreversible_gate'; // 6. next beat commits something un-undoable
    case SceneTransition = 'scene_transition';   // 7. new space, new affordance set
    case SoftTimeout = 'soft_timeout';           // 8. quiet vignette ran long

    public function description(): string
    {
        return match ($this) {
            self::IntentComplete => 'The stated intent resolved; a new scene opens.',
            self::NewThreat => 'Something new has entered the scene.',
            self::MeaningfulFork => 'The path forward genuinely diverges.',
            self::ResourceThreshold => 'A vital resource crossed a critical line.',
            self::FailurePivot => 'A failure has opened a new situation.',
            self::IrreversibleGate => 'The next step cannot be undone.',
            self::SceneTransition => 'The character has entered a new space.',
            self::SoftTimeout => 'The moment settles; time passes.',
        };
    }
}
