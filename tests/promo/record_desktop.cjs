const fs = require('fs');
const path = require('path');
const { chromium } = require('playwright-core');

const EDGE = 'C:/Program Files (x86)/Microsoft/Edge/Application/msedge.exe';
const ROOT = path.resolve(__dirname, '../../..');
const RAW = path.join(ROOT, 'promo/raw');
const APP = 'http://127.0.0.1:8080';
const sleep = (ms) => new Promise((resolve) => setTimeout(resolve, ms));

function overlayInit() {
    const setup = () => {
        if (document.getElementById('promo-ui')) return;

        const style = document.createElement('style');
        style.id = 'promo-ui-style';
        style.textContent = `
            #promo-ui { position: fixed; inset: 0; pointer-events: none; z-index: 2147483000; font-family: "Microsoft YaHei","PingFang SC",sans-serif; }
            #promo-caption {
                position: fixed; left: 50%; bottom: 46px; transform: translateX(-50%) translateY(10px);
                min-width: 560px; max-width: 900px; padding: 14px 24px 15px 26px;
                border-radius: 18px; background: rgba(8,16,31,.82); border: 1px solid rgba(255,255,255,.14);
                box-shadow: 0 18px 48px rgba(0,0,0,.36); backdrop-filter: blur(20px) saturate(140%);
                color: #f8fafc; opacity: 0; transition: opacity .35s ease, transform .5s cubic-bezier(.16,1,.3,1);
                display: flex; flex-direction: column; gap: 3px;
            }
            #promo-caption.show { opacity: 1; transform: translateX(-50%) translateY(0); }
            #promo-caption::before {
                content: ""; position: absolute; left: 0; top: 16px; bottom: 16px; width: 3px;
                border-radius: 0 3px 3px 0; background: linear-gradient(180deg,#79bbff,#18a058);
                box-shadow: 0 0 18px rgba(64,158,255,.55);
            }
            #promo-kicker { font-size: 11px; font-weight: 700; letter-spacing: .22em; color: #79bbff; }
            #promo-title { font-size: 27px; line-height: 1.12; font-weight: 700; letter-spacing: -.02em; }
            #promo-sub { font-size: 14px; line-height: 1.4; color: rgba(226,232,240,.64); }
            #promo-cursor {
                position: fixed; left: 0; top: 0; width: 18px; height: 18px; margin: -9px 0 0 -9px;
                border-radius: 50%; border: 2px solid #79bbff; background: rgba(64,158,255,.16);
                box-shadow: 0 0 0 6px rgba(64,158,255,.12), 0 4px 16px rgba(0,0,0,.25);
                opacity: 0; transition: opacity .2s ease; z-index: 2147483001;
            }
            .promo-ripple {
                position: fixed; width: 18px; height: 18px; margin: -9px 0 0 -9px; border-radius: 50%;
                border: 2px solid rgba(121,187,255,.9); z-index: 2147483000; pointer-events: none;
                animation: promoRipple .55s ease-out forwards;
            }
            @keyframes promoRipple {
                from { width: 18px; height: 18px; margin: -9px 0 0 -9px; opacity: .9; }
                to { width: 82px; height: 82px; margin: -41px 0 0 -41px; opacity: 0; }
            }
        `;
        document.head.appendChild(style);

        const wrap = document.createElement('div');
        wrap.id = 'promo-ui';
        wrap.innerHTML = `
            <div id="promo-caption">
                <span id="promo-kicker"></span>
                <strong id="promo-title"></strong>
                <span id="promo-sub"></span>
            </div>
            <div id="promo-cursor"></div>
        `;
        document.body.appendChild(wrap);

        const cursor = document.getElementById('promo-cursor');
        window.addEventListener('mousemove', (event) => {
            cursor.style.left = `${event.clientX}px`;
            cursor.style.top = `${event.clientY}px`;
            cursor.style.opacity = '1';
        }, { passive: true });
        window.addEventListener('mousedown', (event) => {
            const ripple = document.createElement('div');
            ripple.className = 'promo-ripple';
            ripple.style.left = `${event.clientX}px`;
            ripple.style.top = `${event.clientY}px`;
            document.body.appendChild(ripple);
            setTimeout(() => ripple.remove(), 650);
        }, { passive: true });

        window.setPromoCaption = (kicker, title, sub) => {
            const cap = document.getElementById('promo-caption');
            if (!cap) return;
            cap.classList.remove('show');
            setTimeout(() => {
                document.getElementById('promo-kicker').textContent = kicker || '';
                document.getElementById('promo-title').textContent = title || '';
                document.getElementById('promo-sub').textContent = sub || '';
                requestAnimationFrame(() => cap.classList.add('show'));
            }, 180);
        };
    };

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', setup);
    } else {
        setup();
    }
}

async function caption(page, kicker, title, sub) {
    await page.evaluate(({ kicker, title, sub }) => window.setPromoCaption?.(kicker, title, sub), { kicker, title, sub });
    await sleep(500);
}

