const fs = require('fs');
const path = require('path');
const { chromium } = require('playwright-core');

const EDGE = 'C:/Program Files (x86)/Microsoft/Edge/Application/msedge.exe';
const ROOT = path.resolve(__dirname, '../../..');
const OUT = path.join(ROOT, 'promo/stills');
const APP = 'http://127.0.0.1:8080';
const sleep = (ms) => new Promise((resolve) => setTimeout(resolve, ms));

function anonymize() {
    document.querySelectorAll('.data-table tbody tr').forEach((row, i) => {
        const cells = row.querySelectorAll('td');
        if (cells.length < 2) return;
        const cell = cells[1];
        const nameEl = cell.querySelector('a, .font-medium, span');
        const idEl = cell.querySelector('p');
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

(async () => {
    const browser = await chromium.launch({
        executablePath: EDGE,
        headless: true,
        args: ['--no-sandbox', '--disable-gpu'],
    });

    // Intro / outro cards
    const still = await browser.newPage({ viewport: { width: 1920, height: 1080 } });
    for (const name of ['intro', 'outro']) {
        await still.goto(`file:///${path.join(ROOT, 'promo', `${name}.html`).replace(/\\/g, '/')}`, { waitUntil: 'networkidle' });
        await still.waitForTimeout(600);
        await still.screenshot({ path: path.join(OUT, `${name}.png`) });
        console.log('card', name);
    }

    // Mobile screenshots
    const context = await browser.newContext({
        viewport: { width: 390, height: 844 },
        deviceScaleFactor: 2,
        isMobile: true,
        hasTouch: true,
    });
    const page = await context.newPage();
    await page.goto(`${APP}/login`, { waitUntil: 'networkidle' });
    await page.fill('input[autocomplete="username"]', 'admin');
    await page.fill('input[autocomplete="current-password"]', 'admin123');
    await page.click('button[type="submit"]');
    await page.waitForURL('**/dashboard', { timeout: 30000 });
    await sleep(5200);
    await page.screenshot({ path: path.join(OUT, 'mobile-dashboard.png') });

    await page.goto(`${APP}/leaderboard?scope=class&college_id=1&major_id=1&class_id=2&per_page=10`, { waitUntil: 'networkidle' });
    await sleep(900);
    await page.evaluate(anonymize);
    await sleep(500);
    await page.screenshot({ path: path.join(OUT, 'mobile-leaderboard.png') });
    console.log('mobile captured');
    await context.close();

    await still.goto(`file:///${path.join(ROOT, 'promo', 'mobile.html').replace(/\\/g, '/')}`, { waitUntil: 'networkidle' });
    await still.waitForTimeout(800);
    await still.screenshot({ path: path.join(OUT, 'mobile-frame.png') });
    console.log('mobile frame captured');

    await browser.close();
})().catch((error) => {
    console.error(error);
    process.exit(1);
});
