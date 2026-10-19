<?php

namespace App\Services;

use App\Models\Product;
use App\Models\ProductOffer;
use App\Models\ProductMonitor;
use App\Models\PriceAlert;
use App\Models\PriceHistory;
use App\Models\Scan;
use App\Models\Store;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use RuntimeException;

class ProductScanner
{
    private const STORE_RULES = [
        'amazon.' => ['name' => 'Amazon', 'selectors' => ['name' => '//*[@id="productTitle"]', 'price' => '//*[contains(@class,"a-offscreen")]', 'image' => '//*[@id="landingImage"]']],
        'mercadolibre.' => ['name' => 'Mercado Libre', 'selectors' => ['name' => '//*[contains(@class,"ui-pdp-title") or starts-with(@data-testid,"ui-pdp-title")]', 'price' => '//*[contains(@class,"andes-money-amount__fraction") or contains(@data-testid,"price")]', 'image' => '//*[contains(@class,"ui-pdp-gallery__figure")]//img | //img[contains(@src,"mlstatic.com")]']],
        'ebay.' => ['name' => 'eBay', 'selectors' => ['name' => '//*[contains(@class,"x-item-title__mainTitle")]', 'price' => '//*[contains(@class,"x-price-primary")]//span', 'image' => '//*[contains(@class,"ux-image-carousel-item")]//img']],
        'liverpool.' => ['name' => 'Liverpool', 'selectors' => ['name' => '//h1 | //*[@data-testid="product-name"]', 'price' => '//*[@data-testid="discounted"] | //*[@data-testid="price"]', 'image' => '//*[contains(@data-testid,"gallery__main__image__image") or contains(@src,"liverpool.com.mx/i/")]']],
        'buscalibre.' => ['name' => 'Buscalibre', 'selectors' => ['name' => '//*[contains(@class,"titulo") or contains(@class,"product-name")]', 'price' => '//*[contains(@class,"precio") or contains(@class,"price")]', 'image' => '//*[contains(@class,"product-image")]//img']],
    ];

    public function scan(string $url, int $userId, BrowserScanner $browserScanner): array
    {
        $startedAt = hrtime(true);
        $scan = Scan::create(['user_id' => $userId, 'url' => $url, 'status' => 'running']);

        try {
            $this->assertSafeUrl($url);
            $host = strtolower((string) parse_url($url, PHP_URL_HOST));
            $rule = $this->ruleFor($host);
            $store = Store::where('domain', $host)->first();
            $storedSelectors = $store?->selectors ?? [];
            $customSelectors = array_filter($storedSelectors, static fn ($value): bool => is_string($value) && !str_starts_with(trim($value), '//'));
            $selectors = array_merge($rule['selectors'], $storedSelectors);
            $staticSelectors = array_merge($rule['selectors'], array_filter($storedSelectors, static fn ($value): bool => is_string($value) && str_starts_with(trim($value), '//')));
            if (app()->environment('testing')) {
                $data = $this->scanStaticPage($url, ['selectors' => $staticSelectors]);
            } else {
                try {
                    $data = $browserScanner->scan($url, $customSelectors);
                    if (!$this->hasVerifiedProductData($data, $rule['name'], $host)) {
                        $data = $this->scanStaticPage($url, ['selectors' => $staticSelectors]);
                    }
                } catch (\Throwable $browserException) {
                    try {
                        $data = $this->scanStaticPage($url, ['selectors' => $staticSelectors]);
                    } catch (\Throwable $staticException) {
                        throw new RuntimeException(
                            'Fallaron los dos métodos de escaneo. Playwright: '
                            .$browserException->getMessage()
                            .' HTTP: '.$staticException->getMessage(),
                            previous: $browserException
                        );
                    }
                }
            }
            $data['price'] = $this->price((string) ($data['price'] ?? ''));
            if (!$this->hasVerifiedProductData($data, $rule['name'], $host)) {
                throw new RuntimeException('No se encontraron nombre y precio verificables del producto.');
            }

            $result = DB::transaction(function () use ($data, $url, $host, $rule, $selectors, $scan) {
                $store = Store::updateOrCreate(
                    ['domain' => $host],
                    ['name' => $rule['name'], 'selectors' => $selectors, 'is_auto_discovered' => $rule['name'] === Str::title($host)]
                );
                $product = Product::firstOrCreate(
                    ['slug' => Str::slug($data['name'])],
                    ['name' => $data['name'], 'image_url' => $data['image']]
                );
                $product->increment('search_count');
                $product->update([
                    'image_url' => $data['image'] ?: $product->image_url,
                    'last_scanned_at' => now(),
                    'popularity_score' => $product->search_count + (ProductOffer::where('product_id', $product->id)->count() * 2),
                ]);
                $existingOffer = ProductOffer::where('product_id', $product->id)
                    ->where('store_id', $store->id)
                    ->where('url', $url)
                    ->first();
                $previousPrice = $existingOffer?->price;
                $offer = ProductOffer::updateOrCreate(
                    ['product_id' => $product->id, 'store_id' => $store->id, 'url' => $url],
                    ['price' => $data['price'], 'currency' => $data['currency'], 'image_url' => $data['image'], 'custom_fields' => $data['custom_fields'] ?? [], 'last_seen_at' => now()]
                );
                PriceHistory::create(['product_offer_id' => $offer->id, 'price' => $data['price'], 'currency' => $data['currency'], 'captured_at' => now()]);
                if ($previousPrice !== null && $data['price'] !== null && $data['price'] < $previousPrice) {
                    ProductMonitor::where('product_id', $product->id)->where('is_active', true)->each(function (ProductMonitor $monitor) use ($product, $offer, $previousPrice, $data): void {
                        PriceAlert::create([
                            'user_id' => $monitor->user_id,
                            'product_id' => $product->id,
                            'product_offer_id' => $offer->id,
                            'previous_price' => $previousPrice,
                            'current_price' => $data['price'],
                            'message' => "Oferta detectada: {$product->name} bajó de {$previousPrice} a {$data['price']}.",
                        ]);
                    });
                }
                return [$product->load(['offers.store', 'offers.priceHistories']), $offer, $store];
            });

            $duration = (int) ((hrtime(true) - $startedAt) / 1_000_000);
            $scan->update(['product_id' => $result[0]->id, 'store_id' => $result[2]->id, 'status' => 'completed', 'duration_ms' => $duration]);

            return [
                'product' => $result[0],
                'offer' => $result[1]->load('store'),
                'duration_ms' => $duration,
                'scan_id' => $scan->id,
            ];
        } catch (\Throwable $exception) {
            $scan->update([
                'status' => 'failed',
                'duration_ms' => (int) ((hrtime(true) - $startedAt) / 1_000_000),
                'error_message' => $exception->getMessage(),
            ]);
            throw $exception;
        }
    }

