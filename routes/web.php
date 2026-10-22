<?php

use App\Http\Controllers\ActivityLogController;
use App\Http\Controllers\Advisory\DisasterAdvisoryController;
use App\Http\Controllers\ArchiveController;
use App\Http\Controllers\AlertController;
use App\Http\Controllers\Auth\AuthController;
use App\Http\Controllers\Auth\PasswordResetController;
use App\Http\Controllers\BackupController;
use App\Http\Controllers\Building\BuildingController;
use App\Http\Controllers\Building\HazardInspectionController;
use App\Http\Controllers\Command\CommandPreviewController;
use App\Http\Controllers\ConsolidatedReportController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\GuidanceController;
use App\Http\Controllers\Hotline\HotlineController;
use App\Http\Controllers\MapSearchController;
use App\Http\Controllers\Drill\DrillAfterActivityReportController;
use App\Http\Controllers\Drill\DrillAttachmentController;
use App\Http\Controllers\Drill\DrillController;
use App\Http\Controllers\Drill\DrillDocumentationController;
use App\Http\Controllers\Incident\DamageReportController;
use App\Http\Controllers\Incident\IncidentController;
use App\Http\Controllers\LandingController;
use App\Http\Controllers\ModuleReportController;
use App\Http\Controllers\PersonnelController;
use App\Http\Controllers\ProfileController;
use App\Http\Controllers\SettingsController;
use App\Http\Controllers\Reporter\AppPreviewController;
use Illuminate\Support\Facades\Route;

// Public landing page — reachable whether logged in or not. Logged-in
// visitors get a "Go to Dashboard" link in the nav instead of Login/Register
// (see layouts.public); they are not force-redirected away from it.
Route::get('/', [LandingController::class, 'index'])->name('landing');

// The installed app's "no signal" page (public/sw.js falls back to it).
Route::view('/offline', 'reporter.offline')->name('offline');
// Start page of the installed staff app (public/manifest-staff.webmanifest).
Route::get('/staff', \App\Http\Controllers\StaffAppController::class)->name('staff.start');

// A fresh CSRF cookie for a page left open past its session (ajax.js asks
// for one once on a 419, then sends the request again).
Route::get('/csrf-refresh', fn () => response()->json(['token' => csrf_token()])->header('Cache-Control', 'no-store'))->name('csrf.refresh');

Route::post('/uploads/sign', [\App\Http\Controllers\DirectUploadController::class, 'store'])
    ->middleware(['auth', 'throttle:60,1'])->name('uploads.sign');
Route::post('/broadcasts/work', [\App\Http\Controllers\ServerlessWorkController::class, 'work'])
    ->middleware(['auth', 'staff', 'throttle:10,1'])->name('broadcasts.work');
Route::get('/internal/maintenance', [\App\Http\Controllers\ServerlessWorkController::class, 'maintenance']);

// Guests can inspect the sample app outside production. In production the
// response screens require an approved Reporter account.
    Route::prefix('app')->name('app.')->controller(AppPreviewController::class)
        ->middleware(app()->environment('production') ? ['auth', 'role:Reporter'] : [])->group(function () {
        Route::get('/', 'home')->name('home');
        Route::get('/sos', 'sos')->name('sos');
        Route::get('/sos/status', 'sosStatus')->name('sos.status');
        Route::get('/report', 'report')->name('report');
        Route::get('/reports', 'reports')->name('reports');
        Route::get('/reports/{id}', 'reportShow')->whereNumber('id')->name('reports.show');
        Route::get('/alerts', 'alerts')->name('alerts');
        Route::get('/map', 'map')->name('map');
        Route::get('/me', 'me')->name('me');
        Route::get('/check-in', 'checkIn')->name('check-in');
        Route::get('/check-in/{rollCall}', 'checkIn')->middleware(['auth', 'role:Reporter'])->name('roll-calls.show');
    });
