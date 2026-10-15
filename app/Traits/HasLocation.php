<?php

namespace App\Traits;

trait HasLocation
{
    public function hasLocation(): bool
    {
        return $this->latitude !== null && $this->longitude !== null;
    }

    public function getMapUrlAttribute(): ?string
    {
        if (! $this->hasLocation()) {
            return null;
        }

        return "https://www.google.com/maps?q={$this->latitude},{$this->longitude}";
    }
}