    private function scanStaticPage(string $url, array $rule): array
    {
        $html = Http::timeout(20)
            ->withHeaders([
                'Accept' => 'text/html,application/xhtml+xml',
                'Accept-Language' => 'es-MX,es;q=0.9,en;q=0.8',
                'User-Agent' => 'Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 Chrome/131 Safari/537.36',
            ])
            ->get($url)
            ->throw()
            ->body();

        return $this->extract($html, $rule['selectors'] ?? []);
    }

    private function hasVerifiedProductData(array $data, string $storeName, string $host): bool
    {
        $name = strtolower(trim((string) ($data['name'] ?? '')));
        $normalizedHost = str_replace('www.', '', strtolower($host));
        $nameLooksLikeStorePage = $name === ''
            || str_contains($name, $normalizedHost)
            || $name === strtolower($storeName);

        return !$nameLooksLikeStorePage && $data['price'] !== null && $data['price'] !== '';
    }

    private function extract(string $html, array $selectors): array
    {
        $dom = new \DOMDocument();
        libxml_use_internal_errors(true);
        $dom->loadHTML('<?xml encoding="UTF-8">' . $html);
        libxml_clear_errors();
        $xpath = new \DOMXPath($dom);
        $jsonProduct = $this->jsonLdProduct($xpath);
        $amazonProduct = $this->amazonEmbeddedProduct($html);

        $customFields = [];
        foreach ($selectors as $key => $selector) {
            if (!in_array($key, ['name', 'price', 'image'], true) && is_string($selector)) {
                $customFields[$key] = $this->firstText($xpath, [$selector]);
            }
        }

        return [
            'name' => $jsonProduct['name'] ?? $amazonProduct['name'] ?? $this->firstText($xpath, [$selectors['name'] ?? '', '//h1', '//meta[@property="og:title"]/@content', '//title']),
            'price' => $this->price($jsonProduct['price'] ?? $amazonProduct['price'] ?? $this->firstText($xpath, [$selectors['price'] ?? '', '//meta[@property="product:price:amount"]/@content', '//*[contains(@class,"price")]'])),
            'currency' => $jsonProduct['currency'] ?? $amazonProduct['currency'] ?? $this->firstText($xpath, ['//meta[@property="product:price:currency"]/@content']) ?: 'MXN',
            'image' => $jsonProduct['image'] ?? $amazonProduct['image'] ?? $this->firstAttribute($xpath, [$selectors['image'] ?? '', '//meta[@property="og:image"]/@content'], 'src'),
            'custom_fields' => array_filter($customFields),
        ];
    }

