const fs = require('fs');
const path = require('path');
const puppeteer = require('puppeteer-core');

const BASE = process.env.BASE_URL || 'http://127.0.0.1:8080';
const EDGE_CANDIDATES = [
    process.env.EDGE_PATH,
    'C:\\Program Files (x86)\\Microsoft\\Edge\\Application\\msedge.exe',
    'C:\\Program Files\\Microsoft\\Edge\\Application\\msedge.exe',
    'C:\\Program Files\\Google\\Chrome\\Application\\chrome.exe',
    'C:\\Program Files (x86)\\Google\\Chrome\\Application\\chrome.exe',
];
const EDGE = EDGE_CANDIDATES.find((candidate) => candidate && fs.existsSync(candidate));

if (!EDGE) {
    console.error('未找到 Edge/Chrome，可通过环境变量 EDGE_PATH 指定浏览器路径。');
    process.exit(1);
}
const OUT = path.join(__dirname, 'screenshots');

fs.mkdirSync(OUT, { recursive: true });

const sleep = (ms) => new Promise((resolve) => setTimeout(resolve, ms));

(async () => {
    const browser = await puppeteer.launch({
        executablePath: EDGE,
        headless: true,
        args: ['--no-sandbox', '--disable-gpu', '--font-render-hinting=none'],
    });

    const errors = [];

    async function createPage(width, height, isMobile) {
        const context = await browser.createBrowserContext();
        const page = await context.newPage();
        await page.setViewport({ width, height, deviceScaleFactor: 1, isMobile, hasTouch: isMobile });
        page.on('pageerror', (error) => errors.push(`pageerror: ${error.message}`));
        page.on('console', (message) => {
            if (message.type() === 'error') errors.push(`console: ${message.text()}`);
        });
        return page;
    }

    async function login(page, account, password) {
        await page.goto(`${BASE}/login`, { waitUntil: 'networkidle0' });
        await page.type('input[autocomplete="username"]', account, { delay: 5 });
        await page.type('input[autocomplete="current-password"]', password, { delay: 5 });
        await page.click('button[type="submit"]');
        await page.waitForFunction(() => location.pathname === '/dashboard', { timeout: 20000 });
        await page.waitForSelector('h1');
        await sleep(700);
    }

    async function capture(page, name, label) {
        await page.screenshot({ path: path.join(OUT, `${name}.png`), fullPage: true });
        const metrics = await page.evaluate(() => {
            const bottom = document.querySelector('nav.fixed.inset-x-0.bottom-0');
            const sidebar = document.querySelector('aside.fixed.inset-y-0.left-0');
            return {
                path: location.pathname,
                title: document.title,
                scrollWidth: document.documentElement.scrollWidth,
                clientWidth: document.documentElement.clientWidth,
                h1: document.querySelector('h1')?.textContent?.trim() ?? null,
                bottomNav: bottom ? getComputedStyle(bottom).display !== 'none' : false,
                sidebar: sidebar ? getComputedStyle(sidebar).display !== 'none' : false,
            };
        });
        if (metrics.scrollWidth - metrics.clientWidth > 1) {
            errors.push(`horizontal overflow ${label}: ${metrics.scrollWidth} > ${metrics.clientWidth}`);
        }
        console.log(label, JSON.stringify(metrics));
    }

    async function clickText(page, selector, text) {
        const clicked = await page.evaluate((sel, value) => {
            const target = [...document.querySelectorAll(sel)].find((element) =>
                (element.textContent || '').trim().includes(value),
            );
            if (!target) return false;
            target.click();
            return true;
        }, selector, text);
        if (!clicked) errors.push(`click target not found: ${text}`);
        await sleep(450);
        return clicked;
    }

    const desktop = await createPage(1440, 900, false);
    await desktop.goto(`${BASE}/login`, { waitUntil: 'networkidle0' });
    await capture(desktop, 'desktop-login', 'desktop-login');
    await desktop.goto(`${BASE}/register/student`, { waitUntil: 'networkidle0' });
    await capture(desktop, 'desktop-register', 'desktop-register');
    await login(desktop, 'admin', 'admin123');

    await clickText(desktop, 'aside a', '排行榜');
    const navigated = await desktop
        .waitForFunction(() => location.pathname === '/leaderboard', { timeout: 10000 })
        .then(() => true)
        .catch(() => false);
    if (!navigated) errors.push('inertia navigation failed: 排行榜');
    console.log('inertia navigation:', await desktop.evaluate(() => location.pathname));

    await desktop.goto(`${BASE}/admin/users`, { waitUntil: 'networkidle0' });
    await clickText(desktop, 'button', '新增用户');
    const modalVisible = await desktop.evaluate(() =>
        [...document.querySelectorAll('h3')].some(
            (heading) => heading.textContent.includes('新增用户') && heading.closest('.fixed.inset-0'),
        ),
    );
    if (!modalVisible) errors.push('user modal did not open');
    await desktop.keyboard.press('Escape');
    await sleep(350);
    const modalClosed = await desktop.evaluate(
        () => ![...document.querySelectorAll('h3')].some((heading) => heading.textContent.includes('新增用户')),
    );
    if (!modalClosed) errors.push('user modal did not close on Escape');
    console.log('modal open/close:', modalVisible, modalClosed);

    await desktop.evaluate(() => {
        [...document.querySelectorAll('button')].find((button) => button.title === '主题色')?.click();
    });
    await sleep(300);
    await desktop.evaluate(() => {
        [...document.querySelectorAll('button')].find((button) => button.title === '极光绿')?.click();
    });
    await sleep(300);
    const themeColor = await desktop.evaluate(() =>
        getComputedStyle(document.documentElement).getPropertyValue('--color-brand-500').trim(),
    );
    if (themeColor !== '#18a058') errors.push(`theme switch failed: ${themeColor}`);
    console.log('theme switch:', themeColor);
    await desktop.evaluate(() => {
        [...document.querySelectorAll('button')].find((button) => button.title === '主题色')?.click();
    });
    await sleep(200);
    await desktop.evaluate(() => {
        [...document.querySelectorAll('button')].find((button) => button.title === '拂晓蓝')?.click();
    });
    await sleep(200);

    for (const [url, name] of [
        ['/dashboard', 'desktop-dashboard'],
        ['/leaderboard', 'desktop-leaderboard'],
        ['/admin/review', 'desktop-review'],
        ['/admin/users', 'desktop-users'],
        ['/admin/records', 'desktop-records'],
        ['/admin/statistics', 'desktop-statistics'],
        ['/admin/appeals', 'desktop-appeals'],
        ['/admin/semesters', 'desktop-semesters'],
        ['/admin/settings', 'desktop-settings'],
        ['/announcements', 'desktop-announcements'],
    ]) {
        await desktop.goto(BASE + url, { waitUntil: 'networkidle0' });
        await sleep(500);
        await capture(desktop, name, name);
    }

    const mobileAdmin = await createPage(390, 844, true);
    await mobileAdmin.goto(`${BASE}/login`, { waitUntil: 'networkidle0' });
    await capture(mobileAdmin, 'mobile-login', 'mobile-login');
    await login(mobileAdmin, 'admin', 'admin123');

    for (const [url, name] of [
        ['/dashboard', 'mobile-dashboard'],
        ['/admin/review', 'mobile-review'],
        ['/admin/users', 'mobile-users'],
        ['/leaderboard', 'mobile-leaderboard'],
        ['/admin/records', 'mobile-records'],
        ['/admin/appeals', 'mobile-appeals'],
        ['/admin/semesters', 'mobile-semesters'],
    ]) {
        await mobileAdmin.goto(BASE + url, { waitUntil: 'networkidle0' });
        await sleep(500);
        await capture(mobileAdmin, name, name);
    }

    await clickText(mobileAdmin, 'nav button', '更多');
    const drawerVisible = await mobileAdmin.evaluate(() =>
        [...document.querySelectorAll('aside')].some((element) => {
            const rect = element.getBoundingClientRect();
            return rect.width > 200 && rect.height > 200 && getComputedStyle(element).display !== 'none';
        }),
    );
    if (!drawerVisible) errors.push('mobile drawer did not open');
    console.log('mobile drawer:', drawerVisible);
    await mobileAdmin.keyboard.press('Escape');

    const mobileStudent = await createPage(390, 844, true);
    await login(mobileStudent, '5276110074', '5276110074');
    for (const [url, name] of [
        ['/my-scores', 'mobile-my-scores'],
        ['/applications', 'mobile-applications'],
        ['/applications/create', 'mobile-application-create'],
        ['/appeals', 'mobile-student-appeals'],
    ]) {
        await mobileStudent.goto(BASE + url, { waitUntil: 'networkidle0' });
        await sleep(500);
        await capture(mobileStudent, name, name);
    }

    const collegeAdmin = await createPage(1440, 900, false);
    await login(collegeAdmin, 'college_admin', 'College@123');
    for (const [url, name] of [
        ['/dashboard', 'desktop-college-dashboard'],
        ['/admin/organization', 'desktop-college-organization'],
        ['/admin/users', 'desktop-college-users'],
        ['/admin/review', 'desktop-college-review'],
        ['/leaderboard', 'desktop-college-leaderboard'],
    ]) {
        await collegeAdmin.goto(BASE + url, { waitUntil: 'networkidle0' });
        await sleep(500);
        await capture(collegeAdmin, name, name);
    }

    const classAdmin = await createPage(390, 844, true);
    await login(classAdmin, 'class_admin', 'Class@123');
    for (const [url, name] of [
        ['/dashboard', 'mobile-class-dashboard'],
        ['/admin/users', 'mobile-class-users'],
        ['/admin/review', 'mobile-class-review'],
        ['/leaderboard', 'mobile-class-leaderboard'],
    ]) {
        await classAdmin.goto(BASE + url, { waitUntil: 'networkidle0' });
        await sleep(500);
        await capture(classAdmin, name, name);
    }

    const counselor = await createPage(1440, 900, false);
    await login(counselor, 'counselor', 'Counselor@123');
    for (const [url, name] of [
        ['/dashboard', 'desktop-counselor-dashboard'],
        ['/admin/users', 'desktop-counselor-users'],
        ['/admin/review', 'desktop-counselor-review'],
        ['/leaderboard', 'desktop-counselor-leaderboard'],
    ]) {
        await counselor.goto(BASE + url, { waitUntil: 'networkidle0' });
        await sleep(500);
        await capture(counselor, name, name);
    }

    const mobileRegister = await createPage(390, 844, true);
    await mobileRegister.goto(`${BASE}/register/student`, { waitUntil: 'networkidle0' });
    await capture(mobileRegister, 'mobile-register', 'mobile-register');

    console.log(`UI errors: ${errors.length}`);
    errors.slice(0, 30).forEach((error) => console.log(' -', error));

    await browser.close();
    process.exit(errors.length ? 1 : 0);
})().catch((error) => {
    console.error(error);
    process.exit(1);
});
