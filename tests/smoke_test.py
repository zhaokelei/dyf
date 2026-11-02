# -*- coding: utf-8 -*-
"""德育分管理系统三端冒烟测试。

运行前提：Laravel 服务已启动（默认 http://127.0.0.1:8080），数据库已完成迁移和种子。
用法：python tests/smoke_test.py
可通过环境变量 BASE_URL 覆盖地址。
"""
import base64
import http.cookiejar
import json
import os
import re
import sys
import time
import urllib.error
import urllib.parse
import urllib.request

BASE = os.environ.get('BASE_URL', 'http://127.0.0.1:8080').rstrip('/')
TAG = str(int(time.time()))[-6:]
RESULTS = []


def write_ok(response):
    return response.status in (200, 302, 303)


def check(name, condition, detail=''):
    RESULTS.append((name, bool(condition), detail))
    print(('PASS' if condition else 'FAIL'), name, ('- ' + str(detail)) if detail else '')


class Response:
    def __init__(self, status, url, headers, body):
        self.status = status
        self.url = url
        self.headers = headers
        self.body = body

    @property
    def text(self):
        return self.body.decode('utf-8', 'replace')

    def json_props(self):
        match = re.search(r'<script data-page="app"[^>]*>(.*?)</script>', self.text, re.S)
        if not match:
            return {}
        return json.loads(match.group(1)).get('props', {})

    def component(self):
        match = re.search(r'<script data-page="app"[^>]*>(.*?)</script>', self.text, re.S)
        if not match:
            return None
        return json.loads(match.group(1)).get('component')


class Session:
    def __init__(self):
        self.jar = http.cookiejar.CookieJar()
        self.opener = urllib.request.build_opener(
            urllib.request.HTTPCookieProcessor(self.jar)
        )

    def _open(self, method, path, data=None, headers=None):
        url = path if path.startswith('http') else BASE + path
        request = urllib.request.Request(url, data=data, headers=headers or {}, method=method)
        try:
            response = self.opener.open(request)
            return Response(response.status, response.geturl(), response.headers, response.read())
        except urllib.error.HTTPError as error:
            return Response(error.code, url, error.headers, error.read())

    def token(self):
        for cookie in self.jar:
            if cookie.name == 'XSRF-TOKEN':
                return urllib.parse.unquote(cookie.value)
        return ''

    def get(self, path):
        return self._open('GET', path)

    def write(self, method, path, fields=None, headers=None):
        data = urllib.parse.urlencode(fields or {}, doseq=True).encode()
        merged = {'X-XSRF-TOKEN': self.token(), 'Accept': 'text/html'}
        merged.update(headers or {})
        return self._open(method, path, data=data, headers=merged)

    def upload(self, path, fields, files, method='POST', extra_headers=None):
        boundary = '----dyfboundary' + TAG
        body = bytearray()

        for name, value in fields.items():
            body += f'--{boundary}\r\nContent-Disposition: form-data; name="{name}"\r\n\r\n'.encode()
            body += str(value).encode('utf-8') + b'\r\n'

        for name, (filename, content, mime) in files.items():
            body += (
                f'--{boundary}\r\nContent-Disposition: form-data; name="{name}"; '
                f'filename="{filename}"\r\nContent-Type: {mime}\r\n\r\n'
            ).encode()
            body += content + b'\r\n'

        body += f'--{boundary}--\r\n'.encode()
        headers = {
            'X-XSRF-TOKEN': self.token(),
            'Content-Type': f'multipart/form-data; boundary={boundary}',
            'Accept': 'text/html',
        }
        headers.update(extra_headers or {})
        return self._open(method, path, data=bytes(body), headers=headers)

    def login(self, account, password):
        self.get('/login')
        response = self.write('POST', '/login', {'account': account, 'password': password})
        return response

    def props(self, path):
        return self.get(path).json_props()


def login_as(account, password):
    session = Session()
    response = session.login(account, password)
    check(f'登录 {account}', '/dashboard' in response.url and response.status == 200, response.url)
    return session


def png_bytes():
    return base64.b64decode(
        'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAusB9Y9Z4iQAAAAASUVORK5CYII='
    )