Route::prefix('app')->name('app.')->controller(AppPreviewController::class)->group(function () {
    Route::get('/hotlines', 'hotlines')->name('hotlines');
    Route::get('/help', 'help')->name('help');
    Route::get('/welcome', 'welcome')->name('welcome');
});
Route::middleware('guest')->get('/app/register', [AppPreviewController::class, 'register'])->name('app.register');
Route::middleware('guest')->get('/app/login', [AppPreviewController::class, 'login'])->name('app.login');
Route::middleware('guest')->post('/app/register', [\App\Http\Controllers\Reporter\ReporterAuthController::class, 'register'])
    ->middleware('throttle:5,1')->name('app.register.store');
Route::middleware('guest')->post('/app/login', [AuthController::class, 'login'])
    ->middleware('throttle:5,1')->name('app.login.store');
Route::middleware('guest')->post('/app/resend-verification', [\App\Http\Controllers\Reporter\ReporterAuthController::class, 'resend'])
    ->middleware('throttle:5,1')->name('app.verification.resend');
Route::get('/app/verify-email/{id}/{hash}', [\App\Http\Controllers\Reporter\ReporterAuthController::class, 'verify'])
    ->middleware('signed')->name('verification.verify');

Route::middleware('guest')->group(function () {
    Route::get('/login', [AuthController::class, 'showLogin'])->name('login');
    Route::post('/login', [AuthController::class, 'login']);
    Route::get('/register', [AuthController::class, 'showRegister'])->name('register');
    Route::post('/register', [AuthController::class, 'register']);

    Route::get('/forgot-password', [PasswordResetController::class, 'showForgotPassword'])->name('password.request');
    Route::post('/forgot-password', [PasswordResetController::class, 'sendResetLink'])
        ->middleware('throttle:5,1') // 5 requests per minute per IP, on top of the broker's own per-email 60s throttle.
        ->name('password.email');
    Route::get('/reset-password/{token}', [PasswordResetController::class, 'showResetForm'])->name('password.reset');
    Route::post('/reset-password', [PasswordResetController::class, 'resetPassword'])->name('password.update');
});

// Drill Tracker, identical for both roles (registered in each role group
// below). Who may do what is decided in the controllers — see DrillController.
$drillRoutes = function () {
    Route::get('/drills/trash', [DrillController::class, 'trashed'])->name('drills.trash');
    Route::post('/drills/trash/{drill}/restore', [DrillController::class, 'restore'])->name('drills.restore');
    Route::resource('drills', DrillController::class);
    Route::get('/drills/{drill}/memorandum', [DrillController::class, 'memorandum'])->name('drills.memorandum');
    Route::get('/drills/{drill}/documentation', [DrillDocumentationController::class, 'edit'])->name('drills.documentation.edit');
    Route::put('/drills/{drill}/documentation', [DrillDocumentationController::class, 'update'])->name('drills.documentation.update');
    Route::get('/drills/{drill}/documentation/pdf', [DrillDocumentationController::class, 'pdf'])->name('drills.documentation.pdf');
    Route::get('/drills/{drill}/after-activity-report', [DrillAfterActivityReportController::class, 'edit'])->name('drills.after-activity.edit');
    Route::put('/drills/{drill}/after-activity-report', [DrillAfterActivityReportController::class, 'update'])->name('drills.after-activity.update');
    Route::get('/drills/{drill}/after-activity-report/pdf', [DrillAfterActivityReportController::class, 'pdf'])->name('drills.after-activity.pdf');
    Route::get('/drills/{drill}/attachments/{attachment}', [DrillAttachmentController::class, 'show'])->name('drills.attachments.show');
    Route::delete('/drills/{drill}/attachments/{attachment}', [DrillAttachmentController::class, 'destroy'])->name('drills.attachments.destroy');
};

// Disaster Advisories, identical for both roles. Who may do what is decided
// in the controller — see DisasterAdvisoryController.
$advisoryRoutes = function () {
    Route::get('/advisories/trash', [DisasterAdvisoryController::class, 'trashed'])->name('advisories.trash');
    Route::post('/advisories/trash/{advisory}/restore', [DisasterAdvisoryController::class, 'restore'])->name('advisories.restore');
    Route::resource('advisories', DisasterAdvisoryController::class)->parameters(['advisories' => 'advisory']);
    Route::get('/advisories/{advisory}/memorandum', [DisasterAdvisoryController::class, 'memorandum'])->name('advisories.memorandum');
};

