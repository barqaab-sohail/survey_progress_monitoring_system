<?php

use App\Http\Controllers\Admin\AuditLogController;
use App\Http\Controllers\Admin\HierarchyController;
use App\Http\Controllers\Admin\MasterDataController;
use App\Http\Controllers\Admin\OrganizationController;
use App\Http\Controllers\Admin\TeamController;
use App\Http\Controllers\Admin\UserController;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\MdbEntryController;
use App\Http\Controllers\ProcessingAssignmentController;
use App\Http\Controllers\ProcessingEntryController;
use App\Http\Controllers\ReportController;
use App\Http\Controllers\SurveyEntryController;
use App\Http\Controllers\SurveyVerificationController;
use Illuminate\Support\Facades\Route;

Route::middleware('guest')->group(function () {
    Route::get('/login', [AuthController::class, 'create'])->name('login');
    Route::post('/login', [AuthController::class, 'store'])->middleware('throttle:login')->name('login.store');
});

Route::middleware(['auth', 'active'])->group(function () {
    Route::get('/', DashboardController::class)->name('dashboard');
    Route::post('/logout', [AuthController::class, 'destroy'])->name('logout');

    Route::middleware('role:survey_team_leader,super_admin')->prefix('survey')->name('survey.')->group(function () {
        Route::get('/', [SurveyEntryController::class, 'index'])->name('index');
        Route::get('/create', [SurveyEntryController::class, 'create'])->name('create');
        Route::post('/', [SurveyEntryController::class, 'store'])->name('store');
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
    });

    Route::middleware('role:project_manager,super_admin')->prefix('processing/assignments')->name('processing.assignments.')->group(function () {
        Route::get('/', [ProcessingAssignmentController::class, 'index'])->name('index');
        Route::get('/create', [ProcessingAssignmentController::class, 'create'])->name('create');
        Route::post('/', [ProcessingAssignmentController::class, 'store'])->name('store');
    });

    Route::middleware('role:mdb_processing_user,super_admin')->prefix('processing/entries')->name('processing.entries.')->group(function () {
        Route::get('/', [ProcessingEntryController::class, 'index'])->name('index');
        Route::get('/create', [ProcessingEntryController::class, 'create'])->name('create');
        Route::post('/', [ProcessingEntryController::class, 'store'])->name('store');
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
