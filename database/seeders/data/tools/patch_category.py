import sys
p = sys.argv[1] + '/resources/themes/nexora/views/categories/view.blade.php'
s = open(p, encoding='utf-8').read()
if 'nexora-category-intro__media' in s:
    print('already'); sys.exit(0)
old = '''        @if ($nexoraChildren->isNotEmpty())
            <nav class="nexora-category-chips"'''
new = '''        @php($nexoraVisual = $category->banner_url ?: $category->logo_url)

        @if ($nexoraVisual)
            <div class="nexora-category-intro__media">
                <img src="{{ $nexoraVisual }}" alt="" width="600" height="600">
            </div>
        @endif

        @if ($nexoraChildren->isNotEmpty())
            <nav class="nexora-category-chips"'''
assert old in s
s = s.replace(old, new, 1)
open(p, 'w', encoding='utf-8', newline='\n').write(s)
print('category patched')