// Emergency hotlines editor, both roles. Which groups each may edit is
// decided in HotlineController (the Admin all; a coordinator, their campus's).
$hotlineRoutes = function () {
    Route::get('/broadcasts', [\App\Http\Controllers\Command\BroadcastController::class, 'index'])->name('broadcasts.index');
    Route::post('/broadcasts', [\App\Http\Controllers\Command\BroadcastController::class, 'store'])->name('broadcasts.store');
    Route::get('/broadcasts/{broadcast}', [\App\Http\Controllers\Command\BroadcastController::class, 'show'])->name('broadcasts.show');
    Route::put('/broadcasts/{broadcast}', [\App\Http\Controllers\Command\BroadcastController::class, 'update'])->name('broadcasts.update');
    Route::post('/broadcasts/{broadcast}/publish', [\App\Http\Controllers\Command\BroadcastController::class, 'publish'])->middleware('throttle:10,1')->name('broadcasts.publish');
    Route::post('/broadcasts/{broadcast}/retry', [\App\Http\Controllers\Command\BroadcastController::class, 'retry'])->middleware('throttle:5,1')->name('broadcasts.retry');
    Route::get('/notifications', [\App\Http\Controllers\Command\StaffNotificationController::class, 'index'])->name('notifications.index');
    Route::post('/notifications/{notification}/open', [\App\Http\Controllers\Command\StaffNotificationController::class, 'open'])->name('notifications.open');
    Route::get('/hotlines', [HotlineController::class, 'index'])->name('hotlines.index');
    Route::get('/hotlines/{group}/edit', [HotlineController::class, 'edit'])->name('hotlines.edit');
    Route::put('/hotlines/{group}', [HotlineController::class, 'update'])->name('hotlines.update');
    // Everything deleted, in one place (thesis panel, IT experts).
    Route::get('/archive', [ArchiveController::class, 'index'])->name('archive.index');

    Route::get('/reports-inbox', [CommandPreviewController::class, 'inbox'])->name('command.inbox');
    Route::get('/sos-console', [CommandPreviewController::class, 'sos'])->name('command.sos');
    Route::post('/sos-console/trash/{sos}/restore', [\App\Http\Controllers\Command\ResponseApiController::class, 'restoreSos'])->name('sos.restore');
    Route::get('/campus-status', [CommandPreviewController::class, 'campusStatus'])->name('command.campus-status');
    Route::get('/roll-calls', [\App\Http\Controllers\Command\RollCallController::class, 'index'])->name('roll-calls.index');
    Route::post('/roll-calls', [\App\Http\Controllers\Command\RollCallController::class, 'store'])->name('roll-calls.store');
    Route::get('/roll-calls/{rollCall}', [\App\Http\Controllers\Command\RollCallController::class, 'show'])->name('roll-calls.show');
    Route::post('/roll-calls/{rollCall}/close', [\App\Http\Controllers\Command\RollCallController::class, 'close'])->name('roll-calls.close');
    Route::get('/roll-calls/{rollCall}/export', [\App\Http\Controllers\Command\RollCallController::class, 'export'])->name('roll-calls.export');
    Route::get('/dispatch', [\App\Http\Controllers\Command\DispatchController::class, 'index'])->name('dispatch.index');
};

