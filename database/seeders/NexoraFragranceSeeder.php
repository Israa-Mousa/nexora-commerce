<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Webkul\Attribute\Repositories\AttributeRepository;
use Webkul\Category\Repositories\CategoryRepository;
use Webkul\Product\Helpers\Indexers\ElasticSearch;
use Webkul\Product\Helpers\Indexers\Flat;
use Webkul\Product\Helpers\Indexers\Inventory;
use Webkul\Product\Helpers\Indexers\Price;
use Webkul\Product\Repositories\ProductImageRepository;
use Webkul\Product\Repositories\ProductRepository;

class NexoraFragranceSeeder extends Seeder
{
    /**
     * Add the Nexora fragrance demo alongside the existing catalog.
     */
    public function run(): void
    {
        $expectedProductCount = count($this->productPlans());
        $this->assertEligibleProductSlots($expectedProductCount);

        DB::transaction(function () use ($expectedProductCount): void {
            $categories = $this->createCategories();

            $attributeIds = $this->createBeautyAttributes();

            $products = $this->createProducts($categories, $attributeIds);

            if (count($products) !== $expectedProductCount) {
                throw new \RuntimeException('Expected '.$expectedProductCount.' fragrance demo products, created '.count($products).'.');
            }

            app(Flat::class)->reindexRows($products);
            app(Price::class)->reindexRows($products);
            app(Inventory::class)->reindexRows($products);
            app(ElasticSearch::class)->reindexRows($products);

            $this->updateChannelSeo();
        });

                $this->command?->info('Nexora fragrance and beauty demo products were added without deleting the existing catalog.');
    }

