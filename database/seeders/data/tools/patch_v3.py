import sys
root = sys.argv[1]
V = root + '/resources/themes/nexora/views/'
def rw(path, pairs):
    s = open(path, encoding='utf-8').read(); o = s
    for a, b in pairs:
        if b not in s:
            s = s.replace(a, b, 1)
    if s != o:
        open(path, 'w', encoding='utf-8', newline='\n').write(s); print('patched', path[-40:])
    else:
        print('unchanged', path[-40:])
rw(V + 'components/products/card.blade.php', [('''                <p
                    class="nx-card__size"
                    v-if="meta.size"''', '''                <p
                    class="nx-card__size"
                    dir="auto"
                    v-if="meta.size"''')])
rw(V + 'home/index.blade.php', [
 ("$t('The Nexora Beauty Edit', 'مختارات نيكسورا للجمال')", "$t('Nexora — for her, first', 'نيكسورا — لكِ أولًا')"),
 ("$t('Care that feels like a ritual, not a routine', 'عناية تشبه الطقس، لا مجرد روتين')", "$t('Your beauty ritual, softly curated', 'طقس جمالكِ، مختار بنعومة')"),
 ("$t('Skincare, makeup, hair, body care and fragrance — thoughtfully chosen for women, men and the little ones at home.', 'عناية بالبشرة ومكياج وشعر وجسم وعطور — مختارة بعناية للنساء والرجال والصغار في البيت.')", "$t('Gentle skincare, soft-colour makeup, hair rituals and fragrance chosen for her — with grooming for him and calm care for the little ones.', 'عناية لطيفة بالبشرة، مكياج بألوان ناعمة، طقوس للشعر وعطور مختارة لكِ — مع عناية للرجل ولمسة هادئة للصغار.')"),
 ("<a class=\"nx-btn nx-btn--primary\" href=\"{{ $url('skincare', ['query' => 'serum']) }}\">{{ $t('Shop skincare', 'تسوّقي العناية بالبشرة') }}</a>", "<a class=\"nx-btn nx-btn--primary\" href=\"{{ $url('women', ['query' => 'serum']) }}\">{{ $t('Shop for her', 'تسوّقي لكِ') }}</a>"),
])
rw(V + 'products/view.blade.php', [('<dd>{{ $nxValue }}</dd>', '<dd dir="auto">{{ $nxValue }}</dd>')])
