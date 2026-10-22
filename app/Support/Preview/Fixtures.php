<?php

namespace App\Support\Preview;

use Illuminate\Support\Carbon;

/**
 * Sample data for the student/staff phone app while it is built frontend-first
 * (docs/PLAN.md §8). Every screen under /app reads from here until Phase B
 * gives Reporters real accounts, reports and SOS records — then this class is
 * deleted. The campuses are real; the people and events are not.
 */
class Fixtures
{
    public static function reporter(): array
    {
        return [
            'name' => 'Juan dela Cruz',
            'initials' => 'JD',
            'role' => 'Student',
            'id_number' => '2023-01842',
            'campus' => 'ASCOT Zabali Campus',
            'school' => 'School of Information Technology',
            'phone' => '0917 123 4567',
            'phone_verified' => true,
            'email' => 'juan.delacruz@example.com',
        ];
    }

    /** Each campus's status: normal | advisory | suspended | evacuate. */
    public static function campusStatus(): array
    {
        return [
            ['campus' => 'Zabali', 'status' => 'advisory', 'label' => 'Advisory in effect', 'detail' => 'Signal No. 1 — Typhoon Pepito. Classes continue; stay alert.'],
            ['campus' => 'Bazal', 'status' => 'normal', 'label' => 'Normal', 'detail' => 'No advisory in effect.'],
            ['campus' => 'Casiguran', 'status' => 'suspended', 'label' => 'Classes suspended', 'detail' => 'Storm surge warning along the coast. Stay away from the shore.'],
        ];
    }

    public static function advisories(): array
    {
        return [
            ['id' => 1, 'hazard' => 'Typhoon', 'level' => 'Signal No. 1', 'tone' => 'amber', 'title' => 'Typhoon Pepito: Signal No. 1 over Aurora', 'campus' => 'All campuses', 'issued' => Carbon::now()->subHours(3), 'body' => 'Strong winds expected within 36 hours. Secure loose objects, charge your phone, and keep away from windows during heavy rain.'],
            ['id' => 2, 'hazard' => 'Storm Surge', 'level' => 'Warning', 'tone' => 'red', 'title' => 'Storm surge warning: Casiguran coast', 'campus' => 'Casiguran Campus', 'issued' => Carbon::now()->subHours(5), 'body' => 'Surge up to 2 m possible. Classes are suspended. Do not go near the shoreline or the hatchery.'],
            ['id' => 3, 'hazard' => 'Flood', 'level' => 'Yellow rainfall', 'tone' => 'amber', 'title' => 'Yellow rainfall warning', 'campus' => 'Bazal Campus', 'issued' => Carbon::now()->subDay(), 'body' => 'Flooding possible in low-lying roads near the Agriculture Laboratory.'],
        ];
    }

    /** Upcoming drills on the reporter's campus (schedule only). */
    public static function drills(): array
    {
        return [
            ['id' => 1, 'type' => 'Earthquake', 'date' => Carbon::today()->addDays(1), 'time' => '9:00 AM', 'venue' => 'Gymnasium', 'description' => 'Duck, cover and hold, then walk to the assembly area at the oval.', 'campus' => 'ASCOT Zabali Campus'],
            ['id' => 2, 'type' => 'Fire', 'date' => Carbon::today()->addDays(16), 'time' => '2:00 PM', 'venue' => 'Engineering Building', 'description' => 'With BFP Baler. Leave by the nearest exit; do not use the stairs near the laboratory.', 'campus' => 'ASCOT Zabali Campus'],
        ];
    }

    /** Hazardous areas on the reporter's campus: rated Moderate or above (5×5 risk matrix). */
    public static function hazardAreas(): array
    {
        return [
            ['id' => 3, 'name' => 'Ermita Hill Access Road', 'hazard' => 'Landslide', 'level' => 'Critical Risk', 'lat' => 15.7381, 'lng' => 121.5512, 'inspected' => Carbon::today()->subDays(11)],
            ['id' => 1, 'name' => 'Engineering Building', 'hazard' => 'Earthquake', 'level' => 'Moderate Risk', 'lat' => 15.7362, 'lng' => 121.5489, 'inspected' => Carbon::today()->subDays(3)],
            ['id' => 4, 'name' => "Men's Dormitory", 'hazard' => 'Typhoon', 'level' => 'Moderate Risk', 'lat' => 15.7355, 'lng' => 121.5478, 'inspected' => Carbon::today()->subDays(15)],
        ];
    }