Route::middleware('auth')->group(function () use ($drillRoutes, $advisoryRoutes, $hotlineRoutes) {
    Route::post('/logout', [AuthController::class, 'logout'])->name('logout');
    Route::post('/phone/verification-code', [\App\Http\Controllers\Auth\PhoneVerificationController::class, 'send'])->middleware('throttle:6,1')->name('phone.send');
    Route::post('/phone/verify', [\App\Http\Controllers\Auth\PhoneVerificationController::class, 'verify'])->middleware('throttle:10,1')->name('phone.verify');
    Route::post('/email/verification-link', [\App\Http\Controllers\Reporter\ReporterAuthController::class, 'sendOwnVerification'])->middleware('throttle:5,1')->name('email.verification.send');
    Route::get('/app/broadcasts/{broadcast}', [AppPreviewController::class, 'broadcast'])->middleware('role:Reporter')->name('app.broadcasts.show');
    Route::post('/app/notifications/{notification}/open', [\App\Http\Controllers\Reporter\NotificationController::class, 'open'])->middleware('role:Reporter')->name('app.notifications.open');
    Route::post('/app/me', [\App\Http\Controllers\Reporter\ReporterProfileController::class, 'update'])->middleware(['role:Reporter', 'throttle:10,1'])->name('app.me.update');

    Route::middleware('role:Reporter')->prefix('api/reporter')->name('api.reporter.')->group(function () {
        Route::get('/notifications', [\App\Http\Controllers\Reporter\NotificationController::class, 'summary'])->name('notifications');
        Route::post('/reports/sync', [\App\Http\Controllers\Reporter\ResponseApiController::class, 'syncReport'])->middleware('throttle:10,1')->name('reports.sync');
        Route::post('/roll-calls/{rollCall}/response', [\App\Http\Controllers\Reporter\RollCallResponseController::class, 'store'])->middleware('throttle:20,1')->name('roll-calls.respond');
        Route::get('/reports', [\App\Http\Controllers\Reporter\ResponseApiController::class, 'reports'])->name('reports');
        Route::post('/reports', [\App\Http\Controllers\Reporter\ResponseApiController::class, 'storeReport'])
            ->middleware('throttle:10,1')->name('reports.store');
        Route::get('/reports/{report}', [\App\Http\Controllers\Reporter\ResponseApiController::class, 'report'])->name('reports.show');
        Route::get('/sos', [\App\Http\Controllers\Reporter\ResponseApiController::class, 'sos'])->name('sos');
        Route::post('/sos', [\App\Http\Controllers\Reporter\ResponseApiController::class, 'storeSos'])
            ->middleware('throttle:10,1')->name('sos.store');
    });

    Route::prefix('api/command')->middleware('staff')->name('api.command.')->group(function () {
        Route::post('/responders', [\App\Http\Controllers\Command\DispatchController::class, 'storeResponder'])->name('responders.store');
        Route::patch('/responders/{responder}', [\App\Http\Controllers\Command\DispatchController::class, 'updateResponder'])->name('responders.update');
        Route::put('/responders/{responder}', [\App\Http\Controllers\Command\DispatchController::class, 'updateResponderDetails'])->name('responders.details');
        Route::post('/assignments', [\App\Http\Controllers\Command\DispatchController::class, 'assign'])->name('assignments.store');
        Route::patch('/assignments/{assignment}', [\App\Http\Controllers\Command\DispatchController::class, 'updateAssignment'])->name('assignments.update');
        Route::get('/notifications', [\App\Http\Controllers\Command\StaffNotificationController::class, 'summary'])->middleware('staff')->name('notifications');
        Route::put('/campuses/{campus}/status', [\App\Http\Controllers\Command\CampusStatusController::class, 'update'])->name('campus-status.update');
        Route::get('/reports', [\App\Http\Controllers\Command\ResponseApiController::class, 'reports'])->name('reports');
        Route::patch('/reports/{report}', [\App\Http\Controllers\Command\ResponseApiController::class, 'updateReport'])->name('reports.update');
        Route::get('/sos', [\App\Http\Controllers\Command\ResponseApiController::class, 'sos'])->name('sos');
        Route::patch('/sos/{sos}', [\App\Http\Controllers\Command\ResponseApiController::class, 'updateSos'])->name('sos.update');
        Route::delete('/sos/{sos}', [\App\Http\Controllers\Command\ResponseApiController::class, 'destroySos'])->name('sos.destroy');
    });

    // The Help page, both roles (linked from the sidebar).
    Route::get('/help', [GuidanceController::class, 'help'])->middleware('staff')->name('help');

    // Uploaded files (photos, attachments), from the private records disk —
    // only through a signed, expiring link (App\Support\Files::url()).
    Route::get('/files/{path}', [\App\Http\Controllers\FileController::class, 'show'])
        ->where('path', '.*')->middleware('signed')->name('files.show');

    // Place search for the "Pick on map" dialog, proxied to OpenStreetMap's
    // Nominatim (see MapSearchController and config/map.php).
    Route::get('/map/search', MapSearchController::class)->name('map.search');

    // Shared by both roles at one URL — Coordinators are locked server-side
    // to their own campus inside the controller, Admins get the full
    // campus filter. See ConsolidatedReportController::resolveCampusId().
    Route::get('/reports/consolidated', [ConsolidatedReportController::class, 'index'])->middleware('staff')->name('reports.consolidated.index');
    Route::get('/reports/consolidated/pdf', [ConsolidatedReportController::class, 'downloadPdf'])->middleware('staff')->name('reports.consolidated.pdf');

    // One shared route for every module's own period report
    // (Facility Hazard Auditor, Incident Reporting, Drill Tracker — schedule,
    // documentation and After Activity Reports as three separate reports —
    // SMS & Broadcast Alert) — {module} is validated against
    // ModuleReportController::MODULES inside the controller. Same
    // shared-URL / server-side-campus-lock pattern as the routes above.
    Route::get('/reports/module/{module}/pdf', [ModuleReportController::class, 'pdf'])
        ->middleware('staff')
        ->where('module', 'hazard|incidents|drills|drill-documentation|after-activity|alerts')
        ->name('reports.module.pdf');

    Route::middleware('role:Coordinator')->prefix('coordinator')->name('coordinator.')->group(function () use ($drillRoutes, $advisoryRoutes, $hotlineRoutes) {
        Route::get('/reporters/pending', [PersonnelController::class, 'pendingReporters'])->name('reporters.pending');
        Route::post('/reporters/{personnel}/validate', [PersonnelController::class, 'validateReporter'])->name('reporters.validate');
        // Their own entries only (see ActivityLogController).
        Route::get('/activity-log', [ActivityLogController::class, 'index'])->name('activity-log.index');
        Route::get('/activity-log/report', [ActivityLogController::class, 'downloadReport'])->name('activity-log.report');
        Route::get('/dashboard', [DashboardController::class, 'coordinator'])->name('dashboard');
        Route::get('/buildings/trash', [BuildingController::class, 'trashed'])->name('buildings.trash');
        Route::post('/buildings/trash/{building}/restore', [BuildingController::class, 'restore'])->name('buildings.restore');
        Route::resource('buildings', BuildingController::class);
        Route::get('/buildings/{building}/report', [BuildingController::class, 'downloadReport'])->name('buildings.report');
        Route::resource('buildings.inspections', HazardInspectionController::class)->except(['show']);
        Route::get('/buildings/{building}/inspections/{inspection}/report', [HazardInspectionController::class, 'downloadReport'])->name('buildings.inspections.report');
        Route::get('/incidents/trash', [IncidentController::class, 'trashed'])->name('incidents.trash');
        Route::post('/incidents/trash/{incident}/restore', [IncidentController::class, 'restore'])->name('incidents.restore');
        Route::resource('incidents', IncidentController::class);
        Route::get('/incidents/{incident}/report', [IncidentController::class, 'downloadReport'])->name('incidents.report');
        Route::resource('incidents.damage-reports', DamageReportController::class)->only(['create', 'store', 'edit', 'update', 'destroy']);
        Route::get('/incidents/{incident}/alerts/{alert}/report', [AlertController::class, 'downloadReport'])->name('incidents.alerts.report');
        $advisoryRoutes();
        $drillRoutes();
        $hotlineRoutes();
        Route::get('/profile', [ProfileController::class, 'show'])->name('profile.show');
        Route::post('/profile', [ProfileController::class, 'update'])->name('profile.update');
        Route::post('/profile/password', [ProfileController::class, 'updatePassword'])->name('profile.password');
    });

    Route::middleware('role:DRRMO Admin')->prefix('admin')->name('admin.')->group(function () use ($drillRoutes, $advisoryRoutes, $hotlineRoutes) {
        Route::get('/dashboard', [DashboardController::class, 'admin'])->name('dashboard');
        Route::get('/buildings/trash', [BuildingController::class, 'trashed'])->name('buildings.trash');
        Route::post('/buildings/trash/{building}/restore', [BuildingController::class, 'restore'])->name('buildings.restore');
        Route::resource('buildings', BuildingController::class);
        Route::get('/buildings/{building}/report', [BuildingController::class, 'downloadReport'])->name('buildings.report');
        Route::resource('buildings.inspections', HazardInspectionController::class)->except(['show']);
        Route::get('/buildings/{building}/inspections/{inspection}/report', [HazardInspectionController::class, 'downloadReport'])->name('buildings.inspections.report');
        Route::get('/incidents/trash', [IncidentController::class, 'trashed'])->name('incidents.trash');
        Route::post('/incidents/trash/{incident}/restore', [IncidentController::class, 'restore'])->name('incidents.restore');
        Route::resource('incidents', IncidentController::class);
        Route::get('/incidents/{incident}/report', [IncidentController::class, 'downloadReport'])->name('incidents.report');
        Route::resource('incidents.damage-reports', DamageReportController::class)->only(['create', 'store', 'edit', 'update', 'destroy']);
        Route::get('/incidents/{incident}/alerts/{alert}/report', [AlertController::class, 'downloadReport'])->name('incidents.alerts.report');
        $advisoryRoutes();
        $drillRoutes();
        $hotlineRoutes();
        // Permanent delete from the Archive: the Admin only.
        Route::delete('/archive/{type}/{id}', [ArchiveController::class, 'destroy'])
            ->whereIn('type', array_keys(ArchiveController::PERMANENT))->whereNumber('id')->name('archive.destroy');
        Route::resource('incidents.alerts', AlertController::class)->only(['create', 'store']);
        Route::get('/personnel', [PersonnelController::class, 'index'])->name('personnel.index');
        Route::get('/personnel/report', [PersonnelController::class, 'downloadReport'])->name('personnel.report');
        Route::post('/personnel/{personnel}/approve', [PersonnelController::class, 'approve'])->name('personnel.approve');
        Route::post('/personnel/{personnel}/reject', [PersonnelController::class, 'reject'])->name('personnel.reject');
        Route::get('/personnel/{personnel}/edit', [PersonnelController::class, 'edit'])->name('personnel.edit');
        Route::put('/personnel/{personnel}', [PersonnelController::class, 'update'])->name('personnel.update');
        Route::delete('/personnel/{personnel}', [PersonnelController::class, 'destroy'])->name('personnel.destroy');
        // Schools and the report signatory (thesis panel).
        Route::get('/settings', [SettingsController::class, 'index'])->name('settings.index');
        Route::put('/settings/signatory', [SettingsController::class, 'updateSignatory'])->name('settings.signatory');
        Route::post('/settings/schools', [SettingsController::class, 'storeSchool'])->name('settings.schools.store');
        Route::put('/settings/schools/{school}', [SettingsController::class, 'updateSchool'])->name('settings.schools.update');
        Route::get('/backup', [BackupController::class, 'index'])->name('backup.index');
        Route::post('/backup/privacy-password', [BackupController::class, 'setDataPrivacyPassword'])->middleware('throttle:10,1')->name('backup.privacy-password');
        Route::post('/backup/download', [BackupController::class, 'download'])->name('backup.download');
        Route::post('/backup/restore', [BackupController::class, 'restore'])->name('backup.restore');
        Route::get('/backup/stored/{backup}/download', [BackupController::class, 'downloadStored'])->name('backup.stored.download');
        Route::post('/backup/stored/{backup}/restore', [BackupController::class, 'restoreStored'])->name('backup.stored.restore');
        Route::get('/backup/clear-records', [BackupController::class, 'clearRecordsForm'])->name('backup.clear-records');
        Route::post('/backup/clear-records', [BackupController::class, 'clearRecords'])->name('backup.clear-records.run');
        Route::get('/activity-log', [ActivityLogController::class, 'index'])->name('activity-log.index');
        Route::get('/activity-log/report', [ActivityLogController::class, 'downloadReport'])->name('activity-log.report');
        Route::get('/profile', [ProfileController::class, 'show'])->name('profile.show');
        Route::post('/profile', [ProfileController::class, 'update'])->name('profile.update');
        Route::post('/profile/password', [ProfileController::class, 'updatePassword'])->name('profile.password');
    });
});