    /**
     * Create only missing fragrance and beauty categories under the current root category.
     *
     * @return array<string, int>
     */
    private function createCategories(): array
    {
        $rootId = (int) core()->getCurrentChannel()->root_category_id;

        $tree = [
            'men' => ['parent' => $rootId, 'ar' => 'عطور رجالية', 'en' => 'Fragrance for Men', 'children' => [
                'men_fresh' => ['ar' => 'عطور رجالية منعشة', 'en' => 'Fresh & Aquatic'],
                'men_woody' => ['ar' => 'عطور خشبية وشرقية', 'en' => 'Woody & Oriental'],
                'men_grooming' => ['ar' => 'عناية الرجل ومجموعات الهدايا', 'en' => 'Men’s Grooming & Gift Sets'],
            ]],
            'women' => ['parent' => $rootId, 'ar' => 'عطور نسائية', 'en' => 'Fragrance for Women', 'children' => [
                'women_floral' => ['ar' => 'عطور زهرية', 'en' => 'Floral Fragrance'],
                'women_oriental' => ['ar' => 'عطور شرقية', 'en' => 'Oriental Fragrance'],
                'women_sets' => ['ar' => 'مجموعات الهدايا النسائية', 'en' => 'Women’s Gift Sets'],
            ]],
            'unisex' => ['parent' => $rootId, 'ar' => 'عطور للجنسين', 'en' => 'Unisex Fragrance', 'children' => [
                'unisex_sets' => ['ar' => 'اكتشاف العطور ومجموعات العينات', 'en' => 'Discovery Sets & Samples'],
            ]],
            'beauty' => ['parent' => $rootId, 'ar' => 'الجمال والعناية', 'en' => 'Beauty & Care', 'children' => [
                'skincare' => ['ar' => 'العناية بالبشرة', 'en' => 'Skincare'],
                'cleansers' => ['ar' => 'منظفات الوجه والتونر', 'en' => 'Cleansers & Toners'],
                'serums' => ['ar' => 'السيرومات والمرطبات', 'en' => 'Serums & Moisturizers'],
                'makeup' => ['ar' => 'المكياج ومستحضرات التجميل', 'en' => 'Makeup & Cosmetics'],
                'tools' => ['ar' => 'فرش وأدوات التجميل', 'en' => 'Beauty Brushes & Tools'],
            ]],
        ];

        $this->ensureDemoAttributes();

        $result = [];
        $position = 0;
        $parentIds = [];
        $repository = app(CategoryRepository::class);

        if (DB::table('locales')->where('code', 'ar')->exists()) {
            DB::table('channel_translations')->updateOrInsert(
                ['channel_id' => (int) core()->getDefaultChannel()->id, 'locale' => 'ar'],
                [
                    'name' => 'نيكسورا للعطور والجمال',
                    'description' => 'عطور رجالية ونسائية ومنتجات جمال وعناية — مجموعة تجريبية للعرض التسويقي.',
                    'home_seo' => json_encode([
                        'meta_title' => 'نيكسورا | عطور رجالية ونسائية ومستحضرات تجميل',
                        'meta_description' => 'اكتشف عطور نيكسورا الرجالية والنسائية ومنتجات الجمال والعناية التجريبية.',
                        'meta_keywords' => 'عطور رجالية، عطور نسائية، مكياج، عناية بالبشرة، نيكسورا',
                    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                    'created_at' => now(),
                    'updated_at' => now(),
                ]
            );
        }

        foreach ($tree as $key => $node) {
            $result[$key] = $this->findOrCreateCategory($repository, $key, (int) $node['parent'], $node, ++$position);
            $parentIds[$key] = $result[$key];

            foreach ($node['children'] as $childKey => $child) {
                $result[$childKey] = $this->findOrCreateCategory($repository, $childKey, $parentIds[$key], $child, ++$position);
            }
        }

        return $result;
    }

    /**
     * Add displayable, non-filterable text attributes and family-group mappings.
     */
    private function ensureDemoAttributes(): void
    {
        $definitions = [
            'audience_demo' => ['السمات', 'الفئة التجريبية'],
            'scent_family_demo' => ['السمات', 'عائلة العطر'],
            'volume_demo' => ['السمات', 'الحجم أو الدرجة'],
            'scent_notes_demo' => ['الوصف', 'النفحات التوضيحية'],
            'ingredients_demo' => ['الوصف', 'المكونات التوضيحية'],
            'usage_demo' => ['الوصف', 'طريقة الاستخدام'],
            'brand_label_demo' => ['السمات', 'العلامة التجريبية'],
            'demo_disclosure' => ['الوصف', 'تنويه تجريبي'],
        ];

        $groupIds = DB::table('attribute_groups')->where('attribute_family_id', 1)->pluck('id');

        if ($groupIds->isEmpty()) {
            throw new \RuntimeException('The default product family has no attribute group.');
        }

        foreach ($definitions as $code => [$preferredGroupName, $label]) {
            $attributeId = (int) DB::table('attributes')->where('code', $code)->value('id');

            if (! $attributeId) {
                $attributeId = (int) DB::table('attributes')->insertGetId([
                    'code' => $code,
                    'admin_name' => $label,
                    'type' => 'text',
                    'position' => 100 + count($definitions),
                    'is_required' => 0,
                    'is_unique' => 0,
                    'is_filterable' => 0,
                    'is_comparable' => 0,
                    'is_configurable' => 0,
                    'is_user_defined' => 1,
                    'is_visible_on_front' => 1,
                    'value_per_locale' => 0,
                    'value_per_channel' => 0,
                    'enable_wysiwyg' => 0,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);

                if (DB::table('locales')->where('code', 'ar')->exists()) {
                    DB::table('attribute_translations')->insert([
                        'attribute_id' => $attributeId,
                        'locale' => 'ar',
                        'name' => $label,
                    ]);
                }
            }

            if (! DB::table('attribute_group_mappings')->where('attribute_id', $attributeId)->whereIn('attribute_group_id', $groupIds)->exists()) {
                $groupId = DB::table('attribute_groups')
                    ->where('attribute_family_id', 1)
                    ->where('name', $preferredGroupName)
                    ->value('id') ?? $groupIds->first();

                DB::table('attribute_group_mappings')->insert([
                    'attribute_id' => $attributeId,
                    'attribute_group_id' => $groupId,
                    'position' => 80 + count($definitions),
                ]);
            }
        }
    }

    /**
     * Ensure the built-in brand field and inventory source are available before catalog writes.
     */
    private function assertEligibleProductSlots(int $expectedProductCount): void
    {
        if (! DB::table('attribute_families')->where('code', 'default')->exists()) {
            throw new \RuntimeException('The default Bagisto product family is missing.');
        }

        if (! DB::table('attributes')->where('code', 'brand')->where('type', 'select')->exists()) {
            throw new \RuntimeException('Bagisto’s built-in brand select attribute is not configured.');
        }

            if (! DB::table('inventory_sources')->where('status', 1)->exists()) {
            throw new \RuntimeException('No active Bagisto inventory source is available.');
        }

        if (! DB::table('channel_locales')->where('channel_id', core()->getDefaultChannel()->id)->exists()) {
            throw new \RuntimeException('The Nexora channel has no enabled storefront locale.');
        }

        $plannedSkus = collect(range(1, $expectedProductCount))
            ->map(fn ($index) => 'NX-FR-'.str_pad((string) $index, 3, '0', STR_PAD_LEFT))
            ->all();

        if (DB::table('products')->whereIn('sku', $plannedSkus)->exists()) {
            throw new \RuntimeException('At least one Nexora demo SKU already exists; inspect existing demo items before rerunning.');
        }

        if (! DB::table('product_flat')->where('product_id', 1)->where('locale', 'ar')->exists()) {
            throw new \RuntimeException('An Arabic product-flat template row is unavailable. No catalog writes were made.');
        }
    }

    /**
     * Find a translated category or create one using the native category repository.
     *
     * @param  array<string, mixed>  $node
     */
    private function findOrCreateCategory(CategoryRepository $repository, string $key, int $parentId, array $node, int $position): int
    {
        $slug = Str::slug($key.'-'.$node['en']);
        $existingTranslation = DB::table('category_translations')
            ->where('slug', $slug)
            ->where('locale', app()->getLocale())
            ->first();

        $existing = $existingTranslation
            ? $repository->find($existingTranslation->category_id)
            : null;

        if ($existing) {
            if (! $existing->status) {
                $repository->update(['status' => 1], $existing->id);
            }

            return (int) $existing->id;
        }

        $translations = [
            'ar' => [
                'name' => $node['ar'],
                'slug' => $slug,
                'description' => '<p>'.$node['ar'].' — مجموعة تجريبية ضمن متجر نيكسورا للعطور والجمال.</p>',
                'meta_title' => $node['ar'].' | نيكسورا للعطور والجمال',
                'meta_description' => 'تسوّق '.$node['ar'].' ضمن تشكيلة نيكسورا التجريبية.',
                'meta_keywords' => 'عطور، جمال، '.$node['ar'],
            ],
            'en' => [
                'name' => $node['en'],
                'slug' => $slug.'-en',
                'description' => '<p>'.$node['en'].' — demo products in the Nexora fragrance and beauty collection.</p>',
                'meta_title' => $node['en'].' | Nexora Fragrance & Beauty',
                'meta_description' => 'Explore '.$node['en'].' in the Nexora fragrance and beauty demo.',
                'meta_keywords' => 'fragrance, beauty, '.$node['en'],
            ],
        ];

        $data = [
            'position' => $position,
            'status' => 1,
            'display_mode' => 'products_and_description',
            'parent_id' => $parentId,
            'ar' => $translations['ar'],
        ];

        return (int) $repository->create($data)->id;
    }

    /**
     * Create the bilingual demo collection using Bagisto's product repository and indexers.
     *
     * @param  array<string, int>  $categories
     * @return array<int, \Webkul\Product\Contracts\Product>
     */
    private function createProducts(array $categories, array $attributeIds): array
    {
        $plans = $this->productPlans();
        $attributeRepository = app(AttributeRepository::class);
        $productRepository = app(ProductRepository::class);
        $imageRepository = app(ProductImageRepository::class);
        $family = DB::table('attribute_families')->where('code', 'default')->firstOrFail();
        $attributes = $attributeRepository->all()->keyBy('code');
        $brandOptions = $this->brandOptions();
        $products = [];

        foreach ($plans as $index => $plan) {
            $sku = 'NX-FR-'.str_pad((string) ($index + 1), 3, '0', STR_PAD_LEFT);

            if (DB::table('products')->where('sku', $sku)->exists()) {
                throw new \RuntimeException('Unexpected existing Nexora demo SKU: '.$sku);
            }

            $productData = $this->productData($plan, $sku, (int) $family->id, $attributes, $brandOptions, $attributeIds);
            $product = DB::transaction(fn () => $productRepository->create($productData));
            $product->categories()->sync($plan['categories'] ? array_map(fn ($key) => $categories[$key], $plan['categories']) : [$categories[$plan['category']]]);
            $product->refresh();
            $this->createProductMedia($product, $imageRepository, $plan);

            $products[] = $product;
        }

        return $products;
    }

    /**
     * Build the attributes and inventory payload Bagisto expects for a simple product.
     *
     * @param  array<string, mixed>  $plan
     * @param  \Illuminate\Support\Collection<string, \Webkul\Attribute\Contracts\Attribute>  $attributes
     * @param  array<string, int>  $brandOptions
     * @param  array<string, int>  $beautyAttributes
     * @return array<string, mixed>
     */
    private function productData(array $plan, string $sku, int $familyId, $attributes, array $brandOptions, array $beautyAttributes): array
    {
        $brandOptions[$plan['brand']] ??= $this->createDemoBrandOption($plan['brand']);
        $text = [
            'type' => 'simple',
            'attribute_family_id' => $familyId,
            'sku' => $sku,
            'name' => $plan['ar'],
            'url_key' => $sku.'-demo',
            'product_number' => 'DEMO-'.$sku,
            'tax_category_id' => null,
            'new' => 1,
            'featured' => 1,
            'visible_individually' => 1,
            'status' => 1,
            'short_description' => $plan['description_ar'],
            'description' => $this->description($plan, true),
            'price' => $plan['price'],
            'cost' => $plan['price'],
            'special_price' => null,
            'special_price_from' => null,
            'special_price_to' => null,
            'length' => 10,
            'width' => 10,
            'height' => 16,
            'weight' => 0.2,
            'manage_stock' => 0,
            'allow_backorders' => 1,
            'meta_title' => $plan['ar'].' | نيكسورا للعطور والجمال',
            'meta_keywords' => 'عطر تجريبي، عطور رجالية، عطور نسائية، نيكسورا',
            'meta_description' => $plan['description_ar'],
            'brand' => $brandOptions[$plan['brand']],
            'inventories' => [
                0 => 100,
            ],
            'channels' => [(int) core()->getDefaultChannel()->id],
            'categories' => [],
        ];

        $values = [
            'audience_demo' => $plan['audience_ar'],
            'scent_family_demo' => $plan['family_ar'],
            'volume_demo' => $plan['volume'],
            'scent_notes_demo' => $plan['notes_ar'],
            'ingredients_demo' => $plan['ingredients_ar'],
            'usage_demo' => $plan['usage_ar'],
            'brand_label_demo' => $plan['brand_ar'],
            'demo_disclosure' => 'منتج تجريبي للعرض فقط؛ ليس منتجًا أصليًا ولا مخزونًا تجاريًا متاحًا.',
        ];

            if (core()->getCurrentChannel()->locales()->contains('code', 'en')) {
                $text['en'] = [
                    'name' => $plan['name'],
                    'url_key' => $sku.'-demo',
                    'short_description' => $plan['description_en'],
                    'description' => $this->description($plan, false),
                    'meta_title' => $plan['name'].' | Nexora Fragrance & Beauty',
                    'meta_keywords' => 'demo fragrance, men perfume, women perfume, beauty, Nexora',
                    'meta_description' => $plan['description_en'],
                ];
            }

        foreach (['audience_demo', 'scent_family_demo', 'volume_demo', 'scent_notes_demo', 'ingredients_demo', 'usage_demo', 'brand_label_demo', 'demo_disclosure'] as $code) {
            $attribute = DB::table('attributes')->where('code', $code)->first();

            if (! $attribute) {
                continue;
            }

            $groupIds = DB::table('attribute_groups')->where('attribute_family_id', $familyId)->pluck('id');

            if (DB::table('attribute_group_mappings')->where('attribute_id', $attribute->id)->whereIn('attribute_group_id', $groupIds)->exists()) {
                $text[$code] = $values[$code] ?? null;
            }
        }

        $text['type'] = 'simple';
        $text['attribute_family_id'] = $familyId;
        $text['sku'] = $sku;
        $text['brand'] = $brandOptions[$plan['brand']];
        $text['price'] = $plan['price'];
        $text['cost'] = $plan['price'];
        $text['weight'] = 0.2;
        $text['manage_stock'] = 0;
        $text['status'] = 1;
        $text['featured'] = 1;
        $text['new'] = 1;
        $text['visible_individually'] = 1;
        $text['inventories'] = [['inventory_source_id' => (int) DB::table('inventory_sources')->where('status', 1)->value('id'), 'qty' => 100]];
        $text['channels'] = [(int) core()->getDefaultChannel()->id];
        $text['categories'] = [];

        return $text;
    }

    /**
     * Build a bilingual product detail panel with an explicit demo disclosure.
     *
     * @param  array<string, mixed>  $plan
     */
    private function description(array $plan, bool $arabic): string
    {
        $items = $arabic
            ? [
                $plan['description_ar'],
                'العلامة التجريبية: '.$plan['brand_ar'].'.',
                'الحجم: '.$plan['volume'].'. عائلة العطر: '.$plan['family_ar'].'.',
                'النفحات التوضيحية: '.$plan['notes_ar'].'. المكونات التوضيحية: '.$plan['ingredients_ar'].'.',
                'طريقة الاستخدام: '.$plan['usage_ar'],
                'إصدار تجريبي للعرض التسويقي فقط؛ لا يُدّعى أنه عطر أو منتج تجميل أصلي متوفر للبيع.',
            ]
            : [
                $plan['description_en'],
                'Demo brand: '.$plan['brand'].'.',
                'Volume: '.$plan['volume'].'. Fragrance family: '.$plan['family'].'.',
                'Illustrative scent notes: '.$plan['notes'].'. Illustrative ingredients: '.$plan['ingredients'].'.',
                'Usage: '.$plan['usage_en'],
                'Demo presentation only; this is not represented as authentic branded merchandise or commercially available stock.',
            ];

        return '<ul><li>'.implode('</li><li>', array_map('e', $items)).'</li></ul>';
    }

    /**
     * Create distinct product media through Bagisto's media repository.
     *
     * @param  array<string, mixed>  $plan
     */
    private function createProductMedia($product, ProductImageRepository $imageRepository, array $plan): void
    {
        $sourcePath = public_path('themes/shop/nexora/'.$plan['image']);

        if (! is_file($sourcePath)) {
            throw new \RuntimeException('Missing local Nexora product artwork: '.$sourcePath);
        }

        $imageRepository->upload([
            'images' => [
                'files' => [new UploadedFile($sourcePath, basename($sourcePath), 'image/svg+xml', null, true)],
                'meta' => [
                    0 => [
                        'file_name' => Str::slug($plan['name']).'-demo',
                        'alt_text' => $plan['ar'].' — صورة توضيحية لمنتج تجريبي',
                    ],
                ],
            ],
        ], $product, 'images');
    }

    /**
     * Get built-in brand option IDs or create clearly labeled demo-only brands.
     *
     * @return array<string, int>
     */
    private function brandOptions(): array
    {
        return [];
    }

    /**
     * Update homepage search copy and metadata for the Arabic channel.
     */
    private function updateChannelSeo(): void
    {
        DB::table('channel_translations')->where('channel_id', 1)->where('locale', 'ar')->update([
            'name' => 'نيكسورا للعطور والجمال',
            'description' => 'عطور رجالية ونسائية ومنتجات جمال وعناية — مجموعة تجريبية للعرض التسويقي.',
            'home_seo' => json_encode([
                'meta_title' => 'نيكسورا | عطور رجالية ونسائية ومستحضرات تجميل',
                'meta_description' => 'اكتشف مجموعة نيكسورا التجريبية من العطور الرجالية والنسائية والمكياج والعناية بالبشرة.',
                'meta_keywords' => 'عطور رجالية، عطور نسائية، عطور، مكياج، عناية بالبشرة، نيكسورا',
            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'updated_at' => now(),
        ]);
    }

    /**
     * Define the Nexora men’s fragrance and beauty demo assortment.
     *
     * @return array<int, array<string, mixed>>
     */
    private function productPlans(): array
    {
        return [
            ['name' => 'Cedar Noir Eau de Parfum', 'ar' => 'سيدر نوار أو دو بارفان', 'description_en' => 'A sophisticated demo eau de parfum with crisp bergamot, lavender and warm cedar.', 'description_ar' => 'عطر تجريبي أنيق بنفحات البرغموت واللافندر وخشب الأرز الدافئ.', 'price' => 249, 'brand' => 'Nexora Studio', 'brand_ar' => 'نيكسورا ستوديو — تجريبي', 'family' => 'Citrus / Woody', 'family_ar' => 'حمضي وخشبي', 'volume' => '100 ml', 'notes' => 'Bergamot, lavender, cedar', 'notes_ar' => 'برغموت، لافندر، أرز', 'ingredients' => 'illustrative fragrance accords and perfumer’s alcohol', 'ingredients_ar' => 'نفحات عطرية توضيحية وكحول عطري', 'usage_en' => 'Spray lightly on pulse points from a comfortable distance.', 'usage_ar' => 'يرش برفق على نقاط النبض من مسافة مناسبة.', 'audience_ar' => 'رجالي', 'category' => 'men_fresh', 'image' => 'hero-still-life.svg'],
            ['name' => 'Oud & Amber Reserve', 'ar' => 'عود وعنبر ريزيرف', 'description_en' => 'An opulent demo fragrance blending smooth oud with warm amber and rich woods.', 'description_ar' => 'عطر تجريبي فاخر يجمع العود الناعم والعنبر الدافئ والأخشاب الغنية.', 'price' => 329, 'brand' => 'Nexora Atelier', 'brand_ar' => 'نيكسورا أتيليه — تجريبي', 'family' => 'Oud / Amber', 'family_ar' => 'عود وعنبر', 'volume' => '100 ml', 'notes' => 'Oud, amber, patchouli', 'notes_ar' => 'عود، عنبر، باتشولي', 'ingredients' => 'illustrative oud, amber and woody fragrance accords', 'ingredients_ar' => 'نفحات عود وعنبر وخشب توضيحية', 'usage_en' => 'Apply lightly to pulse points and allow the scent to settle.', 'usage_ar' => 'يوضع برفق على نقاط النبض ويترك حتى يستقر العطر.', 'audience_ar' => 'رجالي', 'category' => 'men_woody', 'image' => 'hero-still-life.svg'],
            ['name' => 'Coastal Blue Eau de Parfum', 'ar' => 'كوستال بلو أو دو بارفان', 'description_en' => 'A refreshing demo scent of mandarin, marine notes and clean musk.', 'description_ar' => 'عطر تجريبي منعش بنفحات اليوسفي والطابع البحري والمسك النظيف.', 'price' => 219, 'brand' => 'Nexora Studio', 'brand_ar' => 'نيكسورا ستوديو — تجريبي', 'family' => 'Aquatic / Citrus', 'family_ar' => 'مائي وحمضي', 'volume' => '100 ml', 'notes' => 'Mandarin, marine accord, musk', 'notes_ar' => 'يوسفي، نفحات بحرية، مسك', 'ingredients' => 'illustrative citrus and marine fragrance accords', 'ingredients_ar' => 'نفحات حمضية وبحرية توضيحية', 'usage_en' => 'Mist onto pulse points as desired.', 'usage_ar' => 'يرش على نقاط النبض حسب الرغبة.', 'audience_ar' => 'رجالي', 'category' => 'men_fresh', 'image' => 'hero-still-life.svg'],
            ['name' => 'Cedar Reserve for Him', 'ar' => 'سيدر ريزيرف للرجال', 'description_en' => 'A warm, polished demo fragrance with cardamom, cedar and soft vetiver.', 'description_ar' => 'عطر تجريبي دافئ وأنيق بالهيل والأرز ونجيل الهند الناعم.', 'price' => 289, 'brand' => 'Nexora Atelier', 'brand_ar' => 'نيكسورا أتيليه — تجريبي', 'family' => 'Spicy / Woody', 'family_ar' => 'متبل وخشبي', 'volume' => '100 ml', 'notes' => 'Cardamom, cedar, vetiver', 'notes_ar' => 'هيل، أرز، نجيل الهند', 'ingredients' => 'illustrative cardamom and cedar fragrance accords', 'ingredients_ar' => 'نفحات هيل وأرز عطرية توضيحية', 'usage_en' => 'Apply to pulse points and refresh as desired.', 'usage_ar' => 'يوضع على نقاط النبض ويجدد حسب الرغبة.', 'audience_ar' => 'رجالي', 'category' => 'men_woody', 'image' => 'hero-still-life.svg'],
            ['name' => 'Urban Musk for Him', 'ar' => 'أوربان مسك للرجال', 'description_en' => 'A versatile demo scent with ginger, soft woods and modern musk.', 'description_ar' => 'عطر تجريبي متعدد الاستخدامات بالزنجبيل والأخشاب الناعمة والمسك العصري.', 'price' => 199, 'brand' => 'Nexora Studio', 'brand_ar' => 'نيكسورا ستوديو — تجريبي', 'family' => 'Musk / Woody', 'family_ar' => 'مسك وخشب', 'volume' => '75 ml', 'notes' => 'Ginger, sandalwood, musk', 'notes_ar' => 'زنجبيل، صندل، مسك', 'ingredients' => 'illustrative ginger and soft wood fragrance accords', 'ingredients_ar' => 'نفحات زنجبيل وأخشاب ناعمة توضيحية', 'usage_en' => 'Mist sparingly over pulse points.', 'usage_ar' => 'يرش باعتدال على نقاط النبض.', 'audience_ar' => 'رجالي', 'category' => 'men_woody', 'image' => 'hero-still-life.svg'],
            ['name' => 'Classic Grooming Duo', 'ar' => 'طقم العناية الكلاسيكي للرجال', 'description_en' => 'A demo men’s grooming set with a fresh scent and a lightweight after-shave balm concept.', 'description_ar' => 'طقم عناية رجالي تجريبي برائحة منعشة وفكرة بلسم خفيف لما بعد الحلاقة.', 'price' => 179, 'brand' => 'Nexora Studio', 'brand_ar' => 'نيكسورا ستوديو — تجريبي', 'family' => 'Fresh / Aromatic', 'family_ar' => 'منعش وعطري', 'volume' => '2-piece set', 'notes' => 'Citrus, aromatic herbs, clean musk', 'notes_ar' => 'حمضيات، أعشاب عطرية، مسك نظيف', 'ingredients' => 'illustrative fragrance accord and demo cosmetic balm base', 'ingredients_ar' => 'نفحات عطرية توضيحية وقاعدة بلسم تجريبية', 'usage_en' => 'Apply the demo balm after shaving; spray fragrance lightly.', 'usage_ar' => 'يستخدم البلسم التجريبي بعد الحلاقة ويرش العطر برفق.', 'audience_ar' => 'رجالي', 'category' => 'men_grooming', 'image' => 'hero-still-life.svg'],
            ['name' => 'Velvet Rose Eau de Parfum', 'ar' => 'فيلفت روز أو دو بارفان', 'description_en' => 'A graceful demo floral fragrance with rose, pear and soft musk.', 'description_ar' => 'عطر زهري تجريبي أنيق بالورد والكمثرى والمسك الناعم.', 'price' => 279, 'brand' => 'Nexora Atelier', 'brand_ar' => 'نيكسورا أتيليه — تجريبي', 'family' => 'Floral / Fruity', 'family_ar' => 'زهري وفاكهي', 'volume' => '100 ml', 'notes' => 'Rose, pear, musk', 'notes_ar' => 'ورد، كمثرى، مسك', 'ingredients' => 'illustrative rose and pear fragrance accords', 'ingredients_ar' => 'نفحات ورد وكمثرى عطرية توضيحية', 'usage_en' => 'Apply to pulse points and let the fragrance settle.', 'usage_ar' => 'يوضع على نقاط النبض ويترك حتى يستقر العطر.', 'audience_ar' => 'نسائي', 'category' => 'women_floral', 'image' => 'hero-still-life.svg'],
            ['name' => 'Golden Amber for Her', 'ar' => 'غولدن أمبر للنساء', 'description_en' => 'A warm demo scent combining amber, vanilla and delicate florals.', 'description_ar' => 'عطر تجريبي دافئ يجمع العنبر والفانيليا والزهور الرقيقة.', 'price' => 299, 'brand' => 'Nexora Atelier', 'brand_ar' => 'نيكسورا أتيليه — تجريبي', 'family' => 'Amber / Floral', 'family_ar' => 'عنبر وزهور', 'volume' => '100 ml', 'notes' => 'Amber, vanilla, jasmine', 'notes_ar' => 'عنبر، فانيليا، ياسمين', 'ingredients' => 'illustrative amber and floral fragrance accords', 'ingredients_ar' => 'نفحات عنبر وزهور توضيحية', 'usage_en' => 'Mist onto pulse points from a comfortable distance.', 'usage_ar' => 'يرش على نقاط النبض من مسافة مناسبة.', 'audience_ar' => 'نسائي', 'category' => 'women_oriental', 'image' => 'hero-still-life.svg'],
            ['name' => 'Petal Mist Eau de Parfum', 'ar' => 'بيتل ميست أو دو بارفان', 'description_en' => 'A light demo floral fragrance of peony, white blooms and airy musk.', 'description_ar' => 'عطر زهري تجريبي خفيف بالفاوانيا والزهور البيضاء والمسك الهوائي.', 'price' => 189, 'brand' => 'Nexora Studio', 'brand_ar' => 'نيكسورا ستوديو — تجريبي', 'family' => 'Soft Floral', 'family_ar' => 'زهري ناعم', 'volume' => '75 ml', 'notes' => 'Peony, white florals, musk', 'notes_ar' => 'فاوانيا، زهور بيضاء، مسك', 'ingredients' => 'illustrative peony and white floral accords', 'ingredients_ar' => 'نفحات فاوانيا وزهور بيضاء توضيحية', 'usage_en' => 'Spray lightly on pulse points.', 'usage_ar' => 'يرش برفق على نقاط النبض.', 'audience_ar' => 'نسائي', 'category' => 'women_floral', 'image' => 'hero-still-life.svg'],
            ['name' => 'Velvet Oud for Her', 'ar' => 'فيلفت عود للنساء', 'description_en' => 'A modern demo oriental fragrance pairing rose with smooth oud and amber.', 'description_ar' => 'عطر شرقي تجريبي عصري يمزج الورد بالعود الناعم والعنبر.', 'price' => 319, 'brand' => 'Nexora Atelier', 'brand_ar' => 'نيكسورا أتيليه — تجريبي', 'family' => 'Oud / Floral', 'family_ar' => 'عود وزهور', 'volume' => '100 ml', 'notes' => 'Rose, oud, amber', 'notes_ar' => 'ورد، عود، عنبر', 'ingredients' => 'illustrative rose, oud and amber accords', 'ingredients_ar' => 'نفحات ورد وعود وعنبر توضيحية', 'usage_en' => 'Apply sparingly to pulse points.', 'usage_ar' => 'يوضع باعتدال على نقاط النبض.', 'audience_ar' => 'نسائي', 'category' => 'women_oriental', 'image' => 'hero-still-life.svg'],
            ['name' => 'Luminous Bloom Eau de Parfum', 'ar' => 'لومينوس بلوم أو دو بارفان', 'description_en' => 'A bright demo floral perfume with citrus blossom, neroli and light woods.', 'description_ar' => 'عطر زهري تجريبي مشرق بزهور الحمضيات والنيرولي والأخشاب الخفيفة.', 'price' => 259, 'brand' => 'Nexora Studio', 'brand_ar' => 'نيكسورا ستوديو — تجريبي', 'family' => 'Floral / Citrus', 'family_ar' => 'زهري وحمضي', 'volume' => '100 ml', 'notes' => 'Citrus blossom, neroli, woods', 'notes_ar' => 'زهر الحمضيات، نيرولي، أخشاب', 'ingredients' => 'illustrative citrus blossom and neroli accords', 'ingredients_ar' => 'نفحات زهر حمضيات ونيرولي توضيحية', 'usage_en' => 'Spray onto pulse points for a soft floral trail.', 'usage_ar' => 'يرش على نقاط النبض لأثر زهري ناعم.', 'audience_ar' => 'نسائي', 'category' => 'women_floral', 'image' => 'hero-still-life.svg'],
            ['name' => 'Radiance Serum', 'ar' => 'سيروم راديانس', 'description_en' => 'A demo lightweight serum concept with a hydrating cosmetic base.', 'description_ar' => 'فكرة سيروم تجريبية خفيفة بقاعدة تجميلية مرطبة.', 'price' => 89, 'brand' => 'Nexora Beauty Lab', 'brand_ar' => 'نيكسورا بيوتي لاب — تجريبي', 'family' => 'Skincare', 'family_ar' => 'عناية بالبشرة', 'volume' => '30 ml', 'notes' => 'Hydration, radiance', 'notes_ar' => 'ترطيب وإشراقة', 'ingredients' => 'demo cosmetic base and illustrative humectants', 'ingredients_ar' => 'قاعدة تجميلية تجريبية ومرطبات توضيحية', 'usage_en' => 'Apply a small amount to clean skin; patch test any real formulation.', 'usage_ar' => 'توضع كمية صغيرة على بشرة نظيفة؛ يختبر أي مستحضر فعلي على مساحة صغيرة.', 'audience_ar' => 'جمال', 'category' => 'serums', 'image' => 'hero-skincare.svg'],
            ['name' => 'Daily Moisture Cream', 'ar' => 'كريم الترطيب اليومي', 'description_en' => 'A demo everyday moisturizer concept with a soft cream texture.', 'description_ar' => 'فكرة مرطب يومي تجريبية بقوام كريم ناعم.', 'price' => 79, 'brand' => 'Nexora Beauty Lab', 'brand_ar' => 'نيكسورا بيوتي لاب — تجريبي', 'family' => 'Skincare', 'family_ar' => 'عناية بالبشرة', 'volume' => '50 ml', 'notes' => 'Moisture, comfort', 'notes_ar' => 'ترطيب وراحة', 'ingredients' => 'demo cream base and illustrative emollients', 'ingredients_ar' => 'قاعدة كريم تجريبية وملينات توضيحية', 'usage_en' => 'Apply to clean skin as desired; demo concept only.', 'usage_ar' => 'يوضع على بشرة نظيفة حسب الرغبة؛ فكرة تجريبية فقط.', 'audience_ar' => 'جمال', 'category' => 'skincare', 'image' => 'hero-skincare.svg'],
            ['name' => 'Gentle Face Cleanser', 'ar' => 'غسول الوجه اللطيف', 'description_en' => 'A gentle demo cleanser concept for a simple daily routine.', 'description_ar' => 'فكرة غسول لطيف تجريبية لروتين يومي بسيط.', 'price' => 59, 'brand' => 'Nexora Beauty Lab', 'brand_ar' => 'نيكسورا بيوتي لاب — تجريبي', 'family' => 'Cleanser', 'family_ar' => 'منظف وجه', 'volume' => '150 ml', 'notes' => 'Gentle cleanse, daily care', 'notes_ar' => 'تنظيف لطيف وعناية يومية', 'ingredients' => 'demo cleanser base and illustrative mild surfactants', 'ingredients_ar' => 'قاعدة منظف تجريبية ومواد تنظيف لطيفة توضيحية', 'usage_en' => 'Massage onto damp skin and rinse; verify real ingredients before use.', 'usage_ar' => 'يدلك على بشرة رطبة ثم يشطف؛ تراجع مكونات أي منتج فعلي قبل الاستخدام.', 'audience_ar' => 'جمال', 'category' => 'cleansers', 'image' => 'hero-skincare.svg'],
            ['name' => 'Daily Sun Shield Display Demo', 'ar' => 'واقي الشمس اليومي — عينة تجريبية', 'description_en' => 'A display-only sunscreen concept; no verified SPF or protection claim is made.', 'description_ar' => 'فكرة واقٍ شمسي للعرض فقط؛ لا توجد ادعاءات مؤكدة عن SPF أو الحماية.', 'price' => 69, 'brand' => 'Nexora Beauty Lab', 'brand_ar' => 'نيكسورا بيوتي لاب — تجريبي', 'family' => 'Skincare', 'family_ar' => 'عناية بالبشرة', 'volume' => '50 ml', 'notes' => 'Daily care display concept', 'notes_ar' => 'فكرة عناية يومية للعرض', 'ingredients' => 'display concept; sun filters and SPF unverified', 'ingredients_ar' => 'فكرة للعرض؛ فلاتر الشمس ومعامل الحماية غير موثقين', 'usage_en' => 'Display concept only; never rely on this item for sun protection.', 'usage_ar' => 'للعرض فقط؛ لا يعتمد عليه للحماية من الشمس.', 'audience_ar' => 'جمال', 'category' => 'skincare', 'image' => 'hero-skincare.svg'],
            ['name' => 'Rosewater Face Toner', 'ar' => 'تونر ماء الورد', 'description_en' => 'A demo toner concept with a light, refreshing feel.', 'description_ar' => 'فكرة تونر تجريبية بلمسة خفيفة ومنعشة.', 'price' => 55, 'brand' => 'Nexora Beauty Lab', 'brand_ar' => 'نيكسورا بيوتي لاب — تجريبي', 'family' => 'Skincare', 'family_ar' => 'عناية بالبشرة', 'volume' => '120 ml', 'notes' => 'Refresh, daily routine', 'notes_ar' => 'انتعاش وروتين يومي', 'ingredients' => 'demo toner base and illustrative humectants', 'ingredients_ar' => 'قاعدة تونر تجريبية ومرطبات توضيحية', 'usage_en' => 'Apply with clean hands or a cotton pad; demo concept only.', 'usage_ar' => 'يوضع بأيدٍ نظيفة أو بقطنة؛ فكرة تجريبية فقط.', 'audience_ar' => 'جمال', 'category' => 'cleansers', 'image' => 'hero-skincare.svg'],
            ['name' => 'Soft Matte Foundation Demo', 'ar' => 'فاونديشن سوفت مات — تجريبي', 'description_en' => 'A display-demo foundation concept with an illustrative sample shade.', 'description_ar' => 'فكرة فاونديشن للعرض بدرجة لونية توضيحية.', 'price' => 99, 'brand' => 'Nexora Beauty Lab', 'brand_ar' => 'نيكسورا بيوتي لاب — تجريبي', 'family' => 'Makeup', 'family_ar' => 'مكياج', 'volume' => '30 ml', 'notes' => 'Face, soft matte', 'notes_ar' => 'الوجه، لمسة مطفية ناعمة', 'ingredients' => 'demo cosmetic base and sample pigments', 'ingredients_ar' => 'قاعدة تجميلية تجريبية وأصباغ عينة', 'usage_en' => 'Blend evenly; sample shade and formulation are illustrative.', 'usage_ar' => 'يمزج بالتساوي؛ الدرجة والتركيبة توضيحيتان.', 'audience_ar' => 'جمال', 'category' => 'makeup', 'image' => 'hero-skincare.svg'],
            ['name' => 'Velvet Lip Colour Demo', 'ar' => 'لون شفاه فيلفت — تجريبي', 'description_en' => 'A demo satin-finish lip colour concept in an illustrative berry shade.', 'description_ar' => 'فكرة لون شفاه تجريبية بلمسة ساتانية ودرجة توتي توضيحية.', 'price' => 49, 'brand' => 'Nexora Beauty Lab', 'brand_ar' => 'نيكسورا بيوتي لاب — تجريبي', 'family' => 'Makeup', 'family_ar' => 'مكياج', 'volume' => '3.5 g', 'notes' => 'Berry, satin finish', 'notes_ar' => 'توتي، لمسة ساتانية', 'ingredients' => 'demo lipstick base and illustrative pigments', 'ingredients_ar' => 'قاعدة أحمر شفاه تجريبية وأصباغ توضيحية', 'usage_en' => 'Apply evenly; shade and cosmetic formula are illustrative.', 'usage_ar' => 'يوضع بالتساوي؛ اللون والتركيبة تجريبيان.', 'audience_ar' => 'جمال', 'category' => 'makeup', 'image' => 'hero-skincare.svg'],
            ['name' => 'Everyday Blush Demo', 'ar' => 'بلاشر إيفريداي — تجريبي', 'description_en' => 'A demo powder blush concept in an illustrative soft rose tone.', 'description_ar' => 'فكرة بلاشر بودرة تجريبية بدرجة وردية ناعمة توضيحية.', 'price' => 45, 'brand' => 'Nexora Beauty Lab', 'brand_ar' => 'نيكسورا بيوتي لاب — تجريبي', 'family' => 'Makeup', 'family_ar' => 'مكياج', 'volume' => '6 g', 'notes' => 'Soft rose, powder', 'notes_ar' => 'وردي ناعم، بودرة', 'ingredients' => 'demo powder base and illustrative pigments', 'ingredients_ar' => 'قاعدة بودرة تجريبية وأصباغ توضيحية', 'usage_en' => 'Apply lightly with a clean brush; demo concept only.', 'usage_ar' => 'يوضع بخفة بفرشاة نظيفة؛ فكرة تجريبية فقط.', 'audience_ar' => 'جمال', 'category' => 'makeup', 'image' => 'hero-skincare.svg'],
            ['name' => 'Signature Makeup Brush Set', 'ar' => 'مجموعة فرش المكياج سيغنتشر', 'description_en' => 'A demo beauty-tool set with soft face and detail brushes.', 'description_ar' => 'مجموعة أدوات تجريبية بفرش ناعمة للوجه والتفاصيل.', 'price' => 89, 'brand' => 'Nexora Beauty Lab', 'brand_ar' => 'نيكسورا بيوتي لاب — تجريبي', 'family' => 'Beauty Tools', 'family_ar' => 'أدوات تجميل', 'volume' => '5-piece set', 'notes' => 'Face, eyes, blending', 'notes_ar' => 'الوجه، العيون، الدمج', 'ingredients' => 'demo synthetic fibres and reusable handles', 'ingredients_ar' => 'ألياف صناعية تجريبية ومقابض قابلة لإعادة الاستخدام', 'usage_en' => 'Clean regularly and allow to dry.', 'usage_ar' => 'تنظف بانتظام وتترك حتى تجف.', 'audience_ar' => 'أدوات تجميل', 'category' => 'tools', 'image' => 'hero-skincare.svg'],
            ['name' => 'Glow Sponge Duo', 'ar' => 'مجموعة إسفنجات غلو', 'description_en' => 'A demo pair of soft makeup sponges for blending creams and liquids.', 'description_ar' => 'زوج إسفنجات مكياج تجريبي ناعم لدمج الكريمات والسوائل.', 'price' => 35, 'brand' => 'Nexora Beauty Lab', 'brand_ar' => 'نيكسورا بيوتي لاب — تجريبي', 'family' => 'Beauty Tools', 'family_ar' => 'أدوات تجميل', 'volume' => '2-piece set', 'notes' => 'Blending, complexion', 'notes_ar' => 'دمج، مستحضرات الوجه', 'ingredients' => 'demo cosmetic sponge material', 'ingredients_ar' => 'إسفنج تجميلي تجريبي', 'usage_en' => 'Dampen before use and clean after use.', 'usage_ar' => 'يرطب قبل الاستخدام وينظف بعده.', 'audience_ar' => 'أدوات تجميل', 'category' => 'tools', 'image' => 'hero-skincare.svg'],
            ['name' => 'Unisex Amber Woods', 'ar' => 'أمبر وودز للجنسين', 'description_en' => 'A balanced demo fragrance with bright citrus, amber and soft woods.', 'description_ar' => 'عطر تجريبي متوازن بالحمضيات المشرقة والعنبر والأخشاب الناعمة.', 'price' => 239, 'brand' => 'Nexora Atelier', 'brand_ar' => 'نيكسورا أتيليه — تجريبي', 'family' => 'Citrus / Amber', 'family_ar' => 'حمضي وعنبر', 'volume' => '100 ml', 'notes' => 'Citrus, amber, soft woods', 'notes_ar' => 'حمضيات، عنبر، أخشاب ناعمة', 'ingredients' => 'illustrative citrus, amber and woody accords', 'ingredients_ar' => 'نفحات حمضيات وعنبر وأخشاب توضيحية', 'usage_en' => 'Apply to pulse points as desired.', 'usage_ar' => 'يوضع على نقاط النبض حسب الرغبة.', 'audience_ar' => 'للجنسين', 'category' => 'unisex', 'image' => 'hero-still-life.svg'],
            ['name' => 'Discovery Fragrance Set', 'ar' => 'طقم اكتشاف العطور', 'description_en' => 'A demo discovery set exploring fresh, floral, woody and amber fragrance styles.', 'description_ar' => 'طقم اكتشاف تجريبي لأساليب عطرية منعشة وزهرية وخشبية وعنبرية.', 'price' => 119, 'brand' => 'Nexora Atelier', 'brand_ar' => 'نيكسورا أتيليه — تجريبي', 'family' => 'Discovery Set', 'family_ar' => 'مجموعة اكتشاف', 'volume' => '4 x 10 ml', 'notes' => 'Fresh, floral, woody, amber', 'notes_ar' => 'منعش، زهري، خشبي، عنبري', 'ingredients' => 'four illustrative fragrance accords', 'ingredients_ar' => 'أربع نفحات عطرية توضيحية', 'usage_en' => 'Try each scent on a separate blotter.', 'usage_ar' => 'تجرب كل رائحة على ورقة عطرية منفصلة.', 'audience_ar' => 'للجميع', 'category' => 'sets', 'image' => 'hero-still-life.svg'],
        ];
    }

    /**
     * Configure existing apparel attribute rows from an already saved demo product as display-only references.
     *
     * @return array<string, int>
     */
    private function createBeautyAttributes(): array
    {
        $codes = [
            'beauty_audience_demo' => ['audience_demo', 'Demo audience', 'الفئة التجريبية'],
            'beauty_family_demo' => ['scent_family_demo', 'Demo fragrance family', 'العائلة العطرية التجريبية'],
            'beauty_volume_demo' => ['volume_demo', 'Demo volume or shade', 'الحجم أو الدرجة التجريبية'],
            'beauty_notes_demo' => ['scent_notes_demo', 'Illustrative notes', 'النفحات التوضيحية'],
            'beauty_ingredients_demo' => ['ingredients_demo', 'Illustrative ingredients', 'المكونات التوضيحية'],
            'beauty_usage_demo' => ['usage_demo', 'Demo usage', 'طريقة الاستخدام التجريبية'],
            'beauty_brand_label_demo' => ['brand_label_demo', 'Demo brand label', 'اسم العلامة التجريبية'],
            'beauty_demo_disclosure' => ['demo_disclosure', 'Demo disclosure', 'تنويه المنتج التجريبي'],
        ];

        $newAttributeIds = [];

        foreach ($codes as $attributeCode => [$dataKey, $adminName, $arabicName]) {
            if (DB::table('attributes')->where('code', $attributeCode)->exists()) {
                $newAttributeIds[$dataKey] = (int) DB::table('attributes')->where('code', $attributeCode)->value('id');

                continue;
            }

            $templateCode = 'color';
            $templateAttribute = DB::table('attributes')->where('code', $templateCode)->first();

            if (! $templateAttribute) {
                $templateCode = 'product_number';
                $templateAttribute = DB::table('attributes')->where('code', $templateCode)->first();
            }

            if (! $templateAttribute) {
                continue;
            }

            $attributeId = DB::table('attributes')->insertGetId([
                'code' => $attributeCode,
                'admin_name' => $adminName,
                'type' => 'text',
                'position' => 100 + count($newAttributeIds),
                'is_required' => 0,
                'is_unique' => 0,
                'is_filterable' => 0,
                'is_comparable' => 0,
                'is_configurable' => 0,
                'is_user_defined' => 1,
                'is_visible_on_front' => 1,
                'value_per_locale' => 0,
                'value_per_channel' => 0,
                'enable_wysiwyg' => 0,
                'default_value' => null,
                'validation' => null,
                'regex' => null,
                'swatch_type' => null,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            if (DB::table('locales')->where('code', 'ar')->exists()) {
                DB::table('attribute_translations')->insert([
                    'attribute_id' => $attributeId,
                    'locale' => 'ar',
                    'name' => $arabicName,
                ]);
            }

            DB::table('attribute_group_mappings')->insert([
                'attribute_id' => $attributeId,
                'attribute_group_id' => 1,
                'position' => 80 + count($newAttributeIds),
            ]);

            $newAttributeIds[$dataKey] = (int) $attributeId;
        }

        return $newAttributeIds;
    }
