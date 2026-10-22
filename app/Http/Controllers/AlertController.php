<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\HandlesCampusScoping;
use App\Http\Controllers\Concerns\LogsReportDownloads;
use App\Models\Alert;
use App\Models\Incident\Incident;
use App\Models\Personnel;
use App\Services\Sms\SmsGatewayInterface;
use App\Support\PhoneNumber;
use App\Traits\RendersPdfAssets;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Illuminate\View\View;

class AlertController extends Controller
{
    use HandlesCampusScoping, RendersPdfAssets, LogsReportDownloads;

    public function __construct(protected SmsGatewayInterface $smsGateway)
    {
    }

    public function create(Incident $incident): View
    {
        $this->authorizeRecordAccess($incident);

        $recipients = $this->recipients($incident);

        return view('alerts.create', [
            'incident' => $incident,
            'recipients' => $recipients,
            'recipientCount' => $recipients->count(),
        ]);
    }

    public function store(Request $request, Incident $incident): RedirectResponse
    {
        $this->authorizeRecordAccess($incident);

        $data = $request->validate([
            'message' => ['required', 'string', 'max:300'],
        ]);

        $recipients = $this->recipients($incident);

        if ($recipients->isEmpty()) {
            return back()->withErrors([
                'message' => 'No personnel with a contact number on file for this campus — alert not sent.',
            ])->withInput();
        }

        $successCount = 0;
        $sent = []; // who it went to, and how: saved with the alert

        // Numbers go out in international form (639…), as every other text does:
        // a gateway rejects "09…". One failure never stops the rest.
        foreach ($recipients as $person) {
            $status = 'Failed';
            $number = PhoneNumber::normalize($person->contact_number);
            if ($number === null) {
                Log::warning('Alert SMS skipped: not a mobile number', ['incident_id' => $incident->incident_id, 'recipient_id' => $person->personnel_id]);
                $status = 'Skipped';
            } else {
                try {
                    if ($this->smsGateway->send($number, $data['message'])) {
                        $successCount++;
                        $status = 'Sent';
                    } else {
                        Log::warning('Alert SMS was not accepted by the gateway', ['incident_id' => $incident->incident_id, 'recipient_id' => $person->personnel_id]);
                    }
                } catch (\Throwable $exception) {
                    Log::error('Alert SMS failed', ['incident_id' => $incident->incident_id, 'recipient_id' => $person->personnel_id,
                        'error' => $exception->getMessage()]);
                }
            }
            $sent[] = ['personnel_id' => $person->personnel_id, 'full_name' => $person->full_name, 'role' => $person->role,
                'contact_number' => $person->contact_number, 'status' => $status];
        }

        $totalAttempted = $recipients->count();

        $deliveryStatus = match (true) {
            $successCount === $totalAttempted => 'Sent',
            $successCount === 0 => 'Failed',
            default => 'Partial',
        };

        $alert = Alert::create([
            'incident_id' => $incident->incident_id,
            'message' => $data['message'],
            'sent_at' => now(),
            'recipient_count' => $totalAttempted,
            'delivery_status' => $deliveryStatus,
        ]);
        $alert->recipients()->createMany($sent);

        return redirect()
            ->route('admin.incidents.show', $incident)
            ->with('status', "Alert sent to {$totalAttempted} personnel. Its delivery status is in the Alert History below.");
    }

    public function downloadReport(Incident $incident, Alert $alert): Response
    {
        $this->authorizeRecordAccess($incident);

        if ($alert->incident_id !== $incident->incident_id) {
            abort(404);
        }

        $incident->loadMissing(['campus', 'school']);
        $alert->load('recipients');

        $pdf = $this->loadPdfViewWithPageCount('reports.records.alert', [
            'alert' => $alert,
            'incident' => $incident,
        ]);

        $filename = sprintf(
            'ASCOT-Alert-Report_ALERT-%d_%s.pdf',
            $alert->alert_id,
            $alert->sent_at->format('Y-m-d')
        );

        $this->logReportDownload(sprintf(
            '%s downloaded the Alert report for INC-%d (%s)',
            Auth::user()->full_name,
            $incident->incident_id,
            $incident->campus->campus_name ?? 'Unknown campus'
        ));

        return $pdf->download($filename);
    }

    /** Everyone approved on the incident's campus with a number — except whoever is sending it. */
    protected function recipients(Incident $incident)
    {
        return Personnel::where('campus_id', $incident->campus_id)
            ->where('is_approved', true) // never alert a registration the DRRMO hasn't approved
            ->whereNotNull('contact_number')
            ->where('personnel_id', '!=', Auth::id())
            ->orderBy('full_name')
            ->get();
    }
}
