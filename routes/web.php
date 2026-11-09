<?php

use App\Http\Controllers\AccountController;
use App\Http\Controllers\AssetController;
use App\Http\Controllers\CalendarController;
use App\Http\Controllers\CanvaController;
use App\Http\Controllers\ClientController;
use App\Http\Controllers\CommentController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\DeadlineController;
use App\Http\Controllers\ExpiredLinkController;
use App\Http\Controllers\FolderController;
use App\Http\Controllers\InboxController;
use App\Http\Controllers\JobController;
use App\Http\Controllers\LocaleController;
use App\Http\Controllers\PortalController;
use App\Http\Controllers\PostController;
use App\Http\Controllers\ProfileController;
use App\Http\Controllers\RecycleBinController;
use App\Http\Controllers\ReportController;
use App\Http\Controllers\ReviewController;
use App\Http\Controllers\SettingsController;
use App\Http\Controllers\StaffController;
use App\Http\Controllers\TodayController;
use App\Http\Middleware\LimitClients;
use App\Models\User;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Route;

Route::get('/', fn () => redirect(User::count() ? '/dashboard' : '/setup'));
Route::get('/setup', [StaffController::class, 'setup'])->name('setup');
Route::post('/setup', [StaffController::class, 'finishSetup'])->name('setup.store');
Route::post('/locale', LocaleController::class)->name('locale'); // staff and guests alike

Route::get('/dashboard', [DashboardController::class, 'index'])->middleware(['auth', 'verified', LimitClients::class])->name('dashboard');

// After a git pull on the server: run new migrations and rebuild the caches, instead of SSH.
Route::post('/refresh', function () {
    Artisan::call('migrate', ['--force' => true]);
    file_exists(storage_path('oauth-private.key')) || Artisan::call('passport:keys'); // the Claude connector's login signs its tokens with these
    Artisan::call('optimize');

    return redirect()->route('dashboard')->with('status', 'app-refreshed');
})->middleware(['auth', 'can:admin'])->name('refresh'); // it runs migrations: admins only, like the link in the side navigation
Route::get('/refresh', fn () => view('refresh'))->middleware(['auth', 'can:admin']); // reachable even while the dashboard fails before a migration

