<?php

namespace App\Http\Controllers;

use App\Services\DirectUploads;
use App\Services\ServerlessBroadcastWork;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;

class ServerlessWorkController extends Controller
{
    public function work(Request $request, ServerlessBroadcastWork $work)
    {
        abort_unless(config('hosting.serverless') && $request->user()->is_approved, 403);
        $worked = $work->run($request->user()->isAdmin() ? null : (int) $request->user()->campus_id);

        return response()->json(['worked' => $worked])->header('Cache-Control', 'private, no-store');
    }

    public function maintenance(Request $request, DirectUploads $uploads, ServerlessBroadcastWork $work)
    {
        $secret = config('hosting.cron_secret');
        abort_unless(config('hosting.serverless') && is_string($secret) && strlen($secret) >= 32
            && hash_equals($secret, (string) $request->bearerToken()), 403);
        $lock = Cache::lock('serverless-maintenance', 90);
        if (! $lock->get()) {
            return response()->json(['busy' => true]);
        }
        try {
            $removed = config('hosting.direct_uploads') ? $uploads->cleanExpired() : 0;
            $disk = Storage::disk('s3');
            foreach ($disk->allFiles('_exports') as $path) {
                if ($disk->lastModified($path) < now()->subHours(2)->timestamp) {
                    $disk->delete($path);
                }
            }
            $worked = $work->run();

            return response()->json(['removed_uploads' => $removed, 'worked' => $worked])->header('Cache-Control', 'private, no-store');
        } finally {
            $lock->release();
        }
    }
}
