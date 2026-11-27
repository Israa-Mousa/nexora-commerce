import sys, shutil, os
root = sys.argv[1]
S = root + '/packages/Webkul/Shop/src/Resources/views/components/layouts/header/desktop/top.blade.php'
T = root + '/resources/themes/nexora/views/components/layouts/header/desktop/top.blade.php'
s = open(S, encoding='utf-8').read()
start = s.index('            <p\n                class="py-3 text-xs font-medium"')
end = s.index('            </p>', start) + len('            </p>')
offer = '''            @php($nxAr = str_starts_with(app()->getLocale(), 'ar'))

            <p class="nexora-topbar__offer" v-pre>
                <span>{{ $nxAr ? 'عناية بالبشرة، مكياج، شعر، جسم وعطور — للنساء والرجال والأطفال' : 'Skincare, makeup, hair, body & fragrance — for women, men and kids' }}</span>
                <span class="nexora-topbar__dot" aria-hidden="true">✦</span>
                <span>{{ $nxAr ? 'متجر تجريبي: المنتجات والأسعار للعرض فقط' : 'Demo store: products and prices are for presentation only' }}</span>
                <a href="{{ route('shop.search.index', ['new' => 1]) }}">{{ $nxAr ? 'وصل حديثًا' : 'New arrivals' }}</a>
            </p>'''
s = s[:start] + offer + s[end:]
s = s.replace('<div class="flex w-full items-center justify-between border border-b border-l-0 border-r-0 border-t-0 px-16">', '<div class="nexora-topbar flex w-full items-center justify-between px-16">', 1)
os.makedirs(os.path.dirname(T), exist_ok=True)
open(T, 'w', encoding='utf-8', newline='\n').write(s)
print('topbar written')

# Header: announcement only on small screens (the desktop top bar carries it on large screens)
H = root + '/resources/themes/nexora/views/components/layouts/header/index.blade.php'
h = open(H, encoding='utf-8').read()
h = h.replace('<div class="nexora-announcement" role="note">', '<div class="nexora-announcement lg:hidden" role="note">', 1)
h = h.replace("""@if(core()->getCurrentChannel()->locales()->count() > 1 || core()->getCurrentChannel()->currencies()->count() > 1 )
    <div class="max-lg:hidden">""", """@if(core()->getCurrentChannel()->locales()->count() > 1 || core()->getCurrentChannel()->currencies()->count() > 1 )
    <div class="nexora-topbar-wrap max-lg:hidden">""", 1)
open(H, 'w', encoding='utf-8', newline='\n').write(h)
print('header patched')

# Desktop nav: always use the mega-menu layout (theme decision), not the sidebar drawer
B = root + '/resources/themes/nexora/views/components/layouts/header/desktop/bottom.blade.php'
b = open(B, encoding='utf-8').read()
b = b.replace("""v-else-if="'{{ core()->getConfigData('general.design.categories.category_view') }}' !== 'sidebar'\"""", 'v-else-if="true"', 1)
b = b.replace('<div class="flex min-h-19.5 w-full justify-between border border-b border-l-0 border-r-0 border-t-0 px-15 max-1180:px-8">', '<div class="nexora-navbar flex min-h-19.5 w-full justify-between px-15 max-1180:px-8">', 1)
open(B, 'w', encoding='utf-8', newline='\n').write(b)
print('nav patched', 'v-else-if="true"' in b)

# Layout: load nexora.js (deferred, before Vue mounts)
L = root + '/resources/themes/nexora/views/components/layouts/index.blade.php'
l = open(L, encoding='utf-8').read()
if 'nexora.js' not in l:
    l = l.replace("""        <link rel="stylesheet" href="{{ asset('themes/shop/nexora/nexora.css') }}?v={{ filemtime(public_path('themes/shop/nexora/nexora.css')) }}">""", """        <link rel="stylesheet" href="{{ asset('themes/shop/nexora/nexora.css') }}?v={{ filemtime(public_path('themes/shop/nexora/nexora.css')) }}">

        <script src="{{ asset('themes/shop/nexora/nexora.js') }}?v={{ filemtime(public_path('themes/shop/nexora/nexora.js')) }}" defer></script>""", 1)
    open(L, 'w', encoding='utf-8', newline='\n').write(l)
print('layout', 'nexora.js' in l)

# Route for card meta
R = root + '/routes/web.php'
r = open(R, encoding='utf-8').read()
if 'nexora/product-meta' not in r:
    r = r.rstrip() + """

use App\\Http\\Controllers\\NexoraProductMetaController;
use Illuminate\\Support\\Facades\\Route;

// Nexora storefront: brand / size / best-seller / hover image for product cards (read-only).
Route::get('nexora/product-meta', NexoraProductMetaController::class)->name('nexora.product-meta');
"""
    open(R, 'w', encoding='utf-8', newline='\n').write(r)
print('route ok')
