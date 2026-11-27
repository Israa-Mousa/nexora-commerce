import sys, re
p = sys.argv[1] + '/resources/themes/nexora/views/home/index.blade.php'
s = open(p, encoding='utf-8').read()
if 'nx-hero3' in s:
    print('already'); sys.exit(0)
a = s.index('        <!-- Hero -->')
b = s.index('        <!-- Ticker -->')
hero = r'''        @php
            $slides = [
                [
                    'tone' => 'sage', 'reverse' => false, 'image' => 'hero/slide-skincare.webp', 'slug' => 'skincare',
                    'eyebrow' => $t('Skincare ritual', 'طقوس العناية بالبشرة'),
                    'title' => $t('Clean, calm skin starts here', 'بشرة هادئة ونقية تبدأ من هنا'),
                    'copy' => $t('Gentle cleansers, light serums and daily moisture — a simple routine that feels good.', 'غسولات لطيفة وسيروم خفيف وترطيب يومي — روتين بسيط تشعرين معه بالراحة.'),
                    'cta' => $t('Shop skincare', 'تسوّق العناية بالبشرة'),
                ],
                [
                    'tone' => 'sand', 'reverse' => true, 'image' => 'hero/slide-fragrance.webp', 'slug' => 'fragrances',
                    'eyebrow' => $t('Fragrance', 'العطور'),
                    'title' => $t('Signature scents for every mood', 'عطور تشبهك في كل حالاتك'),
                    'copy' => $t('Floral, woody and fresh — for her, for him and for everyone.', 'زهرية وخشبية ومنعشة — لها وله وللجميع.'),
                    'cta' => $t('Explore fragrances', 'اكتشف العطور'),
                ],
                [
                    'tone' => 'ivory', 'reverse' => false, 'image' => 'hero/slide-makeup.webp', 'slug' => 'makeup',
                    'eyebrow' => $t('Makeup', 'المكياج'),
                    'title' => $t('Soft colour, effortless finish', 'ألوان ناعمة ولمسة طبيعية'),
                    'copy' => $t('Blush, lips and complexion in easy, wearable shades.', 'بلاشر وشفاه وكريم أساس بدرجات سهلة للاستخدام اليومي.'),
                    'cta' => $t('Shop makeup', 'تسوّق المكياج'),
                ],
            ];
        @endphp

        <!-- Hero slider -->
        <section class="nx-hero3" aria-roledescription="carousel" aria-label="{{ $t('Featured collections', 'مجموعات مختارة') }}" data-nx-slider data-nx-bubbles>
            <div class="nx-hero3__track">
                @foreach ($slides as $index => $slide)
                    <article
                        class="nx-slide nx-slide--{{ $slide['tone'] }} {{ $slide['reverse'] ? 'nx-slide--reverse' : '' }} {{ $index === 0 ? 'is-active' : '' }}"
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
                                <a class="nx-btn nx-btn--text" href="{{ route('shop.search.index', ['new' => 1] + $scope) }}" @if ($index) tabindex="-1" @endif>{{ $t('New arrivals', 'وصل حديثًا') }}</a>
                            </div>
                        </div>

                        <div class="nx-slide__media">
                            <img src="{{ $art($slide['image']) }}" alt="" width="1400" height="1500" @if ($index === 0) fetchpriority="high" @else loading="lazy" @endif>
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

'''
s = s[:a] + hero + s[b:]
s = s.replace("$art('editorial/fragrance.webp')", "$art('hero/fragrance-band.webp')")
s = s.replace("$art('editorial/promo.webp')", "$art('hero/promo-band.webp')")
s = s.replace('<section class="nx-section nx-newsletter" aria-labelledby="nx-news-title" data-nx-reveal>', '<section class="nx-section nx-newsletter" aria-labelledby="nx-news-title" data-nx-reveal data-nx-bubbles>')
open(p, 'w', encoding='utf-8', newline='\n').write(s)
print('home v5 patched')
