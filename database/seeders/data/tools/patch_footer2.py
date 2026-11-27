import sys
p = sys.argv[1] + '/resources/themes/nexora/views/components/layouts/footer/index.blade.php'
s = open(p, encoding='utf-8').read()
a = s.index('            © {{ date(\'Y\') }}')
b = s.index("'All rights reserved.' }}", a) + len("'All rights reserved.' }}")
new = '''            <span class="nexora-footer-copy">
                <span>©</span>
                <bdi>{{ date('Y') }}</bdi>
                <bdi><a href="https://nexorawebsite-1.onrender.com/" target="_blank" rel="noopener" class="nexora-footer-credit">Nexora Tech Solutions</a></bdi>
                <span>{{ str_starts_with(app()->getLocale(), 'ar') ? '— جميع الحقوق محفوظة' : '— All rights reserved' }}</span>
            </span>'''
s = s[:a] + new + s[b:]
open(p, 'w', encoding='utf-8', newline='\n').write(s)
print('footer ok')
