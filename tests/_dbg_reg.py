import sys, os
sys.path.insert(0, os.path.join(os.getcwd(), 'tests'))
from smoke_test import Session
s = Session(); s.login('admin', 'admin123')
p = s.get('/register/teacher')
d = p.json_props()
print('url', p.url, 'status', p.status)
print('keys', sorted(d.keys())[:15])
print('enabled', d.get('enabled'), 'colleges', len(d.get('colleges') or []))
