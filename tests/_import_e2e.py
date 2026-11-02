# -*- coding: utf-8 -*-
import sys, os, io, time
sys.path.insert(0, os.path.join(os.getcwd(), 'tests'))
from smoke_test import Session, check, RESULTS
from openpyxl import load_workbook, Workbook

s = Session()
r = s.login('admin', 'admin123')
check('登录本地管理员', '/dashboard' in r.url, r.url)

# 1) 下载模板
tpl = s.get('/admin/users/import/template')
check('下载导入模板', tpl.status == 200 and len(tpl.body) > 5000, f"{tpl.status} {len(tpl.body)}B")
open('C:/Users/Administrator/Desktop/dyf/tests/_import_template.xlsx', 'wb').write(tpl.body)

wb = load_workbook(io.BytesIO(tpl.body))
check('模板工作表', wb.sheetnames == ['学生','教师','审核端','使用说明'], wb.sheetnames)
print('学生表头:', [c.value for c in wb['学生'][1]])
print('教师表头:', [c.value for c in wb['教师'][1]])
print('审核端表头:', [c.value for c in wb['审核端'][1]])

# 2) 按模板填数（沿用模板示例行结构）
tag = str(int(time.time()))[-6:]
stu_id = f'88{tag}01'
wb['学生'].append([stu_id, '导入测试学生甲', '信息工程学院', '数据科学与大数据技术', None, '2026级'])
wb['学生'].append([f'88{tag}02', '导入测试学生乙', '信息工程学院', '物联网工程', None, '2026级'])
wb['教师'].append([f'T{tag}01', '导入测试教师', '信息工程学院'])
wb['审核端'].append([f'A{tag}01', '导入测试管理员'])
# 删除模板示例行
for name in ('学生','教师','审核端'):
    ws = wb[name]
    ws.delete_rows(2)
buf = io.BytesIO(); wb.save(buf)
open('C:/Users/Administrator/Desktop/dyf/tests/_import_filled.xlsx','wb').write(buf.getvalue())

# 3) 提交导入
res = s.upload('/admin/users/import', {}, {'file': ('用户导入测试.xlsx', buf.getvalue(), 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet')})
check('提交导入请求', res.status in (200, 302, 303), res.status)
page = s.get('/admin/users?keyword=' + stu_id)
props = page.json_props()
users = (props.get('users') or {}).get('data') or []
names = [u['name'] for u in users]
check('学生甲已入库', '导入测试学生甲' in names, names[:5])
print('导入后查询:', names[:5])
print('flash:', (props.get('flash') or {}))

# 4) 登录新学生验证初始密码
s2 = Session()
r2 = s2.login(stu_id, stu_id)
check('新学生可用学号登录', '/dashboard' in r2.url, r2.url)

# 5) 重复导入（应识别为已存在）
buf2 = io.BytesIO(); wb.save(buf2)
res2 = s.upload('/admin/users/import', {}, {'file': ('用户导入测试.xlsx', buf2.getvalue(), 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet')})
check('重复导入成功返回', res2.status in (200, 302, 303), res2.status)

# 6) 角色列混合 CSV
csv = f"账号,姓名,角色,学院\nC{tag}01,混合学生,学生,信息工程学院\nC{tag}02,混合教师,教师,信息工程学院\n".encode('utf-8')
res3 = s.upload('/admin/users/import', {}, {'file': ('混合名单.csv', csv, 'text/csv')})
check('角色列 CSV 导入', res3.status in (200, 302, 303), res3.status)
page3 = s.get('/admin/users?keyword=C' + tag)
names3 = [u['name'] for u in ((page3.json_props().get('users') or {}).get('data') or [])]
check('CSV 两角色入���', '混合学生' in names3 and '混合教师' in names3, names3[:5])

failed = [n for n, ok, _ in RESULTS if not ok]
print('\n失败项:', failed or '无')
