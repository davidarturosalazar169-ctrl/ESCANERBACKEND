<?php

namespace App\Http\Controllers;

use App\Models\Product;
use App\Models\ProductMonitor;
use App\Models\Scan;
use App\Models\PriceAlert;
use App\Models\Store;
use App\Services\ProductScanner;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

class ScannerController extends Controller
{
    public function scan(Request $request, ProductScanner $scanner, \App\Services\BrowserScanner $browserScanner): JsonResponse
    {
        $data = $request->validate(['url' => ['required', 'url', 'max:2048']]);
        try {
            return response()->json($scanner->scan($data['url'], $request->user()->id, $browserScanner), 201);
        } catch (\Throwable $exception) {
            Log::warning('Product scan failed', ['url' => $data['url'], 'error' => $exception->getMessage()]);
            throw ValidationException::withMessages(['url' => [$exception->getMessage()]]);
        }
    }

    public function products(): JsonResponse
    {
        return response()->json(['products' => Product::with(['offers.store', 'offers.priceHistories'])->orderByDesc('popularity_score')->paginate(20)]);
    }

    public function product(Product $product): JsonResponse
    {
        return response()->json(['product' => $product->load(['offers.store', 'offers.priceHistories'])]);
    }

    public function monitors(Request $request): JsonResponse
    {
        return response()->json(['monitors' => ProductMonitor::with('product')->where('user_id', $request->user()->id)->latest()->get()]);
    }

    public function monitor(Request $request, Product $product): JsonResponse
    {
        $data = $request->validate(['interval_minutes' => ['required', 'integer', 'min:5', 'max:10080'], 'is_active' => ['sometimes', 'boolean']]);
        $monitor = ProductMonitor::updateOrCreate(['user_id' => $request->user()->id, 'product_id' => $product->id], $data);
        $monitor->update(['next_run_at' => now()->addMinutes($monitor->interval_minutes)]);
        return response()->json(['monitor' => $monitor->load('product')], 201);
    }

    public function history(Request $request): JsonResponse
    {
        $scans = Scan::with(['product.offers.store', 'product.offers.priceHistories'])
            ->where('user_id', $request->user()->id)
            ->latest()
            ->paginate(20);

        return response()->json(['scans' => $scans]);
    }

    public function alerts(Request $request): JsonResponse
    {
        return response()->json([
            'alerts' => PriceAlert::with(['product', 'productOffer.store'])
                ->where('user_id', $request->user()->id)
                ->latest()
                ->paginate(20),
            'unread_count' => PriceAlert::where('user_id', $request->user()->id)->whereNull('read_at')->count(),
        ]);
    }

    public function readAlert(Request $request, PriceAlert $alert): JsonResponse
    {
        abort_unless($alert->user_id === $request->user()->id, 403);
        $alert->update(['read_at' => now()]);

        return response()->json(['alert' => $alert]);
    }

    public function updateStoreSelectors(Request $request, Store $store): JsonResponse
    {
        $request->validate(['selectors' => ['required', 'array', 'max:50']]);
        $selectors = [];
        foreach ($request->input('selectors') as $key => $value) {
            if (!is_string($key) || !preg_match('/^[a-zA-Z][a-zA-Z0-9_-]{0,60}$/', $key)) {
                throw ValidationException::withMessages(['selectors' => ['El nombre del campo no es válido.']]);
            }
            if (!is_string($value) || mb_strlen($value) > 500) {
                throw ValidationException::withMessages(["selectors.{$key}" => ['El selector debe ser texto de máximo 500 caracteres.']]);
            }
            if (trim($value) !== '') $selectors[$key] = trim($value);
        }
        if (!$selectors) {
            throw ValidationException::withMessages(['selectors' => ['Agrega al menos un selector.']]);
        }

        $store->update(['selectors' => array_merge($store->selectors ?? [], $selectors)]);
        return response()->json(['store' => $store->fresh()]);
    }

    public function previewHtml(Request $request, ProductScanner $scanner, \App\Services\BrowserScanner $browserScanner): JsonResponse
    {
        $data = $request->validate(['url' => ['required', 'url', 'max:2048']]);
        try {
            $scanner->assertSafeUrl($data['url']);
        } catch (\Throwable $exception) {
            throw ValidationException::withMessages(['url' => [$exception->getMessage()]]);
        }

        return response()->json([
            'html' => $browserScanner->preview($data['url']),
        ]);
    }
}
