<v-product-card
    {{ $attributes->class(['nexora-product-card']) }}
    :product="product"
>
</v-product-card>

@pushOnce('scripts')
    <script
        type="text/x-template"
        id="v-product-card-template"
    >
        <!-- Grid Card (Nexora) -->
        <article
            class="nx-card group"
            v-if="mode != 'list'"
            data-nx-reveal
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
                        v-if="meta.best_seller && ! product.on_sale && ! product.is_new"
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
                    dir="auto"
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

        <!-- List Card -->
        <div
            class="relative flex max-w-max grid-cols-2 gap-4 overflow-hidden rounded-sm max-sm:flex-wrap"
            v-else
        >
            <div class="group relative max-h-64.5 max-w-62.5 overflow-hidden">

                {!! view_render_event('bagisto.shop.components.products.card.image.before') !!}

                <a :href="'{{ route('shop.product_or_category.index', ':slug') }}'.replace(':slug', product.url_key)">
                    <x-shop::media.images.lazy
                        class="after:content-[' '] relative min-w-62.5 bg-zinc-100 transition-all duration-300 after:block after:pb-[calc(100%+9px)] group-hover:scale-105"
                        ::src="product.base_image.medium_image_url"
                        ::key="product.id"
                        ::index="product.id"
                        width="291"
                        height="300"
                        ::alt="product.base_image.alt"
                    />
                </a>

                {!! view_render_event('bagisto.shop.components.products.card.image.after') !!}

                <div class="action-items bg-black">
                    <p
                        class="absolute top-5 inline-block rounded-full bg-red-500 px-2.5 text-sm text-white ltr:left-5 max-sm:ltr:left-2 rtl:right-5"
                        v-if="product.on_sale"
                    >
                        @lang('shop::app.components.products.card.sale')
                    </p>

                    <p
                        class="absolute top-5 inline-block rounded-full bg-navyBlue px-2.5 text-sm text-white ltr:left-5 max-sm:ltr:left-2 rtl:right-5"
                        v-else-if="product.is_new"
                    >
                        @lang('shop::app.components.products.card.new')
                    </p>

                    <div class="opacity-0 transition-all duration-300 group-hover:bottom-0 group-hover:opacity-100 max-sm:opacity-100">

                        {!! view_render_event('bagisto.shop.components.products.card.wishlist_option.before') !!}

                        @if (core()->getConfigData('customer.settings.wishlist.wishlist_option'))
                            <span
                                class="absolute top-5 flex h-7.5 w-7.5 cursor-pointer items-center justify-center rounded-md bg-white text-2xl ltr:right-5 rtl:left-5"
                                role="button"
                                aria-label="@lang('shop::app.components.products.card.add-to-wishlist')"
                                tabindex="0"
                                :class="product.is_wishlist ? 'icon-heart-fill text-red-600' : 'icon-heart'"
                                @click="addToWishlist()"
                            >
                            </span>
                        @endif

                        {!! view_render_event('bagisto.shop.components.products.card.wishlist_option.after') !!}

                        {!! view_render_event('bagisto.shop.components.products.card.compare_option.before') !!}

                        @if (core()->getConfigData('catalog.products.settings.compare_option'))
                            <span
                                class="icon-compare absolute top-16 flex h-7.5 w-7.5 cursor-pointer items-center justify-center rounded-md bg-white text-2xl ltr:right-5 rtl:left-5"
                                role="button"
                                aria-label="@lang('shop::app.components.products.card.add-to-compare')"
                                tabindex="0"
                                @click="addToCompare(product.id)"
                            >
                            </span>
                        @endif

                        {!! view_render_event('bagisto.shop.components.products.card.compare_option.after') !!}
                    </div>
                </div>
            </div>

            <div class="grid content-start gap-4">

                {!! view_render_event('bagisto.shop.components.products.card.name.before') !!}

                <p class="text-base wrap-anywhere">
                    @{{ product.name }}
                </p>

                {!! view_render_event('bagisto.shop.components.products.card.name.after') !!}

                {!! view_render_event('bagisto.shop.components.products.card.price.before') !!}

                <div
                    class="flex flex-wrap items-center gap-x-2.5 gap-y-0.5 text-lg font-semibold"
                    v-html="product.price_html"
                >
                </div>

                {!! view_render_event('bagisto.shop.components.products.card.price.after') !!}

                {!! view_render_event('bagisto.shop.components.products.card.average_ratings.before') !!}

                <p class="text-sm text-zinc-500">
                    <template  v-if="! product.ratings.total">
                        <p class="text-sm text-zinc-500">
                            @lang('shop::app.components.products.card.review-description')
                        </p>
                    </template>

                    <template v-else>
                        @if (core()->getConfigData('catalog.products.review.summary') == 'star_counts')
                            <x-shop::products.ratings
                                ::average="product.ratings.average"
                                ::total="product.ratings.total"
                                ::rating="false"
                            />
                        @else
                            <x-shop::products.ratings
                                ::average="product.ratings.average"
                                ::total="product.reviews.total"
                                ::rating="false"
                            />
                        @endif
                    </template>
                </p>

                {!! view_render_event('bagisto.shop.components.products.card.average_ratings.after') !!}

                @if (core()->getConfigData('sales.checkout.shopping_cart.cart_page'))

                    {!! view_render_event('bagisto.shop.components.products.card.add_to_cart.before') !!}

                    <x-shop::button
                        class="primary-button whitespace-nowrap px-8 py-2.5"
                        :title="trans('shop::app.components.products.card.add-to-cart')"
                        ::loading="isAddingToCart"
                        ::disabled="! product.is_saleable || isAddingToCart"
                        @click="addToCart()"
                    />

                    {!! view_render_event('bagisto.shop.components.products.card.add_to_cart.after') !!}

                @endif
            </div>
        </div>
    </script>

    <script type="module">
        app.component('v-product-card', {
            template: '#v-product-card-template',

            props: ['mode', 'product'],

            data() {
                return {
                    isCustomer: '{{ auth()->guard('customer')->check() }}',

                    isAddingToCart: false,

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

            methods: {
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

                addToWishlist() {
                    if (this.isCustomer) {
                        this.$axios.post(`{{ route('shop.api.customers.account.wishlist.store') }}`, {
                                product_id: this.product.id
                            })
                            .then(response => {
                                this.product.is_wishlist = ! this.product.is_wishlist;

                                this.$emitter.emit('add-flash', { type: 'success', message: response.data.data.message });
                            })
                            .catch(error => {});
                        } else {
                            window.location.href = "{{ route('shop.customer.session.index')}}";
                        }
                },

                addToCompare(productId) {
                    /**
                     * This will handle for customers.
                     */
                    if (this.isCustomer) {
                        this.$axios.post('{{ route("shop.api.compare.store") }}', {
                                'product_id': productId
                            })
                            .then(response => {
                                this.$emitter.emit('add-flash', { type: 'success', message: response.data.data.message });
                            })
                            .catch(error => {
                                if ([400, 422].includes(error.response.status)) {
                                    this.$emitter.emit('add-flash', { type: 'warning', message: error.response.data.data.message });

                                    return;
                                }

                                this.$emitter.emit('add-flash', { type: 'error', message: error.response.data.message});
                            });

                        return;
                    }

                    /**
                     * This will handle for guests.
                     */
                    let items = this.getStorageValue() ?? [];

                    if (items.length) {
                        if (! items.includes(productId)) {
                            items.push(productId);

                            localStorage.setItem('compare_items', JSON.stringify(items));

                            this.$emitter.emit('add-flash', { type: 'success', message: "@lang('shop::app.components.products.card.add-to-compare-success')" });
                        } else {
                            this.$emitter.emit('add-flash', { type: 'warning', message: "@lang('shop::app.components.products.card.already-in-compare')" });
                        }
                    } else {
                        localStorage.setItem('compare_items', JSON.stringify([productId]));

                        this.$emitter.emit('add-flash', { type: 'success', message: "@lang('shop::app.components.products.card.add-to-compare-success')" });

                    }
                },

                getStorageValue(key) {
                    let value = localStorage.getItem('compare_items');

                    if (! value) {
                        return [];
                    }

                    return JSON.parse(value);
                },

                addToCart() {
                    this.isAddingToCart = true;

                    this.$axios.post('{{ route("shop.api.checkout.cart.store") }}', {
                            'quantity': 1,
                            'product_id': this.product.id,
                        })
                        .then(response => {
                            if (response.data.message) {
                                this.$emitter.emit('update-mini-cart', response.data.data );

                                this.$emitter.emit('add-flash', { type: 'success', message: response.data.message });
                            } else {
                                this.$emitter.emit('add-flash', { type: 'warning', message: response.data.data.message });
                            }

                            this.isAddingToCart = false;
                        })
                        .catch(error => {
                            this.$emitter.emit('add-flash', { type: 'error', message: error.response.data.message });

                            if (error.response.data.redirect_uri) {
                                window.location.href = error.response.data.redirect_uri;
                            }

                            this.isAddingToCart = false;
                        });
                },
            },
        });
    </script>
@endpushOnce
