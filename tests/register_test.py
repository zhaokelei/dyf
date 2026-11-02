# -*- coding: utf-8 -*-
"""学生自主注册功能测试。"""
import sys
import time

sys.path.insert(0, 'tests')

from smoke_test import BASE, Session, check, login_as, RESULTS

TAG = str(int(time.time()))[-6:]


def register_props(session):
    return session.get('/register/student').json_props()


def base_payload(props, student_id):
    classroom = props['classes'][0]
    college = next(item for item in props['colleges'] if item['id'] == classroom['college_id'])
    major = next(item for item in college['majors'] if item['id'] == classroom['major_id'])

    return {
        'student_id': student_id,
        'name': '注册测试学生',
        'college_id': str(college['id']),
        'major_id': str(major['id']),
        'grade': classroom['grade'],
        'class_id': str(classroom['id']),
        'password': 'Register123',
        'password_confirmation': 'Register123',
    }, college, major, classroom


def restore_settings(admin):
    admin.write('PUT', '/admin/settings', {
        'base_score': '40',
        'system_name': '郑州工商学院德育分管理系统',
        'registration_enabled': '1',
        'registration_requires_approval': '0',
    })


def main():
    print(f'== 学生注册测试开始 {BASE} ==')
    admin = login_as('admin', 'admin123')

    seed_session = Session()
    props = register_props(seed_session)
    check('注册页开放', props.get('enabled') is True)

    if not props.get('classes'):
        print('SKIP: 当前数据库没有班级，学生注册测试需先导入班级或新增班级。')
        return

    student_id = '2026' + TAG
    payload, college, major, classroom = base_payload(props, student_id)

    session = Session()
    register_props(session)
    response = session.write('POST', '/register/student', payload)
    check('注册成功并自动登录', response.status == 200 and '/dashboard' in response.url, response.url)

    users = admin.props(f'/admin/users?search={student_id}')['users']['data']
    created = users[0] if users else None
    check('注册账号已创建', created is not None)
    if created:
        check('账号与学号一致', created['username'] == student_id and created['student_id'] == student_id)
        check('角色为学生', created['role'] == 'student')
        check(
            '学院/专业/班级归属正确',
            created['college_id'] == college['id']
            and created['major_id'] == major['id']
            and created['class_id'] == classroom['id'],
        )

    login_session = Session()
    response = login_session.login(student_id, 'Register123')
    check('注册后可使用自设密码登录', '/dashboard' in response.url)

    duplicate = Session()
    register_props(duplicate)
    response = duplicate.write('POST', '/register/student', payload)
    duplicate_props = response.json_props()
    check(
        '重复学号被拒绝',
        '/register/student' in response.url and 'student_id' in duplicate_props.get('errors', {}),
        duplicate_props.get('errors'),
    )

    mismatch = Session()
    register_props(mismatch)
    bad_payload = dict(payload)
    bad_payload['student_id'] = 'bad' + TAG
    bad_payload['grade'] = '2028级'
    response = mismatch.write('POST', '/register/student', bad_payload)
    mismatch_props = response.json_props()
    check(
        '年级与班级不匹配被拒绝',
        '/register/student' in response.url and 'class_id' in mismatch_props.get('errors', {}),
        mismatch_props.get('errors'),
    )

    admin.write('PUT', '/admin/settings', {
        'base_score': '40',
        'system_name': '郑州工商学院德育分管理系统',
        'registration_enabled': '0',
        'registration_requires_approval': '0',
    })
    check('关闭注册后页面禁用', register_props(Session()).get('enabled') is False)
    disabled = Session()
    register_props(disabled)
    response = disabled.write('POST', '/register/student', {**payload, 'student_id': 'off' + TAG})
    check('关闭注册后提交被拒绝', '/register/student' in response.url)

    admin.write('PUT', '/admin/settings', {
        'base_score': '40',
        'system_name': '郑州工商学院德育分管理系统',
        'registration_enabled': '1',
        'registration_requires_approval': '1',
    })
    approval_id = 'appr' + TAG
    approval = Session()
    register_props(approval)
    response = approval.write('POST', '/register/student', {**payload, 'student_id': approval_id})
    check('需审核时不自动登录', response.url.endswith('/login'), response.url)
    pending = admin.props(f'/admin/users?search={approval_id}')['users']['data']
    check('待审核账号默认禁用', pending and pending[0]['is_active'] is False)

    restore_settings(admin)

    passed = sum(1 for _, ok, _ in RESULTS if ok)
    failed = len(RESULTS) - passed
    print(f'\n== 学生注册测试完成：{passed} 通过，{failed} 失败，共 {len(RESULTS)} 项 ==')
    if failed:
        for name, ok, detail in RESULTS:
            if not ok:
                print(' -', name, detail)
        sys.exit(1)


if __name__ == '__main__':
    main()