Route::middleware(['auth', LimitClients::class])->group(function () {
    Route::resource('clients', ClientController::class)->except('show')->middlewareFor(['create', 'store'], 'can:add-clients');
    Route::get('/clients/{client}/assets', [AssetController::class, 'index'])->name('clients.assets');
    Route::get('/clients/{client}/grid', [ClientController::class, 'grid'])->name('clients.grid');
    Route::post('/clients/{client}/assets', [AssetController::class, 'store'])->name('assets.store'); // staff upload, one file or many
    Route::get('/assets/{asset}/thumb', [AssetController::class, 'thumb'])->name('assets.thumb');
    Route::get('/assets/{asset}/view', [AssetController::class, 'show'])->name('assets.view');
    Route::get('/assets/{asset}/download', [AssetController::class, 'download'])->name('assets.download');
    Route::delete('/assets/{asset}', [AssetController::class, 'destroy'])->name('assets.destroy');
    Route::post('/clients/{client}/reel', [AssetController::class, 'reel'])->name('assets.reel');
    Route::get('/clients/{client}/canva/connect', [CanvaController::class, 'connect'])->name('canva.connect');
    Route::post('/clients/{client}/canva/import', [CanvaController::class, 'import'])->name('canva.import');
    Route::get('/canva/callback', [CanvaController::class, 'callback'])->name('canva.callback');
    Route::post('/clients/{client}/drive', [FolderController::class, 'watchDrive'])->name('drive.watch');
    Route::get('/clients/{client}/dropbox/connect', [FolderController::class, 'connectDropbox'])->name('dropbox.connect');
    Route::post('/clients/{client}/dropbox', [FolderController::class, 'watchDropbox'])->name('dropbox.watch');
    Route::get('/dropbox/callback', [FolderController::class, 'dropboxCallback'])->name('dropbox.callback');
    Route::post('/clients/{client}/folders/{platform}/pull', [FolderController::class, 'pull'])->name('folders.pull');
    Route::get('/clients/{client}/deadlines', [DeadlineController::class, 'index'])->name('clients.deadlines');
    Route::post('/clients/{client}/deadlines', [DeadlineController::class, 'store'])->name('deadlines.store');
    Route::patch('/deadlines/{deadline}', [DeadlineController::class, 'toggle'])->name('deadlines.toggle');
    Route::delete('/deadlines/{deadline}', [DeadlineController::class, 'destroy'])->name('deadlines.destroy');
    Route::get('/clients/{client}/reviews', [ReviewController::class, 'index'])->name('clients.reviews');
    Route::post('/clients/{client}/reviews', [ReviewController::class, 'store'])->name('reviews.store');
    Route::get('/clients/{client}/reports', [ReportController::class, 'index'])->name('clients.reports');
    Route::get('/reports/{report}', [ReportController::class, 'show'])->name('reports.show');
    Route::get('/reports/{report}/download', [ReportController::class, 'download'])->name('reports.download');
    Route::get('/reports/{report}/word', [ReportController::class, 'word'])->name('reports.word');
    Route::post('/reports/{report}/send', [ReportController::class, 'send'])->name('reports.send');
    Route::delete('/accounts/{account}', [AccountController::class, 'destroy'])->name('accounts.destroy'); // also Canva, Drive and Dropbox
    Route::get('/calendar', [CalendarController::class, 'index'])->name('calendar');
    Route::get('/today', [TodayController::class, 'index'])->name('today'); // posting by hand: the texts to paste, then Mark published
    Route::get('/jobs', [JobController::class, 'index'])->name('jobs.index');
    Route::post('/jobs/{uuid}/retry', [JobController::class, 'retry'])->name('jobs.retry');
    Route::delete('/jobs/{uuid}', [JobController::class, 'forget'])->name('jobs.forget');
    Route::get('/clients/{client}/posts/create', [PostController::class, 'create'])->name('posts.create');
    Route::post('/clients/{client}/posts', [PostController::class, 'store'])->name('posts.store');
    Route::get('/posts/{post}/edit', [PostController::class, 'edit'])->withTrashed()->name('posts.edit'); // a binned post's link goes to the bin
    Route::patch('/posts/{post}', [PostController::class, 'update'])->name('posts.update');
    Route::post('/posts/{post}/transition', [PostController::class, 'transition'])->name('posts.transition');
    Route::post('/posts/bulk', [PostController::class, 'bulk'])->name('posts.bulk');
    Route::post('/posts/{post}/duplicate', [PostController::class, 'duplicate'])->name('posts.duplicate');
    Route::patch('/posts/{post}/reschedule', [PostController::class, 'reschedule'])->name('posts.reschedule');
    Route::post('/posts/{post}/notes', [PostController::class, 'note'])->name('posts.note');
    Route::delete('/posts/{post}', [PostController::class, 'destroy'])->name('posts.destroy'); // into the client's recycle bin
    Route::get('/clients/{client}/bin', [RecycleBinController::class, 'index'])->name('clients.bin');
    Route::post('/clients/{client}/bin/restore', [RecycleBinController::class, 'restoreAll'])->name('bin.restore-all');
    Route::delete('/clients/{client}/bin', [RecycleBinController::class, 'empty'])->name('bin.empty');
    Route::post('/posts/{post}/restore', [RecycleBinController::class, 'restore'])->withTrashed()->name('posts.restore');
    Route::delete('/posts/{post}/for-good', [RecycleBinController::class, 'destroy'])->withTrashed()->name('posts.forget');
    Route::get('/posts/{post}/export', [PostController::class, 'export'])->name('posts.export');
    Route::get('/posts/{post}/image/{lang}', [PostController::class, 'image'])->name('posts.image')->where('lang', '[a-z]{2}');
    Route::get('/clients/{client}/posts/export.csv', [CalendarController::class, 'csv'])->name('posts.csv');
    Route::get('/clients/{client}/posts/import.csv', [PostController::class, 'importTemplate'])->name('posts.import.template');
    Route::post('/clients/{client}/posts/import', [PostController::class, 'import'])->name('posts.import');
    Route::get('/settings', [SettingsController::class, 'edit'])->middleware('can:admin')->name('settings.edit');
    Route::post('/settings', [SettingsController::class, 'update'])->middleware('can:admin')->name('settings.update');
    Route::post('/settings/test-mail', [SettingsController::class, 'testMail'])->middleware('can:admin')->name('settings.test-mail');
    Route::get('/staff', [StaffController::class, 'index'])->name('staff.index');
    Route::post('/staff', [StaffController::class, 'store'])->name('staff.store');
    Route::delete('/staff/{user}', [StaffController::class, 'destroy'])->name('staff.destroy');
    Route::patch('/staff/{user}', [StaffController::class, 'update'])->name('staff.update');
    Route::post('/staff/tokens', [StaffController::class, 'tokenStore'])->name('staff.tokens.store');
    Route::delete('/staff/tokens/{token}', [StaffController::class, 'tokenDestroy'])->name('staff.tokens.destroy');
    Route::get('/profile', [ProfileController::class, 'edit'])->name('profile.edit');
    Route::patch('/profile', [ProfileController::class, 'update'])->name('profile.update');
    Route::delete('/profile', [ProfileController::class, 'destroy'])->name('profile.destroy');

    // Claude called from the server: 404 while FEATURE_CLAUDE_API is off (config/features.php); Claude works through the MCP connector instead
    Route::post('/clients/{client}/claude-jobs', [ClientController::class, 'queueClaude'])->name('clients.queue');
    Route::delete('/claude-jobs/{report}', [JobController::class, 'cancelPlan'])->name('claude.cancel'); // the queue for the scheduled Claude worker (MCP)
    Route::post('/posts/{post}/stop-asking-claude', [JobController::class, 'stopAsking'])->name('claude.stop');
    Route::middleware('feature:claude_api')->group(function () {
        Route::post('/clients/{client}/plan', [ClientController::class, 'plan'])->name('clients.plan');
        Route::post('/clients/{client}/draft', [ClientController::class, 'draft'])->name('clients.draft');
        Route::post('/posts/{post}/draft', [PostController::class, 'draft'])->name('posts.draft');
        Route::post('/posts/{post}/shorten', [PostController::class, 'shorten'])->name('posts.shorten');
        Route::post('/posts/{post}/rewrite', [PostController::class, 'rewrite'])->name('posts.rewrite');
    });

    // Posting through our own drivers and reading back from the platform APIs: 404 while FEATURE_PUBLISHING is off (config/features.php)
    Route::middleware('feature:publishing')->group(function () {
        Route::get('/clients/{client}/accounts', [AccountController::class, 'index'])->name('clients.accounts');
        Route::get('/clients/{client}/accounts/connect/{provider}', [AccountController::class, 'connect'])->name('accounts.connect');
        Route::post('/accounts/{account}/import', [AccountController::class, 'import'])->name('accounts.import');
        Route::post('/clients/{client}/reports/monthly', [ReportController::class, 'monthly'])->name('reports.monthly');
        Route::post('/clients/{client}/reports/listening', [ReportController::class, 'listening'])->name('reports.listening');
        Route::get('/clients/{client}/comments', [CommentController::class, 'index'])->name('clients.comments');
        Route::post('/comments/{comment}/draft', [CommentController::class, 'draft'])->name('comments.draft');
        Route::post('/comments/{comment}/reply', [CommentController::class, 'reply'])->name('comments.reply');
        Route::post('/comments/{comment}/hide', [CommentController::class, 'hide'])->name('comments.hide');
    });
});

