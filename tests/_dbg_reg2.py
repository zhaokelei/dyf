import sys, os
sys.path.insert(0, os.path.join(os.getcwd(), 'tests'))
from smoke_test import Session
anon = Session(); anon.get('/register/teacher')
p = anon.write('POST', '/register/teacher', {
    'employee_id': 'TEDEBUG01', 'name': '调试教师', 'college_id': 1,
    'invite_code': 'TCH2026E2E', 'password': 'teach12345', 'password_confirmation': 'teach12345',
})
print('status', p.status, 'url', p.url)
print('errors', (p.json_props().get('errors') or {}))
