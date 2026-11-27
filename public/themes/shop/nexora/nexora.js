/*
 * Nexora storefront motion & helpers (dependency-free).
 *  - Scroll reveal for [data-nx-reveal] (survives Vue re-rendering the DOM)
 *  - Hero slider [data-nx-slider] (cross-fade, autoplay with pause, keyboard, RTL-aware)
 *  - Soft bubbles only inside [data-nx-bubbles] sections
 *  - Horizontal scrollers [data-nx-scroll], header scrolled state
 *  - NexoraMeta: batched brand / size / best-seller / hover-image lookup for product cards
 * Everything respects prefers-reduced-motion.
 */
(function () {
    var root = document.documentElement;
    var reduce = window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches;

    root.classList.add('nx-js');

    if (reduce) {
        root.classList.add('nx-reduced-motion');
    }

    /* ---------- Scroll reveal ---------- */
    var io = (!reduce && 'IntersectionObserver' in window) ? new IntersectionObserver(function (entries) {
        entries.forEach(function (entry) {
            if (entry.isIntersecting) {
                entry.target.classList.add('is-visible');
                io.unobserve(entry.target);
            }
        });
    }, { rootMargin: '0px 0px -8% 0px', threshold: 0.08 }) : null;

    function observeReveal() {
        var nodes = document.querySelectorAll('[data-nx-reveal]:not([data-nx-observed])');

        for (var i = 0; i < nodes.length; i++) {
            nodes[i].setAttribute('data-nx-observed', '');

            if (io) {
                io.observe(nodes[i]);
            } else {
                nodes[i].classList.add('is-visible');
            }
        }
    }

    /* ---------- Bubbles (scoped, decorative) ---------- */
    var palette = ['246 214 219', '255 255 255', '230 163 174', '251 236 236', '165 75 99'];

    function rand(a, b) {
        return a + Math.random() * (b - a);
    }

    function addBubbles() {
        if (reduce) {
            return;
        }

        var hosts = document.querySelectorAll('[data-nx-bubbles]');

        for (var h = 0; h < hosts.length; h++) {
            var host = hosts[h];

            if (host.querySelector(':scope > .nx-bubbles')) {
                continue;
            }

            var layer = document.createElement('div');
            var count = window.innerWidth < 768 ? 5 : 10;

            layer.className = 'nx-bubbles';
            layer.setAttribute('aria-hidden', 'true');

            for (var i = 0; i < count; i++) {
                var bubble = document.createElement('span');
                var color = palette[i % palette.length];
                // Keep to the outer edges so bubbles never sit on top of copy or products.
                var lane = i % 2 === 0 ? rand(1, 14) : rand(86, 98);

                bubble.style.cssText = [
                    '--s:' + Math.round(rand(10, 38)) + 'px',
                    '--x:' + lane.toFixed(1) + '%',
                    '--d:' + rand(16, 30).toFixed(1) + 's',
                    '--delay:-' + rand(0, 30).toFixed(1) + 's',
                    '--drift:' + Math.round(rand(-30, 30)) + 'px',
                    '--o:' + rand(0.35, color === '165 75 99' ? 0.4 : 0.75).toFixed(2),
                    '--c:' + color
                ].join(';');

                layer.appendChild(bubble);
            }

            // A few falling petals, kept to the outer edges as well
            var petals = window.innerWidth < 768 ? 3 : 6;

            for (var k = 0; k < petals; k++) {
                var petal = document.createElement('i');

                petal.style.cssText = [
                    '--s:' + Math.round(rand(10, 18)) + 'px',
                    '--x:' + (k % 2 === 0 ? rand(2, 18) : rand(82, 97)).toFixed(1) + '%',
                    '--d:' + rand(14, 24).toFixed(1) + 's',
                    '--delay:-' + rand(0, 24).toFixed(1) + 's',
                    '--drift:' + Math.round(rand(-60, 60)) + 'px',
                    '--o:' + rand(0.45, 0.8).toFixed(2)
                ].join(';');

                layer.appendChild(petal);
            }

            host.appendChild(layer);
        }
    }

    /* ---------- Hero slider ---------- */
    var sliderTimer = null;
    var SLIDE_MS = 6500;
    var sliderStopped = false; // stops for good once the visitor uses the controls

    function sliderEl() {
        return document.querySelector('[data-nx-slider]');
    }

    function goTo(index) {
        var slider = sliderEl();

        if (!slider) {
            return;
        }

        var slides = slider.querySelectorAll('.nx-slide');
        var dots = slider.querySelectorAll('.nx-hero3__dot');
        var total = slides.length;

        if (!total) {
            return;
        }

        index = (index + total) % total;

        for (var i = 0; i < total; i++) {
            var active = i === index;

            slides[i].classList.toggle('is-active', active);
            slides[i].setAttribute('aria-hidden', active ? 'false' : 'true');

            var links = slides[i].querySelectorAll('a');

            for (var l = 0; l < links.length; l++) {
                if (active) {
                    links[l].removeAttribute('tabindex');
                } else {
                    links[l].setAttribute('tabindex', '-1');
                }
            }

            if (dots[i]) {
                dots[i].classList.toggle('is-active', active);
                dots[i].setAttribute('aria-selected', active ? 'true' : 'false');
            }
        }

        slider.setAttribute('data-index', index);
        restartAutoplay();
    }

    function current() {
        var slider = sliderEl();

        return slider ? parseInt(slider.getAttribute('data-index') || '0', 10) : 0;
    }

    function restartAutoplay() {
        clearTimeout(sliderTimer);

        var slider = sliderEl();

        if (reduce || sliderStopped || !slider || slider.matches(':hover') || slider.contains(document.activeElement) || document.hidden) {
            return;
        }

        slider.classList.remove('is-timing');
        void slider.offsetWidth;
        slider.classList.add('is-timing');

        sliderTimer = setTimeout(function () {
            goTo(current() + 1);
        }, SLIDE_MS);
    }

    document.addEventListener('click', function (event) {
        var target = event.target.closest ? event.target.closest('[data-nx-slide]') : null;

        if (target) {
            var value = target.getAttribute('data-nx-slide');

            sliderStopped = true;

            goTo(value === 'next' ? current() + 1 : value === 'prev' ? current() - 1 : parseInt(value, 10));

            return;
        }

        var scroll = event.target.closest ? event.target.closest('[data-nx-scroll]') : null;

        if (scroll) {
            var section = scroll.closest('section') || document;
            var track = section.querySelector('[data-nx-scroller]');

            if (track) {
                var rtl = getComputedStyle(track).direction === 'rtl';

                track.scrollBy({
                    left: parseInt(scroll.getAttribute('data-nx-scroll'), 10) * track.clientWidth * 0.8 * (rtl ? -1 : 1),
                    behavior: reduce ? 'auto' : 'smooth'
                });
            }
        }
    });

    document.addEventListener('keydown', function (event) {
        var slider = sliderEl();

        if (!slider || !slider.contains(document.activeElement)) {
            return;
        }

        var rtl = root.dir === 'rtl';

        if (event.key === 'ArrowRight') {
            goTo(current() + (rtl ? -1 : 1));
        } else if (event.key === 'ArrowLeft') {
            goTo(current() + (rtl ? 1 : -1));
        }
    });

    document.addEventListener('mouseover', function (event) {
        var slider = sliderEl();

        if (slider && slider.contains(event.target)) {
            clearTimeout(sliderTimer);
            slider.classList.remove('is-timing');
        }
    });

    document.addEventListener('mouseout', function (event) {
        var slider = sliderEl();

        if (slider && slider.contains(event.target) && !slider.contains(event.relatedTarget)) {
            restartAutoplay();
        }
    });

    document.addEventListener('visibilitychange', function () {
        if (document.hidden) {
            clearTimeout(sliderTimer);
        } else {
            restartAutoplay();
        }
    });

    /* ---------- Header scrolled state ---------- */
    var ticking = false;

    window.addEventListener('scroll', function () {
        if (ticking) {
            return;
        }

        ticking = true;

        requestAnimationFrame(function () {
            root.classList.toggle('nx-scrolled', window.scrollY > 24);
            ticking = false;
        });
    }, { passive: true });

    /* ---------- Boot + re-run after Vue mounts ---------- */
    var scheduled = false;
    var sliderStarted = null;

    function enhance() {
        // Product rails start at the first card (Bagisto can leave RTL rails part-scrolled)
        var rails = document.querySelectorAll('.nx-carousel .flex.overflow-auto:not([data-nx-rail])');

        for (var r = 0; r < rails.length; r++) {
            if (rails[r].children.length > 1 && rails[r].querySelector('.nx-card')) {
                rails[r].setAttribute('data-nx-rail', '');
                rails[r].scrollLeft = 0;
            }
        }

        observeReveal();
        addBubbles();

        var slider = sliderEl();

        if (slider && slider !== sliderStarted) {
            sliderStarted = slider;
            slider.setAttribute('data-index', '0');
            restartAutoplay();
        }
    }

    document.addEventListener('DOMContentLoaded', function () {
        enhance();

        if ('MutationObserver' in window) {
            new MutationObserver(function () {
                if (scheduled) {
                    return;
                }

                scheduled = true;

                requestAnimationFrame(function () {
                    scheduled = false;
                    enhance();
                });
            }).observe(document.body, { childList: true, subtree: true });
        }

        setTimeout(function () {
            var hidden = document.querySelectorAll('[data-nx-reveal]:not(.is-visible)');

            for (var i = 0; i < hidden.length; i++) {
                if (hidden[i].getBoundingClientRect().top < window.innerHeight) {
                    hidden[i].classList.add('is-visible');
                }
            }
        }, 2500);
    });

    /* ---------- Product card meta (batched) ---------- */
    var cache = {};
    var waiting = {};
    var queue = [];
    var timer = null;

    function flush() {
        timer = null;

        var ids = queue.splice(0, 60);

        if (!ids.length) {
            return;
        }

        var base = (document.querySelector('meta[name="base-url"]') || {}).content || '';
        var url = base.replace(/\/$/, '') + '/nexora/product-meta?ids=' + ids.join(',') + '&locale=' + encodeURIComponent(root.lang || 'ar');

        fetch(url, { headers: { Accept: 'application/json' }, credentials: 'same-origin' })
            .then(function (response) { return response.ok ? response.json() : { data: {} }; })
            .catch(function () { return { data: {} }; })
            .then(function (json) {
                ids.forEach(function (id) {
                    cache[id] = (json.data && json.data[id]) || {};
                    (waiting[id] || []).forEach(function (resolve) { resolve(cache[id]); });
                    delete waiting[id];
                });

                if (queue.length) {
                    timer = setTimeout(flush, 0);
                }
            });
    }

    window.NexoraMeta = {
        get: function (id) {
            id = parseInt(id, 10);

            if (cache[id]) {
                return Promise.resolve(cache[id]);
            }

            return new Promise(function (resolve) {
                if (!waiting[id]) {
                    waiting[id] = [];
                    queue.push(id);
                }

                waiting[id].push(resolve);

                if (!timer) {
                    timer = setTimeout(flush, 40);
                }
            });
        }
    };
})();