async function moveTo(page, locator) {
    const box = await locator.boundingBox();
    if (!box) return;
    await page.mouse.move(box.x + box.width / 2, box.y + box.height / 2, { steps: 22 });
    await sleep(220);
}

async function selectText(page, index, text) {
    await page.evaluate(({ index, text }) => {
        const select = [...document.querySelectorAll('select')][index];
        if (!select) return;
        const option = [...select.options].find((item) => (item.textContent || '').includes(text));
        if (!option) return;
        select.value = option.value;
        select.dispatchEvent(new Event('change', { bubbles: true }));
    }, { index, text });
    await sleep(1000);
}

async function pageSizeAll(page) {
    await page.evaluate(() => {
        const select = [...document.querySelectorAll('select')].find((item) =>
            [...item.options].some((option) => (option.textContent || '').trim() === '全部'),
        );
        if (!select) return;
        select.value = 'all';
        select.dispatchEvent(new Event('change', { bubbles: true }));
    });
    await page.waitForFunction(() => location.search.includes('per_page=all'), { timeout: 10000 }).catch(() => {});
    await sleep(1300);
}

function anonymizeLeaderboard() {
    document.querySelectorAll('.data-table tbody tr').forEach((row, i) => {
        const cells = row.querySelectorAll('td');
        if (cells.length < 2) return;
        const nameEl = cells[1].querySelector('a, .font-medium, span');
        const idEl = cells[1].querySelector('p');
        if (nameEl) nameEl.textContent = `示例学生 ${String(i + 1).padStart(2, '0')}`;
        if (idEl) idEl.textContent = `2026${String(i + 1).padStart(4, '0')}`;
    });
    document.querySelectorAll('div.grid > div').forEach((card, i) => {
        const ps = card.querySelectorAll('p');
        if (ps.length >= 2 && /^\d{10,}/.test((ps[1].textContent || '').trim())) {
            ps[0].textContent = `示例学生 ${String(i + 1).padStart(2, '0')}`;
            ps[1].textContent = `2026${String(i + 1).padStart(4, '0')} · 计算机科学与技术（专升本）2601班`;
        }
    });
}

async function anonymizeLeaderboardFully(page) {
    await page.waitForFunction(() => document.querySelectorAll('.data-table tbody tr').length >= 50, { timeout: 15000 }).catch(() => {});
    for (let attempt = 0; attempt < 10; attempt++) {
        await page.evaluate(anonymizeLeaderboard);
        await sleep(280);
        const clean = await page.evaluate(() => {
            const table = document.querySelector('.data-table');
            const cards = [...document.querySelectorAll('div.grid > div')].map((item) => item.innerText).join(' ');
            const text = (table?.innerText || '') + ' ' + cards;
            return text.includes('示例学生') && !/2608\d{8}/.test(text);
        });
        if (clean) return;
    }
}

function anonymizeUsers() {
    document.querySelectorAll('.data-table tbody tr').forEach((row, i) => {
        const cells = row.querySelectorAll('td');
        if (cells.length < 3) return;
        const name = cells[0].querySelector('p.font-medium');
        const username = cells[0].querySelector('p.text-xs');
        if (name) name.textContent = `示例用户 ${String(i + 1).padStart(2, '0')}`;
        if (username) username.textContent = `demo${String(i + 1).padStart(3, '0')}`;
        cells[2].textContent = `2026${String(i + 1).padStart(4, '0')}`;
    });
}

async function anonymizeUsersFully(page) {
    await page.waitForFunction(() => document.querySelectorAll('.data-table tbody tr').length >= 10, { timeout: 15000 }).catch(() => {});
    for (let attempt = 0; attempt < 8; attempt++) {
        await page.evaluate(anonymizeUsers);
        await sleep(280);
        const clean = await page.evaluate(() => {
            const table = document.querySelector('.data-table');
            const text = table?.innerText || '';
            return text.includes('示例用户') && !/2608\d{8}/.test(text);
        });
        if (clean) return;
    }
}

async function saveVideo(context, page, filename) {
    const video = page.video();
    await context.close();
    const src = await video.path();
    const dest = path.join(RAW, filename);
    fs.renameSync(src, dest);
    console.log('saved', dest);
}

