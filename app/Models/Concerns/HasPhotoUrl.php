<?php

namespace App\Models\Concerns;

use App\Support\PhotoStorage;
use Illuminate\Database\Eloquent\Casts\Attribute;

/**
 * Provides a consistent `photo_url` accessor for models that store a
 * `photo_path`. Delegates all URL building to PhotoStorage so the model,
 * the API resources and the upload services never diverge.
 */
trait HasPhotoUrl
{
    protected function photoUrl(): Attribute
    {
        return Attribute::make(
            get: fn () => PhotoStorage::url($this->photo_path),
        );
    }
}
