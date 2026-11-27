<?php

namespace Database\Seeders;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Webkul\Attribute\Repositories\AttributeRepository;
use Webkul\Product\Repositories\ProductAttributeValueRepository;
use Webkul\Product\Repositories\ProductRepository;

/**
 * Turn the storefront into the Nexora Beauty & Personal Care store — reversibly, without deleting anything.
 *
 * Run AFTER NexoraBeautySeeder:
 *   php artisan db:seed --class="Database\Seeders\NexoraReorganizeSeeder" --force
 * Revert (uses the JSON log written by the run):
 *   set NEXORA_REVERT=storage\app\nexora-backups\reorganize-YYYYmmdd-HHMMSS.json
 *   php artisan db:seed --class="Database\Seeders\NexoraReorganizeSeeder" --force
 *
 * What it changes (every change is logged so it can be undone):
 *  1. Categories in the channel tree that are not part of the Nexora tree (Women / Men / Kids & Babies and
 *     their sub-categories) are hidden (status 0). Nothing is deleted.
 *  2. Older, non-Nexora products are disabled on the storefront (status 0 for the default channel), so
 *     search and listings show only the beauty catalogue. Their data, images, stock and orders stay intact.
 */
class NexoraReorganizeSeeder extends Seeder
{
    public function run(): void
    {
        Model::reguard();

        if ($log = env('NEXORA_REVERT')) {
            $this->revert(base_path($log));

            return;
        }

        $catalog = json_decode(file_get_contents(base_path(NexoraBeautySeeder::CATALOG_PATH)), true, 512, JSON_THROW_ON_ERROR);

        $channel = core()->getDefaultChannel();

        $rootId = (int) $channel->root_category_id;

        $nexoraIds = $this->resolveNexoraCategoryIds($catalog['categories'], $rootId);

        if (count($nexoraIds) < 3) {
            throw new \RuntimeException('Nexora categories were not found — run NexoraBeautySeeder first. Nothing was changed.');
        }

        $nexoraProductIds = DB::table('products')->where('sku', 'like', 'NXB-%')->pluck('id')->all();

        if (! $nexoraProductIds) {
            throw new \RuntimeException('No Nexora (NXB-*) products found — run NexoraBeautySeeder first. Nothing was changed.');
        }

        $treeIds = DB::table('categories')
            ->where('_lft', '>', DB::table('categories')->where('id', $rootId)->value('_lft'))
            ->where('_rgt', '<', DB::table('categories')->where('id', $rootId)->value('_rgt'))
            ->pluck('id')
            ->all();

        $log = [
            'created_at' => now()->toDateTimeString(),
            'channel' => $channel->code,
            'hidden_categories' => [],
            'disabled_products' => [],
        ];

        $logPath = storage_path('app/nexora-backups/reorganize-'.now()->format('Ymd-His').'.json');

        @mkdir(dirname($logPath), 0775, true);

        DB::transaction(function () use (&$log, $treeIds, $nexoraIds) {
            // 1) Hide non-Nexora categories.
            $toHide = DB::table('categories')
                ->whereIn('id', array_diff($treeIds, $nexoraIds))
                ->where('status', 1)
                ->pluck('id')
                ->all();

            if ($toHide) {
                DB::table('categories')->whereIn('id', $toHide)->update(['status' => 0, 'updated_at' => now()]);
            }

            $log['hidden_categories'] = $toHide;
        });

        file_put_contents($logPath, json_encode($log, JSON_PRETTY_PRINT));

        // 2) Disable old products on the storefront through the product repository (reindexes like the admin does).
        $oldProductIds = DB::table('product_flat')
            ->where('channel', $channel->code)
            ->where('locale', core()->getDefaultLocaleCodeFromDefaultChannel())
            ->where('status', 1)
            ->whereNull('parent_id')
            ->where('sku', 'not like', 'NXB-%')
            ->pluck('product_id')
            ->unique()
            ->values()
            ->all();

        $this->setStatus($oldProductIds, 0, $channel, function ($id) use (&$log, $logPath) {
            $log['disabled_products'][] = $id;

            file_put_contents($logPath, json_encode($log, JSON_PRETTY_PRINT));
        });

        $this->command?->line('Hidden categories      : '.count($log['hidden_categories']));
        $this->command?->line('Disabled old products  : '.count($log['disabled_products']));
        $this->command?->line('Undo log               : '.$logPath);
        $this->command?->line('Nothing was deleted. To undo: set NEXORA_REVERT to the log path and run this seeder again.');
    }

    /**
     * @return array<int, int>
     */
    protected function resolveNexoraCategoryIds(array $nodes, int $parentId): array
    {
        $ids = [];

        foreach ($nodes as $node) {
            $id = DB::table('category_translations as t')
                ->join('categories as c', 'c.id', '=', 't.category_id')
                ->where('c.parent_id', $parentId)
                ->where(function ($query) use ($node) {
                    $query->whereIn('t.slug', [$node['slug'], 'nx-'.$node['slug']])
                        ->orWhereRaw('LOWER(t.name) = ?', [mb_strtolower($node['en'])])
                        ->orWhere('t.name', $node['ar']);
                })
                ->value('c.id');

            if (! $id) {
                continue;
            }

            $ids[] = (int) $id;

            if (! empty($node['children'])) {
                $ids = array_merge($ids, $this->resolveNexoraCategoryIds($node['children'], (int) $id));
            }
        }

        return $ids;
    }

    protected function setStatus(array $productIds, int $status, $channel, ?callable $after = null): void
    {
        $repository = app(ProductRepository::class);

        $statusAttribute = app(AttributeRepository::class)->findOneByField('code', 'status');

        $values = app(ProductAttributeValueRepository::class);

        foreach ($productIds as $productId) {
            $product = $repository->find($productId);

            if (! $product) {
                continue;
            }

            // Write only the "status" attribute value (like the admin mass-update), so product types with
            // request-dependent update logic (booking, bundle, ...) are never invoked from the console.
            $values->saveValues([
                'status' => $status,
                'channel' => $channel->code,
                'locale' => core()->getDefaultLocaleCodeFromDefaultChannel(),
            ], $product, collect([$statusAttribute]));

            Event::dispatch('catalog.product.update.after', $product->refresh());

            if ($after) {
                $after($productId);
            }
        }
    }

    protected function revert(string $path): void
    {
        if (! is_file($path)) {
            throw new \RuntimeException('Undo log not found: '.$path);
        }

        $log = json_decode(file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);

        $channel = core()->getDefaultChannel();

        DB::transaction(function () use ($log) {
            if ($log['hidden_categories']) {
                DB::table('categories')->whereIn('id', $log['hidden_categories'])->update(['status' => 1, 'updated_at' => now()]);
            }
        });

        $this->setStatus($log['disabled_products'], 1, $channel);

        $this->command?->line('Reverted: '.count($log['hidden_categories']).' categories, '.count($log['disabled_products']).' products.');
    }
}
