<?php

namespace App\Services\Ai\Contracts;

interface AiClient
{
    /**
     * @param  list<array{role: string, content: string}>  $messages
     * @param  array<string, mixed>  $options
     */
    public function chat(array $messages, array $options = []): string;
}
