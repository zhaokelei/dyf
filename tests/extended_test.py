# -*- coding: utf-8 -*-
"""扩展功能测试：学期/学年汇总、周月/自定义排行、图片材料、扣分申诉。"""
import sys
import time

sys.path.insert(0, 'tests')

from smoke_test import BASE, Session, check, csv_bytes, login_as, png_bytes, RESULTS

TAG = str(int(time.time()))[-6:]


def main():
    print(f'== 扩展测试开始 {BASE} ==')

    admin = login_as('admin', 'admin123')
    student = login_as('5276110074', '5276110074')
    other = login_as('5276110085', '5276110085')

    # ---------- 学期 / 学年汇总 ----------
    semesters = admin.props('/admin/semesters')['semesters']
    term1 = next(item for item in semesters if item['name'] == '2026-2027学年第一学期')
    term2 = next(item for item in semesters if item['name'] == '2026-2027学年第二学期')

    semester_rank = admin.props(f"/leaderboard?period=semester:{term1['id']}&per_page=200")
    year_rank = admin.props('/leaderboard?period=year:2026-2027&per_page=200')
    check('学年汇总范围', year_rank['period']['scope'] == 'year', year_rank['period']['label'])

    semester_student = next(item for item in semester_rank['leaderboard']['data'] if item['student_id'] == '5276110074')
    year_student = next(item for item in year_rank['leaderboard']['data'] if item['student_id'] == '5276110074')
    check('学年汇总包含第一学期成绩', year_student['total_score'] >= semester_student['total_score'])

    # 在第二学期写入一条成绩，验证学年汇总会即时聚合
    records_props = admin.props('/admin/records')
    add_category = next(item for item in records_props['categories'] if item['type'] == 'add')
    admin.write('POST', '/admin/records', {
        'user_id': '2',
        'category_id': str(add_category['id']),
        'semester_id': str(term2['id']),
        'score': '3',
        'description': f'SMOKE-TERM2-{TAG}',
    })
    semester_after = next(
        item for item in admin.props(f"/leaderboard?period=semester:{term1['id']}&per_page=200")['leaderboard']['data']
        if item['student_id'] == '5276110074'
    )
    year_after = next(
        item for item in admin.props('/leaderboard?period=year:2026-2027&per_page=200')['leaderboard']['data']
        if item['student_id'] == '5276110074'
    )
    check('第二学期成绩不影响第一学期', semester_after['total_score'] == semester_student['total_score'])
    check('学年汇总跨学期累加', year_after['total_score'] == year_student['total_score'] + 3, year_after['total_score'])

    term2_record = next(
        (item for item in admin.props(f"/admin/records?semester_id={term2['id']}&search=5276110074")['records']['data']
         if item['description'] == f'SMOKE-TERM2-{TAG}'),
        None,
    )
    if term2_record:
        admin.write('DELETE', f"/admin/records/{term2_record['id']}")

    # ---------- 周 / 月 / 自定义排行 ----------
    week_rank = admin.props(f"/leaderboard?period=semester:{term1['id']}&rank_period=week&per_page=200")
    month_rank = admin.props(f"/leaderboard?period=semester:{term1['id']}&rank_period=month&per_page=200")
    today = time.strftime('%Y-%m-%d')
    custom_rank = admin.props(
        f"/leaderboard?period=semester:{term1['id']}&rank_period=custom&start_date={today}&end_date={today}&per_page=200"
    )
    check('本周排行模式', week_rank['ranking']['rank_period'] == 'week')
    check('本月排行模式', month_rank['ranking']['rank_period'] == 'month')
    check('自定义日期排行模式', custom_rank['ranking']['rank_period'] == 'custom')
    week_student = next(item for item in week_rank['leaderboard']['data'] if item['student_id'] == '5276110074')
    check('周排行返回本期得分', 'period_score' in week_student and week_student['period_score'] >= 0)
    check('周排行累计总分字段存在', 'total_score' in week_student)

    # ---------- 申请材料必须是图片 ----------
    pdf_content = b'%PDF-1.4\n1 0 obj << /Type /Catalog >> endobj\ntrailer << /Root 1 0 R >>\n%%EOF\n'
    pdf_description = f'SMOKE-PDF-REJECT-{TAG}'
    student.upload(
        '/applications',
        {'category_id': '1', 'score': '2', 'description': pdf_description},
        {'evidence': ('proof.pdf', pdf_content, 'application/pdf')},
    )
    applications = student.props('/applications')['applications']['data']
    check('PDF 证明材料被拒绝', not any(item['description'] == pdf_description for item in applications))

    png_description = f'SMOKE-PNG-ACCEPT-{TAG}'
    response = student.upload(
        '/applications',
        {'category_id': '1', 'score': '2', 'description': png_description},
        {'evidence': ('proof.png', png_bytes(), 'image/png')},
    )
    applications = student.props('/applications')['applications']['data']
    png_app = next((item for item in applications if item['description'] == png_description), None)
    check('图片证明材料可以提交', png_app is not None)
    if png_app:
        student.write('POST', f"/applications/{png_app['id']}/withdraw")

    # ---------- 扣分申诉 ----------
    baseline_deduct = student.props('/my-scores')['summary']['deduct_score']
    deduct_category = next(item for item in records_props['categories'] if item['type'] == 'deduct')

    admin.write('POST', '/admin/records', {
        'user_id': '2',
        'category_id': str(deduct_category['id']),
        'semester_id': str(term1['id']),
        'score': '2',
        'description': f'SMOKE-APPEAL-DEDUCT-{TAG}',
    })
    check('扣分记录录入成功', student.props('/my-scores')['summary']['deduct_score'] == baseline_deduct + 2)

    appeals_page = student.props('/appeals')
    record = next(
        (item for item in appeals_page['records']['data'] if item['description'] == f'SMOKE-APPEAL-DEDUCT-{TAG}'),
        None,
    )
    check('申诉页展示扣分记录', record is not None)

    if record:
        response = student.upload(
            '/appeals',
            {'score_record_id': str(record['id']), 'reason': '该扣分与事实不符，已附签到截图。'},
            {'evidence': ('appeal.png', png_bytes(), 'image/png')},
        )
        check('提交扣分申诉', response.status == 200, response.url)
        record_after = next(
            item for item in student.props('/appeals')['records']['data'] if item['id'] == record['id']
        )
        check('申诉状态为待处理', record_after['latest_appeal'] and record_after['latest_appeal']['status'] == 'pending')

        appeal = next(
            (item for item in admin.props('/admin/appeals')['appeals']['data'] if item['score_record']['id'] == record['id']),
            None,
        )
        check('管理员看到待处理申诉', appeal is not None)

        if appeal:
            check('他人禁止查看申诉材料', other.get(f"/appeals/{appeal['id']}/evidence").status == 403)
            check('本人可查看申诉材料', student.get(f"/appeals/{appeal['id']}/evidence").status == 200)
            check('管理员可查看申诉材料', admin.get(f"/appeals/{appeal['id']}/evidence").status == 200)
            response = admin.write('PUT', f"/admin/appeals/{appeal['id']}/approve", {'review_comment': '情况属实'})
            check('通过申诉请求成功', response.status in (200, 302))
            check('通过后扣分被撤销', student.props('/my-scores')['summary']['deduct_score'] == baseline_deduct)
            revoked = next(
                item for item in student.props('/my-scores')['summary']['records'] if item['id'] == record['id']
            )
            check('记录标记为已撤销', revoked['is_revoked'] is True)

            # 重复申诉应被拒绝，已有申诉状态不改变
            student.upload(
                '/appeals',
                {'score_record_id': str(record['id']), 'reason': '重复申诉测试'},
                {'evidence': ('appeal.png', png_bytes(), 'image/png')},
            )
            record_again = next(
                item for item in student.props('/appeals')['records']['data'] if item['id'] == record['id']
            )
            check('已通过的申诉不能重复提交', record_again['latest_appeal']['status'] == 'approved')

    admin.write('POST', '/admin/records', {
        'user_id': '2',
        'category_id': str(deduct_category['id']),
        'semester_id': str(term1['id']),
        'score': '1',
        'description': f'SMOKE-APPEAL-REJECT-{TAG}',
    })
    reject_record = next(
        (item for item in student.props('/appeals')['records']['data'] if item['description'] == f'SMOKE-APPEAL-REJECT-{TAG}'),
        None,
    )
    if reject_record:
        student.upload(
            '/appeals',
            {'score_record_id': str(reject_record['id']), 'reason': '对扣分有异议。'},
            {'evidence': ('appeal.png', png_bytes(), 'image/png')},
        )
        reject_appeal = next(
            (item for item in admin.props('/admin/appeals')['appeals']['data'] if item['score_record']['id'] == reject_record['id']),
            None,
        )
        if reject_appeal:
            response = admin.write('PUT', f"/admin/appeals/{reject_appeal['id']}/reject", {'review_comment': '证明材料不足'})
            check('驳回申诉请求成功', response.status in (200, 302))
            check('驳回后扣分保留', student.props('/my-scores')['summary']['deduct_score'] == baseline_deduct + 1)
            rejected = next(
                (item for item in admin.props('/admin/appeals?status=all')['appeals']['data'] if item['id'] == reject_appeal['id']),
                None,
            )
            check('申诉状态为已驳回', rejected and rejected['status'] == 'rejected')

    # ---------- 汇总 ----------
    passed = sum(1 for _, ok, _ in RESULTS if ok)
    failed = len(RESULTS) - passed
    print(f'\n== 扩展测试完成：{passed} 通过，{failed} 失败，共 {len(RESULTS)} 项 ==')
    if failed:
        for name, ok, detail in RESULTS:
            if not ok:
                print(' -', name, detail)
        sys.exit(1)


if __name__ == '__main__':
    main()