def submit_application(student, description, score=4):
    response = student.upload(
        '/applications',
        {
            'category_id': '1',
            'score': str(score),
            'description': description,
        },
        {'evidence': ('proof.png', png_bytes(), 'image/png')},
    )
    check(f'提交申请：{description}', response.status == 200 and '/applications' in response.url, response.url)
    applications = student.props('/applications')['applications']['data']
    return next((item for item in applications if item['description'] == description), None)


def find_application(student, description):
    applications = student.props('/applications')['applications']['data']
    return next((item for item in applications if item['description'] == description), None)


def csv_bytes(rows):
    lines = ['学号,姓名,分值'] + [f'{sid},{name},{score}' for sid, name, score in rows]
    return ('\ufeff' + '\n'.join(lines) + '\n').encode('utf-8')


def main():
    print(f'== 冒烟测试开始 {BASE} ==')

    # ---------- 访客 ----------
    guest = Session()
    response = guest.get('/dashboard')
    check('访客访问工作台被重定向到登录页', response.status == 200 and response.url.endswith('/login'), response.url)

    # ---------- 管理员页面 ----------
    admin = login_as('admin', 'admin123')
    for path in [
        '/dashboard', '/leaderboard', '/admin/review', '/admin/appeals', '/admin/users', '/admin/categories',
        '/admin/semesters', '/announcements', '/admin/records', '/admin/statistics',
        '/admin/audit-logs', '/admin/settings', '/profile/password',
    ]:
        page = admin.get(path)
        check(f'管理员页面 {path}', page.status == 200, page.status)

    # ---------- 学生页面与权限 ----------
    student = login_as('5276110074', '5276110074')
    for path in ['/dashboard', '/my-scores', '/applications', '/applications/create', '/appeals', '/announcements']:
        page = student.get(path)
        check(f'学生页面 {path}', page.status == 200, page.status)

    check('学生禁止访问用户管理', student.get('/admin/users').status == 403)
    check('学生禁止访问教师集体申请', student.get('/teacher/group-applications').status == 403)

    baseline = student.props('/my-scores')['summary']
    baseline_add = baseline['add_score']

    # ---------- 学生申请 + 撤回 ----------
    app_a = submit_application(student, f'SMOKE-WITHDRAW-{TAG}', 4)
    check('申请出现在我的申请列表', app_a is not None)
    if app_a:
        response = student.write('POST', f"/applications/{app_a['id']}/withdraw")
        check('学生撤回申请', response.status == 200)
        check('撤回后状态为 withdrawn', find_application(student, f'SMOKE-WITHDRAW-{TAG}')['status'] == 'withdrawn')

    # ---------- 管理员审核通过 ----------
    app_b = submit_application(student, f'SMOKE-APPROVE-{TAG}', 4)
    check('待审核申请生成', app_b is not None and app_b['status'] == 'pending')
    response = admin.write('PUT', f"/admin/applications/{app_b['id']}/approve", {
        'final_score': '6',
        'review_comment': '材料齐全，同意加分',
    })
    check('管理员通过个人申请', write_ok(response), response.status)
    updated = find_application(student, f'SMOKE-APPROVE-{TAG}')
    check('审核后状态为 approved', updated and updated['status'] == 'approved')
    summary = student.props('/my-scores')['summary']
    check('审核后加分增加 6 分', summary['add_score'] == baseline_add + 6, summary['add_score'])
    check('总分 = 基础 40 + 加分 - 扣分', summary['total_score'] == 40 + summary['add_score'] - summary['deduct_score'], summary['total_score'])

    # ---------- 证据下载权限 ----------
    other = login_as('5276110085', '5276110085')
    if app_b and app_b.get('evidence_path'):
        check('他人禁止下载证明材料', other.get(f"/applications/{app_b['id']}/evidence").status == 403)
        check('本人可下载证明材料', student.get(f"/applications/{app_b['id']}/evidence").status == 200)
        check('管理员可下载证明材料', admin.get(f"/applications/{app_b['id']}/evidence").status == 200)

    # ---------- 驳回与批量驳回 ----------
    app_c = submit_application(student, f'SMOKE-REJECT-{TAG}', 3)
    response = admin.write('PUT', f"/admin/applications/{app_c['id']}/reject", {
        'review_comment': '证明材料不清晰，请重新提交',
    })
    check('管理员驳回个人申请', write_ok(response))
    check('驳回后状态为 rejected', find_application(student, f'SMOKE-REJECT-{TAG}')['status'] == 'rejected')

    bulk_ids = []
    for index, score in enumerate([2, 3], start=1):
        item = submit_application(other, f'SMOKE-BULK-{TAG}-{index}', score)
        if item:
            bulk_ids.append(item['id'])
    response = admin.write('POST', '/admin/review/bulk', {
        'type': 'personal',
        'action': 'reject',
        'ids[]': bulk_ids,
        'review_comment': '批量测试驳回',
    })
    check('批量驳回请求成功', write_ok(response))
    other_apps = other.props('/applications')['applications']['data']
    check(
        '批量驳回后状态为 rejected',
        all(next(a for a in other_apps if a['id'] == i)['status'] == 'rejected' for i in bulk_ids),
    )

    # ---------- 教师端 ----------
    teacher = login_as('0002025001', '0002025001')
    for path in ['/dashboard', '/teacher/group-applications', '/teacher/group-applications/create', '/leaderboard', '/announcements']:
        check(f'教师页面 {path}', teacher.get(path).status == 200)
    check('教师禁止访问审核中心', teacher.get('/admin/review').status == 403)
    check('教师可下载成员模板', teacher.get('/teacher/group-applications/template').status == 200)

    group_title = f'SMOKE-GROUP-{TAG}'
    response = teacher.upload(
        '/teacher/group-applications',
        {
            'title': group_title,
            'description': '冒烟测试集体活动，用于验证成员导入与审核流程。',
            'category_id': '1',
        },
        {'members_file': ('members.csv', csv_bytes([
            ('5276110074', '郭永强', 3),
            ('5276110085', '侯景翔', 3),
            ('5276110109', '房俊皓', 3),
        ]), 'text/csv')},
    )
    group = next(
        (item for item in teacher.props('/teacher/group-applications')['applications']['data'] if item['title'] == group_title),
        None,
    )
    check('教师提交集体申请', group is not None and response.status == 200, response.url)
    check('集体申请成员数为 3', group and group['members_count'] == 3, group['members_count'] if group else None)

    before_total = teacher.props('/teacher/group-applications')['applications']['total']
    teacher.upload(
        '/teacher/group-applications',
        {
            'title': f'{group_title}-DUP',
            'description': '重复学号名单应当被拒绝。',
            'category_id': '1',
        },
        {'members_file': ('members.csv', csv_bytes([
            ('5276110074', '郭永强', 3),
            ('5276110074', '郭永强', 3),
        ]), 'text/csv')},
    )
    after_total = teacher.props('/teacher/group-applications')['applications']['total']
    check('文件内重复学号被拒绝', before_total == after_total, f'{before_total} -> {after_total}')

    # ---------- 管理员审核集体申请 ----------
    group_detail = admin.props(f"/admin/review/groups/{group['id']}")
    members = group_detail['application']['members']
    check('管理员可查看集体申请成员', len(members) == 3)
    fields = {
        'review_comment': '集体申请材料齐全',
    }
    for member in members:
        fields[f'final_scores[{member["id"]}]'] = '4'
    response = admin.write('PUT', f"/admin/group-applications/{group['id']}/approve", fields)
    check('管理员通过集体申请', write_ok(response))
    group_after = teacher.props(f"/teacher/group-applications/{group['id']}")['application']
    check('集体申请状态为 approved', group_after['status'] == 'approved')
    summary_after_group = student.props('/my-scores')['summary']
    check(
        '集体成员获得 4 分',
        summary_after_group['add_score'] == baseline_add + 6 + 4,
        summary_after_group['add_score'],
    )

    # ---------- 教师撤回集体申请 ----------
    second_title = f'{group_title}-WITHDRAW'
    teacher.upload(
        '/teacher/group-applications',
        {
            'title': second_title,
            'description': '用于验证撤回流程。',
            'category_id': '1',
        },
        {'members_file': ('members.csv', csv_bytes([
            ('5276110074', '郭永强', 2),
            ('5276110085', '侯景翔', 2),
        ]), 'text/csv')},
    )
    second = next(
        (item for item in teacher.props('/teacher/group-applications')['applications']['data'] if item['title'] == second_title),
        None,
    )
    response = teacher.write('POST', f"/teacher/group-applications/{second['id']}/withdraw") if second else None
    check('教师撤回集体申请', response and response.status == 200)
    check('撤回后状态为 withdrawn', second and teacher.props(f"/teacher/group-applications/{second['id']}")['application']['status'] == 'withdrawn')

    # ---------- 类别 CRUD ----------
    category_name = f'SMOKE-CAT-{TAG}'
    admin.write('POST', '/admin/categories', {
        'name': category_name,
        'type': 'add',
        'description': '冒烟测试类别',
        'default_score': '2',
        'max_score': '10',
        'sort_order': '99',
        'is_active': '1',
    })
    categories = admin.props('/admin/categories')['categories']
    category = next((item for item in categories if item['name'] == category_name), None)
    check('新增类别', category is not None)
    if category:
        admin.write('PUT', f"/admin/categories/{category['id']}", {
            'name': category_name + '-2',
            'type': 'add',
            'sort_order': '99',
            'is_active': '1',
        })
        categories = admin.props('/admin/categories')['categories']
        check('编辑类别', any(item['name'] == category_name + '-2' for item in categories))
        response = admin.write('DELETE', f"/admin/categories/{category['id']}")
        categories = admin.props('/admin/categories')['categories']
        check('删除未使用类别', write_ok(response) and not any(item['id'] == category['id'] for item in categories))

    # ---------- 学年 ----------
    test_year = f'9{TAG[:3]}-9{int(TAG[:3]) + 1:03d}'
    admin.write('POST', '/admin/semesters', {
        'academic_year': test_year,
        'term': '1',
        'create_both': '1',
    })
    semesters = admin.props('/admin/semesters')['semesters']
    created_semesters = [item for item in semesters if item['academic_year'] == test_year]
    check('新增学期（一次创建两个学期）', len(created_semesters) == 2, len(created_semesters))
    if created_semesters:
        first_term = next(item for item in created_semesters if item['term'] == 1)
        admin.write('POST', f"/admin/semesters/{first_term['id']}/current")
        semesters = admin.props('/admin/semesters')['semesters']
        check('设置当前学期', next(item for item in semesters if item['id'] == first_term['id'])['is_current'])
        original = next(item for item in semesters if item['name'] == '2026-2027学年第一学期')
        admin.write('POST', f"/admin/semesters/{original['id']}/current")
        removed = 0
        for semester in created_semesters:
            response = admin.write('DELETE', f"/admin/semesters/{semester['id']}")
            removed += 1 if write_ok(response) else 0
        semesters = admin.props('/admin/semesters')['semesters']
        check('删除未使用学期', removed == len(created_semesters) and not any(item['academic_year'] == test_year for item in semesters))

    # ---------- 公告 ----------
    announcement_title = f'SMOKE-公告-{TAG}'
    admin.write('POST', '/admin/announcements', {
        'title': announcement_title,
        'content': '这是冒烟测试公告内容。',
        'is_important': '1',
    })
    announcements = admin.props('/announcements')['announcements']['data']
    announcement = next((item for item in announcements if item['title'] == announcement_title), None)
    check('发布公告', announcement is not None)
    if announcement:
        admin.write('PUT', f"/admin/announcements/{announcement['id']}", {
            'title': announcement_title + '-更新',
            'content': '更新后的公告内容。',
            'is_important': '0',
        })
        announcements = admin.props('/announcements')['announcements']['data']
        check('编辑公告', any(item['title'] == announcement_title + '-更新' for item in announcements))
        response = admin.write('DELETE', f"/admin/announcements/{announcement['id']}")
        announcements = admin.props('/announcements')['announcements']['data']
        check('删除公告', write_ok(response) and not any(item['id'] == announcement['id'] for item in announcements))

    # ---------- 用户管理 ----------
    username = f'smoke_{TAG}'
    admin.write('POST', '/admin/users', {
        'role': 'student',
        'name': '冒烟测试用户',
        'username': username,
        'student_id': username,
        'class_name': '测试班',
        'college': '测试书院',
        'grade': '2027级',
        'password': 'SmokePass123',
        'is_active': '1',
    })
    users = admin.props(f'/admin/users?search={username}')['users']['data']
    created = next((item for item in users if item['username'] == username), None)
    check('新增用户', created is not None)
    if created:
        admin.write('PUT', f"/admin/users/{created['id']}", {
            'role': 'student',
            'name': '冒烟测试用户2',
            'username': username,
            'student_id': username,
            'class_name': '测试班',
            'college': '测试书院',
            'grade': '2027级',
            'is_active': '1',
        })
        users = admin.props(f'/admin/users?search={username}')['users']['data']
        check('编辑用户', any(item['name'] == '冒烟测试用户2' for item in users))
        admin.write('POST', f"/admin/users/{created['id']}/toggle")
        users = admin.props(f'/admin/users?search={username}')['users']['data']
        check('禁用用户', users[0]['is_active'] is False)
        admin.write('POST', f"/admin/users/{created['id']}/reset-password")
        admin.write('POST', f"/admin/users/{created['id']}/toggle")
        users = admin.props(f'/admin/users?search={username}')['users']['data']
        check('重新启用用户', users[0]['is_active'] is True)
        smoke = Session()
        response = smoke.login(username, username)
        check('重置密码后可用账号作为密码登录', response.status == 200 and '/dashboard' in response.url)

    # ---------- 手工成绩 CRUD ----------
    admin.write('POST', '/admin/records', {
        'user_id': '2',
        'category_id': '1',
        'semester_id': str(next(item for item in admin.props('/admin/records')['semesters'] if item['is_current'])['id']),
        'score': '2',
        'description': f'SMOKE-MANUAL-{TAG}',
    })
    records = admin.props('/admin/records?search=5276110074')['records']['data']
    manual = next((item for item in records if item['description'] == f'SMOKE-MANUAL-{TAG}'), None)
    check('手工录入成绩', manual is not None and manual['score'] == 2)
    if manual:
        admin.write('PUT', f"/admin/records/{manual['id']}", {
            'user_id': '2',
            'category_id': '1',
            'semester_id': str(manual['semester_id']),
            'score': '3',
            'description': f'SMOKE-MANUAL-{TAG}',
        })
        records = admin.props('/admin/records?search=5276110074')['records']['data']
        check('修改成绩', next(item for item in records if item['id'] == manual['id'])['score'] == 3)
        admin.write('DELETE', f"/admin/records/{manual['id']}")
        records = admin.props('/admin/records?search=5276110074')['records']['data']
        check('撤销成绩', not any(item['id'] == manual['id'] for item in records))

    # ---------- 系统设置 ----------
    admin.write('PUT', '/admin/settings', {'base_score': '41', 'system_name': '德育分管理系统'})
    settings = admin.props('/admin/settings')['settings']
    check('修改基础分', settings['base_score'] == 41, settings['base_score'])
    check('基础分变更立即生效', student.props('/my-scores')['summary']['base_score'] == 41)
    admin.write('PUT', '/admin/settings', {'base_score': '40', 'system_name': '德育分管理系统'})
    check('恢复基础分', admin.props('/admin/settings')['settings']['base_score'] == 40)

    # ---------- 导出 ----------
    personal_export = student.get('/my-scores/export')
    check('导出个人成绩', personal_export.status == 200 and 'spreadsheet' in personal_export.headers.get('Content-Type', ''))
    leaderboard_export = admin.get('/admin/leaderboard/export')
    check('导出排行榜', leaderboard_export.status == 200 and 'spreadsheet' in leaderboard_export.headers.get('Content-Type', ''))

    # ---------- 统计与审计 ----------
    stats = admin.props('/admin/statistics')
    check('统计页面数据', stats.get('overview', {}).get('student_count', 0) >= 500)
    audit = admin.props('/admin/audit-logs')
    check('审计日志已记录', audit.get('logs', {}).get('total', 0) > 0, audit.get('logs', {}).get('total'))
    leaderboard = admin.props('/leaderboard')['leaderboard']['data']
    top_student = next((item for item in leaderboard if item['student_id'] == '5276110074'), None)
    check('排行榜总分同步', top_student is not None and top_student['total_score'] >= 50, top_student['total_score'] if top_student else None)

    # ---------- 汇总 ----------
    passed = sum(1 for _, ok, _ in RESULTS if ok)
    failed = len(RESULTS) - passed
    print(f'\n== 测试完成：{passed} 通过，{failed} 失败，共 {len(RESULTS)} 项 ==')
    if failed:
        print('失败项：')
        for name, ok, detail in RESULTS:
            if not ok:
                print(' -', name, detail)
        sys.exit(1)


if __name__ == '__main__':
    main()