    /** What a reporter can report: the real list (config/report_categories.php). */
    public static function categories(): array
    {
        return config('report_categories');
    }

    public static function reports(): array
    {
        return [
            [
                'id' => 1042, 'category' => 'Fallen tree / blocked road', 'icon' => 'park', 'status' => 'Responding',
                'place' => 'Main gate, Zabali Campus', 'when' => Carbon::now()->subMinutes(40),
                'description' => 'A big acacia branch fell across the main gate. Tricycles can\'t pass.',
                'injured' => 0,
                'timeline' => [
                    ['status' => 'Submitted', 'note' => 'Your report reached the DRRM Office.', 'at' => Carbon::now()->subMinutes(40)],
                    ['status' => 'Verified', 'note' => 'Confirmed by the Zabali coordinator.', 'at' => Carbon::now()->subMinutes(31)],
                    ['status' => 'Responding', 'note' => 'Campus security and the grounds team are on the way.', 'at' => Carbon::now()->subMinutes(18)],
                ],
            ],
            [
                'id' => 1031, 'category' => 'Flood', 'icon' => 'flood', 'status' => 'Resolved',
                'place' => 'Covered walk near the library', 'when' => Carbon::now()->subDays(4),
                'description' => 'Ankle-deep water on the covered walk after the afternoon rain.',
                'injured' => 0,
                'timeline' => [
                    ['status' => 'Submitted', 'note' => 'Your report reached the DRRM Office.', 'at' => Carbon::now()->subDays(4)],
                    ['status' => 'Verified', 'note' => 'Confirmed by the DRRM Office.', 'at' => Carbon::now()->subDays(4)->addMinutes(12)],
                    ['status' => 'Resolved', 'note' => 'Drain cleared. Thank you for reporting.', 'at' => Carbon::now()->subDays(3)],
                ],
            ],
        ];
    }

    /** The staff side: reports arriving from the phone app, newest first. */
    public static function inbox(): array
    {
        $now = Carbon::now();

        return [
            ['id' => 1048, 'status' => 'New', 'urgent' => true, 'category' => 'Injury / medical', 'icon' => 'medical_services', 'campus' => 'Zabali', 'place' => 'Engineering Building, 2nd floor stairs', 'lat' => 15.7362, 'lng' => 121.5489, 'accuracy' => 9, 'reporter' => 'Ana Villanueva', 'role' => 'Student', 'phone' => '0917 555 0148', 'when' => $now->copy()->subMinutes(4), 'injured' => 1, 'description' => 'A student slipped on the wet stairs and cannot stand. Bleeding from the knee.', 'caption' => 'Wet stair landing, no warning sign', 'tags' => ['#MedicalEmergency']],
            ['id' => 1047, 'status' => 'New', 'urgent' => false, 'category' => 'Flood', 'icon' => 'flood', 'campus' => 'Bazal', 'place' => 'Gymnasium entrance', 'lat' => 15.7905, 'lng' => 121.4751, 'accuracy' => 15, 'reporter' => 'Mark Dizon', 'role' => 'Staff', 'phone' => '0928 555 0147', 'when' => $now->copy()->subMinutes(22), 'injured' => 0, 'description' => 'Water is ankle-deep at the gym entrance and rising.', 'caption' => 'Entrance from the parking side', 'tags' => ['#FlashFlood']],
            ['id' => 1042, 'status' => 'Responding', 'urgent' => false, 'category' => 'Fallen tree / blocked road', 'icon' => 'park', 'campus' => 'Zabali', 'place' => 'Main gate', 'lat' => 15.7376, 'lng' => 121.5494, 'accuracy' => 11, 'reporter' => 'Juan dela Cruz', 'role' => 'Student', 'phone' => '0917 123 4567', 'when' => $now->copy()->subMinutes(40), 'injured' => 0, 'description' => 'A big acacia branch fell across the main gate. Tricycles cannot pass.', 'caption' => 'Branch across both lanes', 'tags' => ['#StructureDamage']],
            ['id' => 1039, 'status' => 'Verified', 'urgent' => false, 'category' => 'Power / electrical', 'icon' => 'bolt', 'campus' => 'Casiguran', 'place' => 'Hatchery pump house', 'lat' => 16.2849, 'lng' => 122.1196, 'accuracy' => 20, 'reporter' => 'Liza Ramos', 'role' => 'Staff', 'phone' => '0921 555 0139', 'when' => $now->copy()->subHours(2), 'injured' => 0, 'description' => 'Sparks from the outlet beside the pump after the rain.', 'caption' => null, 'tags' => []],
            ['id' => 1031, 'status' => 'Resolved', 'urgent' => false, 'category' => 'Flood', 'icon' => 'flood', 'campus' => 'Zabali', 'place' => 'Covered walk near the library', 'lat' => 15.7369, 'lng' => 121.5496, 'accuracy' => 12, 'reporter' => 'Juan dela Cruz', 'role' => 'Student', 'phone' => '0917 123 4567', 'when' => $now->copy()->subDays(4), 'injured' => 0, 'description' => 'Ankle-deep water on the covered walk after the afternoon rain.', 'caption' => null, 'tags' => ['#FlashFlood']],
        ];
    }