    private function amazonEmbeddedProduct(string $html): array
    {
        $name = null;
        if (preg_match('/id=["\']productTitle["\'][^>]*>\s*([^<]{3,240})\s*</is', $html, $nameMatch)) {
            $name = trim(html_entity_decode(strip_tags($nameMatch[1])));
        } elseif (preg_match('/"title"\s*:\s*"([^"]{3,240})"/is', $html, $nameMatch)) {
            $name = trim(json_decode('"'.$nameMatch[1].'"') ?: $nameMatch[1]);
        }
        if (!$name) {
            return [];
        }

        preg_match('/(?:a-offscreen|priceblock_ourprice|priceblock_dealprice)[^>]*>([^<]+)/is', $html, $priceMatch);
        preg_match('/(?:id=["\']landingImage["\'][^>]+(?:src|data-old-hires)=["\']|["\']image["\']\s*:\s*["\'])([^"\']+)/is', $html, $imageMatch);

        return [
            'name' => $name ?: null,
            'price' => $priceMatch[1] ?? null,
            'currency' => 'MXN',
            'image' => $imageMatch[1] ?? null,
        ];
    }

    private function jsonLdProduct(\DOMXPath $xpath): array
    {
        foreach ($xpath->query('//script[@type="application/ld+json"]') as $node) {
            $decoded = json_decode($node->textContent, true);
            $items = isset($decoded['@graph']) ? $decoded['@graph'] : (is_array($decoded) && array_is_list($decoded) ? $decoded : [$decoded]);
            foreach ($items as $item) {
                if (is_array($item) && in_array($item['@type'] ?? null, ['Product', 'ProductGroup'], true)) {
                    $offer = $item['offers'] ?? [];
                    if (isset($offer[0])) $offer = $offer[0];
                    return ['name' => trim($item['name'] ?? ''), 'image' => is_array($item['image'] ?? null) ? ($item['image'][0] ?? null) : ($item['image'] ?? null), 'price' => $offer['price'] ?? null, 'currency' => $offer['priceCurrency'] ?? null];
                }
            }
        }
        return [];
    }

    private function firstText(\DOMXPath $xpath, array $queries): ?string
    {
        foreach ($queries as $query) {
            if (!$query) continue;
            $nodes = $xpath->query($query);
            if ($nodes !== false && $nodes->length) {
                $value = trim($nodes->item(0)->nodeValue);
                if ($value !== '') return Str::of(html_entity_decode($value))->squish()->toString();
            }
        }
        return null;
    }

    private function firstAttribute(\DOMXPath $xpath, array $queries, string $attribute): ?string
    {
        foreach ($queries as $query) {
            if (!$query) continue;
            $nodes = $xpath->query($query);
            if ($nodes !== false && $nodes->length) {
                $node = $nodes->item(0);
                $value = $node instanceof \DOMAttr ? $node->value : $node->attributes?->getNamedItem($attribute)?->nodeValue;
                if ($value) return trim($value);
            }
        }
        return null;
    }

    private function price(?string $value): ?float
    {
        if (!$value) return null;
        $value = preg_replace('/[^\d,.]/', '', $value);
        if (str_contains($value, ',') && str_contains($value, '.')) $value = str_replace(',', '', $value);
        elseif (str_contains($value, ',')) $value = str_replace(',', '.', $value);
        return is_numeric($value) ? (float) $value : null;
    }

    private function ruleFor(string $host): array
    {
        foreach (self::STORE_RULES as $domain => $rule) {
            if (str_contains($host, $domain)) return $rule;
        }
        return ['name' => Str::title(str_replace(['www.', '.', '-'], ['', ' ', ' '], $host)), 'selectors' => ['name' => '//h1', 'price' => '//*[contains(@class,"price")]', 'image' => '//meta[@property="og:image"]/@content']];
    }

    public function assertSafeUrl(string $url): void
    {
        if (!filter_var($url, FILTER_VALIDATE_URL) || !in_array(strtolower((string) parse_url($url, PHP_URL_SCHEME)), ['http', 'https'], true)) {
            throw new RuntimeException('URL no válida. Usa una dirección http o https.');
        }
        $host = parse_url($url, PHP_URL_HOST);
        $ip = gethostbyname($host);
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false) {
            throw new RuntimeException('No se permiten direcciones locales o privadas.');
        }
    }
}
