<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Scan extends Model
{
    protected $fillable = ['user_id', 'product_id', 'store_id', 'url', 'status', 'duration_ms', 'error_message'];

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }
}
