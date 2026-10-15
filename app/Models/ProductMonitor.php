<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ProductMonitor extends Model
{
    protected $fillable = ['user_id', 'product_id', 'interval_minutes', 'is_active', 'last_run_at', 'next_run_at'];

    protected function casts(): array
    {
        return ['is_active' => 'boolean', 'last_run_at' => 'datetime', 'next_run_at' => 'datetime'];
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }
}
