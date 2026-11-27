<?php

namespace Database\Seeders;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Seeder;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Webkul\Attribute\Repositories\AttributeOptionRepository;
use Webkul\Attribute\Repositories\AttributeRepository;
use Webkul\Category\Repositories\CategoryRepository;
use Webkul\Product\Repositories\ProductRepository;

/**
 * Nexora Beauty & Personal Care demo catalog.
 *
 * Idempotent and additive:
 *  - never deletes or truncates anything, never edits products it did not create;
 *  - categories, attributes, options, the attribute family and products are looked up first
 *    (by slug / code / SKU) and only created when missing, so re-running adds nothing twice;
 *  - every product is written through Bagisto's own repositories, then indexed through the same
 *    `catalog.product.update.after` event the admin panel fires.
 *
 * Run:  php artisan db:seed --class="Database\Seeders\NexoraBeautySeeder"
 * Data: database/seeders/data/nexora-beauty-catalog.json
 * Art:  public/themes/shop/nexora/beauty/{products,categories,banners}
 */
class NexoraBeautySeeder extends Seeder
{
    public const CATALOG_PATH = 'database/seeders/data/nexora-beauty-catalog.json';

    public const MEDIA_PATH = 'public/themes/shop/nexora/beauty';

    public const FAMILY_CODE = 'nexora_beauty';

    public const FAMILY_GROUP = 'Beauty & Personal Care Details';

    /**
     * Locale-specific product attributes written once per storefront locale.
     */
    protected const LOCALE_ATTRIBUTES = [
        'name',
        'url_key',
        'short_description',
        'description',
        'meta_title',
        'meta_keywords',
        'meta_description',
        'nx_ingredients',
        'nx_how_to_use',
        'nx_demo_notice',
    ];

    protected array $catalog = [];

    /** @var array<int, string> */
    protected array $locales = [];

    protected string $defaultLocale;

    protected $channel;

    protected array $changeLog = [];

    protected array $report = [
        'categories_created' => 0,
        'categories_reused' => 0,
        'attributes_created' => 0,
        'options_created' => 0,
        'products_created' => 0,
        'products_skipped' => 0,
    ];

    public function run(): void
    {
        // `db:seed` runs seeders with mass-assignment protection off; Bagisto's repositories rely on it
        // (Product::update() receives every attribute), so turn it back on for this seeder.
        Model::reguard();

        $this->catalog = $this->loadCatalog();

        $this->channel = core()->getDefaultChannel();

        $this->ensureEnglishLocale();

        $this->locales = $this->channel->locales()->pluck('code')->all();

        $this->defaultLocale = core()->getDefaultLocaleCodeFromDefaultChannel();

        $this->preflight();

        $attributeIds = DB::transaction(fn () => $this->ensureAttributes());

        $familyId = DB::transaction(fn () => $this->ensureFamily($attributeIds));

        $brandOptions = DB::transaction(fn () => $this->ensureBrandOptions());

        $categoryIds = DB::transaction(fn () => $this->ensureCategories($this->catalog['categories'], (int) $this->channel->root_category_id));

        DB::transaction(fn () => $this->retireCategories($categoryIds));

        DB::transaction(fn () => $this->ensureCategoryFilters($categoryIds));

        DB::transaction(fn () => $this->cleanStorefrontFilters());

        $products = [];

        foreach ($this->catalog['products'] as $plan) {
            $product = $this->ensureProduct($plan, $familyId, $categoryIds, $brandOptions);

            if ($product) {
                $products[] = $product;
            }
        }

        $this->updateHomeSeo();

        DB::transaction(fn () => $this->syncRelatedProducts($products, $categoryIds));

        $this->writeChangeLog();

        foreach ($products as $product) {
            Event::dispatch('catalog.product.update.after', $product);
        }

        $this->summary(count($products));
    }

    // ---------------------------------------------------------------------
    // Setup & checks
    // ---------------------------------------------------------------------

