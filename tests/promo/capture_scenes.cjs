const fs = require('fs');
const path = require('path');
const { chromium } = require('playwright-core');

const EDGE = 'C:/Program Files (x86)/Microsoft/Edge/Application/msedge.exe';
const ROOT = path.resolve(__dirname, '../../..');
const OUT = path.join(ROOT, 'promo/stills');
const APP = 'http://127.0.0.1:8080';
const sleep = (ms) => new Promise((resolve) => setTimeout(resolve, ms));

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

function anonymizeDashboard() {
    const heading = [...document.querySelectorAll('h2')].find((item) => item.textContent.includes('排行榜前'));
    const section = heading?.closest('section');
    if (!section) return;
    section.querySelectorAll('.divide-y > div').forEach((row, i) => {
        const name = row.querySelector('p.text-sm.font-medium');
        const id = row.querySelector('p.text-xs');
        if (name) name.textContent = `示例学生 ${String(i + 1).padStart(2, '0')}`;
        if (id) id.textContent = `2026${String(i + 1).padStart(4, '0')} · 计算机科学与技术（专升本）2601班`;
    });
}

(async () => {
    fs.mkdirSync(OUT, { recursive: true });
    const browser = await chromium.launch({ executablePath: EDGE, headless: true, args: ['--no-sandbox', '--disable-gpu'] });

    const guestContext = await browser.newContext({ viewport: { width: 1920, height: 1080 } });
    const guest = await guestContext.newPage();
    await guest.goto(`${APP}/login`, { waitUntil: 'networkidle' });
    await sleep(1700);
    await guest.screenshot({ path: path.join(OUT, 'scene-login.png') });
    await guest.goto(`${APP}/register`, { waitUntil: 'networkidle' });
    await sleep(900);
    await guest.screenshot({ path: path.join(OUT, 'scene-register-choice.png') });
    await guest.goto(`${APP}/register/teacher`, { waitUntil: 'networkidle' });
    await sleep(900);
    await guest.screenshot({ path: path.join(OUT, 'scene-teacher-register.png') });
    await guest.goto(`${APP}/register/student`, { waitUntil: 'networkidle' });
    await sleep(900);
    await guest.screenshot({ path: path.join(OUT, 'scene-student-register.png') });
    await guestContext.close();

    const adminContext = await browser.newContext({ viewport: { width: 1920, height: 1080 } });
    const page = await adminContext.newPage();
    await page.goto(`${APP}/login`, { waitUntil: 'networkidle' });
    await page.fill('input[autocomplete="username"]', 'admin');
    await page.fill('input[autocomplete="current-password"]', 'admin123');
    await page.click('button[type="submit"]');
    await page.waitForURL('**/dashboard', { timeout: 30000 });
    await sleep(5200);
    await page.evaluate(anonymizeDashboard);
    await sleep(400);
    await page.screenshot({ path: path.join(OUT, 'scene-dashboard.png') });

    await page.goto(`${APP}/leaderboard?scope=class&college_id=1&major_id=1&class_id=2&per_page=all`, { waitUntil: 'networkidle' });
    await page.waitForFunction(() => document.querySelectorAll('.data-table tbody tr').length >= 50, { timeout: 15000 }).catch(() => {});
    for (let i = 0; i < 8; i++) {
        await page.evaluate(anonymizeLeaderboard);
        await sleep(250);
    }
    await page.screenshot({ path: path.join(OUT, 'scene-leaderboard.png') });

    await page.goto(`${APP}/admin/users?per_page=20`, { waitUntil: 'networkidle' });
    await page.waitForFunction(() => document.querySelectorAll('.data-table tbody tr').length >= 10, { timeout: 15000 }).catch(() => {});
    for (let i = 0; i < 6; i++) {
        await page.evaluate(anonymizeUsers);
        await sleep(250);
    }
    await page.screenshot({ path: path.join(OUT, 'scene-users.png') });

    await page.goto(`${APP}/admin/organization`, { waitUntil: 'networkidle' });
    await sleep(900);
    await page.evaluate(() => {
        const heading = [...document.querySelectorAll('h2')].find((item) => item.textContent.trim() === '信息工程学院');
        const card = heading?.closest('.card');
        if (card) {
            window.scrollTo({ top: card.getBoundingClientRect().top + window.scrollY - 112, behavior: 'instant' });
        }
    });
    await sleep(900);
    await page.screenshot({ path: path.join(OUT, 'scene-organization.png') });

    await adminContext.close();
    await browser.close();
    console.log('scenes captured');
})().catch((error) => {
    console.error(error);
    process.exit(1);
});
