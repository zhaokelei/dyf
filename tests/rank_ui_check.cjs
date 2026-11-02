const fs = require('fs');
const puppeteer = require('puppeteer-core');

const BASE = process.env.BASE_URL || 'http://127.0.0.1:8080';
const EDGE_CANDIDATES = [
    process.env.EDGE_PATH,
    'C:\\Program Files (x86)\\Microsoft\\Edge\\Application\\msedge.exe',
    'C:\\Program Files\\Microsoft\\Edge\\Application\\msedge.exe',
    'C:\\Program Files\\Google\\Chrome\\Application\\chrome.exe',
];
const EDGE = EDGE_CANDIDATES.find((candidate) => candidate && fs.existsSync(candidate));
const sleep = (ms) => new Promise((resolve) => setTimeout(resolve, ms));

(async () => {
    const browser = await puppeteer.launch({
        executablePath: EDGE,
        headless: true,
        args: ['--no-sandbox', '--disable-gpu'],
    });
    const page = await browser.newPage();
    await page.setViewport({ width: 1440, height: 900 });
    const errors = [];

    await page.goto(`${BASE}/login`, { waitUntil: 'networkidle0' });
    await page.type('input[autocomplete="username"]', 'admin', { delay: 5 });
    await page.type('input[autocomplete="current-password"]', 'admin123', { delay: 5 });
    await page.click('button[type="submit"]');
    await page.waitForFunction(() => location.pathname === '/dashboard', { timeout: 20000 });
    await page.goto(`${BASE}/leaderboard`, { waitUntil: 'networkidle0' });

    async function waitSearch(needle) {
        const ok = await page
            .waitForFunction((value) => location.search.includes(value), { timeout: 10000 }, needle)
            .then(() => true)
            .catch(() => false);
        if (!ok) errors.push(`leaderboard query failed: ${needle} (${await page.evaluate(() => location.search)})`);
        await sleep(350);
        return ok;
    }

    async function clickText(text) {
        const clicked = await page.evaluate((value) => {
            const button = [...document.querySelectorAll('button')].find((item) => (item.textContent || '').trim() === value);
            if (!button) return false;
            button.click();
            return true;
        }, text);
        if (!clicked) errors.push(`leaderboard button not found: ${text}`);
        await sleep(400);
        return clicked;
    }

    async function pickOption(marker) {
        const ready = await page
            .waitForFunction((text) => {
                const select = [...document.querySelectorAll('select')].find((item) =>
                    [...item.options].some((option) => (option.textContent || '').includes(text)),
                );
                return Boolean(select && !select.disabled && [...select.options].some((item) => item.value));
            }, { timeout: 10000 }, marker)
            .then(() => true)
            .catch(() => false);

        if (!ready) {
            errors.push(`leaderboard select not ready: ${marker}`);
            return null;
        }

        const chosen = await page.evaluate((text) => {
            const select = [...document.querySelectorAll('select')].find((item) =>
                [...item.options].some((option) => (option.textContent || '').includes(text)),
            );
            const option = [...select.options].find((item) => item.value);
            select.value = option.value;
            select.dispatchEvent(new Event('change', { bubbles: true }));
            return option.textContent.trim();
        }, marker);
        await sleep(600);
        return chosen;
    }

    async function rowsContain(text) {
        return page.evaluate((value) => {
            const rows = [...document.querySelectorAll('.data-table tbody tr')].slice(0, 5);
            return rows.length > 0 && rows.every((row) => row.textContent.includes(value));
        }, text);
    }

    await clickText('学院榜');
    await waitSearch('scope=college');
    const college = await pickOption('全部学院');
    await waitSearch('college_id=');
    if (college && !(await rowsContain(college))) errors.push(`college scope rows mismatch: ${college}`);
    console.log('college scope:', college, await page.evaluate(() => location.search));

    await clickText('重置');
    await sleep(500);
    await clickText('年级榜');
    await waitSearch('scope=grade');
    const grade = await pickOption('全部年级');
    await waitSearch('grade=');
    if (grade && !(await rowsContain(grade))) errors.push(`grade scope rows mismatch: ${grade}`);
    console.log('grade scope:', grade, await page.evaluate(() => location.search));

    await clickText('重置');
    await sleep(500);
    await clickText('班级榜');
    await waitSearch('scope=class');
    const classLocked = await page.evaluate(() => {
        const select = [...document.querySelectorAll('select')].find((item) =>
            [...item.options].some((option) => (option.textContent || '').includes('请先选择专业')),
        );
        return select ? select.disabled : false;
    });
    if (!classLocked) errors.push('class select should require a major first');
    const majorOption = await pickOption('全部专业');
    await waitSearch('major_id=');
    const classOption = await pickOption('全部班级');
    await waitSearch('class_id=');
    const className = classOption ? classOption.split('（')[0] : null;
    if (majorOption && !(await page.evaluate(() => location.search.includes('major_id=')))) {
        errors.push('class scope major filter missing');
    }
    if (className && !(await rowsContain(className))) errors.push(`class scope rows mismatch: ${className}`);
    console.log('class scope:', majorOption, classOption, await page.evaluate(() => location.search));

    await clickText('本周');
    await waitSearch('rank_period=week');
    console.log('week rank:', await page.evaluate(() => location.search));

    await clickText('重置');
    await waitSearch('scope=all');
    await page.evaluate(() => {
        const input = [...document.querySelectorAll('input')].find((item) => item.placeholder === '姓名或学号');
        if (input) {
            input.value = '260806120101';
            input.dispatchEvent(new Event('input', { bubbles: true }));
        }
    });
    await clickText('筛选');
    await waitSearch('search=260806120101');
    if (!(await rowsContain('260806120101'))) errors.push('search result mismatch: 260806120101');
    console.log('search:', await page.evaluate(() => location.search));

    console.log(`RANK UI errors: ${errors.length}`);
    errors.forEach((error) => console.log(' -', error));
    await browser.close();
    process.exit(errors.length ? 1 : 0);
})().catch((error) => {
    console.error(error);
    process.exit(1);
});