    protected function loadCatalog(): array
    {
        $path = base_path(self::CATALOG_PATH);

        if (! is_file($path)) {
            throw new \RuntimeException('Catalog file not found: '.$path);
        }

        return json_decode(file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
    }

    protected function preflight(): void
    {
        if (! $this->channel) {
            throw new \RuntimeException('No default channel found.');
        }

        if (empty($this->locales)) {
            throw new \RuntimeException('The default channel has no locales.');
        }

        if (! DB::table('attribute_families')->where('code', 'default')->exists()) {
            throw new \RuntimeException('The default attribute family is missing.');
        }

        if (! DB::table('inventory_sources')->where('status', 1)->exists()) {
            throw new \RuntimeException('No active inventory source.');
        }

        foreach ($this->catalog['products'] as $plan) {
            foreach ([1, 2] as $n) {
                if (! is_file($this->mediaFile('products/'.$plan['slug'].'-'.$n.'.webp'))) {
                    throw new \RuntimeException('Missing product artwork: '.$plan['slug'].'-'.$n.'.webp (run the image generator first).');
                }
            }
        }

        $this->info('Channel: '.$this->channel->code.' | locales: '.implode(', ', $this->locales).' | default: '.$this->defaultLocale);
    }

    /**
     * The storefront must offer Arabic (RTL) and English (LTR): add the English locale to the channel
     * if it is missing. Additive only — no existing locale or translation is changed.
     */
    protected function ensureEnglishLocale(): void
    {
        $localeId = DB::table('locales')->where('code', 'en')->value('id');

        if (! $localeId) {
            $localeId = DB::table('locales')->insertGetId([
                'code' => 'en',
                'name' => 'English',
                'direction' => 'ltr',
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            $this->info('Created locale: English (en, LTR).');
        }

        $attached = DB::table('channel_locales')
            ->where('channel_id', $this->channel->id)
            ->where('locale_id', $localeId)
            ->exists();

        if (! $attached) {
            DB::table('channel_locales')->insert(['channel_id' => $this->channel->id, 'locale_id' => $localeId]);

            $this->info('Enabled English (en) on channel "'.$this->channel->code.'".');
        }

        if (! DB::table('channel_translations')->where('channel_id', $this->channel->id)->where('locale', 'en')->exists()) {
            DB::table('channel_translations')->insert([
                'channel_id' => $this->channel->id,
                'locale' => 'en',
                'name' => 'Nexora Beauty & Personal Care',
                'description' => 'Beauty and personal care for women, men, kids and babies.',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }

    protected function lang(string $localeCode): string
    {
        return str_starts_with($localeCode, 'ar') ? 'ar' : 'en';
    }

    protected function mediaFile(string $relative): string
    {
        return base_path(self::MEDIA_PATH.'/'.$relative);
    }

    // ---------------------------------------------------------------------
    // Attributes, options and family
    // ---------------------------------------------------------------------

    /**
     * @return array<string, int>
     */
    protected function ensureAttributes(): array
    {
        $definitions = [
            'nx_size' => ['type' => 'text', 'en' => 'Size / Volume', 'ar' => 'الحجم / السعة', 'per_locale' => 0, 'filterable' => 0, 'visible' => 1, 'comparable' => 1],
            'nx_skin_type' => ['type' => 'select', 'en' => 'Skin / Hair Type', 'ar' => 'نوع البشرة / الشعر', 'per_locale' => 0, 'filterable' => 1, 'visible' => 1, 'comparable' => 1],
            'nx_age_group' => ['type' => 'select', 'en' => 'Age Group', 'ar' => 'الفئة العمرية', 'per_locale' => 0, 'filterable' => 1, 'visible' => 1, 'comparable' => 1],
            'nx_ingredients' => ['type' => 'textarea', 'en' => 'Key Ingredients', 'ar' => 'المكونات الرئيسية', 'per_locale' => 1, 'filterable' => 0, 'visible' => 1, 'comparable' => 0],
            'nx_how_to_use' => ['type' => 'textarea', 'en' => 'How to Use', 'ar' => 'طريقة الاستخدام', 'per_locale' => 1, 'filterable' => 0, 'visible' => 1, 'comparable' => 0],
            'nx_demo_notice' => ['type' => 'textarea', 'en' => 'Demo Notice', 'ar' => 'تنويه', 'per_locale' => 1, 'filterable' => 0, 'visible' => 1, 'comparable' => 0],
            'nx_best_seller' => ['type' => 'boolean', 'en' => 'Best Seller', 'ar' => 'الأكثر مبيعًا', 'per_locale' => 0, 'filterable' => 0, 'visible' => 0, 'comparable' => 0],
            'nx_special_offer' => ['type' => 'boolean', 'en' => 'Special Offer', 'ar' => 'عرض خاص', 'per_locale' => 0, 'filterable' => 0, 'visible' => 0, 'comparable' => 0],
        ];

        $repository = app(AttributeRepository::class);

        $ids = [];

        $position = (int) DB::table('attributes')->max('position');

        foreach ($definitions as $code => $def) {
            $existing = DB::table('attributes')->where('code', $code)->first();

            if ($existing) {
                $ids[$code] = (int) $existing->id;
            } else {
                $data = [
                    'code' => $code,
                    'admin_name' => $def['en'],
                    'type' => $def['type'],
                    'position' => ++$position,
                    'is_required' => 0,
                    'is_unique' => 0,
                    'is_filterable' => $def['filterable'],
                    'is_comparable' => $def['comparable'],
                    'is_configurable' => 0,
                    'is_user_defined' => 1,
                    'is_visible_on_front' => $def['visible'],
                    'value_per_locale' => $def['per_locale'],
                    'value_per_channel' => 0,
                    'enable_wysiwyg' => 0,
                ];

                foreach ($this->locales as $locale) {
                    $data[$locale] = ['name' => $def[$this->lang($locale)]];
                }

                $ids[$code] = (int) $repository->create($data)->id;

                $this->report['attributes_created']++;
            }

            if (isset($this->catalog['select_options'][$code])) {
                $this->ensureOptions($ids[$code], $this->catalog['select_options'][$code]);
            }
        }

        return $ids;
    }

    /**
     * @param  array<string, array{en: string, ar: string}>  $options
     * @return array<string, int>
     */
    protected function ensureOptions(int $attributeId, array $options): array
    {
        $repository = app(AttributeOptionRepository::class);

        $result = [];

        $sort = (int) DB::table('attribute_options')->where('attribute_id', $attributeId)->max('sort_order');

        foreach ($options as $key => $labels) {
            $id = DB::table('attribute_options')
                ->where('attribute_id', $attributeId)
                ->where('admin_name', $labels['en'])
                ->value('id');

            if (! $id) {
                $data = [
                    'attribute_id' => $attributeId,
                    'admin_name' => $labels['en'],
                    'sort_order' => ++$sort,
                ];

                foreach ($this->locales as $locale) {
                    $data[$locale] = ['label' => $labels[$this->lang($locale)]];
                }

                $id = $repository->create($data)->id;

                $this->report['options_created']++;
            }

            $result[$key] = (int) $id;
        }

        return $result;
    }

    /**
     * @return array<string, int>
     */
    protected function ensureBrandOptions(): array
    {
        $brandAttributeId = (int) DB::table('attributes')->where('code', 'brand')->where('type', 'select')->value('id');

        if (! $brandAttributeId) {
            $this->info('Built-in "brand" select attribute not found — brands will be shown only in descriptions.');

            return [];
        }

        return $this->ensureOptions($brandAttributeId, $this->catalog['brands']);
    }

    /**
     * Clone the default family once (keeping every system group and mapping) and add a
     * "Beauty & Personal Care Details" group holding the Nexora attributes.
     *
     * @param  array<string, int>  $attributeIds
     */
    protected function ensureFamily(array $attributeIds): int
    {
        $familyId = (int) DB::table('attribute_families')->where('code', self::FAMILY_CODE)->value('id');

        if (! $familyId) {
            $default = DB::table('attribute_families')->where('code', 'default')->first();

            $familyId = (int) DB::table('attribute_families')->insertGetId([
                'code' => self::FAMILY_CODE,
                'name' => 'Beauty & Personal Care',
                'status' => $default->status ?? 0,
                'is_user_defined' => 1,
            ]);

            foreach (DB::table('attribute_groups')->where('attribute_family_id', $default->id)->orderBy('position')->get() as $group) {
                $row = (array) $group;

                $oldGroupId = $row['id'];

                unset($row['id']);

                $row['attribute_family_id'] = $familyId;

                $newGroupId = DB::table('attribute_groups')->insertGetId($row);

                $mappings = DB::table('attribute_group_mappings')->where('attribute_group_id', $oldGroupId)->get();

                foreach ($mappings as $mapping) {
                    DB::table('attribute_group_mappings')->insert([
                        'attribute_id' => $mapping->attribute_id,
                        'attribute_group_id' => $newGroupId,
                        'position' => $mapping->position,
                    ]);
                }
            }

            $this->info('Created attribute family "Beauty & Personal Care" (code '.self::FAMILY_CODE.').');
        }

        $groupId = (int) DB::table('attribute_groups')
            ->where('attribute_family_id', $familyId)
            ->where('name', self::FAMILY_GROUP)
            ->value('id');

        if (! $groupId) {
            $row = [
                'attribute_family_id' => $familyId,
                'name' => self::FAMILY_GROUP,
                'position' => ((int) DB::table('attribute_groups')->where('attribute_family_id', $familyId)->max('position')) + 1,
                'is_user_defined' => 1,
            ];

            if (DB::getSchemaBuilder()->hasColumn('attribute_groups', 'column')) {
                $row['column'] = 1;
            }

            if (DB::getSchemaBuilder()->hasColumn('attribute_groups', 'code')) {
                $row['code'] = 'nexora_beauty_details';
            }

            $groupId = (int) DB::table('attribute_groups')->insertGetId($row);
        }

        $familyGroupIds = DB::table('attribute_groups')->where('attribute_family_id', $familyId)->pluck('id');

        $wanted = $attributeIds;

        if ($brandId = DB::table('attributes')->where('code', 'brand')->value('id')) {
            $wanted = ['brand' => (int) $brandId] + $wanted;
        }

        $position = 0;

        foreach ($wanted as $attributeId) {
            $position++;

            $mapped = DB::table('attribute_group_mappings')
                ->where('attribute_id', $attributeId)
                ->whereIn('attribute_group_id', $familyGroupIds)
                ->exists();

            if (! $mapped) {
                DB::table('attribute_group_mappings')->insert([
                    'attribute_id' => $attributeId,
                    'attribute_group_id' => $groupId,
                    'position' => $position,
                ]);
            }
        }

        return $familyId;
    }

    // ---------------------------------------------------------------------
    // Categories
    // ---------------------------------------------------------------------

    /**
     * Ids of categories that belong to Nexora: any category whose slug is one of the catalogue's
     * current, legacy or retired slugs (with or without the "nx-" collision prefix).
     *
     * @return array<int, int>
     */
    protected function nexoraCategoryIds(): array
    {
        $slugs = [];

        $collect = function (array $nodes) use (&$collect, &$slugs) {
            foreach ($nodes as $node) {
                foreach (array_merge([$node['slug']], $node['legacy'] ?? []) as $slug) {
                    $slugs[] = $slug;
                    $slugs[] = 'nx-'.$slug;
                }

                $collect($node['children'] ?? []);
            }
        };

        $collect($this->catalog['categories']);

        foreach ($this->catalog['retired_category_slugs'] ?? [] as $slug) {
            $slugs[] = $slug;
            $slugs[] = 'nx-'.$slug;
        }

        return DB::table('category_translations')->whereIn('slug', array_unique($slugs))
            ->distinct()->pluck('category_id')->map(fn ($id) => (int) $id)->all();
    }

    /**
     * Build (or re-shape) the Nexora category tree. Existing Nexora categories are found by their
     * current or legacy slug and moved / renamed in place, so nothing is duplicated; missing ones are created.
     *
     * @return array<string, int>
     */
    protected function ensureCategories(array $nodes, int $parentId, int &$position = 0): array
    {
        if (! isset($this->ourCategoryIds)) {
            $this->ourCategoryIds = $this->nexoraCategoryIds();
        }

        $ids = [];

        foreach ($nodes as $node) {
            $position++;

            $id = $this->syncCategory($node, $parentId, $position);

            $this->ourCategoryIds[] = $id;

            $ids[$node['key']] = $id;

            if (! empty($node['children'])) {
                $childPosition = 0;

                $ids += $this->ensureCategories($node['children'], $id, $childPosition);
            }
        }

        return $ids;
    }

    protected ?array $ourCategoryIds = null;

    protected function syncCategory(array $node, int $parentId, int $position): int
    {
        $candidates = [];

        foreach (array_merge([$node['slug']], $node['legacy'] ?? []) as $slug) {
            $candidates[] = $slug;
            $candidates[] = 'nx-'.$slug;
        }

        $existingId = null;

        foreach ($candidates as $slug) {
            $existingId = DB::table('category_translations')
                ->where('slug', $slug)
                ->whereIn('category_id', $this->ourCategoryIds ?: [0])
                ->value('category_id');

            if ($existingId) {
                break;
            }
        }

        if (! $existingId) {
            return $this->createCategory($node, $parentId, $position);
        }

        $id = (int) $existingId;

        $this->report['categories_reused']++;

        $category = app(CategoryRepository::class)->find($id);

        if ((int) $category->parent_id !== $parentId || (int) $category->position !== $position || ! $category->status) {
            // Eloquent model save: kalnoy/nestedset moves the node when parent_id changes.
            $category->parent_id = $parentId;
            $category->position = $position;
            $category->status = 1;
            $category->save();
        }

        $slug = $this->availableSlug($node['slug'], $id);

        foreach ($this->locales as $locale) {
            $values = $this->categoryTranslation($node, $locale, $slug);

            $exists = DB::table('category_translations')->where('category_id', $id)->where('locale', $locale)->exists();

            if ($exists) {
                DB::table('category_translations')->where('category_id', $id)->where('locale', $locale)->update($values);
            } else {
                DB::table('category_translations')->insert($values + ['category_id' => $id, 'locale' => $locale]);
            }
        }

        $this->attachCategoryArt($id, $node);

        return $id;
    }

    protected function availableSlug(string $slug, ?int $categoryId = null): string
    {
        $taken = DB::table('category_translations')
            ->where('slug', $slug)
            ->when($categoryId, fn ($query) => $query->where('category_id', '!=', $categoryId))
            ->exists();

        return $taken ? 'nx-'.$slug : $slug;
    }

    protected function categoryTranslation(array $node, string $locale, string $slug): array
    {
        $lang = $this->lang($locale);

        $name = $node[$lang];

        $tagline = $node['tagline_'.$lang] ?? null;

        return [
            'name' => $name,
            'slug' => $slug,
            'description' => '<p>'.e($tagline ?: ($lang === 'ar'
                ? 'تسوّق '.$name.' ضمن تشكيلة نيكسورا للجمال والعناية الشخصية.'
                : 'Shop '.$name.' in the Nexora Beauty & Personal Care collection.')).'</p>',
            'meta_title' => $name.($lang === 'ar' ? ' | نيكسورا للجمال والعناية' : ' | Nexora Beauty & Personal Care'),
            'meta_description' => $lang === 'ar'
                ? 'اكتشف '.$name.' من نيكسورا: عناية بالبشرة ومكياج وعطور وعناية شخصية للنساء والرجال والأطفال.'
                : 'Discover '.$name.' at Nexora: skincare, makeup, fragrance and personal care for women, men and kids.',
            'meta_keywords' => $name.($lang === 'ar' ? '، جمال، عناية شخصية، نيكسورا' : ', beauty, personal care, Nexora'),
            'locale_id' => DB::table('locales')->where('code', $locale)->value('id'),
        ];
    }

    protected function createCategory(array $node, int $parentId, int $position): int
    {
        $slug = $this->availableSlug($node['slug']);

        $data = [
            'position' => $position,
            'status' => 1,
            'display_mode' => 'products_and_description',
            'parent_id' => $parentId,
        ];

        foreach ($this->locales as $locale) {
            $data[$locale] = $this->categoryTranslation($node, $locale, $slug);
        }

        $category = app(CategoryRepository::class)->create($data);

        $this->report['categories_created']++;

        $this->info('  + category: '.$node['en']);

        $this->attachCategoryArt((int) $category->id, $node);

        return (int) $category->id;
    }

    /**
     * Hide Nexora categories that are no longer part of the tree (status 0, never deleted).
     *
     * @param  array<string, int>  $categoryIds
     */
    protected function retireCategories(array $categoryIds): void
    {
        $keep = array_values($categoryIds);

        $retire = DB::table('categories')
            ->whereIn('id', array_diff($this->nexoraCategoryIds(), $keep))
            ->where('status', 1)
            ->pluck('id')
            ->all();

        if ($retire) {
            DB::table('categories')->whereIn('id', $retire)->update(['status' => 0, 'updated_at' => now()]);
        }

        $this->changeLog['hidden_nexora_categories'] = $retire;

        $this->report['categories_hidden'] = count($retire);
    }

    /**
     * Give a category a logo (and top-level categories a banner) only when it has none.
     */
    protected function attachCategoryArt(int $id, array $node): void
    {
        $category = DB::table('categories')->where('id', $id)->first();

        $updates = [];

        $logo = $this->mediaFile('categories/'.$node['key'].'.webp');

        if (empty($category->logo_path) && is_file($logo)) {
            $path = 'categories/'.$id.'/'.Str::slug($node['en']).'-nexora.webp';

            Storage::put($path, file_get_contents($logo));

            $updates['logo_path'] = $path;
        }

        $banner = $this->mediaFile('banners/hero-'.$node['key'].'.webp');

        if (empty($category->banner_path) && is_file($banner)) {
            $path = 'categories/'.$id.'/'.Str::slug($node['en']).'-banner-nexora.webp';

            Storage::put($path, file_get_contents($banner));

            $updates['banner_path'] = $path;
        }

        if ($updates) {
            DB::table('categories')->where('id', $id)->update($updates + ['updated_at' => now()]);
        }
    }

    /**
     * @param  array<string, int>  $categoryIds
     */
    protected function ensureCategoryFilters(array $categoryIds): void
    {
        $attributeIds = DB::table('attributes')
            ->whereIn('code', ['price', 'brand', 'nx_skin_type', 'nx_age_group'])
            ->pluck('id');

        foreach ($categoryIds as $categoryId) {
            foreach ($attributeIds as $attributeId) {
                $exists = DB::table('category_filterable_attributes')
                    ->where('category_id', $categoryId)
                    ->where('attribute_id', $attributeId)
                    ->exists();

                if (! $exists) {
                    DB::table('category_filterable_attributes')->insert([
                        'category_id' => $categoryId,
                        'attribute_id' => $attributeId,
                    ]);
                }
            }
        }
    }

    /**
     * Category ids a product should appear in: its own categories plus every ancestor
     * (so it is listed in "Women", "Women > Skincare" and "Women > Skincare > Serums").
     *
     * @param  array<string, int>  $categoryIds
     * @return array<int, int>
     */
    protected function productCategoryIds(array $plan, array $categoryIds): array
    {
        $rootId = (int) $this->channel->root_category_id;

        $ids = [];

        foreach ($plan['categories'] as $key) {
            $id = $categoryIds[$key] ?? null;

            while ($id && $id !== $rootId) {
                $ids[$id] = $id;

                $id = (int) DB::table('categories')->where('id', $id)->value('parent_id');
            }
        }

        return array_values($ids);
    }

    // ---------------------------------------------------------------------
    // Products
    // ---------------------------------------------------------------------

    protected function ensureProduct(array $plan, int $familyId, array $categoryIds, array $brandOptions)
    {
        $repository = app(ProductRepository::class);

        $categories = $this->productCategoryIds($plan, $categoryIds);

        $existing = $repository->findOneByField('sku', $plan['sku']);

        if ($existing) {
            // Idempotent re-run: the product itself is not recreated or overwritten.
            // Nexora's own demo products: keep their category links exactly in line with the catalogue.
            $existing->categories()->sync($categories);

            $this->refreshMedia($existing, $plan);

            $this->report['products_skipped']++;

            return $existing;
        }

        return DB::transaction(function () use ($repository, $plan, $familyId, $categories, $brandOptions) {
            $product = $repository->create([
                'type' => 'simple',
                'attribute_family_id' => $familyId,
                'sku' => $plan['sku'],
            ]);

            $skinOptions = $this->optionIds('nx_skin_type');

            $ageOptions = $this->optionIds('nx_age_group');

            $urlKey = $this->uniqueUrlKey($plan['slug'], $product->id);

            $data = array_merge($this->localeData($plan, $this->defaultLocale, $urlKey), [
                'channel' => $this->channel->code,
                'locale' => $this->defaultLocale,
                'sku' => $plan['sku'],
                'product_number' => $plan['sku'],
                'price' => $plan['price'],
                'special_price' => $plan['special'] ?? null,
                'special_price_from' => null,
                'special_price_to' => null,
                'cost' => null,
                'weight' => 0.3,
                'length' => null,
                'width' => null,
                'height' => null,
                'status' => 1,
                'visible_individually' => 1,
                'guest_checkout' => 1,
                'manage_stock' => 1,
                'new' => (int) $plan['new'],
                'featured' => (int) $plan['featured'],
                'nx_best_seller' => (int) $plan['best'],
                'nx_special_offer' => (int) ($plan['offer'] || ! empty($plan['special'])),
                'nx_size' => $plan['size'],
                'nx_skin_type' => $plan['skin'] ? ($skinOptions[$plan['skin']] ?? null) : null,
                'nx_age_group' => $ageOptions[$plan['age']] ?? null,
                'brand' => $brandOptions[$plan['brand']] ?? null,
                'tax_category_id' => null,
                'inventories' => [
                    (int) DB::table('inventory_sources')->where('status', 1)->orderBy('priority')->value('id') => 100,
                ],
                'channels' => [(int) $this->channel->id],
                'categories' => $categories,
                'images' => [
                    'files' => [
                        $this->uploadedFile($plan['slug'].'-1.webp'),
                        $this->uploadedFile($plan['slug'].'-2.webp'),
                    ],
                    'meta' => [
                        ['file_name' => $plan['slug'], 'alt_text' => $plan['en'].' — '.$plan['ar']],
                        ['file_name' => $plan['slug'].'-texture', 'alt_text' => $plan['en'].' — '.$plan['ar']],
                    ],
                ],
            ]);

            $product = $repository->update($data, $product->id);

            foreach ($this->locales as $locale) {
                if ($locale === $this->defaultLocale) {
                    continue;
                }

                $repository->update(
                    array_merge($this->localeData($plan, $locale, $urlKey), [
                        'channel' => $this->channel->code,
                        'locale' => $locale,
                    ]),
                    $product->id,
                    self::LOCALE_ATTRIBUTES
                );
            }

            $this->report['products_created']++;

            $this->rememberMediaHash($plan);

            $this->info('  + '.$plan['sku'].'  '.$plan['en']);

            return $product->refresh();
        });
    }

    /**
     * @return array<string, int>
     */
    protected function optionIds(string $code): array
    {
        static $cache = [];

        if (isset($cache[$code])) {
            return $cache[$code];
        }

        $attributeId = DB::table('attributes')->where('code', $code)->value('id');

        $byLabel = DB::table('attribute_options')->where('attribute_id', $attributeId)->pluck('id', 'admin_name');

        $ids = [];

        foreach ($this->catalog['select_options'][$code] ?? [] as $key => $labels) {
            if (isset($byLabel[$labels['en']])) {
                $ids[$key] = (int) $byLabel[$labels['en']];
            }
        }

        return $cache[$code] = $ids;
    }

    protected function uniqueUrlKey(string $slug, int $productId): string
    {
        $attributeId = DB::table('attributes')->where('code', 'url_key')->value('id');

        $taken = DB::table('product_attribute_values')
            ->where('attribute_id', $attributeId)
            ->where('text_value', $slug)
            ->where('product_id', '!=', $productId)
            ->exists();

        return $taken ? $slug.'-nexora' : $slug;
    }

    /**
     * Replace a Nexora product's two images when the generated artwork changed since the last run
     * (tracked by file hash), through Bagisto's media repository so the old files are cleaned up.
     */
    protected function refreshMedia($product, array $plan): void
    {
        $hashes = $this->mediaHashes();

        $current = $this->mediaHash($plan);

        if (($hashes[$plan['sku']] ?? null) === $current) {
            return;
        }

        $existingIds = DB::table('product_images')->where('product_id', $product->id)->where('type', 'images')->orderBy('position')->pluck('id')->values();

        $files = [];
        $meta = [];

        foreach ([1, 2] as $index => $n) {
            $key = $existingIds[$index] ?? $index;

            $files[$key] = $this->uploadedFile($plan['slug'].'-'.$n.'.webp');

            $meta[$key] = [
                'file_name' => $plan['slug'].($n === 2 ? '-styled' : ''),
                'alt_text' => $plan['en'].' — '.$plan['ar'],
            ];
        }

        app(\Webkul\Product\Repositories\ProductImageRepository::class)->upload([
            'images' => ['files' => $files, 'meta' => $meta],
        ], $product->refresh(), 'images');

        $this->rememberMediaHash($plan);

        $this->report['images_refreshed'] = ($this->report['images_refreshed'] ?? 0) + 1;
    }

    protected ?array $mediaHashCache = null;

    protected function mediaHashes(): array
    {
        $file = storage_path('app/nexora-media-hashes.json');

        return $this->mediaHashCache ??= is_file($file) ? (json_decode(file_get_contents($file), true) ?: []) : [];
    }

    protected function mediaHash(array $plan): string
    {
        return md5_file($this->mediaFile('products/'.$plan['slug'].'-1.webp')).md5_file($this->mediaFile('products/'.$plan['slug'].'-2.webp'));
    }

    protected function rememberMediaHash(array $plan): void
    {
        $this->mediaHashes();

        $this->mediaHashCache[$plan['sku']] = $this->mediaHash($plan);

        file_put_contents(storage_path('app/nexora-media-hashes.json'), json_encode($this->mediaHashCache, JSON_PRETTY_PRINT));
    }

    protected function uploadedFile(string $name): UploadedFile
    {
        $path = $this->mediaFile('products/'.$name);

        // Copy to a temp file so Bagisto's media pipeline never touches the source artwork.
        $tmp = sys_get_temp_dir().DIRECTORY_SEPARATOR.'nxb-'.uniqid().'-'.$name;

        copy($path, $tmp);

        return new UploadedFile($tmp, $name, 'image/webp', null, true);
    }

    protected function localeData(array $plan, string $locale, string $urlKey): array
    {
        $lang = $this->lang($locale);

        $brand = $this->catalog['brands'][$plan['brand']][$lang];

        $name = $plan[$lang];

        $short = $plan['short_'.$lang];

        $notice = $this->catalog['disclaimer'][$lang];

        $skin = $plan['skin'] ? $this->catalog['select_options']['nx_skin_type'][$plan['skin']][$lang] : null;

        $age = $this->catalog['select_options']['nx_age_group'][$plan['age']][$lang];

        $labels = $lang === 'ar'
            ? ['brand' => 'العلامة', 'size' => 'الحجم', 'for' => 'مناسب لـ', 'age' => 'الفئة العمرية', 'ing' => 'المكونات الرئيسية', 'use' => 'طريقة الاستخدام']
            : ['brand' => 'Brand', 'size' => 'Size', 'for' => 'Suitable for', 'age' => 'Age group', 'ing' => 'Key ingredients', 'use' => 'How to use'];

        $facts = [
            $labels['brand'].': '.$brand,
            $labels['size'].': '.$plan['size'],
        ];

        if ($skin) {
            $facts[] = $labels['for'].': '.$skin;
        }

        $facts[] = $labels['age'].': '.$age;

        $description = '<p>'.e($short).'</p>'
            .'<ul>'.implode('', array_map(fn ($line) => '<li>'.e($line).'</li>', $facts)).'</ul>'
            .'<h3>'.e($labels['ing']).'</h3><p>'.e($plan['ing_'.$lang]).'</p>'
            .'<h3>'.e($labels['use']).'</h3><p>'.e($plan['use_'.$lang]).'</p>'
            .'<p><em>'.e($notice).'</em></p>';

        return [
            'name' => $name,
            'url_key' => $urlKey,
            'short_description' => '<p>'.e($short).'</p>',
            'description' => $description,
            'meta_title' => $name.($lang === 'ar' ? ' | نيكسورا' : ' | Nexora'),
            'meta_keywords' => implode($lang === 'ar' ? '، ' : ', ', array_filter([$brand, $skin, $lang === 'ar' ? 'عناية شخصية' : 'personal care', 'Nexora'])),
            'meta_description' => $short,
            'nx_ingredients' => $plan['ing_'.$lang],
            'nx_how_to_use' => $plan['use_'.$lang],
            'nx_demo_notice' => $notice,
        ];
    }

    // ---------------------------------------------------------------------
    // Channel copy
    // ---------------------------------------------------------------------

    protected function updateHomeSeo(): void
    {
        foreach ($this->locales as $locale) {
            $ar = $this->lang($locale) === 'ar';

            DB::table('channel_translations')
                ->where('channel_id', $this->channel->id)
                ->where('locale', $locale)
                ->update([
                    'home_seo' => json_encode($ar ? [
                        'meta_title' => 'نيكسورا | متجر الجمال والعناية الشخصية',
                        'meta_description' => 'نيكسورا: عناية بالبشرة ومكياج وعناية بالشعر والجسم وعطور للنساء والرجال، وعناية لطيفة للأطفال والرضّع.',
                        'meta_keywords' => 'جمال، عناية شخصية، عناية بالبشرة، مكياج، عناية الرجل، منتجات الأطفال، نيكسورا',
                    ] : [
                        'meta_title' => 'Nexora | Beauty & Personal Care',
                        'meta_description' => 'Nexora: skincare, makeup, hair, body and fragrance for women and men, plus gentle care for kids and babies.',
                        'meta_keywords' => 'beauty, personal care, skincare, makeup, men grooming, baby care, Nexora',
                    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                    'updated_at' => now(),
                ]);
        }
    }

    // ---------------------------------------------------------------------
    // Storefront clean-up (filters, brands, related products)
    // ---------------------------------------------------------------------

    /**
     * Keep storefront filters on beauty data: stop apparel attributes from showing as filters and
     * remove off-topic demo brand options that no enabled product uses. Everything is logged.
     */
    protected function cleanStorefrontFilters(): void
    {
        $codes = $this->catalog['unfilter_attributes'] ?? [];

        $unfiltered = DB::table('attributes')->whereIn('code', $codes)->where('is_filterable', 1)->pluck('code')->all();

        if ($unfiltered) {
            DB::table('attributes')->whereIn('code', $unfiltered)->update(['is_filterable' => 0, 'updated_at' => now()]);
        }

        $this->changeLog['unfiltered_attributes'] = $unfiltered;

        $brandAttributeId = DB::table('attributes')->where('code', 'brand')->value('id');

        $this->changeLog['removed_brand_options'] = [];

        if (! $brandAttributeId) {
            return;
        }

        $options = DB::table('attribute_options')
            ->where('attribute_id', $brandAttributeId)
            ->whereIn('admin_name', $this->catalog['retired_brand_options'] ?? [])
            ->get();

        foreach ($options as $option) {
            $usedBy = DB::table('product_attribute_values')
                ->where('attribute_id', $brandAttributeId)
                ->where('integer_value', $option->id)
                ->pluck('product_id')
                ->all();

            $usedByEnabled = DB::table('product_flat')
                ->whereIn('product_id', $usedBy ?: [0])
                ->where('status', 1)
                ->exists();

            if ($usedByEnabled) {
                $this->info('  ! kept brand option "'.$option->admin_name.'" — an enabled product still uses it.');

                continue;
            }

            $this->changeLog['removed_brand_options'][] = [
                'option' => (array) $option,
                'translations' => DB::table('attribute_option_translations')->where('attribute_option_id', $option->id)->get()->map(fn ($row) => (array) $row)->all(),
                'used_by_disabled_products' => $usedBy,
            ];

            DB::table('attribute_option_translations')->where('attribute_option_id', $option->id)->delete();

            DB::table('attribute_options')->where('id', $option->id)->delete();

            $this->info('  - removed demo brand option: '.$option->admin_name);
        }
    }

    /**
     * Related products for every Nexora product: other Nexora products from the same leaf category,
     * topped up from the same top-level department.
     *
     * @param  array<int, \Webkul\Product\Contracts\Product>  $products
     * @param  array<string, int>  $categoryIds
     */
    protected function syncRelatedProducts(array $products, array $categoryIds): void
    {
        $bySku = collect($products)->keyBy('sku');

        $plans = collect($this->catalog['products'])->keyBy('sku');

        $leafMembers = [];

        foreach ($plans as $sku => $plan) {
            foreach ($plan['categories'] as $key) {
                $leafMembers[$key][] = $sku;
            }
        }

        foreach ($plans as $sku => $plan) {
            $product = $bySku->get($sku);

            if (! $product) {
                continue;
            }

            $related = collect($plan['categories'])
                ->flatMap(fn ($key) => $leafMembers[$key] ?? [])
                ->reject(fn ($other) => $other === $sku)
                ->unique();

            if ($related->count() < 4) {
                $related = $related->merge(
                    $plans->filter(fn ($other) => $other['sku'] !== $sku && $other['audience'] === $plan['audience'])->keys()->shuffle(crc32($sku))
                )->unique();
            }

            $ids = $related->take(4)->map(fn ($other) => $bySku->get($other)?->id)->filter()->values()->all();

            $product->related_products()->sync($ids);
        }
    }

    protected function writeChangeLog(): void
    {
        $path = storage_path('app/nexora-backups/catalog-sync-'.now()->format('Ymd-His').'.json');

        @mkdir(dirname($path), 0775, true);

        file_put_contents($path, json_encode($this->changeLog + ['created_at' => now()->toDateTimeString()], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));

        $this->info('Change log: '.$path);
    }

    // ---------------------------------------------------------------------
    // Output
    // ---------------------------------------------------------------------

    protected function info(string $message): void
    {
        $this->command?->line($message);
    }

    protected function summary(int $productCount): void
    {
        $this->info('');
        $this->info('Nexora Beauty & Personal Care demo catalog');
        foreach ($this->report as $key => $value) {
            $this->info(str_pad(str_replace('_', ' ', $key), 22).': '.$value);
        }
        $this->info('Nexora demo products in catalog: '.DB::table('products')->where('sku', 'like', 'NXB-%')->count().' (expected '.count($this->catalog['products']).')');
        $this->info('Demo data only — not real stock. Nothing was deleted.');
    }
}
