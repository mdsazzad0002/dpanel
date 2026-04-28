<?php

namespace App\Services\ServerPanel\Contracts;

interface AiSuggestionProvider
{
    /**
     * @param  array<string,mixed>  $context
     * @return array<string,mixed>
     */
    public function suggest(array $context): array;
}
