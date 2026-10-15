<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PriceAlert extends Model
{
    protected $fillable = ['user_id', 'product_id', 'product_offer_id', 'previous_price', 'current_price', 'message', 'read_at'];

    protected function casts(): array
    {
        return ['previous_price' => 'float', 'current_price' => 'float', 'read_at' => 'datetime'];
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function productOffer(): BelongsTo
    {
        return $this->belongsTo(ProductOffer::class);
    }
}
