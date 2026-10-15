<?php

namespace App\Support;

use App\Models\Personnel;
use App\Models\StaffNotification;
use App\Services\Sms\SmsGatewayInterface;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;

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
        foreach ($recipients as $recipient) {
            StaffNotification::create(['personnel_id' => $recipient->personnel_id,
                'campus_id' => $campusId, 'kind' => $kind, 'subject_id' => $subjectId,
                'message' => mb_substr($message, 0, 255)]);
        }
    }

    /** Text every staff recipient for SOS. A gateway failure never rolls back the SOS record. */
    public static function textSos(Collection $recipients, int $id, string $campus, string $reporter): void
    {
        $sms = app(SmsGatewayInterface::class);
        foreach ($recipients as $recipient) {
            if (! filled($recipient->contact_number)) {
                continue;
            }
            try {
                if (! $sms->send($recipient->contact_number,
                    "ASCOT DRRMS SOS #{$id}: {$reporter} at {$campus}. Open the SOS Console now.")) {
                    Log::warning('SOS SMS was not accepted by the gateway', ['sos_id' => $id, 'recipient_id' => $recipient->personnel_id]);
                }
            } catch (\Throwable $exception) {
                Log::error('SOS SMS failed', ['sos_id' => $id, 'recipient_id' => $recipient->personnel_id,
                    'error' => $exception->getMessage()]);
            }
        }
    }
}
