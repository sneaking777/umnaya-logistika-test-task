<?php

declare(strict_types=1);

use App\Http\Controllers\NotificationsController;
use Illuminate\Support\Facades\Route;

// Маршруты API v1. Префикс /api/v1 задаётся в bootstrap/app.php (apiPrefix).

Route::post('/notifications/bulk', [NotificationsController::class, 'bulk'])->name('notifications.bulk');

