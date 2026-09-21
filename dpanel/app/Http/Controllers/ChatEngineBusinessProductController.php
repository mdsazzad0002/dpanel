<?php

namespace App\Http\Controllers;

use App\Models\Business;
use App\Models\BusinessProduct;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class ChatEngineBusinessProductController extends Controller
{
    public function store(Request $request, $token, Business $business): RedirectResponse
    {
        $this->authorizeBusiness($request, $business);

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:2000'],
        ]);

        $business->products()->create([
            ...$validated,
            'sort_order' => $business->products()->max('sort_order') + 1,
        ]);

        return back()->with('success', 'Product added.');
    }

    public function update(Request $request, $token, Business $business, BusinessProduct $product): RedirectResponse
    {
        $this->authorizeBusiness($request, $business);
        abort_unless($product->business_id === $business->id, 404);

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:2000'],
        ]);

        $product->update($validated);

        return back()->with('success', 'Product updated.');
    }

    public function destroy(Request $request, $token, Business $business, BusinessProduct $product): RedirectResponse
    {
        $this->authorizeBusiness($request, $business);
        abort_unless($product->business_id === $business->id, 404);

        $product->delete();

        return back()->with('success', 'Product deleted.');
    }

    private function authorizeBusiness(Request $request, Business $business): void
    {
        abort_unless(Business::query()->visibleTo($request->user())->whereKey($business->id)->exists(), 403);
    }
}
