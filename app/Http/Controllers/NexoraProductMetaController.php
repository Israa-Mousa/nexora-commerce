<?php

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Webkul\Product\Repositories\ProductRepository;

/**
 * Nexora storefront: small, read-only card details that Bagisto's ProductCardResource does not carry
 * (brand label, size, best-seller flag and a second "hover" image) for up to 60 products at once.
 *
 * GET /nexora/product-meta?ids=1,2,3&locale=ar
 */
class NexoraProductMetaController extends Controller
{
    public function __invoke(Request $request, ProductRepository $productRepository): JsonResponse
    {
        $ids = collect(explode(',', (string) $request->query('ids')))
            ->map(fn ($id) => (int) $id)
            ->filter()
            ->unique()
            ->take(60)
            ->values();

        $locale = preg_replace('/[^a-z_]/i', '', (string) $request->query('locale', app()->getLocale())) ?: app()->getLocale();

        if ($ids->isEmpty()) {
            return response()->json(['data' => []]);
        }

        $data = Cache::remember('nexora:product-meta:'.$locale.':'.md5($ids->implode(',')), 600, function () use ($ids, $locale, $productRepository) {
            $attributes = DB::table('attributes')->whereIn('code', ['brand', 'nx_size', 'nx_best_seller'])->pluck('id', 'code');

            $values = DB::table('product_attribute_values')
                ->whereIn('product_id', $ids)
                ->whereIn('attribute_id', $attributes->values())
                ->get(['product_id', 'attribute_id', 'integer_value', 'text_value', 'boolean_value']);

            $brandIds = $values->where('attribute_id', $attributes['brand'] ?? 0)->pluck('integer_value')->filter()->unique();

            $brandLabels = DB::table('attribute_options as o')
                ->leftJoin('attribute_option_translations as t', function ($join) use ($locale) {
                    $join->on('t.attribute_option_id', '=', 'o.id')->where('t.locale', $locale);
                })
                ->whereIn('o.id', $brandIds)
                ->get(['o.id', 'o.admin_name', 't.label'])
                ->mapWithKeys(fn ($row) => [$row->id => $row->label ?: $row->admin_name]);

            $products = $productRepository->with('images')->findWhereIn('id', $ids->all());

            $result = [];

            foreach ($products as $product) {
                $own = $values->where('product_id', $product->id);

                $brandId = $own->firstWhere('attribute_id', $attributes['brand'] ?? 0)?->integer_value;

                $gallery = product_image()->getGalleryImages($product);

                $result[$product->id] = [
                    'brand' => $brandId ? ($brandLabels[$brandId] ?? null) : null,
                    'size' => $own->firstWhere('attribute_id', $attributes['nx_size'] ?? 0)?->text_value,
                    'best_seller' => (bool) $own->firstWhere('attribute_id', $attributes['nx_best_seller'] ?? 0)?->boolean_value,
                    'hover_image' => $gallery[1]['medium_image_url'] ?? null,
                ];
            }

            return $result;
        });

        return response()->json(['data' => $data])->header('Cache-Control', 'public, max-age=300');
    }
}
