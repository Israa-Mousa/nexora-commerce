import sys
p = sys.argv[1] + '/resources/themes/nexora/views/components/products/card.blade.php'
s = open(p, encoding='utf-8').read()
if 'nx-card__media' in s:
    print('already patched'); sys.exit(0)
a = s.index('        <!-- Grid Card -->')
b = s.index('        <!-- List Card -->')
grid = r'''        <!-- Grid Card (Nexora) -->
        <article
            class="nx-card group"
            v-if="mode != 'list'"
        >
            <div class="nx-card__media">
                {!! view_render_event('bagisto.shop.components.products.card.image.before') !!}

                <a
                    class="nx-card__image-link"
                    :href="productUrl"
                    :aria-label="product.name"
                >
                    <x-shop::media.images.lazy
                        class="nx-card__img"
                        ::src="product.base_image.medium_image_url"
                        ::srcset="`
                            ${product.base_image.small_image_url} 150w,
                            ${product.base_image.medium_image_url} 300w,
                            ${product.base_image.large_image_url ?? product.base_image.medium_image_url} 600w,
                        `"
                        sizes="(max-width: 768px) 50vw, (max-width: 1200px) 25vw, 320px"
                        ::key="product.id"
                        ::index="product.id"
                        width="300"
                        height="300"
                        ::alt="product.base_image.alt || product.name"
                    />

                    <img
                        class="nx-card__img-hover"
                        v-if="meta.hover_image"
                        :src="meta.hover_image"
                        alt=""
                        aria-hidden="true"
                        loading="lazy"
                        width="300"
                        height="300"
                    >
                </a>

                {!! view_render_event('bagisto.shop.components.products.card.image.after') !!}

                {!! view_render_event('bagisto.shop.components.products.card.average_ratings.before') !!}

                @if (core()->getConfigData('catalog.products.review.summary') == 'star_counts')
                    <x-shop::products.ratings
                        class="nx-card__rating"
                        ::average="product.ratings.average"
                        ::total="product.ratings.total"
                        ::rating="false"
                        v-if="product.ratings.total"
                    />
                @else
                    <x-shop::products.ratings
                        class="nx-card__rating"
                        ::average="product.ratings.average"
                        ::total="product.reviews.total"
                        ::rating="false"
                        v-if="product.reviews.total"
                    />
                @endif

                {!! view_render_event('bagisto.shop.components.products.card.average_ratings.after') !!}

                <div class="nx-card__badges">
                    <span
                        class="nx-badge nx-badge--sale"
                        v-if="product.on_sale"
                    >
                        @lang('shop::app.components.products.card.sale')
                    </span>

                    <span
                        class="nx-badge nx-badge--new"
                        v-else-if="product.is_new"
                    >
                        @lang('shop::app.components.products.card.new')
                    </span>

                    <span
                        class="nx-badge nx-badge--best"
                        v-if="meta.best_seller"
                    >
                        {{ str_starts_with(app()->getLocale(), 'ar') ? 'الأكثر مبيعًا' : 'Best Seller' }}
                    </span>
                </div>

                <div class="nx-card__icons">
                    {!! view_render_event('bagisto.shop.components.products.card.wishlist_option.before') !!}

                    @if (core()->getConfigData('customer.settings.wishlist.wishlist_option'))
                        <button
                            type="button"
                            class="nx-card__icon"
                            :class="product.is_wishlist ? 'icon-heart-fill is-active' : 'icon-heart'"
                            aria-label="@lang('shop::app.components.products.card.add-to-wishlist')"
                            @click="addToWishlist()"
                        >
                        </button>
                    @endif

                    {!! view_render_event('bagisto.shop.components.products.card.wishlist_option.after') !!}

                    {!! view_render_event('bagisto.shop.components.products.card.compare_option.before') !!}

                    @if (core()->getConfigData('catalog.products.settings.compare_option'))
                        <button
                            type="button"
                            class="nx-card__icon icon-compare"
                            aria-label="@lang('shop::app.components.products.card.add-to-compare')"
                            @click="addToCompare(product.id)"
                        >
                        </button>
                    @endif

                    {!! view_render_event('bagisto.shop.components.products.card.compare_option.after') !!}
                </div>

                @if (core()->getConfigData('sales.checkout.shopping_cart.cart_page'))
                    {!! view_render_event('bagisto.shop.components.products.card.add_to_cart.before') !!}

                    <button
                        type="button"
                        class="nx-card__quick"
                        :class="{ 'is-loading': isAddingToCart }"
                        :disabled="! product.is_saleable || isAddingToCart"
                        @click="addToCart()"
                    >
                        <span class="icon-cart" aria-hidden="true"></span>

                        <span>@lang('shop::app.components.products.card.add-to-cart')</span>
                    </button>

                    {!! view_render_event('bagisto.shop.components.products.card.add_to_cart.after') !!}
                @endif
            </div>

            <div class="nx-card__body">
                <p
                    class="nx-card__brand"
                    v-if="meta.brand"
                    v-text="meta.brand"
                ></p>

                {!! view_render_event('bagisto.shop.components.products.card.name.before') !!}

                <a
                    class="nx-card__name"
                    :href="productUrl"
                    v-text="product.name"
                ></a>

                {!! view_render_event('bagisto.shop.components.products.card.name.after') !!}

                <p
                    class="nx-card__size"
                    v-if="meta.size"
                    v-text="meta.size"
                ></p>

                {!! view_render_event('bagisto.shop.components.products.card.price.before') !!}

                <div
                    class="nx-card__price"
                    v-html="product.price_html"
                >
                </div>

                {!! view_render_event('bagisto.shop.components.products.card.price.after') !!}
            </div>
        </article>

'''
s = s[:a] + grid + s[b:]
s = s.replace("""                    isAddingToCart: false,
                }
            },
""", """                    isAddingToCart: false,

                    meta: {},
                }
            },

            computed: {
                productUrl() {
                    return '{{ route('shop.product_or_category.index', ':slug') }}'.replace(':slug', this.product.url_key);
                },
            },

            mounted() {
                this.loadMeta();
            },

            watch: {
                'product.id'() {
                    this.loadMeta();
                },
            },
""", 1)
s = s.replace("""            methods: {
                addToWishlist() {""", """            methods: {
                loadMeta() {
                    if (! window.NexoraMeta || ! this.product?.id) {
                        return;
                    }

                    const id = this.product.id;

                    window.NexoraMeta.get(id).then(meta => {
                        if (this.product.id === id) {
                            this.meta = meta || {};
                        }
                    });
                },

                addToWishlist() {""", 1)
open(p, 'w', encoding='utf-8', newline='\n').write(s)
print('patched', 'loadMeta' in s, 'productUrl()' in s)
