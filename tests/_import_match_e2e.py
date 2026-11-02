# -*- coding: utf-8 -*-
import sys, os, io
sys.path.insert(0, os.path.join(os.getcwd(), 'tests'))
from smoke_test import Session, check, RESULTS
from openpyxl import load_workbook

s = Session()
r = s.login('admin', 'admin123')
check('login', '/dashboard' in r.url, r.url)

tpl = s.get('/admin/users/import/template')
wb = load_workbook(io.BytesIO(tpl.body))
ws = wb['学生']
ws.delete_rows(2)
ws.append(['8801679801', '导入测试学生甲', '信息工程学院', '计算机科学与技术', None, '2026级'])
buf = io.BytesIO(); wb.save(buf)

res = s.upload('/admin/users/import', {}, {'file': ('用户导入模板.xlsx', buf.getvalue(), 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet')})
check('import', res.status in (200, 302, 303), res.status)
page = s.get('/admin/users?search=8801679801')
users = (page.json_props().get('users') or {}).get('data') or []
row = next((u for u in users if u.get('student_id') == '8801679801'), None)
check('class auto matched', bool(row) and row.get('class_name') == '计算机科学与技术（专升本）2601班', row and row.get('class_name'))
print('user row:', {k: row.get(k) for k in ('name','student_id','class_name','grade','major_id')} if row else None)
print('failures:', [n for n, ok, _ in RESULTS if not ok] or 'none')
