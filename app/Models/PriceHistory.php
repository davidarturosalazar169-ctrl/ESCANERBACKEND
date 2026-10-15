<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PriceHistory extends Model
{
    public $timestamps = false;

    protected $table = 'price_history';
    protected $fillable = ['product_offer_id', 'price', 'currency', 'captured_at'];

    protected function casts(): array
    {
        return ['price' => 'float', 'captured_at' => 'datetime'];
    }
}
