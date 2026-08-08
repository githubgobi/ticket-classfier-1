<?php

use App\Http\Controllers\TicketController;
use Illuminate\Support\Facades\Route;

Route::post('/classify', [TicketController::class, 'classify'])->middleware('throttle:5,1');
