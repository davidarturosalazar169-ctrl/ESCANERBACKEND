<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Product extends Model
{
    protected $fillable = ['name', 'slug', 'image_url', 'search_count', 'popularity_score', 'last_scanned_at'];

    protected function casts(): array
    {
        return ['last_scanned_at' => 'datetime', 'popularity_score' => 'float'];
    }

    public function offers(): HasMany
    {
        return $this->hasMany(ProductOffer::class);
    }
}
