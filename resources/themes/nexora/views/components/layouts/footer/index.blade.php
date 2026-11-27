{!! view_render_event('bagisto.shop.layout.footer.before') !!}

@inject('sectionRepository', \Webkul\Theme\Repositories\SectionRepository::class)

@php
    $channel = core()->getCurrentChannel();

    $isArabic = str_starts_with(app()->getLocale(), 'ar');

    $t = fn (string $en, string $ar) => $isArabic ? $ar : $en;

    /* Information links managed in Admin > Appearance > Themes > Footer Links */
    $section = $sectionRepository->findOneOfType(
        \Webkul\Theme\Enums\SectionTypeEnum::FOOTER_LINKS->value,
        $channel->id,
        $channel->theme,
        app()->getLocale()
    );

    $infoLinks = collect($section?->getTypeInstance()?->sanitize((array) $section->options) ?? [])
        ->flatMap(fn ($links) => array_filter((array) $links, fn ($link) => filled($link['title'] ?? null) && filled($link['url'] ?? null)))
        ->unique('url')
        ->values();

    /* Department links read from the live category tree */
    $categoryRepository = app(\Webkul\Category\Repositories\CategoryRepository::class);

    $shopLinks = collect(['skincare', 'makeup', 'hair-care', 'body-care', 'fragrances', 'women', 'men', 'kids-babies'])
        ->map(function ($slug) use ($categoryRepository) {
            $id = \Illuminate\Support\Facades\DB::table('category_translations as t')
                ->join('categories as c', 'c.id', '=', 't.category_id')
                ->where('c.status', 1)
                ->whereIn('t.slug', [$slug, 'nx-'.$slug])
                ->value('c.id');

            $category = $id ? $categoryRepository->find($id) : null;

            return $category ? ['url' => $category->url, 'label' => $category->name] : null;
        })
        ->filter()
        ->values();

    $newsletter = (bool) core()->getConfigData('customer.settings.newsletter.subscription');
@endphp

<footer
    class="nexora-footer nxf"
    @if ($section && $sectionRepository->isPreviewing())
        data-section-id="{{ $section->id }}"
        data-section-name="{{ $section->name }}"
    @endif
>
    <div class="nxf__top">
        <!-- Brand -->
        <div class="nxf__brand">
            <a href="{{ route('shop.home.index') }}" class="nexora-brand-link" aria-label="Nexora">
                <span class="nexora-logo-mark nxf__mark" aria-hidden="true">
                    <svg viewBox="0 0 32 32" fill="none" xmlns="http://www.w3.org/2000/svg">
                        <path d="M16 15.1C12.9 11.6 13.3 7.3 16 3.5c2.7 3.8 3.1 8.1 0 11.6Z" fill="currentColor"/>
                        <path d="M16.9 16c3.5-3.1 7.8-2.7 11.6 0-3.8 2.7-8.1 3.1-11.6 0Z" fill="currentColor" opacity=".82"/>
                        <path d="M16 16.9c3.1 3.5 2.7 7.8 0 11.6-2.7-3.8-3.1-8.1 0-11.6Z" fill="currentColor" opacity=".68"/>
                        <path d="M15.1 16c-3.5 3.1-7.8 2.7-11.6 0 3.8-2.7 8.1-3.1 11.6 0Z" fill="currentColor" opacity=".9"/>
                        <circle cx="16" cy="16" r="2.3" fill="#D4B77E"/>
                    </svg>
                </span>
                <span class="nexora-wordmark">Nexora</span>
            </a>

            <p class="nxf__about">{{ $t('Beauty & personal care: skincare, makeup, hair, body care and fragrance for women and men, with gentle care for kids and babies.', 'الجمال والعناية الشخصية: عناية بالبشرة ومكياج وشعر وجسم وعطور للنساء والرجال، مع عناية لطيفة للأطفال والرضّع.') }}</p>

            <p class="nxf__note">{{ $t('Products, brands and prices in this store are demo data for presentation.', 'المنتجات والعلامات والأسعار في هذا المتجر بيانات تجريبية للعرض.') }}</p>
        </div>

        <!-- Shop -->
        @if ($shopLinks->isNotEmpty())
            <nav class="nxf__col" aria-labelledby="nxf-shop">
                <p class="nxf__title" id="nxf-shop">{{ $t('Shop', 'تسوّق') }}</p>

                <ul>
                    @foreach ($shopLinks as $link)
                        <li><a href="{{ $link['url'] }}">{{ $link['label'] }}</a></li>
                    @endforeach
                </ul>
            </nav>
        @endif

        <!-- Information (admin-managed CMS links) -->
        <nav class="nxf__col" aria-labelledby="nxf-info" v-pre>
            <p class="nxf__title" id="nxf-info">{{ $t('Information', 'معلومات') }}</p>

            <ul>
                @foreach ($infoLinks as $link)
                    <li><a href="{{ url($link['url']) }}">{{ $link['title'] }}</a></li>
                @endforeach

                <li><a href="{{ route('shop.checkout.cart.index') }}">{{ $t('Shopping cart', 'سلة التسوق') }}</a></li>
                <li><a href="{{ route('shop.customer.session.index') }}">{{ $t('My account', 'حسابي') }}</a></li>
            </ul>
        </nav>

        <!-- Newsletter -->
        {!! view_render_event('bagisto.shop.layout.footer.newsletter_subscription.before') !!}

        @if ($newsletter)
            <div class="nxf__col nxf__newsletter">
                <p class="nxf__title" role="heading" aria-level="2">{{ $t('Newsletter', 'النشرة البريدية') }}</p>

                <p class="nxf__lead">{{ $t('New arrivals and beauty notes, once a month.', 'جديد المتجر وملاحظات الجمال، مرة في الشهر.') }}</p>

                <x-shop::form
                    :action="route('shop.subscription.store')"
                    class="nxf__form"
                    toolname="subscribe_to_newsletter"
                    tooldescription="{{ trans('shop::app.components.layouts.webmcp.subscribe-newsletter') }}"
                    toolautosubmit
                >
                    <x-shop::form.control-group.control
                        type="email"
                        name="email"
                        rules="required|email"
                        label="Email"
                        :aria-label="trans('shop::app.components.layouts.footer.email')"
                        placeholder="email@example.com"
                        toolparamdescription="{{ trans('shop::app.components.layouts.webmcp.subscribe-newsletter-email') }}"
                    />

                    <button type="submit">@lang('shop::app.components.layouts.footer.subscribe')</button>

                    <x-shop::form.control-group.error control-name="email" />
                </x-shop::form>
            </div>
        @endif

        {!! view_render_event('bagisto.shop.layout.footer.newsletter_subscription.after') !!}
    </div>

    <!-- Copyright -->
    <div class="nxf__bottom">
        {!! view_render_event('bagisto.shop.layout.footer.footer_text.before') !!}

        <p class="nxf__copy" dir="{{ $isArabic ? 'rtl' : 'ltr' }}">
            <bdi dir="ltr">© {{ date('Y') }} <a href="https://nexorawebsite-1.onrender.com/" target="_blank" rel="noopener" class="nxf__credit">Nexora Tech Solutions</a></bdi>
            <span>— {{ $t('All rights reserved.', 'جميع الحقوق محفوظة.') }}</span>
        </p>

        {!! view_render_event('bagisto.shop.layout.footer.footer_text.after') !!}
    </div>
</footer>

{!! view_render_event('bagisto.shop.layout.footer.after') !!}
