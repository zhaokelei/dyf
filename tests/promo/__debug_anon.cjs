const { chromium } = require('playwright-core');
const APP='http://127.0.0.1:8080';
(async()=>{
 const b=await chromium.launch({executablePath:'C:/Program Files (x86)/Microsoft/Edge/Application/msedge.exe',headless:true,args:['--no-sandbox']});
 const p=await b.newPage({viewport:{width:1920,height:1080}});
 await p.goto(APP+'/login',{waitUntil:'networkidle'});
 await p.fill('input[autocomplete="username"]','admin');
 await p.fill('input[autocomplete="current-password"]','admin123');
 await p.click('button[type="submit"]');
 await p.waitForURL('**/dashboard');
 await p.goto(APP+'/leaderboard?scope=class&college_id=1&major_id=1&class_id=2&per_page=all',{waitUntil:'networkidle'});
 await p.waitForTimeout(1500);
 console.log('before rows', await p.locator('.data-table tbody tr').count());
 const info = await p.evaluate(()=>{
  const row=document.querySelector('.data-table tbody tr'); if(!row) return null;
  const cells=[...row.children]; return {cellCount:cells.length, cell1:cells[1]?.innerHTML, cell2:cells[2]?.innerHTML,
   grids:[...document.querySelectorAll('div.grid > div')].slice(0,4).map(d=>d.innerText.slice(0,80))};
 });
 console.log(JSON.stringify(info,null,2));
 await p.evaluate(()=>{
  document.querySelectorAll('.data-table tbody tr').forEach((row,i)=>{
   const cells=row.querySelectorAll('td'); if(cells.length<2) return;
   const cell=cells[1]; const nameEl=cell.querySelector('a, .font-medium, span'); const idEl=cell.querySelector('p');
   if(nameEl) nameEl.textContent=`示例学生 ${String(i+1).padStart(2,'0')}`;
   if(idEl) idEl.textContent=`2026${String(i+1).padStart(4,'0')}`;
  });
  document.querySelectorAll('div.grid > div').forEach((card,i)=>{
   const ps=card.querySelectorAll('p');
   if(ps.length>=2 && /^\d{10,}/.test((ps[1].textContent||'').trim())) {
    ps[0].textContent=`示例学生 ${String(i+1).padStart(2,'0')}`;
    ps[1].textContent=`2026${String(i+1).padStart(4,'0')} · 计算机科学与技术（专升本）2601班`;
   }
  });
 });
 await p.waitForTimeout(300);
 console.log('after', (await p.locator('.data-table tbody tr').first().innerText()).slice(0,100));
 console.log('top', (await p.locator('.card').filter({hasText:'示例学生'}).first().innerText()).slice(0,100));
 await b.close();
})().catch(e=>{console.error(e);process.exit(1)});
