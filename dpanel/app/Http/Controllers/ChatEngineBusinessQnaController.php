<?php

namespace App\Http\Controllers;

use App\Models\Business;
use App\Models\BusinessProduct;
use App\Models\BusinessQna;
use App\Services\AiGateway\AiGatewayService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class ChatEngineBusinessQnaController extends Controller
{
    public function __construct(private readonly AiGatewayService $aiGateway)
    {
    }

    public function store(Request $request, $token, Business $business, BusinessProduct $product): RedirectResponse
    {
        $this->authorizeProduct($request, $business, $product);

        $validated = $request->validate([
            'question' => ['required', 'string', 'max:2000'],
            'answer' => ['required', 'string', 'max:4000'],
        ]);

        $product->qnas()->create([
            ...$validated,
            'sort_order' => $product->qnas()->max('sort_order') + 1,
        ]);

        return back()->with('success', 'Q&A added.');
    }

    public function update(Request $request, $token, Business $business, BusinessProduct $product, BusinessQna $qna): RedirectResponse
    {
        $this->authorizeProduct($request, $business, $product);
        abort_unless($qna->business_product_id === $product->id, 404);

        $validated = $request->validate([
            'question' => ['required', 'string', 'max:2000'],
            'answer' => ['required', 'string', 'max:4000'],
        ]);

        $qna->update($validated);

        return back()->with('success', 'Q&A updated.');
    }

    public function destroy(Request $request, $token, Business $business, BusinessProduct $product, BusinessQna $qna): RedirectResponse
    {
        $this->authorizeProduct($request, $business, $product);
        abort_unless($qna->business_product_id === $product->id, 404);

        $qna->delete();

        return back()->with('success', 'Q&A deleted.');
    }

    /**
     * Draft candidate Q&A pairs from the business + product context — never
     * saved directly; the admin reviews and adds them individually via
     * store(), keeping unreviewed AI output out of the live knowledge base.
     */
    public function suggest(Request $request, $token, Business $business, BusinessProduct $product): JsonResponse
    {
        $this->authorizeProduct($request, $business, $product);

        $existing = $product->qnas()->pluck('question')->implode('; ') ?: 'none yet';

        $result = $this->aiGateway->chatAuto(null, [
            ['role' => 'user', 'content' => 'Business description: '.($business->description ?: 'not provided')
                ."\nProduct: {$product->name}\nProduct description: ".($product->description ?: 'not provided')
                ."\nQuestions already covered (do not repeat these): {$existing}"],
        ], [
            'system' => 'You draft candidate customer-support Q&A pairs for a business\'s AI knowledge base. '
                .'Given the business/product info below, output ONLY a strict JSON array (no markdown, no prose) of 3 to 5 objects shaped {"question": "...", "answer": "..."}. '
                .'Every answer must be grounded only in the given business/product text — if there isn\'t enough information to answer a plausible customer question confidently, do not include that question at all. Never invent prices, policies, or facts not stated. '
                .'Do not repeat any already-covered question.',
            'operation' => 'business_qna_suggest',
        ]);

        $suggestions = json_decode(trim((string) $result['content']), true);

        if (! is_array($suggestions)) {
            return response()->json(['suggestions' => []]);
        }

        $suggestions = array_values(array_filter(array_map(function ($item) {
            if (! is_array($item) || empty($item['question']) || empty($item['answer'])) {
                return null;
            }

            return ['question' => (string) $item['question'], 'answer' => (string) $item['answer']];
        }, $suggestions)));

        return response()->json(['suggestions' => $suggestions]);
    }

    private function authorizeProduct(Request $request, Business $business, BusinessProduct $product): void
    {
        abort_unless(Business::query()->visibleTo($request->user())->whereKey($business->id)->exists(), 403);
        abort_unless($product->business_id === $business->id, 404);
    }
}
