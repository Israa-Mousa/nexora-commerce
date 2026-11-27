<?php

/**
 * Nexora — read-only catalog status report (and optional theme activation).
 *
 *   php database/seeders/data/tools/nexora-status.php
 *   php database/seeders/data/tools/nexora-status.php --activate-theme   (sets the default channel theme to "nexora")
 *
 * Prints counts only; never prints credentials.
 */

use Illuminate\Support\Facades\DB;

$root = dirname(__DIR__, 4);

require $root.'/vendor/autoload.php';

$app = require $root.'/bootstrap/app.php';

$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

try {
    DB::connection()->getPdo();
} catch (Throwable $e) {
    fwrite(STDERR, "DATABASE: not reachable — ".$e->getMessage().PHP_EOL);
    exit(2);
}

$channel = DB::table('channels')->orderBy('id')->first();

$locales = DB::table('channel_locales as cl')->join('locales as l', 'l.id', '=', 'cl.locale_id')
    ->where('cl.channel_id', $channel->id)->pluck('l.code')->implode(', ');

if (in_array('--activate-theme', $argv, true)) {
    DB::table('channels')->where('id', $channel->id)->update(['theme' => 'nexora', 'updated_at' => now()]);

    $channel = DB::table('channels')->where('id', $channel->id)->first();

    echo "Theme for channel '{$channel->code}' set to: nexora".PHP_EOL;
}

$rootChildren = DB::table('categories as c')
    ->leftJoin('category_translations as t', function ($join) {
        $join->on('t.category_id', '=', 'c.id')->where('t.locale', 'en');
    })
    ->where('c.parent_id', $channel->root_category_id)
    ->orderBy('c.position')
    ->get(['c.id', 'c.status', 't.name', 't.slug']);

$lines = [
    'Database'                => DB::connection()->getDatabaseName().' ('.DB::connection()->getDriverName().')',
    'Channel'                 => $channel->code.' | theme: '.$channel->theme.' | locales: '.$locales,
    'Products (all)'          => DB::table('products')->count(),
    'Nexora beauty (NXB-*)'   => DB::table('products')->where('sku', 'like', 'NXB-%')->count(),
    'Old fragrance (NX-FR-*)' => DB::table('products')->where('sku', 'like', 'NX-FR-%')->count(),
    'product_flat rows NXB'   => DB::table('product_flat')->where('sku', 'like', 'NXB-%')->count(),
    'Images for NXB'          => DB::table('product_images')->whereIn('product_id', DB::table('products')->where('sku', 'like', 'NXB-%')->select('id'))->count(),
    'Attribute family'        => DB::table('attribute_families')->where('code', 'nexora_beauty')->exists() ? 'nexora_beauty present' : 'nexora_beauty missing',
    'Orders'                  => DB::table('orders')->count(),
];

foreach ($lines as $label => $value) {
    echo str_pad($label, 26).': '.$value.PHP_EOL;
}

echo PHP_EOL.'Top-level categories under the channel root:'.PHP_EOL;

foreach ($rootChildren as $category) {
    echo sprintf('  #%-4d %-8s %-32s /%s', $category->id, $category->status ? 'active' : 'hidden', $category->name, $category->slug).PHP_EOL;
}
