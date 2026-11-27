import sys
p = sys.argv[1] + '/resources/themes/nexora/views/products/view.blade.php'
s = open(p, encoding='utf-8').read()
if 'nexora-pdp-facts' in s:
    print('already'); sys.exit(0)
anchor_name = "                                {!! view_render_event('bagisto.shop.products.name.before', ['product' => $product]) !!}\n"
assert anchor_name in s
brand_block = anchor_name + r'''
                                @php
                                    $nxAr = str_starts_with(app()->getLocale(), 'ar');

                                    $nxOption = function (?string $code) use ($product) {
                                        $value = $product->{$code} ?? null;

                                        if (! $value) {
                                            return null;
                                        }

                                        $option = \Webkul\Attribute\Models\AttributeOption::find($value);

                                        return $option ? ($option->label ?: $option->admin_name) : null;
                                    };

                                    $nxBrand = $nxOption('brand');

                                    $nxFacts = array_filter([
                                        $nxAr ? 'الحجم' : 'Size' => $product->nx_size ?? null,
                                        $nxAr ? 'مناسب لـ' : 'Suitable for' => $nxOption('nx_skin_type'),
                                        $nxAr ? 'الفئة العمرية' : 'Age group' => $nxOption('nx_age_group'),
                                        'SKU' => $product->sku,
                                    ]);

                                    $nxInStock = $product->getTypeInstance()->isSaleable();

                                    $nxIngredients = trim((string) ($product->nx_ingredients ?? ''));

                                    $nxHowTo = trim((string) ($product->nx_how_to_use ?? ''));
                                @endphp

                                @if ($nxBrand)
                                    <p class="nexora-pdp-brand" v-pre>{{ $nxBrand }}</p>
                                @endif
'''
s = s.replace(anchor_name, brand_block, 1)
anchor_sd = "                                {!! view_render_event('bagisto.shop.products.short_description.after', ['product' => $product]) !!}\n"
assert anchor_sd in s
facts = anchor_sd + r'''
                                <!-- Nexora product facts -->
                                <div class="nexora-pdp-facts" v-pre>
                                    <p class="nexora-pdp-stock {{ $nxInStock ? 'is-in' : 'is-out' }}">
                                        <span aria-hidden="true"></span>
                                        {{ $nxInStock ? ($nxAr ? 'متوفر' : 'In stock') : ($nxAr ? 'غير متوفر حاليًا' : 'Currently unavailable') }}
                                    </p>

                                    @if ($nxFacts)
                                        <dl>
                                            @foreach ($nxFacts as $nxLabel => $nxValue)
                                                <div>
                                                    <dt>{{ $nxLabel }}</dt>
                                                    <dd>{{ $nxValue }}</dd>
                                                </div>
                                            @endforeach
                                        </dl>
                                    @endif

                                    @if ($nxIngredients || $nxHowTo)
                                        <div class="nexora-pdp-more">
                                            @if ($nxIngredients)
                                                <details open>
                                                    <summary>{{ $nxAr ? 'المكونات الرئيسية' : 'Key ingredients' }}</summary>
                                                    <p>{{ $nxIngredients }}</p>
                                                </details>
                                            @endif

                                            @if ($nxHowTo)
                                                <details>
                                                    <summary>{{ $nxAr ? 'طريقة الاستخدام' : 'How to use' }}</summary>
                                                    <p>{{ $nxHowTo }}</p>
                                                </details>
                                            @endif
                                        </div>
                                    @endif
                                </div>
'''
s = s.replace(anchor_sd, facts, 1)
open(p, 'w', encoding='utf-8', newline='\n').write(s)
print('pdp patched')
