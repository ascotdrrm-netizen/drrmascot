<?php

namespace App\Http\Controllers\Command;

use App\Http\Controllers\Controller;
use App\Jobs\DeliverBroadcast;
use App\Models\ActivityLog;
use App\Models\Broadcast;
use App\Models\Campus;
use App\Models\StaffNotification;
use App\Services\BroadcastAudience;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

class BroadcastController extends Controller
{
    private function prefix(Request $request): string
    {
        return $request->user()->isAdmin() ? 'admin' : 'coordinator';
    }

    private function authorizeCampus(Request $request, Broadcast $broadcast): void
    {
        abort_unless($request->user()->isAdmin() || (int) $broadcast->campus_id === (int) $request->user()->campus_id, 404);
    }

    public function index(Request $request)
    {
        return view('command.broadcasts.index', [
            'broadcasts' => Broadcast::with('campus')->when(! $request->user()->isAdmin(), fn ($q) => $q->where('campus_id', $request->user()->campus_id))->latest()->paginate(20),
            'campuses' => Campus::when(! $request->user()->isAdmin(), fn ($q) => $q->whereKey($request->user()->campus_id))->orderBy('campus_name')->get(),
            'routePrefix' => $this->prefix($request),
        ]);
    }

    private function validated(Request $request): array
    {
        $data = $request->validate(['campus_id' => ['required', 'integer', 'exists:campuses,campus_id'],
            'title' => ['required', 'string', 'max:150'], 'body' => ['required', 'string', 'max:5000'],
            'send_email' => ['sometimes', 'boolean'], 'send_sms' => ['sometimes', 'boolean'],
            'sms_text' => ['nullable', 'required_if:send_sms,1', 'string', 'max:480']]);
        abort_unless($request->user()->isAdmin() || (int) $data['campus_id'] === (int) $request->user()->campus_id, 403);
        $data['send_email'] = $request->boolean('send_email');
        $data['send_sms'] = $request->boolean('send_sms');
        if ($data['send_sms'] && preg_match('/^TEST\b/i', ltrim($data['sms_text'] ?? ''))) {
            throw ValidationException::withMessages(['sms_text' => 'Start with ASCOT DRRMS or the notice subject. The SMS provider discards messages beginning with TEST.']);
        }

        return $data;
    }

    public function store(Request $request)
    {
        $key = $request->validate(['client_key' => ['required', 'uuid']])['client_key'];
        $data = $this->validated($request);
        $broadcast = Broadcast::firstOrCreate(['client_key' => $key], $data + ['created_by' => $request->user()->getKey()]);
        abort_unless((int) $broadcast->created_by === (int) $request->user()->getKey(), 409);
        $this->authorizeCampus($request, $broadcast);

        return redirect()->route($this->prefix($request).'.broadcasts.show', $broadcast)->with('status', 'Draft saved. Review the message and audience before publishing.');
    }

    public function show(Request $request, Broadcast $broadcast, BroadcastAudience $audience)
    {
        $this->authorizeCampus($request, $broadcast);

        return view('command.broadcasts.show', ['broadcast' => $broadcast->load('campus'),
            'audience' => $broadcast->published_at ? null : $audience->snapshot($broadcast),
            'history' => $broadcast->deliveries()->selectRaw('channel, status, count(*) as total')->groupBy('channel', 'status')->get(),
            'problems' => $broadcast->deliveries()->whereIn('status', ['Failed', 'Unknown', 'Skipped'])->latest()->limit(100)->get(),
            'routePrefix' => $this->prefix($request)]);
    }

    public function update(Request $request, Broadcast $broadcast)
    {
        $this->authorizeCampus($request, $broadcast);
        $data = $this->validated($request);
        DB::transaction(function () use ($request, $broadcast, $data) {
            $locked = Broadcast::whereKey($broadcast->getKey())->lockForUpdate()->firstOrFail();
            $this->authorizeCampus($request, $locked);
            abort_if($locked->published_at !== null, 409, 'Published messages cannot be edited.');
            $locked->update($data);
        });

        return back()->with('status', 'Draft updated. Review the refreshed audience before publishing.');
    }

