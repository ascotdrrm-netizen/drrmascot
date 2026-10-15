<?php

namespace App\Http\Controllers;

use App\Services\DirectUploads;
use App\Support\Files;
use Illuminate\Http\Request;

class DirectUploadController extends Controller
{
    public function store(Request $request, DirectUploads $uploads)
    {
        abort_unless(config('hosting.direct_uploads') && Files::diskName() === 's3', 404);
        abort_unless($request->user()->is_approved, 403);
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'size' => ['required', 'integer', 'min:1', 'max:'.config('hosting.upload_max_bytes')],
            'field' => ['required', 'string', 'max:150', 'regex:/\A[a-zA-Z][a-zA-Z0-9_]*(?:\.[a-zA-Z0-9_]+)*\z/'],
            'target' => ['required', 'string', 'max:1000', 'regex:~\A/(?!/)[^?#]*\z~'],
            'method' => ['required', 'in:POST,PUT,PATCH'],
        ]);

        return response()->json($uploads->sign($request->user(), $data))->header('Cache-Control', 'private, no-store');
    }
}
