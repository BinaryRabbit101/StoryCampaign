<?php

namespace Tests\Unit;

use App\Services\CapabilityClamp;
use Tests\TestCase;

class CapabilityClampTest extends TestCase
{
    public function test_magnitudes_are_clamped_to_bible_bounds()
    {
        $result = (new CapabilityClamp)->clamp([
            ['capability' => 'reach', 'magnitude' => 1000],
        ]);

        $this->assertSame(20, $result['capabilities'][0]['magnitude']);
    }

    public function test_unknown_capabilities_are_dropped()
    {
        $result = (new CapabilityClamp)->clamp([
            ['capability' => 'fly_to_the_moon', 'magnitude' => 5],
            ['capability' => 'grapple'],
        ]);

        $this->assertCount(1, $result['capabilities']);
        $this->assertSame('grapple', $result['capabilities'][0]['capability']);
    }

    public function test_high_magnitudes_recouple_a_constraint()
    {
        $result = (new CapabilityClamp)->clamp([
            ['capability' => 'reach', 'magnitude' => 18],
        ]);

        $this->assertSame('unwieldy', $result['constraints'][0]['name']);
        $this->assertSame('reach', $result['constraints'][0]['coupled_capability']);
    }

    public function test_modest_magnitudes_carry_no_recoupled_constraint()
    {
        $result = (new CapabilityClamp)->clamp([
            ['capability' => 'reach', 'magnitude' => 12],
        ]);

        $this->assertSame([], $result['constraints']);
    }
}
