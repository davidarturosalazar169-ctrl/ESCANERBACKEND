<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ProductOffer extends Model
{
    protected $fillable = ['product_id', 'store_id', 'url', 'price', 'currency', 'image_url', 'custom_fields', 'last_seen_at'];

    protected function casts(): array
    {
        return ['price' => 'float', 'custom_fields' => 'array', 'last_seen_at' => 'datetime'];
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function store(): BelongsTo
    {
        return $this->belongsTo(Store::class);
    }

    public function priceHistories(): HasMany
    {
        return $this->hasMany(PriceHistory::class);
    }
}
