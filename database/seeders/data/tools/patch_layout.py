import sys
root = sys.argv[1] + '/resources/themes/nexora/views/components/layouts/'

def patch(rel, old, new, count=1):
    p = root + rel
    s = open(p, encoding='utf-8').read()
    if new in s:
        print('already', rel); return
    if old not in s:
        raise SystemExit('anchor not found in ' + rel + ': ' + old[:60])
    s = s.replace(old, new, count)
    open(p, 'w', encoding='utf-8', newline='\n').write(s)
    print('patched', rel)

# Fonts: add Arabic display + text faces next to the Latin ones
patch('index.blade.php',
      'family=Manrope:wght@400;500;600;700;800&family=Cormorant+Garamond:wght@500;600;700&family=Noto+Naskh+Arabic:wght@400;500;600;700&display=swap"\n        />\n\n        <link\n            rel="stylesheet"',
      'family=Manrope:wght@400;500;600;700;800&family=Cormorant+Garamond:wght@500;600;700&family=Noto+Naskh+Arabic:wght@400;500;600;700&family=IBM+Plex+Sans+Arabic:wght@400;500;600;700&display=swap"\n        />\n\n        <link\n            rel="stylesheet"')
patch('index.blade.php',
      'href="https://fonts.googleapis.com/css2?family=Manrope:wght@400;500;600;700;800&family=Cormorant+Garamond:wght@500;600;700&family=Noto+Naskh+Arabic:wght@400;500;600;700&display=swap"\n        />\n\n        @stack',
      'href="https://fonts.googleapis.com/css2?family=Manrope:wght@400;500;600;700;800&family=Cormorant+Garamond:wght@500;600;700&family=Noto+Naskh+Arabic:wght@400;500;600;700&family=IBM+Plex+Sans+Arabic:wght@400;500;600;700&display=swap"\n        />\n\n        @stack')
patch('index.blade.php', '<body>\n', '<body class="nexora-theme">\n')

# Announcement bar above the sticky header
patch('header/index.blade.php',
      '<header class="nexora-header',
      '''@php($nexoraAr = str_starts_with(app()->getLocale(), 'ar'))
<div class="nexora-announcement" role="note">
    <span>{{ $nexoraAr ? 'متجر تجريبي — المنتجات والأسعار للعرض فقط' : 'Demo store — products and prices are for presentation only' }}</span>
    <span class="nexora-announcement__sep" aria-hidden="true">✦</span>
    <span>{{ $nexoraAr ? 'جمال وعناية للنساء والرجال والأطفال' : 'Beauty & personal care for women, men and kids' }}</span>
</div>

<header class="nexora-header''')

# Footer: brand copy, shop column, disclaimer
patch('footer/index.blade.php',
      "<p>{{ str_starts_with(app()->getLocale(), 'ar') ? 'مساحة هادئة لاكتشاف العناية والجمال، واختيار ما يشبهك.' : 'A thoughtful edit of skincare and beauty, chosen to feel like you.' }}</p>",
      "<p>{{ str_starts_with(app()->getLocale(), 'ar') ? 'نيكسورا للجمال والعناية الشخصية — عناية بالبشرة ومكياج وعناية للرجل ومنتجات لطيفة للأطفال والرضّع.' : 'Nexora Beauty & Personal Care — skincare, makeup, grooming and gentle care for kids and babies.' }}</p>")

patch('footer/index.blade.php',
      '''    <div class="flex justify-between gap-x-6 gap-y-8 p-15 max-1060:flex-col-reverse max-md:gap-5 max-md:p-8 max-sm:px-4 max-sm:py-5">
''',
      '''    @php
        $nexoraFooterAr = str_starts_with(app()->getLocale(), 'ar');

        $nexoraFooterLinks = collect([
            ['slugs' => ['women', 'nx-women'], 'label' => $nexoraFooterAr ? 'النساء' : 'Women'],
            ['slugs' => ['men', 'nx-men'], 'label' => $nexoraFooterAr ? 'الرجال' : 'Men'],
            ['slugs' => ['kids-babies', 'nx-kids-babies'], 'label' => $nexoraFooterAr ? 'الأطفال والرضّع' : 'Kids & Babies'],
        ])->map(function ($item) {
            $categoryId = \\Illuminate\\Support\\Facades\\DB::table('category_translations')->whereIn('slug', $item['slugs'])->value('category_id');

            $category = $categoryId ? app(\\Webkul\\Category\\Repositories\\CategoryRepository::class)->find($categoryId) : null;

            return $category ? ['url' => $category->url, 'label' => $category->name ?: $item['label']] : null;
        })->filter();
    @endphp

    <nav class="nexora-footer-shop" aria-label="{{ $nexoraFooterAr ? 'تسوّق' : 'Shop' }}">
        <p class="nexora-footer-shop__title">{{ $nexoraFooterAr ? 'تسوّق' : 'Shop' }}</p>
        <ul>
            @foreach ($nexoraFooterLinks as $nexoraLink)
                <li><a href="{{ $nexoraLink['url'] }}">{{ $nexoraLink['label'] }}</a></li>
            @endforeach
            <li><a href="{{ route('shop.search.index', ['new' => 1]) }}">{{ $nexoraFooterAr ? 'وصل حديثًا' : 'New Arrivals' }}</a></li>
            <li><a href="{{ route('shop.checkout.cart.index') }}">{{ $nexoraFooterAr ? 'سلة التسوق' : 'Shopping Cart' }}</a></li>
        </ul>
    </nav>

    <div class="flex justify-between gap-x-6 gap-y-8 p-15 max-1060:flex-col-reverse max-md:gap-5 max-md:p-8 max-sm:px-4 max-sm:py-5">
''')

patch('footer/index.blade.php',
      "        {!! view_render_event('bagisto.shop.layout.footer.footer_text.after') !!}",
      """        <p class="nexora-footer-note">{{ str_starts_with(app()->getLocale(), 'ar') ? 'جميع المنتجات والعلامات والأسعار في هذا المتجر تجريبية لأغراض العرض فقط.' : 'All products, brands and prices in this store are demo data for presentation only.' }}</p>

        {!! view_render_event('bagisto.shop.layout.footer.footer_text.after') !!}""")
