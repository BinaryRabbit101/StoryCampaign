<?php

namespace Tests\Feature;

use App\Models\Campaign;
use App\Models\InterviewMessage;
use App\Models\User;
use App\Services\Claude\ClaudeCli;
use Database\Seeders\WorldSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

/**
 * The creation interview leans with the bargain, not always against it.
 *
 * The claim this holds down: the narrator is TOLD the running balance the
 * player can already see on screen, and which way to lean because of it. A
 * narrator blind to it hedges the only safe way it can — by asking what
 * everything costs, every single time — which turned every offered answer
 * into a burden even for a character with points sitting unspent.
 *
 * The engine owns the direction (it owns the arithmetic); Claude owns the
 * words. So what is asserted here is the instruction in the prompt, not the
 * prose that comes back from it.
 */
class InterviewBalanceTest extends TestCase
{
    use RefreshDatabase;

    /** @var list<string> */
    private array $prompts = [];

    /** The draft the narrator answers with next, replaced between exchanges. */
    private ?array $sheet = null;

    /** The suggestions the narrator answers with next. */
    private array $offered = [['text' => 'I can swim.', 'kind' => 'gift']];

    private function openInterview(): Campaign
    {
        $this->seed(WorldSeeder::class);
        Notification::fake();

        $this->prompts = [];
        $this->mock(ClaudeCli::class, function ($mock) {
            $mock->shouldReceive('promptForJson')->andReturnUsing(function (string $prompt) {
                $this->prompts[] = $prompt;

                return [
                    'reply' => 'And what walks in with you?',
                    'suggestions' => $this->offered,
                    'character' => $this->sheet,
                ];
            })->byDefault();
            $mock->shouldReceive('prompt')->andReturn('A tale begins.')->byDefault();
        });

        $user = User::factory()->create();
        $this->actingAs($user)->post('/campaigns', ['name' => 'The Weighing']);

        return $user->campaigns()->firstOrFail();
    }

    /** One exchange, with whatever draft the narrator is currently answering with. */
    private function say(Campaign $campaign, string $words): void
    {
        $this->actingAs($campaign->user)
            ->post("/campaigns/{$campaign->id}/interview", ['body' => $words]);
    }

    /**
     * The creation prompt from the newest exchange. The world is forged through
     * the same CLI, so the transcript of prompts holds more than this one.
     */
    private function lastCreationPrompt(): string
    {
        $creation = array_values(array_filter(
            $this->prompts,
            fn (string $p) => str_contains($p, 'character creation interview'),
        ));

        $this->assertNotEmpty($creation, 'no creation prompt was built');

        return end($creation);
    }

    public function test_the_opening_exchange_leans_neither_way()
    {
        $campaign = $this->openInterview();
        $this->say($campaign, 'I am something large and quiet.');

        $prompt = $this->lastCreationPrompt();

        // Nothing is priced yet, so there is no bargain to be on a side of.
        $this->assertStringContainsString('Nothing is priced yet', $prompt);
        $this->assertStringContainsString('carrying a gift AND the price it drags', $prompt);
        $this->assertStringNotContainsString('LEAN POSITIVE', $prompt);
        $this->assertStringNotContainsString('LEAN TOWARD THE PRICE', $prompt);
    }

    public function test_points_in_hand_lean_the_interview_toward_gifts()
    {
        $campaign = $this->openInterview();

        // swim costs 1 against an allowance of 3: two points unspent.
        $this->sheet = [
            'name' => 'The Unspent',
            'description' => 'Carries less than they can afford.',
            'capabilities' => [['capability' => 'swim']],
            'constraints' => [],
        ];

        $this->say($campaign, 'I swim well.');
        $this->say($campaign, 'What else?');

        $prompt = $this->lastCreationPrompt();

        $this->assertStringContainsString('2 points STILL UNSPENT', $prompt);
        $this->assertStringContainsString('LEAN POSITIVE', $prompt);
        $this->assertStringContainsString('must reach for a gift (kind "gift" or "both")', $prompt);
        $this->assertStringContainsString('Do not ask what it costs them', $prompt);
        $this->assertStringNotContainsString('LEAN TOWARD THE PRICE', $prompt);

        // And it says what is already carried, so nothing is offered twice.
        $this->assertStringContainsString('gifts: swim', $prompt);
        $this->assertStringContainsString('prices carried: none yet', $prompt);
    }

    public function test_an_overspent_draft_leans_toward_the_price_but_never_only_downward()
    {
        $campaign = $this->openInterview();

        // reach(12) 4 + intimidate 3 against an allowance of 3: overspent by 4.
        $this->sheet = [
            'name' => 'The Unpaid',
            'description' => 'All gift, no debt.',
            'capabilities' => [
                ['capability' => 'reach', 'magnitude' => 12],
                ['capability' => 'intimidate', 'scope' => ['vs' => 'regular']],
            ],
            'constraints' => [],
        ];

        $this->say($campaign, 'I am mighty.');
        $this->say($campaign, 'And more besides.');

        $prompt = $this->lastCreationPrompt();

        $this->assertStringContainsString('OVERSPENT by 4', $prompt);
        $this->assertStringContainsString('LEAN TOWARD THE PRICE', $prompt);
        $this->assertStringNotContainsString('LEAN POSITIVE', $prompt);

        // Overspent is still not four ways to make yourself worse.
        $this->assertStringContainsString('at least ONE suggestion must be a way FORWARD', $prompt);
        $this->assertStringContainsString('Name what they would still be', $prompt);
    }

