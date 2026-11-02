# -*- coding: utf-8 -*-
"""排行榜范围与周期组合测试。"""
import sys
import time
import urllib.parse

sys.path.insert(0, 'tests')

from smoke_test import BASE, check, login_as, RESULTS

YEAR = '2026-2027'


def main():
    print(f'== 排行榜范围测试开始 {BASE} ==')
    admin = login_as('admin', 'admin123')

    base = admin.props('/leaderboard?scope=all&per_page=1')
    semester_value = base['period']['value']
    college = base['filterOptions']['colleges'][0]
    grade = base['filterOptions']['grades'][0]
    classroom = base['filterOptions']['classes'][0]
    major = base['filterOptions']['majors'][0]

    check('全校总榜', base['leaderboard']['total'] >= 500 and base['scopeLabel'] == '全校总榜')

    college_scope = admin.props(f"/leaderboard?scope=college&college_id={college['id']}&per_page=200")
    check(
        '学院榜只含该学院学生',
        college_scope['leaderboard']['total'] > 0
        and all(item['college'] == college['name'] for item in college_scope['leaderboard']['data']),
    )

    encoded_grade = urllib.parse.quote(grade)
    grade_scope = admin.props(f'/leaderboard?scope=grade&grade={encoded_grade}&per_page=200')
    check(
        '年级榜只含该年级学生',
        grade_scope['leaderboard']['total'] > 0
        and all(item['grade'] == grade for item in grade_scope['leaderboard']['data']),
    )

    class_scope = admin.props(f"/leaderboard?scope=class&class_id={classroom['id']}&per_page=200")
    check(
        '班级榜只含该班级学生',
        class_scope['leaderboard']['total'] > 0
        and all(item['class_name'] == classroom['name'] for item in class_scope['leaderboard']['data']),
    )

    check('排行榜展示专业', all(item['major'] for item in base['leaderboard']['data']))
    major_scope = admin.props(f"/leaderboard?scope=all&major_id={major['id']}&per_page=200")
    check(
        '专业筛选生效',
        major_scope['leaderboard']['total'] > 0
        and all(item['major'] == major['name'] for item in major_scope['leaderboard']['data']),
    )
    search_scope = admin.props('/leaderboard?scope=all&search=5276110074&per_page=200')
    check(
        '姓名/学号搜索生效',
        search_scope['leaderboard']['total'] == 1
        and search_scope['leaderboard']['data'][0]['student_id'] == '5276110074',
    )
    check('班级榜支持周排行', admin.props(
        f"/leaderboard?scope=class&class_id={classroom['id']}&rank_period=week&per_page=1"
    )['ranking']['rank_period'] == 'week')
    check('学院榜支持学年汇总', admin.props(
        f"/leaderboard?scope=college&college_id={college['id']}&period=year:{YEAR}&per_page=1"
    )['period']['scope'] == 'year')
    check('学院榜导出', admin.get(
        f"/admin/leaderboard/export?scope=college&college_id={college['id']}&period={semester_value}&rank_period=month"
    ).status == 200)

    today = time.strftime('%Y-%m-%d')
    scope_params = {
        'all': '',
        'college': f"&college_id={college['id']}",
        'grade': f'&grade={encoded_grade}',
        'class': f"&class_id={classroom['id']}",
    }

    for period in [semester_value, f'year:{YEAR}']:
        for rank in ['all', 'week', 'month', 'custom']:
            for scope, extra in scope_params.items():
                query = f'/leaderboard?period={period}&rank_period={rank}&scope={scope}{extra}&per_page=1'
                if rank == 'custom':
                    query += f'&start_date={today}&end_date={today}'
                props = admin.props(query)
                check(
                    f'组合 {period}/{rank}/{scope}',
                    props['period']['value'] == period
                    and props['ranking']['rank_period'] == rank
                    and props['filters']['scope'] == scope,
                )

    for scope, extra in scope_params.items():
        total_all = admin.props(
            f'/leaderboard?period={semester_value}&rank_period=all&scope={scope}{extra}&per_page=1'
        )['leaderboard']['total']
        total_week = admin.props(
            f'/leaderboard?period={semester_value}&rank_period=week&scope={scope}{extra}&per_page=1'
        )['leaderboard']['total']
        check(f'{scope} 范围总人数一致', total_all == total_week, (total_all, total_week))

    passed = sum(1 for _, ok, _ in RESULTS if ok)
    failed = len(RESULTS) - passed
    print(f'\n== 排行榜测试完成：{passed} 通过，{failed} 失败，共 {len(RESULTS)} 项 ==')
    if failed:
        for name, ok, detail in RESULTS:
            if not ok:
                print(' -', name, detail)
        sys.exit(1)


if __name__ == '__main__':
    main()