(async () => {
    fs.mkdirSync(RAW, { recursive: true });
    const browser = await chromium.launch({
        executablePath: EDGE,
        headless: true,
        args: ['--no-sandbox', '--disable-gpu', '--autoplay-policy=no-user-gesture-required'],
    });

    // Admin flow
    const adminContext = await browser.newContext({
        viewport: { width: 1920, height: 1080 },
        recordVideo: { dir: RAW, size: { width: 1920, height: 1080 } },
    });
    await adminContext.addInitScript(overlayInit);
    const page = await adminContext.newPage();
    await page.goto(`${APP}/login`, { waitUntil: 'networkidle' });
    await sleep(2200);
    await caption(page, '01 / 登录', '安全登录', '学号 / 工号 + 密码，角色权限自动识别');

    await moveTo(page, page.locator('input[autocomplete="username"]'));
    await page.locator('input[autocomplete="username"]').click();
    await page.keyboard.type('admin', { delay: 90 });
    await moveTo(page, page.locator('input[autocomplete="current-password"]'));
    await page.locator('input[autocomplete="current-password"]').click();
    await page.keyboard.type('admin123', { delay: 80 });
    await moveTo(page, page.locator('button[type="submit"]'));
    await page.locator('button[type="submit"]').click();
    await page.waitForURL('**/dashboard', { timeout: 30000 });
    await sleep(2300);

    await caption(page, '02 / 工作台', '数据总览', '学生、待审核、申诉、加分一屏掌握');
    const statCard = page.locator('.card').filter({ hasText: '在校学生' }).first();
    await moveTo(page, statCard);
    await sleep(1200);
    await page.mouse.wheel(0, 420);
    await sleep(1100);
    await page.mouse.wheel(0, -420);
    await sleep(800);

    await page.locator('a[href="/leaderboard"]').first().click();
    await page.waitForURL('**/leaderboard**', { timeout: 20000 });
    await sleep(1500);
    await caption(page, '03 / 排行榜', '多维排行', '全校 · 学院 · 年级 · 班级 / 周 · 月 · 学期 · 学年');
    await page.getByRole('button', { name: '班级榜' }).click();
    await sleep(900);
    await selectText(page, 1, '信息工程学院');
    await selectText(page, 2, '计算机科学与技术');
    await selectText(page, 3, '计算机科学与技术（专升本）2601班');
    await pageSizeAll(page);
    await anonymizeLeaderboardFully(page);
    await sleep(900);
    await page.mouse.wheel(0, 520);
    await sleep(1200);
    await page.mouse.wheel(0, -520);
    await sleep(600);

    await page.locator('a[href="/admin/users"]').first().click();
    await page.waitForURL('**/admin/users**', { timeout: 20000 });
    await sleep(1300);
    await caption(page, '04 / 用户管理', '角色与范围', '学生、教师、学院管理员、辅导员分级管理');
    await anonymizeUsersFully(page);
    await sleep(700);
    await page.mouse.wheel(0, 460);
    await sleep(1200);
    await page.mouse.wheel(0, -460);
    await sleep(600);

    await page.locator('a[href="/admin/organization"]').first().click();
    await page.waitForURL('**/admin/organization**', { timeout: 20000 });
    await sleep(1400);
    await caption(page, '05 / 组织管理', '分级授权', '学院 → 专业 → 年级 → 班级，辅导员统一配置');
    await page.evaluate(() => {
        const heading = [...document.querySelectorAll('h2')].find((item) => item.textContent.trim() === '信息工程学院');
        heading?.scrollIntoView({ behavior: 'smooth', block: 'start' });
    });
    await sleep(2200);
    await page.mouse.wheel(0, 360);
    await sleep(1200);

    await saveVideo(adminContext, page, 'admin.webm');

    // Guest registration flow
    const guestContext = await browser.newContext({
        viewport: { width: 1920, height: 1080 },
        recordVideo: { dir: RAW, size: { width: 1920, height: 1080 } },
    });
    await guestContext.addInitScript(overlayInit);
    const guest = await guestContext.newPage();
    await guest.goto(`${APP}/login`, { waitUntil: 'networkidle' });
    await sleep(1800);
    await caption(guest, '06 / 自主注册', '身份选择', '学生注册 · 教师注册，两条独立通道');
    await guest.locator('a[href="/register"]').click();
    await guest.waitForURL('**/register', { timeout: 15000 });
    await sleep(1800);
    await caption(guest, '学生注册', '学号 + 班级', '学院 / 专业 / 年级 / 班级逐级选择，无需邀请码');
    await guest.locator('a[href="/register/teacher"]').click();
    await guest.waitForURL('**/register/teacher', { timeout: 15000 });
    await sleep(1700);
    await caption(guest, '教师注册', '邀请码 + 审核', '只选择学院和专业，管理员核对后启用');
    await moveTo(guest, guest.locator('input[placeholder="请输入教师邀请码"]'));
    await sleep(1200);
    await guest.goto(`${APP}/register`, { waitUntil: 'networkidle' });
    await sleep(900);
    await guest.locator('a[href="/register/student"]').click();
    await guest.waitForURL('**/register/student', { timeout: 15000 });
    await sleep(1700);
    await caption(guest, '学生注册', '信息逐级校验', '班级与学院 / 专业 / 年级不匹配时自动拦截');
    await moveTo(guest, guest.locator('select').nth(0));
    await sleep(1200);
    await saveVideo(guestContext, guest, 'guest.webm');

    await browser.close();
})().catch((error) => {
    console.error(error);
    process.exit(1);
});
