import sys
p = sys.argv[1] + '/resources/themes/nexora/views/components/layouts/index.blade.php'
s = open(p, encoding='utf-8').read()
old = 'family=Manrope:wght@400;500;600;700;800&family=Cormorant+Garamond:wght@500;600;700&family=Noto+Naskh+Arabic:wght@400;500;600;700&family=IBM+Plex+Sans+Arabic:wght@400;500;600;700&display=swap'
new = 'family=Manrope:wght@400;500;600;700&family=Cormorant+Garamond:wght@500;600&family=IBM+Plex+Sans+Arabic:wght@300;400;500;600;700&display=swap'
n = s.count(old)
s = s.replace(old, new)
open(p, 'w', encoding='utf-8', newline='\n').write(s)
print('fonts replaced', n)