    public function test_an_even_bargain_asks_in_both_directions()
    {
        $campaign = $this->openInterview();

        // swim 1 + grapple 1 + pull 1 against an allowance of 3: settled up.
        $this->sheet = [
            'name' => 'The Settled',
            'description' => 'Every point spoken for.',
            'capabilities' => [
                ['capability' => 'swim'],
                ['capability' => 'grapple'],
                ['capability' => 'pull'],
            ],
            'constraints' => [],
        ];

        $this->say($campaign, 'I am exactly what I am.');
        $this->say($campaign, 'Anything else?');

        $prompt = $this->lastCreationPrompt();

        $this->assertStringContainsString('EXACTLY EVEN', $prompt);
        $this->assertStringContainsString('BALANCE THE OFFER', $prompt);
        $this->assertStringNotContainsString('LEAN POSITIVE', $prompt);
        $this->assertStringNotContainsString('LEAN TOWARD THE PRICE', $prompt);
    }

    /**
     * The prices a burden bought back are named too — a lean is only readable
     * against what the sheet is actually carrying.
     */
    public function test_the_prompt_names_the_prices_already_carried()
    {
        $campaign = $this->openInterview();

        // reach(12) 4, paid down by a constraint worth 2, against an allowance
        // of 3: a point still in hand, and the singular says so.
        $this->sheet = [
            'name' => 'The Half-Paid',
            'description' => 'Long-limbed and unmistakable.',
            'capabilities' => [['capability' => 'reach', 'magnitude' => 12]],
            'constraints' => [['name' => 'stealth_penalty', 'params' => ['reason' => 'unmistakable']]],
        ];

        $this->say($campaign, 'My reach betrays me.');
        $this->say($campaign, 'Go on.');

        $prompt = $this->lastCreationPrompt();

        $this->assertStringContainsString('gifts: reach(12)', $prompt);
        $this->assertStringContainsString('prices carried: stealth penalty', $prompt);

        // A burden the player already took is money in hand, and the lean has
        // to follow it: keeping the question on the price here would be the
        // interview refusing to notice they paid.
        $this->assertStringContainsString('1 point STILL UNSPENT', $prompt);
        $this->assertStringContainsString('LEAN POSITIVE', $prompt);
    }

    /**
     * The kind rides with the answer, so the chip can be tinted before the
     * player taps it. It is Claude labelling its own sentence — nothing here
     * reads the prose and decides what it meant.
     */
    public function test_each_offered_answer_carries_what_it_would_do_to_the_sheet()
    {
        $campaign = $this->openInterview();

        $this->offered = [
            ['text' => 'I can put my shoulder through a locked door.', 'kind' => 'gift'],
            ['text' => 'Crowds see me coming a street away.', 'kind' => 'price'],
            ['text' => 'I lift what three others cannot, and every room is too small.', 'kind' => 'both'],
        ];

        $this->say($campaign, 'I am strong.');

        $suggestions = $campaign->interviewMessages()
            ->where('role', 'narrator')->orderByDesc('id')->first()->suggestions;

        $this->assertSame($this->offered, $suggestions);
    }

    /** An invented kind is not a kind: it falls to neutral and the chip is plain. */
    public function test_an_unknown_kind_falls_back_to_no_tint_at_all()
    {
        $campaign = $this->openInterview();

        $this->offered = [
            ['text' => 'Something the narrator made up a colour for.', 'kind' => 'catastrophic'],
            ['text' => 'A bare string, the way it used to arrive.'],
        ];

        $this->say($campaign, 'Anything.');

        $suggestions = $campaign->interviewMessages()
            ->where('role', 'narrator')->orderByDesc('id')->first()->suggestions;

        $this->assertSame('neutral', $suggestions[0]['kind']);
        $this->assertSame('neutral', $suggestions[1]['kind']);
        $this->assertSame('A bare string, the way it used to arrive.', $suggestions[1]['text']);
    }

    /**
     * Every campaign already has rows holding plain strings. They are normalized
     * on the way OUT rather than backfilled, because a backfill would have to
     * guess a kind for prose nobody labelled — and guessing is the one thing the
     * kind exists to avoid.
     */
    public function test_suggestions_written_before_kinds_existed_still_read()
    {
        $campaign = $this->openInterview();

        $row = InterviewMessage::create([
            'campaign_id' => $campaign->id,
            'kind' => 'creation',
            'role' => 'narrator',
            'body' => 'From before the column carried kinds.',
        ]);

        // Straight past the model, exactly as the old code wrote it.
        InterviewMessage::whereKey($row->id)->update([
            'suggestions' => json_encode(['I am quick.', 'I am slow but certain.']),
        ]);

        $this->assertSame(
            [
                ['text' => 'I am quick.', 'kind' => 'neutral'],
                ['text' => 'I am slow but certain.', 'kind' => 'neutral'],
            ],
            $row->fresh()->suggestions,
        );
    }

    /** No suggestions at all stays null, so the chip row hides rather than empties. */
    public function test_an_empty_offer_is_null_rather_than_an_empty_row()
    {
        $campaign = $this->openInterview();

        $this->offered = [['text' => '   ', 'kind' => 'gift']];
        $this->say($campaign, 'Nothing useful comes back.');

        $this->assertNull(
            $campaign->interviewMessages()
                ->where('role', 'narrator')->orderByDesc('id')->first()->suggestions,
        );
    }
}
