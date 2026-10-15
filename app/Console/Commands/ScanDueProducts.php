<?php

namespace App\Console\Commands;

use App\Models\ProductMonitor;
use App\Services\ProductScanner;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class ScanDueProducts extends Command
{
    protected $signature = 'scanner:monitor';
    protected $description = 'Actualiza productos cuyo monitoreo ya venció';

    public function handle(ProductScanner $scanner, \App\Services\BrowserScanner $browserScanner): int
    {
        ProductMonitor::with(['product.offers'])
            ->where('is_active', true)
            ->where(function ($query) {
                $query->whereNull('next_run_at')->orWhere('next_run_at', '<=', now());
            })
            ->each(function (ProductMonitor $monitor) use ($scanner, $browserScanner): void {
                $url = $monitor->product->offers->sortByDesc('last_seen_at')->first()?->url;
                if (!$url) return;

                try {
                    $scanner->scan($url, $monitor->user_id, $browserScanner);
                    $monitor->update(['last_run_at' => now(), 'next_run_at' => now()->addMinutes($monitor->interval_minutes)]);
                } catch (\Throwable $exception) {
                    Log::warning('Product monitor failed', ['monitor_id' => $monitor->id, 'error' => $exception->getMessage()]);
                    $monitor->update(['last_run_at' => now(), 'next_run_at' => now()->addMinutes($monitor->interval_minutes)]);
                }
            });

        return self::SUCCESS;
    }
}
