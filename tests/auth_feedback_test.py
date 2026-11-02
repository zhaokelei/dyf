# -*- coding: utf-8 -*-
"""登录成功/失败原因与退出提示测试。"""
import sys

sys.path.insert(0, 'tests')

from smoke_test import BASE, Session, check, login_as, RESULTS


def message_of(errors, key):
    value = errors.get(key, '')

    if isinstance(value, list):
        return value[0] if value else ''

    return value


def main():
    print(f'== 登录反馈测试开始 {BASE} ==')
    admin = login_as('admin', 'admin123')

    success = Session()
    response = success.login('5276110074', '5276110074')
    flash = response.json_props().get('flash', {})
    check('登录成功提示', '登录成功' in (flash.get('success') or ''), flash)

    wrong = Session()
    wrong.get('/login')
    response = wrong.write('POST', '/login', {'account': '5276110074', 'password': 'wrong-password'})
    errors = response.json_props().get('errors', {})
    check('密码错误原因', '密码错误' in message_of(errors, 'password'), errors)

    unknown = Session()
    unknown.get('/login')
    response = unknown.write('POST', '/login', {'account': '0000000000', 'password': 'anything'})
    errors = response.json_props().get('errors', {})
    check('账号未注册原因', '尚未注册' in message_of(errors, 'account'), errors)

    users = admin.props('/admin/users?search=5276110074')['users']['data']
    user_id = users[0]['id']
    admin.write('POST', f'/admin/users/{user_id}/toggle')

    disabled = Session()
    disabled.get('/login')
    response = disabled.write('POST', '/login', {'account': '5276110074', 'password': '5276110074'})
    errors = response.json_props().get('errors', {})
    check('账号禁用原因', '禁用' in message_of(errors, 'account'), errors)

    admin.write('POST', f'/admin/users/{user_id}/toggle')

    logout = Session()
    logout.login('5276110074', '5276110074')
    response = logout.write('POST', '/logout')
    flash = response.json_props().get('flash', {})
    check('退出登录提示', flash.get('success') == '已安全退出登录。', flash)

    passed = sum(1 for _, ok, _ in RESULTS if ok)
    failed = len(RESULTS) - passed
    print(f'\n== 登录反馈测试完成：{passed} 通过，{failed} 失败，共 {len(RESULTS)} 项 ==')
    if failed:
        for name, ok, detail in RESULTS:
            if not ok:
                print(' -', name, detail)
        sys.exit(1)


if __name__ == '__main__':
    main()
