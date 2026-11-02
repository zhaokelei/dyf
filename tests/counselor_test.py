# -*- coding: utf-8 -*-
"""辅导员多年级授权测试。"""
import sys

sys.path.insert(0, 'tests')

from smoke_test import BASE, Session, check, login_as, RESULTS


def main():
    print(f'== 辅导员多年级测试开始 {BASE} ==')
    admin = login_as('admin', 'admin123')
    college = login_as('college_admin', 'College@123')
    counselor = Session()
    response = counselor.login('counselor', 'Counselor@123')
    check('辅导员登录', response.status == 200 and '/dashboard' in response.url, response.url)

    org = admin.props('/admin/organization')
    canglan = next(item for item in org['colleges'] if item['name'] == '沧澜书院')
    counselor_user = admin.props('/admin/users?search=counselor')['users']['data'][0]
    grade_scopes = [
        scope
        for scope in canglan['grade_admins']
        if scope['user']['id'] == counselor_user['id']
    ]
    check('辅导员拥有两个年级授权', len(grade_scopes) == 2, grade_scopes)

    scope_keys = {(scope['major']['name'], scope['grade']) for scope in grade_scopes}
    expected = sum(
        classroom['users_count']
        for classroom in canglan['classes']
        if (classroom['major_name'], classroom['grade']) in scope_keys
    )

    dashboard = counselor.props('/dashboard')
    check('辅导员学生范围等于授权年级班级之和', dashboard['overview']['student_count'] == expected, dashboard['overview']['student_count'])
    check('辅导员范围标签包含年级', '2027级' in dashboard['scopeLabel'] and '2028级' in dashboard['scopeLabel'], dashboard['scopeLabel'])
    users_stats = counselor.props('/admin/users')['stats']
    check('辅导员用户统计', users_stats['students'] == expected)
    active_expected = expected - users_stats['inactive']
    check('辅导员排行榜仅统计启用学生', counselor.props('/leaderboard')['leaderboard']['total'] == active_expected, active_expected)
    check('辅导员可审核/成绩/申诉', all(counselor.get(path).status == 200 for path in ['/admin/review', '/admin/records', '/admin/appeals']))
    check('辅导员禁止组织管理', counselor.get('/admin/organization').status == 403)

    leaderboard = admin.props('/leaderboard?per_page=100')['leaderboard']['data']
    outside = next(item for item in leaderboard if item['class_name'].startswith('心理'))
    outside_user = admin.props(f"/admin/users?search={outside['student_id']}")['users']['data'][0]
    check('辅导员禁止访问非授权年级学生', counselor.get(f"/admin/students/{outside_user['id']}").status == 403)

    education = next(item for item in canglan['majors'] if item['name'] == '教育')
    education_expected = sum(
        classroom['users_count']
        for classroom in canglan['classes']
        if classroom['major_id'] == education['id'] and classroom['grade'] == '2028级'
    )
    response = college.write('POST', '/admin/organization/grade-admins', {
        'user_id': str(counselor_user['id']),
        'major_id': str(education['id']),
        'grade': '2028级',
    })
    check('学院管理员为辅导员追加年级授权', response.status in (200, 302), response.status)
    check('追加后学生范围扩大', counselor.props('/dashboard')['overview']['student_count'] == expected + education_expected)

    org = college.props('/admin/organization')
    extra_scope = next(
        (
            scope
            for scope in org['colleges'][0]['grade_admins']
            if scope['user']['id'] == counselor_user['id']
            and scope['major']['id'] == education['id']
            and scope['grade'] == '2028级'
        ),
        None,
    )
    check('新增年级授权记录可见', extra_scope is not None)
    if extra_scope:
        college.write('DELETE', f"/admin/organization/grade-admins/{extra_scope['id']}")
        check('撤销后学生范围还原', counselor.props('/dashboard')['overview']['student_count'] == expected)

    passed = sum(1 for _, ok, _ in RESULTS if ok)
    failed = len(RESULTS) - passed
    print(f'\n== 辅导员多年级测试完成：{passed} 通过，{failed} 失败，共 {len(RESULTS)} 项 ==')
    if failed:
        for name, ok, detail in RESULTS:
            if not ok:
                print(' -', name, detail)
        sys.exit(1)


if __name__ == '__main__':
    main()
