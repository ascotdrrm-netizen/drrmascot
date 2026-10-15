<?php

namespace App\Support;

use InvalidArgumentException;

/**
 * Google Material Symbols (Rounded, weight 400), inlined as SVG by <x-icon>.
 *
 * The SVG files live in resources/icons/material/ and are copied there from
 * the @material-symbols/svg-400 npm package by scripts/copy-assets.mjs — only
 * the names used in the views (or listed in resources/icons/extra.json). An
 * icon name that isn't there is a mistake, so it throws instead of rendering
 * an empty space nobody notices.
 */
class MaterialIcon
{
    /** @var array<string, string> path contents, read once per request */
    protected static array $cache = [];

    public static function path(string $name, bool $fill = false): string
    {
        if (! preg_match('/^[a-z0-9_]+$/', $name)) {
            throw new InvalidArgumentException("Invalid icon name [$name].");
        }

        return resource_path('icons/material/'.$name.($fill ? '-fill' : '').'.svg');
    }

    /** The <path …/> markup inside the icon's <svg>, without the wrapper. */
    public static function inner(string $name, bool $fill = false): string
    {
        $file = static::path($name, $fill);

        if (! isset(static::$cache[$file])) {
            if (! is_file($file)) {
                throw new InvalidArgumentException(
                    "Icon [$name".($fill ? ', filled' : '').'] is not in resources/icons/material. '
                    .'Run `npm run assets` after adding it to a view or to resources/icons/extra.json.'
                );
            }

            $svg = file_get_contents($file);
            static::$cache[$file] = preg_replace('/^<svg[^>]*>|<\/svg>\s*$/', '', trim($svg));
        }

        return static::$cache[$file];
    }
}
