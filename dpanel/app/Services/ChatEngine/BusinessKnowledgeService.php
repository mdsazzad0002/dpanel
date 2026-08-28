<?php

namespace App\Services\ChatEngine;

use App\Models\Business;
use App\Models\ChatChannel;

/**
 * Builds the system prompt sent to the AI Gateway for a channel: the
 * channel's own system prompt, plus (when the channel is assigned to a
 * business) that business's trained product/Q&A knowledge, with an explicit
 * instruction to answer only from it and never guess. Anything live
 * (searchable data sources, order/email/SMS actions) is exposed separately
 * as callable tools — see BusinessToolService — rather than injected here,
 * since those are on-demand, not always-relevant background knowledge.
 */
class BusinessKnowledgeService
{
    public const FALLBACK_REPLY = "I don't have that information yet — let me connect you with a team member who can help.";

    public function buildSystemPrompt(ChatChannel $channel): string
    {
        $base = $channel->systemPrompt();

        $business = $channel->business()->with('products.qnas')->first();

        if (! $business || (! $business->description && $business->products->isEmpty())) {
            return $base;
        }

        return $base."\n\n".$this->formatKnowledge($business);
    }

    private function formatKnowledge(Business $business): string
    {
        $lines = [
            'You are answering on behalf of the business "'.$business->name.'".',
            'Use ONLY the information below (business profile, products, and Q&A) to answer. Do not guess, assume, or invent facts, prices, policies, contact details, or availability that are not explicitly stated below.',
            'If the user\'s question is not answered by the information below, respond exactly with: "'.self::FALLBACK_REPLY.'" — do not attempt a best-guess answer.',
            '',
            '=== BUSINESS KNOWLEDGE ===',
        ];

        if ($business->description) {
            $lines[] = '';
            $lines[] = '# About this business (who we are, what we do, how to contact us)';
            $lines[] = $business->description;
        }

        foreach ($business->products as $product) {
            $lines[] = '';
            $lines[] = '# Product: '.$product->name;

            if ($product->description) {
                $lines[] = $product->description;
            }

            foreach ($product->qnas as $qna) {
                $lines[] = 'Q: '.$qna->question;
                $lines[] = 'A: '.$qna->answer;
            }
        }

        $lines[] = '=== END BUSINESS KNOWLEDGE ===';

        return implode("\n", $lines);
    }
}
