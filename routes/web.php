<?php

use App\Http\Controllers\Admin\AuditLogController;
use App\Http\Controllers\Admin\HierarchyController;
use App\Http\Controllers\Admin\MasterDataController;
use App\Http\Controllers\Admin\OrganizationController;
use App\Http\Controllers\Admin\TeamController;
use App\Http\Controllers\Admin\UserController;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\GoogleDriveController;
use App\Http\Controllers\MdbEntryController;
use App\Http\Controllers\MdbVerificationController;
use App\Http\Controllers\PasswordResetController;
use App\Http\Controllers\ReportController;
use App\Http\Controllers\SurveyEntryController;
use App\Http\Controllers\SurveyVerificationController;
use App\Http\Controllers\TransformerController;
use Illuminate\Support\Facades\Route;

Route::view('/privacy-policy', 'legal.privacy-policy')->name('privacy-policy');
Route::view('/terms-of-service', 'legal.terms-of-service')->name('terms-of-service');

Route::middleware('guest')->group(function () {
    Route::get('/login', [AuthController::class, 'create'])->name('login');
    Route::post('/login', [AuthController::class, 'store'])->middleware('throttle:login')->name('login.store');
    Route::get('/forgot-password', [PasswordResetController::class, 'request'])->name('password.request');
    Route::post('/forgot-password', [PasswordResetController::class, 'email'])->middleware('throttle:password-reset')->name('password.email');
    Route::get('/reset-password/{token}', [PasswordResetController::class, 'form'])->name('password.reset');
    Route::post('/reset-password', [PasswordResetController::class, 'update'])->middleware('throttle:password-reset-submit')->name('password.update');
});

Route::middleware(['auth', 'active'])->group(function () {
    Route::get('/', DashboardController::class)->name('dashboard');
    Route::get('/google-drive', [GoogleDriveController::class, 'index'])->name('google-drive.index');
    Route::post('/auth/google', [GoogleDriveController::class, 'connect'])->middleware('throttle:10,1')->name('google-drive.connect');
    Route::get('/auth/google/callback', [GoogleDriveController::class, 'callback'])->name('google-drive.callback');
    Route::delete('/google-drive', [GoogleDriveController::class, 'disconnect'])->name('google-drive.disconnect');
    Route::post('/logout', [AuthController::class, 'destroy'])->name('logout');

    Route::middleware('role:survey_team_leader,mdb_team_user,super_admin,project_manager')->prefix('transformer-gis')->name('transformers.')->group(function () {
        Route::get('/', [TransformerController::class, 'index'])->name('index');
        Route::get('/{transformer}', [TransformerController::class, 'show'])->name('show');
    });

    Route::middleware('role:survey_team_leader,super_admin')->prefix('survey')->name('survey.')->group(function () {
        Route::get('/', [SurveyEntryController::class, 'index'])->name('index');
        Route::get('/create', [SurveyEntryController::class, 'create'])->name('create');
        Route::post('/', [SurveyEntryController::class, 'store'])->name('store');
        Route::get('/entries/{entry}/edit', [SurveyEntryController::class, 'edit'])->name('edit');
        Route::put('/entries/{entry}', [SurveyEntryController::class, 'updateEntry'])->name('update');
        Route::get('/returned', [SurveyEntryController::class, 'returned'])->name('returned');
        Route::put('/items/{item}/resubmit', [SurveyEntryController::class, 'update'])->name('resubmit');
    });

    Route::middleware('role:mdb_team_user,super_admin')->prefix('verification')->name('verification.')->group(function () {
        Route::get('/', [SurveyVerificationController::class, 'index'])->name('index');
        Route::post('/{item}/verify', [SurveyVerificationController::class, 'verify'])->name('verify');
        Route::post('/{item}/return', [SurveyVerificationController::class, 'return'])->name('return');
    });

    Route::middleware('role:mdb_team_user,super_admin')->prefix('mdb')->name('mdb.')->group(function () {
        Route::get('/', [MdbEntryController::class, 'index'])->name('index');
        Route::get('/create', [MdbEntryController::class, 'create'])->name('create');
        Route::post('/', [MdbEntryController::class, 'store'])->name('store');
        Route::get('/entries/{entry}/edit', [MdbEntryController::class, 'edit'])->name('edit');
        Route::put('/entries/{entry}', [MdbEntryController::class, 'update'])->name('update');
        Route::get('/returned', [MdbEntryController::class, 'returned'])->name('returned');
        Route::put('/items/{item}/resubmit', [MdbEntryController::class, 'resubmit'])->name('resubmit');
    });

    Route::middleware('role:mdb_processing_user,super_admin')->prefix('mdb-verification')->name('mdb-verification.')->group(function () {
        Route::get('/', [MdbVerificationController::class, 'index'])->name('index');
        Route::get('/history', [MdbVerificationController::class, 'history'])->name('history');
        Route::post('/items/{item}/verify', [MdbVerificationController::class, 'verify'])->name('verify');
        Route::post('/items/{item}/return', [MdbVerificationController::class, 'return'])->name('return');
    });

    Route::middleware('role:super_admin,project_manager,management_viewer')->prefix('reports')->name('reports.')->group(function () {
        Route::get('/', [ReportController::class, 'index'])->name('index');
        Route::get('/export.csv', [ReportController::class, 'csv'])->name('csv');
    });

    Route::middleware('role:super_admin')->prefix('legacy-admin')->name('admin.')->group(function () {
        Route::get('/master-data', [MasterDataController::class, 'index'])->name('master.index');
        Route::get('/master-data/hierarchy', [HierarchyController::class, 'index'])->name('hierarchy.index');
        Route::post('/master-data/hierarchy/projects', [HierarchyController::class, 'project'])->name('hierarchy.projects.store');
        Route::post('/master-data/hierarchy/circles', [HierarchyController::class, 'circle'])->name('hierarchy.circles.store');
        Route::post('/master-data/hierarchy/divisions', [HierarchyController::class, 'division'])->name('hierarchy.divisions.store');
        Route::post('/master-data/hierarchy/sub-divisions', [HierarchyController::class, 'subDivision'])->name('hierarchy.sub-divisions.store');
        Route::post('/master-data/hierarchy/grid-stations', [HierarchyController::class, 'gridStation'])->name('hierarchy.grid-stations.store');
        Route::get('/master-data/feeders/{feeder}/edit', [MasterDataController::class, 'edit'])->name('master.edit');
        Route::put('/master-data/feeders/{feeder}', [MasterDataController::class, 'update'])->name('master.update');
        Route::post('/master-data/import', [MasterDataController::class, 'import'])->name('master.import');
        Route::get('/master-data/import-template', [MasterDataController::class, 'template'])->name('master.template');
        Route::get('/organizations', [OrganizationController::class, 'index'])->name('organizations.index');
        Route::post('/organizations', [OrganizationController::class, 'store'])->name('organizations.store');
        Route::put('/organizations/{organization}', [OrganizationController::class, 'update'])->name('organizations.update');
        Route::get('/users', [UserController::class, 'index'])->name('users.index');
        Route::post('/users', [UserController::class, 'store'])->name('users.store');
        Route::put('/users/{user}', [UserController::class, 'update'])->name('users.update');
        Route::get('/teams', [TeamController::class, 'index'])->name('teams.index');
        Route::post('/teams', [TeamController::class, 'store'])->name('teams.store');
        Route::post('/teams/member', [TeamController::class, 'member'])->name('teams.member');
        Route::post('/teams/assign-feeder', [TeamController::class, 'assignFeeder'])->name('teams.assign-feeder');
        Route::get('/audit-log', AuditLogController::class)->name('audit.index');
    });
});
