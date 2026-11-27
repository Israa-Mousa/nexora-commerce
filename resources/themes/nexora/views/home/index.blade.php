@php
    $channel = core()->getCurrentChannel();

    $isArabic = str_starts_with(app()->getLocale(), 'ar');

    $t = fn (string $en, string $ar) => $isArabic ? $ar : $en;

    $metaTitle = $channel->home_seo['meta_title'] ?? $t('Nexora | Beauty & Personal Care', 'نيكسورا | متجر الجمال والعناية الشخصية');

    $art = fn (string $file) => asset('themes/shop/nexora/beauty/'.$file);

    $categoryRepository = app(\Webkul\Category\Repositories\CategoryRepository::class);

    /**
     * Storefront categories are resolved from the database by slug (the "nx-" variant is used when a
     * slug was already taken). Nothing here is hard-coded by id.
     */
    $categoryCache = [];

    $category = function (string $slug) use ($categoryRepository, &$categoryCache) {
        if (! array_key_exists($slug, $categoryCache)) {
            $id = \Illuminate\Support\Facades\DB::table('category_translations as t')
                ->join('categories as c', 'c.id', '=', 't.category_id')
                ->where('c.status', 1)
                ->whereIn('t.slug', [$slug, 'nx-'.$slug])
                ->orderByRaw('t.slug = ? desc', [$slug])
                ->value('c.id');

            $categoryCache[$slug] = $id ? $categoryRepository->find($id) : null;
        }

        return $categoryCache[$slug];
    };

    $url = fn (string $slug, array $fallback = []) => $category($slug)?->url ?? route('shop.search.index', $fallback);

    $departments = collect(['skincare', 'makeup', 'hair-care', 'body-care', 'fragrances', 'men', 'kids-babies'])
        ->map(fn ($slug) => $category($slug))
        ->filter()
        ->values();

    $audiences = [
        ['slug' => 'women', 'image' => 'editorial/women.webp', 'eyebrow' => $t('For her', 'لها'), 'title' => $t('Women', 'النساء'), 'copy' => $t('Skincare, makeup, hair, body care and fragrance.', 'عناية بالبشرة ومكياج وشعر وجسم وعطور.')],
        ['slug' => 'men', 'image' => 'editorial/men.webp', 'eyebrow' => $t('For him', 'له'), 'title' => $t('Men', 'الرجال'), 'copy' => $t('Skincare, beard, shaving and everyday grooming.', 'عناية بالبشرة واللحية والحلاقة والعناية اليومية.')],
        ['slug' => 'kids-babies', 'image' => 'editorial/kids.webp', 'eyebrow' => $t('For little ones', 'للصغار'), 'title' => $t('Kids & Babies', 'الأطفال والرضّع'), 'copy' => $t('Gentle cleansers, lotions and everyday care.', 'منظفات ولوشن وعناية يومية لطيفة.')],
    ];

    /**
     * Keep the curated rails inside the Nexora departments, so older catalogue items never leak in.
     */
    $scopeIds = collect(['skincare', 'makeup', 'hair-care', 'body-care', 'fragrances', 'women', 'men', 'kids-babies'])
        ->map(fn ($slug) => $category($slug)?->id)
        ->filter()
        ->implode(',');

    $scope = $scopeIds ? ['category_id' => $scopeIds] : [];

    $hasAttribute = fn (string $code) => \Illuminate\Support\Facades\DB::table('attributes')->where('code', $code)->exists();

    $bestFilter = ($hasAttribute('nx_best_seller') ? ['nx_best_seller' => 1] : ['sort' => 'price-desc']) + $scope;

    $offerFilter = ($hasAttribute('nx_special_offer') ? ['nx_special_offer' => 1] : ['sort' => 'price-asc']) + $scope;

    $inCategory = fn (string $slug) => ($category($slug) ? ['category_id' => $category($slug)->id] : $scope);

    /**
     * Featured brands: options of the built-in "brand" attribute used by products in the Nexora departments.
     */
    $brands = collect();

    $brandAttributeId = \Illuminate\Support\Facades\DB::table('attributes')->where('code', 'brand')->value('id');

    if ($brandAttributeId && $scopeIds) {
        $brandIds = \Illuminate\Support\Facades\DB::table('product_attribute_values')
            ->where('attribute_id', $brandAttributeId)
            ->whereNotNull('integer_value')
            ->whereIn('product_id', \Illuminate\Support\Facades\DB::table('product_categories')->whereIn('category_id', explode(',', $scopeIds))->select('product_id'))
            ->distinct()
            ->pluck('integer_value');

        $brands = app(\Webkul\Attribute\Repositories\AttributeOptionRepository::class)
            ->findWhereIn('id', $brandIds->all())
            ->sortBy('sort_order')
            ->values();
    }

    $brandTaglines = [
        'Lumière Botanica' => $t('Botanical skin & body care', 'عناية نباتية بالبشرة والجسم'),
        'Maison Dune' => $t('Wearable, warm-toned makeup', 'مكياج دافئ للاستخدام اليومي'),
        'Silk & Saffron' => $t('Hair care rituals', 'طقوس العناية بالشعر'),
        'Atlas Grooming' => $t('Modern men’s grooming', 'عناية عصرية للرجل'),
        'Petit Nuage' => $t('Gentle baby & kids care', 'عناية لطيفة للأطفال والرضّع'),
        'Nexora Essentials' => $t('Fragrance & body mists', 'عطور ومعطّرات للجسم'),
    ];

    $tips = [
        ['image' => 'editorial/tip-routine.webp', 'tag' => $t('Skincare', 'العناية بالبشرة'), 'title' => $t('A simple three-step morning routine', 'روتين صباحي بسيط من ثلاث خطوات'), 'copy' => $t('Cleanse gently, layer a light serum, and finish with moisturizer and sun care.', 'نظّفي بلطف، ثم طبقة خفيفة من السيروم، واختمي بالمرطب وواقي الشمس.'), 'slug' => 'skincare'],
        ['image' => 'editorial/tip-hair.webp', 'tag' => $t('Hair Care', 'العناية بالشعر'), 'title' => $t('Shampoo, condition, then care for the ends', 'شامبو ثم بلسم، ثم عناية بالأطراف'), 'copy' => $t('Focus shampoo on the scalp, conditioner on the lengths, and a drop of oil on dry ends.', 'ركّزي الشامبو على فروة الرأس والبلسم على الأطوال وقطرة زيت على الأطراف الجافة.'), 'slug' => 'hair-care'],
        ['image' => 'editorial/tip-baby.webp', 'tag' => $t('Kids & Babies', 'الأطفال والرضّع'), 'title' => $t('Calm bath time, step by step', 'وقت استحمام هادئ خطوة بخطوة'), 'copy' => $t('Lukewarm water, a mild wash, a soft towel and a light lotion. Always check each product’s label.', 'ماء فاتر، غسول معتدل، منشفة ناعمة ولوشن خفيف. اقرئي دائمًا ملصق كل منتج.'), 'slug' => 'kids-babies'],
    ];

    $newsletter = (bool) core()->getConfigData('customer.settings.newsletter.subscription');

    $arrow = $isArabic ? '←' : '→';
