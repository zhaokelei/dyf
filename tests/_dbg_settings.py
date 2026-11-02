import sys, os
sys.path.insert(0, os.path.join(os.getcwd(), 'tests'))
from smoke_test import Session
s = Session(); s.login('admin', 'admin123')
props = s.get('/admin/settings').json_props()
print('settings dict:', props.get('settings'))
r = s.write('PUT', '/admin/settings', {'base_score': 40, 'system_name': '郑州工商学院德育分管理系统', 'teacher_registration_enabled': 1, 'teacher_registration_code': 'TCH2026E2E'})
print('put status', r.status, 'url', r.url)
print('errors', r.json_props().get('errors'))
print('after:', s.get('/admin/settings').json_props().get('settings'))
