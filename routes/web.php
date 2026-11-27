<?php

use App\Http\Controllers\NexoraProductMetaController;
use Illuminate\Support\Facades\Route;

// Nexora storefront: brand / size / best-seller / hover image for product cards (read-only).
Route::get('nexora/product-meta', NexoraProductMetaController::class)->name('nexora.product-meta');
