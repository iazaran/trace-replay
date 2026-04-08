<?php

namespace TraceReplay\Services\Ai;

interface AiDriverInterface
{
    /**
     * Complete a prompt using the AI provider.
     */
    public function complete(string $prompt): ?string;
}
