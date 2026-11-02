# -*- coding: utf-8 -*-
import sys, os, time
sys.path.insert(0, os.path.join(os.getcwd(), 'tests'))
from smoke_test import Session, check, RESULTS

admin = Session(); admin.login('admin', 'admin123')
original = admin.get('/admin/settings').json_props().get('settings') or {}
print('original:', original)

payload = dict(original); payload.update({'teacher_registration_enabled': True, 'teacher_registration_code': 'TCH2026E2E'})
admin.write('PUT', '/admin/settings', payload)
saved = admin.get('/admin/settings').json_props().get('settings') or {}
check('invite code updated', saved.get('teacher_registration_code') == 'TCH2026E2E', saved.get('teacher_registration_code'))

anon = Session(); props = anon.get('/register/teacher').json_props()
colleges = props.get('colleges') or []
check('registration enabled', props.get('enabled') is True)
check('college options', len(colleges) > 0, len(colleges))

tag = str(int(time.time()))[-6:]; emp = 'TE' + tag
s2 = Session(); s2.get('/register/teacher')
reg = s2.write('POST', '/register/teacher', {
    'employee_id': emp, 'name': '注册验证教师', 'college_id': colleges[0]['id'],
    'invite_code': 'TCH2026E2E', 'password': 'teach12345', 'password_confirmation': 'teach12345',
})
check('register ok', '/login' in reg.url, (reg.url, reg.json_props().get('errors')))

s3 = Session(); login = s3.login(emp, 'teach12345')
check('new teacher can login immediately', '/dashboard' in login.url, login.url)

admin.write('PUT', '/admin/settings', original)
restored = admin.get('/admin/settings').json_props().get('settings') or {}
check('settings restored', restored.get('teacher_registration_code') == original.get('teacher_registration_code'), restored)
print('registered employee:', emp)
print('failures:', [n for n, ok, _ in RESULTS if not ok] or 'none')