    /** Active SOS alerts, oldest first (the longest wait on top). */
    public static function sosQueue(): array
    {
        $now = Carbon::now();

        return [
            ['id' => 81, 'type' => 'Medical', 'reporter' => 'Ana Villanueva', 'role' => 'Student', 'phone' => '0917 555 0148', 'campus' => 'Zabali', 'place' => 'Engineering Building, 2nd floor', 'lat' => 15.7362, 'lng' => 121.5489, 'accuracy' => 9, 'sent_at' => $now->copy()->subMinutes(3), 'acknowledged' => false],
            ['id' => 82, 'type' => 'Trapped', 'reporter' => 'Paolo Garcia', 'role' => 'Student', 'phone' => '0918 555 0182', 'campus' => 'Casiguran', 'place' => 'Fisheries building, ground floor', 'lat' => 16.2852, 'lng' => 122.1192, 'accuracy' => 18, 'sent_at' => $now->copy()->subMinute(), 'acknowledged' => false],
            ['id' => 79, 'type' => null, 'reporter' => 'Grace Santos', 'role' => 'Staff', 'phone' => '0919 555 0179', 'campus' => 'Bazal', 'place' => 'Near the Agriculture Laboratory', 'lat' => 15.7898, 'lng' => 121.4743, 'accuracy' => 35, 'sent_at' => $now->copy()->subMinutes(11), 'acknowledged' => true],
        ];
    }

    public static function sos(): array
    {
        return [
            'id' => 77, 'type' => 'Medical', 'sent_at' => Carbon::now()->subMinutes(3),
            'location' => 'Engineering Building, 2nd floor', 'lat' => 15.7362, 'lng' => 121.5489, 'accuracy' => 12,
            'timeline' => [
                ['status' => 'Sent', 'note' => 'Your SOS and location were sent.', 'at' => Carbon::now()->subMinutes(3), 'done' => true],
                ['status' => 'Received', 'note' => 'The DRRM Office has your alert.', 'at' => Carbon::now()->subMinutes(2), 'done' => true],
                ['status' => 'Help on the way', 'note' => 'Campus security is heading to you.', 'at' => Carbon::now()->subMinute(), 'done' => true],
                ['status' => 'Arrived', 'note' => 'Responders reach your location.', 'at' => null, 'done' => false],
            ],
        ];
    }
}