// OAuth return and the pick step: the state and candidates in the session are the credential, staff or client alike
Route::middleware('feature:publishing')->group(function () {
    Route::get('/oauth/{provider}/callback', [AccountController::class, 'callback'])->name('oauth.callback');
    Route::post('/clients/{client}/accounts', [AccountController::class, 'attach'])->name('accounts.attach');
});

// Client-facing pages: no login, the signed link is the credential (GET and POST share the URL)
Route::middleware('signed')->group(function () {
    Route::get('/connect/{client:slug}', [AccountController::class, 'clientConnect'])->middleware('feature:publishing')->name('connect.show');
    Route::get('/connect/{client:slug}/{provider}', [AccountController::class, 'connect'])->middleware('feature:publishing')->name('connect.start');
    Route::get('/calendar/{client:slug}', [CalendarController::class, 'client'])->name('calendar.client');
    Route::get('/reports/{report}/client', [ReportController::class, 'client'])->name('reports.client');
    Route::get('/inbox/{client:slug}', [InboxController::class, 'show'])->name('inbox.show');
    Route::post('/inbox/{client:slug}', [InboxController::class, 'store'])->name('inbox.store');
    Route::get('/review/{review}', [PortalController::class, 'show'])->name('portal.show');
    Route::post('/review/{review}', [PortalController::class, 'decide'])->name('portal.decide');
    Route::get('/review/{review}/asset/{asset}', [PortalController::class, 'asset'])->name('portal.asset');
});
// An expired or incomplete one of those links shows a page instead of the bare 403 (App\Http\ExpiredLink); its button lands here
Route::post('/link-request', [ExpiredLinkController::class, 'store'])->name('links.request');

require __DIR__.'/auth.php';
