import sys, re
root = sys.argv[1]
S = root + '/packages/Webkul/Shop/src/Resources/views/'
T = root + '/resources/themes/nexora/views/'

CAT_INTRO = '''
    @php
        $nexoraIsArabic = str_starts_with(app()->getLocale(), 'ar');
        $nexoraChildren = $category->children()->where('status', 1)->orderBy('position')->get();
        $nexoraParent = $category->parent_id && $category->parent_id != core()->getCurrentChannel()->root_category_id ? $category->parent : null;
    @endphp

    <!-- Nexora category intro -->
    <section class="nexora-category-intro" aria-labelledby="nexora-category-title">
        <div class="nexora-category-intro__copy">
            @if ($nexoraParent)
                <a class="nexora-category-intro__parent" href="{{ $nexoraParent->url }}">{{ $nexoraParent->name }}</a>
            @else
                <span class="nexora-category-intro__parent">{{ $nexoraIsArabic ? 'نيكسورا للجمال والعناية' : 'Nexora Beauty & Personal Care' }}</span>
            @endif

            <h1 id="nexora-category-title">{{ $category->name }}</h1>

            @if (trim(strip_tags((string) $category->description)))
                <div class="nexora-category-intro__text">{!! strip_tags((string) $category->description, '<p><br><strong><em>') !!}</div>
            @endif
        </div>

        @if ($nexoraChildren->isNotEmpty())
            <nav class="nexora-category-chips" aria-label="{{ $nexoraIsArabic ? 'الأقسام الفرعية' : 'Subcategories' }}">
                @foreach ($nexoraChildren as $nexoraChild)
                    <a href="{{ $nexoraChild->url }}">
                        @if ($nexoraChild->logo_url)
                            <img src="{{ $nexoraChild->logo_url }}" alt="" width="44" height="44" loading="lazy">
                        @endif
                        <span>{{ $nexoraChild->name }}</span>
                    </a>
                @endforeach
            </nav>
        @endif
    </section>
'''

def wrap(rel, cls, intro=''):
    src = open(S + rel, encoding='utf-8').read()
    start = src.index('<x-shop::layouts>')
    slot_end = src.index('    </x-slot>\n', start) + len('    </x-slot>\n')
    end_anchor = src.rfind('\n    @pushOnce')
    if end_anchor == -1 or end_anchor < slot_end:
        end_anchor = src.rfind('\n</x-shop::layouts>')
    out = src[:slot_end] + '\n    <div class="' + cls + '">\n' + intro + src[slot_end:end_anchor] + '\n    </div>\n' + src[end_anchor:]
    open(T + rel, 'w', encoding='utf-8', newline='\n').write(out)
    print('wrote', rel, len(out))

wrap('categories/view.blade.php', 'nexora-category-page', CAT_INTRO)
wrap('products/view.blade.php', 'nexora-product-detail')