@endphp

<!-- SEO Meta Content -->
@push ('meta')
    <meta name="title" content="{{ $metaTitle }}" />

    <meta name="description" content="{{ $channel->home_seo['meta_description'] ?? '' }}" />

    <meta name="keywords" content="{{ $channel->home_seo['meta_keywords'] ?? '' }}" />
@endPush

@push ('scripts')
    @if (! empty($categories ?? null))
        <script>
            localStorage.setItem('categories:{{ $channel->code }}:{{ app()->getLocale() }}', JSON.stringify(@json($categories)));
        </script>
    @endif
@endPush

<x-shop::layouts>
    <x-slot:title>
        {{ $metaTitle }}
    </x-slot>

    <div class="nexora-home">
        @php
            $slides = [
                [
                    'image' => 'v7/hero-skincare.webp', 'slug' => 'skincare',
                    'eyebrow' => $t('Skincare ritual', 'طقوس العناية بالبشرة'),
                    'title' => $t('Clean, calm skin starts here', 'بشرة هادئة ونقية تبدأ من هنا'),
                    'copy' => $t('Gentle cleansers, light serums and daily moisture — a simple routine that feels good.', 'غسولات لطيفة وسيروم خفيف وترطيب يومي — روتين بسيط تشعرين معه بالراحة.'),
                    'cta' => $t('Shop skincare', 'تسوّقي العناية بالبشرة'),
                ],
                [
                    'image' => 'v7/hero-fragrance.webp', 'slug' => 'fragrances',
                    'eyebrow' => $t('Fragrance', 'العطور'),
                    'title' => $t('Signature scents for every mood', 'عطور تشبهك في كل حالاتك'),
                    'copy' => $t('Floral, woody and fresh — for her, for him and for everyone.', 'زهرية وخشبية ومنعشة — لها وله وللجميع.'),
                    'cta' => $t('Explore fragrances', 'اكتشفي العطور'),
                ],
                [
                    'image' => 'v7/hero-makeup.webp', 'slug' => 'makeup',
                    'eyebrow' => $t('Makeup', 'المكياج'),
                    'title' => $t('Soft colour, effortless finish', 'ألوان ناعمة ولمسة طبيعية'),
                    'copy' => $t('Blush, lips and complexion in easy, wearable shades.', 'بلاشر وشفاه وكريم أساس بدرجات سهلة للاستخدام اليومي.'),
                    'cta' => $t('Shop makeup', 'تسوّقي المكياج'),
                ],
            ];
        @endphp

        <!-- 3. Hero slider -->
        <section class="nx-hero3" aria-roledescription="carousel" aria-label="{{ $t('Featured collections', 'مجموعات مختارة') }}" data-nx-slider data-nx-bubbles>
            <div class="nx-hero3__track">
                @foreach ($slides as $index => $slide)
                    <article
                        class="nx-slide {{ $index === 0 ? 'is-active' : '' }}"
                        aria-roledescription="slide"
                        aria-label="{{ $index + 1 }} / {{ count($slides) }}"
                        @if ($index) aria-hidden="true" @endif
                    >
                        <div class="nx-slide__copy">
                            <span class="nx-eyebrow">{{ $slide['eyebrow'] }}</span>

                            @if ($index === 0)
                                <h1 class="nx-slide__title">{{ $slide['title'] }}</h1>
                            @else
                                <h2 class="nx-slide__title">{{ $slide['title'] }}</h2>
                            @endif

                            <p class="nx-slide__text">{{ $slide['copy'] }}</p>

                            <div class="nx-slide__actions">
                                <a class="nx-btn nx-btn--primary" href="{{ $url($slide['slug']) }}" @if ($index) tabindex="-1" @endif>{{ $slide['cta'] }}</a>
                                <a class="nx-btn nx-btn--secondary" href="{{ route('shop.search.index', ['new' => 1] + $scope) }}" @if ($index) tabindex="-1" @endif>{{ $t('New arrivals', 'وصل حديثًا') }}</a>
                            </div>
                        </div>

                        <div class="nx-slide__media">
                            <img src="{{ $art($slide['image']) }}" alt="" width="1600" height="1240" @if ($index === 0) fetchpriority="high" @else loading="lazy" @endif>
                        </div>
                    </article>
                @endforeach
            </div>

            <div class="nx-hero3__controls">
                <button type="button" class="nx-hero3__arrow" data-nx-slide="prev" aria-label="{{ $t('Previous slide', 'الشريحة السابقة') }}"><span aria-hidden="true">{{ $isArabic ? '→' : '←' }}</span></button>

                <div class="nx-hero3__dots" role="tablist">
                    @foreach ($slides as $index => $slide)
                        <button type="button" role="tab" class="nx-hero3__dot {{ $index === 0 ? 'is-active' : '' }}" data-nx-slide="{{ $index }}" aria-label="{{ $slide['eyebrow'] }}" aria-selected="{{ $index === 0 ? 'true' : 'false' }}"><span></span></button>
                    @endforeach
                </div>

                <button type="button" class="nx-hero3__arrow" data-nx-slide="next" aria-label="{{ $t('Next slide', 'الشريحة التالية') }}"><span aria-hidden="true">{{ $arrow }}</span></button>
            </div>
        </section>

        <!-- Moving category ribbon -->
        @if ($departments->isNotEmpty())
            <div class="nx-ticker" aria-hidden="true">
                <div class="nx-ticker__track">
                    @for ($i = 0; $i < 2; $i++)
                        @foreach ($departments as $department)
                            <span>{{ $department->name }}</span><i>✿</i>
                        @endforeach
                    @endfor
                </div>
            </div>
        @endif

        <!-- 4. Shop by category -->
        @if ($departments->isNotEmpty())
            <section class="nx-section" aria-labelledby="nx-cats-title" data-nx-reveal>
                <header class="nx-head">
                    <div class="nx-head__text">
                        <span class="nx-eyebrow">{{ $t('Shop by category', 'تسوّقي حسب القسم') }}</span>
                        <h2 id="nx-cats-title">{{ $t('Every step of your routine', 'كل خطوة في روتينك') }}</h2>
                    </div>

                    <div class="nx-scroller-nav">
                        <button type="button" data-nx-scroll="-1" aria-label="{{ $t('Previous', 'السابق') }}"><span aria-hidden="true">{{ $isArabic ? '→' : '←' }}</span></button>
                        <button type="button" data-nx-scroll="1" aria-label="{{ $t('Next', 'التالي') }}"><span aria-hidden="true">{{ $arrow }}</span></button>
                    </div>
                </header>

                <div class="nx-cats__track" data-nx-scroller>
                    @foreach ($departments as $department)
                        @php
                            $childCount = $department->children()->where('status', 1)->count();
                        @endphp

                        <a class="nx-cat" href="{{ $department->url }}">
                            <span class="nx-cat__media">
                                @if ($department->logo_url)
                                    <img src="{{ $department->logo_url }}" alt="" width="300" height="300" loading="lazy">
                                @endif
                            </span>

                            <span class="nx-cat__name">{{ $department->name }}</span>

                            @if ($childCount)
                                <span class="nx-cat__count">{{ $childCount }} {{ $t('categories', 'أقسام') }}</span>
                            @endif
                        </a>
                    @endforeach
                </div>
            </section>
        @endif

        <!-- 5. Best sellers -->
        <section class="nx-section nx-carousel" aria-label="{{ $t('Best sellers', 'الأكثر مبيعًا') }}" data-nx-reveal>
            <x-shop::products.carousel
                :title="$t('Best Sellers', 'الأكثر مبيعًا')"
                :src="route('shop.api.products.index', $bestFilter + ['limit' => 12])"
                :navigation-link="route('shop.search.index', $bestFilter)"
                aria-label="{{ $t('Best sellers', 'الأكثر مبيعًا') }}"
            />
        </section>

        <!-- 6. Featured skincare -->
        <section class="nx-band nx-band--blush" aria-labelledby="nx-skin-title">
            <div class="nx-inner nx-feature">
                <div class="nx-feature__aside" data-nx-reveal>
                    <a class="nx-feature__visual" href="{{ $url('skincare') }}" tabindex="-1" aria-hidden="true">
                        <img src="{{ $art('v7/skincare.webp') }}" alt="" width="1200" height="1400" loading="lazy">
                    </a>

                    <div class="nx-feature__copy">
                        <span class="nx-eyebrow">{{ $t('Featured skincare', 'العناية بالبشرة') }}</span>
                        <h2 id="nx-skin-title" class="nx-h3">{{ $t('Cleanse, treat, moisturise, protect', 'نظّفي، عالجي، رطّبي، واحمي') }}</h2>
                        <p class="nx-lead">{{ $t('Four simple steps with gentle textures for everyday skin.', 'أربع خطوات بسيطة بقوامات لطيفة لبشرتك كل يوم.') }}</p>
                        <a class="nx-btn nx-btn--secondary" href="{{ $url('skincare') }}">{{ $t('Shop skincare', 'تسوّقي العناية بالبشرة') }}</a>
                    </div>
                </div>

                <div class="nx-carousel nx-carousel--3 nx-carousel--quiet" data-nx-reveal>
                    <x-shop::products.carousel
                        :title="$t('Skincare Essentials', 'أساسيات العناية بالبشرة')"
                        :src="route('shop.api.products.index', $inCategory('skincare') + ['sort' => 'name-asc', 'limit' => 10])"
                        :navigation-link="$url('skincare')"
                        aria-label="{{ $t('Skincare products', 'منتجات العناية بالبشرة') }}"
                    />
                </div>
            </div>
        </section>

        <!-- 7. Promotional editorial banner -->
        <section class="nx-section nx-promo" aria-labelledby="nx-promo-title" data-nx-reveal>
            <div class="nx-promo__media">
                <img src="{{ $art('v7/promo.webp') }}" alt="" width="1800" height="1000" loading="lazy">
            </div>

            <div class="nx-promo__copy">
                <span class="nx-eyebrow">{{ $t('Special offers', 'عروض خاصة') }}</span>
                <h2 id="nx-promo-title" class="nx-h2">{{ $t('Selected favourites at special prices', 'منتجات مختارة بأسعار خاصة') }}</h2>
                <p class="nx-lead">{{ $t('Makeup, body care and fragrance with reduced demo prices.', 'مكياج وعناية بالجسم وعطور بأسعار مخفّضة تجريبية.') }}</p>
                <a class="nx-btn nx-btn--primary" href="{{ route('shop.search.index', $offerFilter) }}">{{ $t('Shop offers', 'تسوّقي العروض') }}</a>
            </div>
        </section>

        <!-- 8. Fragrance collection -->
        <section class="nx-section" aria-labelledby="nx-frag-title">
            <div class="nx-frag" data-nx-reveal>
                <div class="nx-frag__copy">
                    <span class="nx-eyebrow">{{ $t('Fragrance collection', 'مجموعة العطور') }}</span>
                    <h2 id="nx-frag-title" class="nx-h2">{{ $t('Scents for her, for him, for everyone', 'عطور لها، له، وللجميع') }}</h2>
                    <p class="nx-lead">{{ $t('Floral, woody and fresh eaux de parfum and light body mists.', 'عطور زهرية وخشبية ومنعشة ومعطّرات جسم خفيفة.') }}</p>
                    <a class="nx-btn nx-btn--light" href="{{ $url('fragrances') }}">{{ $t('Explore fragrances', 'اكتشفي العطور') }}</a>
                </div>

                <a class="nx-frag__media" href="{{ $url('fragrances') }}" tabindex="-1" aria-hidden="true">
                    <img src="{{ $art('v7/fragrance-wine-wide.webp') }}" alt="" width="1800" height="900" loading="lazy">
                </a>
            </div>

            <div class="nx-carousel nx-carousel--quiet nx-frag__rail" data-nx-reveal>
                <x-shop::products.carousel
                    :title="$t('Signature Scents', 'عطور مختارة')"
                    :src="route('shop.api.products.index', $inCategory('fragrances') + ['sort' => 'name-asc', 'limit' => 10])"
                    :navigation-link="$url('fragrances')"
                    aria-label="{{ $t('Fragrances', 'العطور') }}"
                />
            </div>
        </section>

        <!-- 9. Men & personal care -->
        <section class="nx-section" aria-labelledby="nx-men-title">
            <header class="nx-head" data-nx-reveal>
                <div class="nx-head__text">
                    <span class="nx-eyebrow">{{ $t('For him', 'للرجال') }}</span>
                    <h2 id="nx-men-title">{{ $t('Men & personal care', 'عناية الرجل') }}</h2>
                    <p class="nx-lead">{{ $t('Skincare, beard, shaving and everyday grooming.', 'عناية بالبشرة واللحية والحلاقة والعناية اليومية.') }}</p>
                </div>

                <a class="nx-link" href="{{ $url('men') }}">{{ $t('Shop men', 'تسوّق قسم الرجال') }}</a>
            </header>

            <div class="nx-duo">
                <a class="nx-duo__tile" href="{{ $url('men') }}" data-nx-reveal>
                    <span><img src="{{ $art('v6/men.webp') }}" alt="" width="1200" height="1400" loading="lazy"></span>
                    <span class="nx-duo__label nx-link">{{ $category('men')?->name ?? $t('Men', 'الرجال') }}</span>
                </a>

                <div class="nx-carousel nx-carousel--3 nx-carousel--quiet" data-nx-reveal>
                    <x-shop::products.carousel
                        :title="$t('Grooming picks', 'مختارات العناية')"
                        :src="route('shop.api.products.index', $inCategory('men') + ['sort' => 'name-asc', 'limit' => 10])"
                        :navigation-link="$url('men')"
                        aria-label="{{ $t('Men products', 'منتجات الرجال') }}"
                    />
                </div>
            </div>
        </section>

        <!-- 10. Kids & baby care -->
        <section class="nx-band nx-band--white" aria-labelledby="nx-kids-title">
            <div class="nx-inner">
                <div class="nx-story" data-nx-reveal>
                    <div class="nx-story__copy">
                        <span class="nx-eyebrow">{{ $t('For little ones', 'للصغار') }}</span>
                        <h2 id="nx-kids-title" class="nx-h2">{{ $t('Kids & baby care', 'عناية الأطفال والرضّع') }}</h2>
                        <p class="nx-lead">{{ $t('Mild everyday care for bath time and beyond.', 'عناية يومية لطيفة لوقت الاستحمام وما بعده.') }}</p>

                        <ul class="nx-story__points">
                            <li>{{ $t('Gentle cleansers and baby shampoo', 'منظفات لطيفة وشامبو للأطفال') }}</li>
                            <li>{{ $t('Light lotions and diaper care', 'لوشن خفيف وعناية بمنطقة الحفاض') }}</li>
                            <li>{{ $t('Always read each product’s label', 'اقرئي دائمًا ملصق كل منتج') }}</li>
                        </ul>

                        <a class="nx-btn nx-btn--primary" href="{{ $url('kids-babies') }}">{{ $t('Shop kids & babies', 'تسوّقي قسم الأطفال') }}</a>
                    </div>

                    <div class="nx-story__media">
                        <img src="{{ $art('v7/kids.webp') }}" alt="" width="1200" height="1400" loading="lazy">
                    </div>
                </div>

                <div class="nx-carousel nx-carousel--quiet" data-nx-reveal>
                    <x-shop::products.carousel
                        :title="$t('Gentle favourites', 'منتجات لطيفة مختارة')"
                        :src="route('shop.api.products.index', $inCategory('kids-babies') + ['sort' => 'name-asc', 'limit' => 10])"
                        :navigation-link="$url('kids-babies')"
                        aria-label="{{ $t('Kids and babies products', 'منتجات الأطفال والرضّع') }}"
                    />
                </div>
            </div>
        </section>

        <!-- 11. New arrivals -->
        <section class="nx-section nx-carousel" aria-label="{{ $t('New arrivals', 'وصل حديثًا') }}" data-nx-reveal>
            <x-shop::products.carousel
                :title="$t('New Arrivals', 'وصل حديثًا')"
                :src="route('shop.api.products.index', ['new' => 1, 'sort' => 'created_at-desc', 'limit' => 12] + $scope)"
                :navigation-link="route('shop.search.index', ['new' => 1] + $scope)"
                aria-label="{{ $t('New arrivals', 'وصل حديثًا') }}"
            />
        </section>

        <!-- 12. Featured brands -->
        @if ($brands->isNotEmpty())
            <section class="nx-section" aria-labelledby="nx-brands-title" data-nx-reveal>
                <header class="nx-head nx-head--center">
                    <div class="nx-head__text">
                        <span class="nx-eyebrow">{{ $t('Featured brands', 'علامات مميزة') }}</span>
                        <h2 id="nx-brands-title">{{ $t('The houses behind the edit', 'العلامات وراء المختارات') }}</h2>
                    </div>
                </header>

                <div class="nx-brands">
                    @foreach ($brands as $brand)
                        <a class="nx-brand" href="{{ route('shop.search.index', ['brand' => $brand->id]) }}">
                            <span class="nx-brand__name">{{ $brand->admin_name }}</span>

                            @if ($brand->label && $brand->label !== $brand->admin_name)
                                <span class="nx-brand__label">{{ $brand->label }}</span>
                            @endif

                            @if (! empty($brandTaglines[$brand->admin_name]))
                                <span class="nx-brand__tag">{{ $brandTaglines[$brand->admin_name] }}</span>
                            @endif
                        </a>
                    @endforeach
                </div>
            </section>
        @endif

        <!-- 13. Beauty tips -->
        <section class="nx-section" aria-labelledby="nx-journal-title">
            <header class="nx-head" data-nx-reveal>
                <div class="nx-head__text">
                    <span class="nx-eyebrow">{{ $t('The Nexora Journal', 'دفتر نيكسورا') }}</span>
                    <h2 id="nx-journal-title">{{ $t('Beauty notes & simple rituals', 'ملاحظات جمال وطقوس بسيطة') }}</h2>
                </div>
            </header>

            <div class="nx-journal">
                @foreach ($tips as $index => $tip)
                    <a class="nx-article" href="{{ $url($tip['slug']) }}" data-nx-reveal style="--d: {{ $index * 0.08 }}s">
                        <span class="nx-article__media">
                            <img src="{{ $art(str_replace('editorial/', 'v7/', $tip['image'])) }}" alt="" width="1200" height="900" loading="lazy">
                        </span>
                        <span class="nx-article__tag">{{ $tip['tag'] }}</span>
                        <strong>{{ $tip['title'] }}</strong>
                        <span class="nx-article__copy">{{ $tip['copy'] }}</span>
                        <span class="nx-link">{{ $t('Shop the routine', 'تسوّقي الروتين') }}</span>
                    </a>
                @endforeach
            </div>
        </section>

        <!-- 14. Newsletter -->
        <section class="nx-section nx-newsletter" aria-labelledby="nx-news-title" data-nx-reveal data-nx-bubbles>
            <div class="nx-newsletter__copy">
                <span class="nx-eyebrow">{{ $t('Newsletter', 'النشرة البريدية') }}</span>
                <h2 id="nx-news-title" class="nx-h2">{{ $t('New arrivals and beauty notes, once a month', 'جديد المتجر وملاحظات الجمال، مرة في الشهر') }}</h2>
                <p class="nx-lead">{{ $t('No noise — just new products, routines and seasonal edits.', 'دون إزعاج — فقط منتجات جديدة وروتينات ومختارات موسمية.') }}</p>
            </div>

            @if ($newsletter)
                <x-shop::form :action="route('shop.subscription.store')" class="nx-newsletter__form">
                    <x-shop::form.control-group.control
                        type="email"
                        name="email"
                        rules="required|email"
                        :label="$t('Email', 'البريد الإلكتروني')"
                        :aria-label="$t('Email', 'البريد الإلكتروني')"
                        placeholder="email@example.com"
                    />

                    <button type="submit" class="nx-btn nx-btn--primary">{{ $t('Subscribe', 'اشتركي') }}</button>

                    <x-shop::form.control-group.error control-name="email" />
                </x-shop::form>
            @else
                <a class="nx-btn nx-btn--primary" href="{{ route('shop.customers.register.index') }}">{{ $t('Create an account', 'أنشئي حسابًا') }}</a>
            @endif
        </section>

        <!-- Admin-managed theme sections (Appearance > Themes) -->
        <div id="nexora-collections" class="nexora-home-sections">
            @foreach ($sections as $section)
                @php
                    $data = $section->options;

                    $marks = ($preview ?? false)
                        && ! $section->getTypeInstance()?->rendersInLayout();
                @endphp

                @if ($marks)
                    <div
                        data-section-id="{{ $section->id }}"
                        data-section-name="{{ $section->name }}"
                    >
                @endif

                @switch ($section->type)
                    @case (\Webkul\Theme\Enums\SectionTypeEnum::IMAGE_CAROUSEL->value)
                        <x-shop::carousel
                            :options="$section->getTypeInstance()?->sanitize((array) $data) ?? $data"
                            aria-label="{{ trans('shop::app.home.index.image-carousel') }}"
                        />

                        @break
                    @case (\Webkul\Theme\Enums\SectionTypeEnum::STATIC_CONTENT->value)
                        @if (! empty($data['css']))
                            @push ('styles')
                                <style>
                                    {!! bagisto_theme_storage()->resolveMarkup($data['css']) !!}
                                </style>
                            @endpush
                        @endif

                        @if (! empty($data['html']))
                            {!! bagisto_theme_storage()->resolveMarkup($data['html']) !!}
                        @endif

                        @break
                    @case (\Webkul\Theme\Enums\SectionTypeEnum::CATEGORY_CAROUSEL->value)
                        <x-shop::categories.carousel
                            :title="$data['title'] ?? ''"
                            :src="route('shop.api.categories.index', array_merge((array) ($data['filters'] ?? []), $catalogQuery ?? []))"
                            :navigation-link="route('shop.home.index')"
                            aria-label="{{ trans('shop::app.home.index.categories-carousel') }}"
                        />

                        @break
                    @case (\Webkul\Theme\Enums\SectionTypeEnum::PRODUCT_CAROUSEL->value)
                        <x-shop::products.carousel
                            :title="$data['title'] ?? ''"
                            :src="route('shop.api.products.index', array_merge((array) ($data['filters'] ?? []), $catalogQuery ?? []))"
                            :navigation-link="route('shop.search.index', $data['filters'] ?? [])"
                            aria-label="{{ trans('shop::app.home.index.product-carousel') }}"
                        />

                        @break
                @endswitch

                @if ($marks)
                    </div>
                @endif
            @endforeach
        </div>

        @if ($preview ?? false)
            @include('shop::home.preview-bridge')
        @endif
    </div>
</x-shop::layouts>
