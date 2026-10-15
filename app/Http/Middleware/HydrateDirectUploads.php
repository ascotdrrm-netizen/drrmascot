<?php

namespace App\Http\Middleware;

use App\Services\DirectUploads;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Validation\ValidationException;

class HydrateDirectUploads
{
    public function handle(Request $request, Closure $next)
    {
        if (! $request->has('_staged_uploads')) {
            return $next($request);
        }
        abort_unless(config('hosting.direct_uploads') && $request->user(), 403);
        abort_unless($request->user()->is_approved, 403);
        $request->validate(['_staged_uploads' => ['required', 'array', 'max:20'], '_staged_uploads.*' => ['required', 'string', 'max:8000']]);
        $temporary = [];
        try {
            $files = $request->files->all();
            foreach ($request->input('_staged_uploads') as $ticket) {
                [$field, $file, $path] = app(DirectUploads::class)->hydrate($ticket, $request->user(), '/'.$request->path(), $request->method());
                $temporary[] = $path;
                if (Arr::has($files, $field)) {
                    throw ValidationException::withMessages([$field => 'The same file field was submitted more than once.']);
                }
                Arr::set($files, $field, $file);
            }
            // all()/validation may already have cached Laravel's converted
            // file bag. Clear that cache when replacing Symfony's file bag.
            (function (array $files) {
                $this->files->replace($files);
                $this->convertedFiles = null;
            })->call($request, $files);
            $request->request->remove('_staged_uploads');

            return $next($request);
        } finally {
            foreach ($temporary as $path) {
                @unlink($path);
            }
        }
    }
}
