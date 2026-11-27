import sys, os
root = sys.argv[1]
V = root + '/resources/themes/nexora/views/'

def rw(path, fn):
    s = open(path, encoding='utf-8').read()
    n = fn(s)
    if n != s:
        open(path, 'w', encoding='utf-8', newline='\n').write(n)
        print('patched', path.replace(root, ''))
    else:
        print('unchanged', path.replace(root, ''))

# 1) Footer copyright -> Nexora with link
def footer(s):
    old = """            <x-shop::layouts.footer.copyright>
                @lang('shop::app.components.layouts.footer.footer-text', ['current_year'=> date('Y') ])
            </x-shop::layouts.footer.copyright>"""
    new = """            © {{ date('Y') }}
            <a href="https://nexorawebsite-1.onrender.com/" target="_blank" rel="noopener" class="nexora-footer-credit">Nexora Tech Solutions</a>
            — {{ str_starts_with(app()->getLocale(), 'ar') ? 'جميع الحقوق محفوظة.' : 'All rights reserved.' }}"""
    return s.replace(old, new, 1)
rw(V + 'components/layouts/footer/index.blade.php', footer)

# 2) Mini cart: replace stock offer text with Nexora copy (theme override of the stock view)
src = root + '/packages/Webkul/Shop/src/Resources/views/checkout/cart/mini-cart.blade.php'
dst = V + 'checkout/cart/mini-cart.blade.php'
if not os.path.exists(dst):
    os.makedirs(os.path.dirname(dst), exist_ok=True)
    s = open(src, encoding='utf-8').read()
    s = s.replace("{{ core()->getConfigData('sales.checkout.mini_cart.offer_info')}}", "{{ str_starts_with(app()->getLocale(), 'ar') ? 'منتجات تجريبية للعرض — الدفع في هذا المتجر للتجربة فقط.' : 'Demo products — checkout in this store is for testing only.' }}", 1)
    open(dst, 'w', encoding='utf-8', newline='\n').write(s)
    print('mini-cart override written')

# 3) Product cards reveal softly when they enter the viewport
def card(s):
    return s.replace('''        <article
            class="nx-card group"
            v-if="mode != 'list'"
        >''', '''        <article
            class="nx-card group"
            v-if="mode != 'list'"
            data-nx-reveal
        >''', 1)
rw(V + 'components/products/card.blade.php', card)

# 4) Home: animated ticker under the hero
def home(s):
    if 'nx-ticker' in s:
        return s
    anchor = "        <!-- Shop by Category -->"
    ticker = """        <!-- Ticker -->
        <div class="nx-ticker" aria-hidden="true">
            <div class="nx-ticker__track">
                @for ($i = 0; $i < 2; $i++)
                    @foreach ($departments as $department)
                        <span>{{ $department->name }}</span><i>✦</i>
                    @endforeach
                @endfor
            </div>
        </div>

"""
    return s.replace(anchor, ticker + anchor, 1)
rw(V + 'home/index.blade.php', home)
