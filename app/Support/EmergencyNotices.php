<?php

namespace App\Support;

use App\Mail\SosAlert;
use App\Models\Personnel;
use App\Models\Reporter\SosEvent;
use App\Models\StaffNotification;
use App\Services\Sms\SmsGatewayInterface;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

class EmergencyNotices
{
    public static function recipients(int $campusId): Collection
    {
        return Personnel::where('is_approved', true)
            ->where(fn ($query) => $query->where('role', 'DRRMO Admin')
                ->orWhere(fn ($campus) => $campus->where('role', 'Coordinator')->where('campus_id', $campusId)))
            ->get();
    }

    public static function record(Collection $recipients, string $kind, int $subjectId, int $campusId, string $message): void
    {
        // One INSERT per 500 recipients: an all-campus advisory reaches every
        // student, and each row would otherwise be a round trip to the database.
        $now = now();
        $message = mb_substr($message, 0, 255);
        $recipients->pluck('personnel_id')->unique()->chunk(500)->each(fn ($ids) => StaffNotification::insert(
            $ids->map(fn ($id) => ['personnel_id' => $id, 'campus_id' => $campusId, 'kind' => $kind, 'subject_id' => $subjectId,
                'message' => $message, 'created_at' => $now, 'updated_at' => $now])->values()->all()
        ));
    }

    /** Text every staff recipient for SOS. A gateway failure never rolls back the SOS record. */
    public static function textSos(Collection $recipients, SosEvent $sos, Personnel $reporter): void
    {
        $id = (int) $sos->sos_id;
        $message = self::sosText($sos, $reporter);
        $sms = app(SmsGatewayInterface::class);
        foreach ($recipients as $recipient) {
            $number = PhoneNumber::normalize($recipient->contact_number);
            if ($number === null) {
                if (filled($recipient->contact_number)) {
                    Log::warning('SOS SMS skipped: not a mobile number', ['sos_id' => $id, 'recipient_id' => $recipient->personnel_id]);
                }
                continue;
            }
            try {
                if (! $sms->send($number, $message)) {
                    Log::warning('SOS SMS was not accepted by the gateway', ['sos_id' => $id, 'recipient_id' => $recipient->personnel_id]);
                }
            } catch (\Throwable $exception) {
                Log::error('SOS SMS failed', ['sos_id' => $id, 'recipient_id' => $recipient->personnel_id,
                    'error' => $exception->getMessage()]);
            }
        }
    }

    /** Free alert: one queued email per staff recipient (no shared To list). A mail failure never rolls back the SOS. */
    public static function emailSos(Collection $recipients, SosEvent $sos, Personnel $reporter): void
    {
        foreach ($recipients as $recipient) {
            if (! filter_var($recipient->email, FILTER_VALIDATE_EMAIL)) {
                continue;
            }
            $console = route(($recipient->role === 'DRRMO Admin' ? 'admin' : 'coordinator').'.command.sos');
            try {
                Mail::to($recipient->email)->queue(new SosAlert($sos, $reporter, $console));
            } catch (\Throwable $exception) {
                Log::error('SOS email failed', ['sos_id' => (int) $sos->sos_id, 'recipient_id' => $recipient->personnel_id,
                    'error' => $exception->getMessage()]);
            }
        }
    }

    /** What, who and where in one text, with a map link when the phone shared its location. */
    public static function sosText(SosEvent $sos, Personnel $reporter): string
    {
        $hasGps = $sos->latitude !== null && $sos->longitude !== null;
        $where = $sos->place ?: ($hasGps ? 'GPS location' : 'Location not shared');

        return "ASCOT DRRMS SOS #{$sos->sos_id}".($sos->type ? " ({$sos->type})" : '')
            .": {$reporter->full_name}".(filled($reporter->contact_number) ? " {$reporter->contact_number}" : '')
            .', '.($reporter->campus?->campus_name ?: 'ASCOT campus').". At: {$where}."
            .($hasGps ? ' Map: https://maps.google.com/?q='.round((float) $sos->latitude, 6).','.round((float) $sos->longitude, 6) : '')
            .' Open the SOS Console now.';
    }
}
