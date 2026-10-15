<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Store extends Model
{
    protected $fillable = ['name', 'domain', 'selectors', 'is_auto_discovered'];

    protected function casts(): array
    {
        return ['selectors' => 'array', 'is_auto_discovered' => 'boolean'];
    }

    public function offers(): HasMany
    {
        return $this->hasMany(ProductOffer::class);
    }
}
