<?php

namespace App\Services\Ai;

use App\Models\Establishment;
use App\Services\Ai\Contracts\AiClient;

class PitchGenerator
{
    public function __construct(
        private readonly AiClient $ai,
        private readonly Prompts $prompts,
        private readonly CompanySummarizer $summarizer,
    ) {}

    /** @param 'email'|'call' $channel */
    public function generate(Establishment $establishment, string $channel): string
    {
        $this->summarizer->assertAllowed($establishment); // même garde RGPD

        return $this->ai->chat(
            $this->prompts->pitchMessages($establishment, $channel),
            ['max_tokens' => 500, 'temperature' => 0.5],
        );
    }
}
