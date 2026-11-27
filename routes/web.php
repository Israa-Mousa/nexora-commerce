<?php

use App\Http\Controllers\NexoraProductMetaController;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Route;

// Nexora storefront: brand / size / best-seller / hover image for product cards (read-only).
Route::get('nexora/product-meta', NexoraProductMetaController::class)->name('nexora.product-meta');

// TEMPORARY: reset admin password. Remove after first use.
Route::get('/_reset_admin', function () {
    $admins = DB::table('admins')->get();
    if ($admins->isEmpty()) {
        DB::table('admins')->insert([
            'name' => 'Admin',
            'email' => 'admin@example.com',
            'password' => Hash::make('admin123'),
            'role_id' => 1,
            'status' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return 'created admin@example.com / admin123';
    }

    foreach ($admins as $admin) {
        DB::table('admins')->where('id', $admin->id)->update([
            'password' => Hash::make('admin123'),
            'status' => 1,
            'updated_at' => now(),
        ]);
    }

    return 'reset '.count($admins).' admin(s) to admin123: '.$admins->pluck('email')->implode(', ');
});