    public function publish(Request $request, Broadcast $broadcast, BroadcastAudience $audience)
    {
        $this->authorizeCampus($request, $broadcast);
        $fingerprint = $request->validate(['audience_fingerprint' => ['required', 'string', 'size:64']])['audience_fingerprint'];
        DB::transaction(function () use ($request, $broadcast, $audience, $fingerprint) {
            $locked = Broadcast::whereKey($broadcast->getKey())->lockForUpdate()->firstOrFail();
            if ($locked->published_at) {
                return;
            }
            $this->authorizeCampus($request, $locked);
            $snapshot = $audience->snapshot($locked, true);
            if (! hash_equals($snapshot['fingerprint'], $fingerprint)) {
                throw ValidationException::withMessages(['audience_fingerprint' => 'The message or recipients changed. Reload this preview and review the new totals before publishing.']);
            }
            if (! $snapshot['recipients']) {
                throw ValidationException::withMessages(['audience_fingerprint' => 'This campus has no approved recipients.']);
            }
            foreach ($snapshot['recipients'] as $recipient) {
                $inApp = $recipient['channel'] === 'in_app';
                $locked->deliveries()->create($recipient + ['status' => $inApp ? 'Accepted' : 'Pending', 'sent_at' => $inApp ? now() : null]);
                if ($inApp) {
                    StaffNotification::create(['personnel_id' => $recipient['personnel_id'], 'campus_id' => $locked->campus_id,
                        'kind' => 'broadcast', 'subject_id' => $locked->getKey(), 'message' => mb_substr($locked->title, 0, 255)]);
                }
            }
            $locked->update(['published_by' => $request->user()->getKey(), 'published_at' => now(),
                'status' => $snapshot['counts']['email'] + $snapshot['counts']['sms'] > 0 ? 'Queued' : 'Complete']);
            ActivityLog::create(['personnel_id' => $request->user()->getKey(), 'action' => 'broadcast_published',
                'subject_type' => 'Broadcast', 'subject_id' => $locked->getKey(), 'ip_address' => $request->ip(),
                'description' => 'Published campus broadcast #'.$locked->getKey().': '.json_encode($snapshot['counts'])]);
        });
        $this->dispatch($broadcast->getKey());

        return back()->with('status', 'Published. In-app notices are available; check delivery history for email and SMS results.');
    }

    public function retry(Request $request, Broadcast $broadcast)
    {
        $this->authorizeCampus($request, $broadcast);
        DB::transaction(function () use ($request, $broadcast) {
            $locked = Broadcast::whereKey($broadcast->getKey())->lockForUpdate()->firstOrFail();
            $this->authorizeCampus($request, $locked);
            abort_unless($locked->published_at, 409);
            $count = $locked->deliveries()->where('status', 'Failed')->update(['status' => 'Pending', 'error' => null]);
            if ($count) {
                $locked->update(['status' => 'Queued']);
                ActivityLog::create(['personnel_id' => $request->user()->getKey(), 'action' => 'broadcast_retry', 'subject_type' => 'Broadcast',
                    'subject_id' => $locked->getKey(), 'ip_address' => $request->ip(), 'description' => "Retried {$count} explicitly rejected broadcast deliveries."]);
            }
        });
        $this->dispatch($broadcast->getKey());

        return back()->with('status', 'Rejected deliveries were queued again. Unconfirmed deliveries need a provider history check.');
    }

    private function dispatch(int $id): void
    {
        // The Vercel demo advances one delivery per authenticated dashboard
        // poll. Do not recursively execute the queue synchronously here.
        if (config('hosting.serverless')) return;
        try {
            DeliverBroadcast::dispatch($id);
        } catch (\Throwable $exception) {
            Log::warning('Broadcast queue unavailable; pending deliveries retained.', ['broadcast_id' => $id]);
        }
    }
}
