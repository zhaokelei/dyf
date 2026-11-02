# -*- coding: utf-8 -*-
"""学院管理员 / 班级管理员分级权限测试。"""
import sys
import time

sys.path.insert(0, 'tests')

from smoke_test import BASE, Session, check, csv_bytes, login_as, png_bytes, RESULTS

TAG = str(int(time.time()))[-6:]


def submit_application(student, description):
    student.upload(
        '/applications',
        {'category_id': '1', 'score': '2', 'description': description},
        {'evidence': ('proof.png', png_bytes(), 'image/png')},
    )
    items = student.props('/applications')['applications']['data']

    return next((item for item in items if item['description'] == description), None)


def main():
    print(f'== 分级权限测试开始 {BASE} ==')

    admin = login_as('admin', 'admin123')
    college = login_as('college_admin', 'College@123')
    klass = login_as('class_admin', 'Class@123')

    # 找到不同学院/班级的学生
    leaderboard = admin.props('/leaderboard?per_page=100')['leaderboard']['data']
    own_student = next(item for item in leaderboard if item['student_id'] == '5276110074')
    other_college = next(item for item in leaderboard if item['college'] == '青云书院')
    same_class_other = next(
        item
        for item in leaderboard
        if item['class_name'] == '公安71' and item['student_id'] != '5276110074'
    )
    other_student = Session()
    other_student.login(other_college['student_id'], other_college['student_id'])
    classmate = Session()
    classmate.login(same_class_other['student_id'], same_class_other['student_id'])

    # ---------- 页面与范围 ----------
    check('学院管理员进入组织管理', college.get('/admin/organization').status == 200)
    check('班级管理员禁止进入组织管理', klass.get('/admin/organization').status == 403)
    check('学院管理员禁止进入学期配置', college.get('/admin/semesters').status == 403)
    check('班级管理员禁止进入学期配置', klass.get('/admin/semesters').status == 403)

    college_dashboard = college.props('/dashboard')
    class_dashboard = klass.props('/dashboard')
    check('学院工作台仅统计本学院学生', college_dashboard['overview']['student_count'] == 167, college_dashboard['overview']['student_count'])
    check('班级工作台仅统计本班学生', class_dashboard['overview']['student_count'] == 11, class_dashboard['overview']['student_count'])
    check('学院范围标识', college_dashboard['scopeLabel'] == '沧澜书院', college_dashboard['scopeLabel'])
    check('班级范围标识', class_dashboard['scopeLabel'] == '公安71', class_dashboard['scopeLabel'])

    college_users = college.props('/admin/users')
    class_users = klass.props('/admin/users')
    check('学院用户统计', college_users['stats']['students'] == 167, college_users['stats'])
    check('班级用户统计', class_users['stats']['students'] == 11, class_users['stats'])
    check('班级管理员没有创建用户权限', class_users['canCreate'] is False)

    # ---------- 个人申请范围 ----------
    desc_own = f'SCOPE-OWN-{TAG}'
    desc_other = f'SCOPE-OTHER-{TAG}'
    desc_classmate = f'SCOPE-CLASSMATE-{TAG}'
    own_app = submit_application(login_as('5276110074', '5276110074'), desc_own)
    other_app = submit_application(other_student, desc_other)
    classmate_app = submit_application(classmate, desc_classmate)

    all_personal = admin.props('/admin/review?status=all')['personal']['data']

    def app_id(description):
        return next((item['id'] for item in all_personal if item['description'] == description), None)

    own_app_id = app_id(desc_own)
    other_app_id = app_id(desc_other)
    classmate_app_id = app_id(desc_classmate)
    check('测试申请已创建', None not in (own_app_id, other_app_id, classmate_app_id))

    college_review = college.props('/admin/review?status=all')['personal']['data']
    class_review = klass.props('/admin/review?status=all')['personal']['data']
    college_descs = {item['description'] for item in college_review}
    class_descs = {item['description'] for item in class_review}
    check('学院审核列表包含本学院申请', desc_own in college_descs and desc_classmate in college_descs)
    check('学院审核列表排除其他学院申请', desc_other not in college_descs)
    check('班级审核列表包含本班申请', desc_own in class_descs and desc_classmate in class_descs)
    check('班级审核列表排除其他学院申请', desc_other not in class_descs)

    response = klass.write('PUT', f'/admin/applications/{other_app_id}/approve', {'final_score': '2'})
    check('班级管理员禁止审核外班申请', response.status == 403, response.status)
    response = college.write('PUT', f'/admin/applications/{other_app_id}/approve', {'final_score': '2'})
    check('学院管理员禁止审核外院申请', response.status == 403, response.status)
    response = klass.write('PUT', f'/admin/applications/{own_app_id}/approve', {'final_score': '2'})
    check('班级管理员可审核本班申请', response.status in (200, 302), response.status)
    response = college.write('PUT', f'/admin/applications/{classmate_app_id}/approve', {'final_score': '2'})
    check('学院管理员可审核本学院申请', response.status in (200, 302), response.status)

    # ---------- 组织授权 ----------
    org = college.props('/admin/organization')
    own_college = org['colleges'][0]
    own_class = next(item for item in own_college['classes'] if item['name'] == '公安71')
    check('学院管理员只看到授权学院', [item['name'] for item in org['colleges']] == ['沧澜书院'])

    admin_org = admin.props('/admin/organization')
    other_class = next(
        item
        for college_item in admin_org['colleges']
        if college_item['name'] == '青云书院'
        for item in college_item['classes']
    )

    admin_users = admin.props(f'/admin/users?search={other_college["student_id"]}')['users']['data']
    other_user = next(item for item in admin_users if item['student_id'] == other_college['student_id'])
    classmate_users = admin.props(f'/admin/users?search={same_class_other["student_id"]}')['users']['data']
    classmate_user = next(item for item in classmate_users if item['student_id'] == same_class_other['student_id'])

    response = college.write('POST', '/admin/organization/class-admins', {
        'user_id': str(other_user['id']),
        'class_id': str(other_class['id']),
    })
    check('学院管理员禁止授权其他学院班级管理员', response.status == 403, response.status)

    response = college.write('POST', '/admin/organization/class-admins', {
        'user_id': str(other_user['id']),
        'class_id': str(own_class['id']),
    })
    check('学院管理员不能授权外院用户为班级管理员', response.status in (200, 302), response.status)

    new_class_admin = Session()
    response = new_class_admin.login(other_college['student_id'], other_college['student_id'])
    # 外院用户不应被授权成功，仍保持学生角色
    check('外院用户仍为学生', '/dashboard' in response.url)
    users_after = admin.props(f'/admin/users?search={other_college["student_id"]}')['users']['data']
    check('外院用户角色未变更', users_after[0]['role'] == 'student', users_after[0]['role'])

    response = college.write('POST', '/admin/organization/class-admins', {
        'user_id': str(classmate_user['id']),
        'class_id': str(own_class['id']),
    })
    check('学院管理员可授权本班班级管理员', response.status in (200, 302), response.status)
    users_after = admin.props(f'/admin/users?search={same_class_other["student_id"]}')['users']['data']
    check('本班用户角色变为班级管理员', users_after[0]['role'] == 'class_admin', users_after[0]['role'])
    response = college.write('DELETE', f"/admin/organization/class-admins/{classmate_user['id']}/{own_class['id']}")
    check('学院管理员可撤销班级管理员', response.status in (200, 302), response.status)

    # ---------- 用户管理范围 ----------
    response = college.write('PUT', f"/admin/users/{other_user['id']}", {
        'role': 'student',
        'name': other_user['name'],
        'username': other_user['username'],
        'student_id': other_user['student_id'],
        'college_id': str(other_user['college_id'] or ''),
        'class_id': str(other_user['class_id'] or ''),
        'is_active': '1',
    })
    check('学院管理员禁止修改外院用户', response.status == 403, response.status)
    response = klass.write('PUT', f"/admin/users/{other_user['id']}", {
        'role': 'student',
        'name': other_user['name'],
        'username': other_user['username'],
        'student_id': other_user['student_id'],
        'college_id': str(other_user['college_id'] or ''),
        'class_id': str(other_user['class_id'] or ''),
        'is_active': '1',
    })
    check('班级管理员禁止修改外班用户', response.status == 403, response.status)

    # ---------- 成绩与申诉范围 ----------
    records = admin.props('/admin/records')['categories']
    deduct = next(item for item in records if item['type'] == 'deduct')
    semesters = admin.props('/admin/records')['semesters']
    current_semester = next(item for item in semesters if item['is_current'])
    other_id = other_user['id']
    admin.write('POST', '/admin/records', {
        'user_id': str(other_id),
        'category_id': str(deduct['id']),
        'semester_id': str(current_semester['id']),
        'score': '1',
        'description': f'SCOPE-RECORD-{TAG}',
    })
    other_records = admin.props(f'/admin/records?search={other_college["student_id"]}')['records']['data']
    other_record = next(item for item in other_records if item['description'] == f'SCOPE-RECORD-{TAG}')
    college_records = college.props('/admin/records?search=' + other_college['student_id'])['records']['data']
    class_records = klass.props('/admin/records?search=' + other_college['student_id'])['records']['data']
    check('学院成绩列表排除外院记录', not any(item['id'] == other_record['id'] for item in college_records))
    check('班级成绩列表排除外班记录', not any(item['id'] == other_record['id'] for item in class_records))
    response = college.write('DELETE', f"/admin/records/{other_record['id']}")
    check('学院管理员禁止删除外院成绩', response.status == 403, response.status)

    other_student.upload(
        '/appeals',
        {'score_record_id': str(other_record['id']), 'reason': '跨学院申诉范围测试'},
        {'evidence': ('appeal.png', png_bytes(), 'image/png')},
    )
    other_appeal = next(
        (item for item in admin.props('/admin/appeals?status=all')['appeals']['data'] if item['score_record']['id'] == other_record['id']),
        None,
    )
    check('外院申诉已创建', other_appeal is not None)
    if other_appeal:
        college_appeals = college.props('/admin/appeals?status=all')['appeals']['data']
        class_appeals = klass.props('/admin/appeals?status=all')['appeals']['data']
        check('学院申诉列表排除外院申诉', not any(item['id'] == other_appeal['id'] for item in college_appeals))
        check('班级申诉列表排除外班申诉', not any(item['id'] == other_appeal['id'] for item in class_appeals))
        response = college.write('PUT', f"/admin/appeals/{other_appeal['id']}/approve", {'review_comment': '越权测试'})
        check('学院管理员禁止审核外院申诉', response.status == 403, response.status)

    # ---------- 集体申请范围 ----------
    teacher = login_as('0002025001', '0002025001')
    group_title = f'SCOPE-GROUP-{TAG}'
    teacher.upload(
        '/teacher/group-applications',
        {
            'title': group_title,
            'description': '分级权限测试集体申请。',
            'category_id': '1',
        },
        {'members_file': ('members.csv', csv_bytes([
            ('5276110074', '郭永强', 2),
            ('5276110085', '侯景翔', 2),
        ]), 'text/csv')},
    )
    group = next(
        (item for item in teacher.props('/teacher/group-applications')['applications']['data'] if item['title'] == group_title),
        None,
    )
    check('集体申请已创建', group is not None)
    if group:
        college_groups = college.props('/admin/review?status=all')['groups']['data']
        class_groups = klass.props('/admin/review?status=all')['groups']['data']
        check('学院可见涉及本院成员的集体申请', any(item['id'] == group['id'] for item in college_groups))
        check('班级可见涉及本班成员的集体申请', any(item['id'] == group['id'] for item in class_groups))
        response = klass.write('PUT', f"/admin/group-applications/{group['id']}/approve", {})
        check('班级管理员禁止审核集体申请', response.status == 403, response.status)
        response = college.write('PUT', f"/admin/group-applications/{group['id']}/approve", {})
        check('学院管理员可审核涉及本院成员的集体申请', response.status in (200, 302), response.status)

    # ---------- 汇总 ----------
    passed = sum(1 for _, ok, _ in RESULTS if ok)
    failed = len(RESULTS) - passed
    print(f'\n== 分级权限测试完成：{passed} 通过，{failed} 失败，共 {len(RESULTS)} 项 ==')
    if failed:
        for name, ok, detail in RESULTS:
            if not ok:
                print(' -', name, detail)
        sys.exit(1)


if __name__ == '__main__':
    main()
