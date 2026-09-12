import {chromium,firefox,webkit,expect} from '@playwright/test';
import fs from 'node:fs';
const target=process.env.RI_BROWSER||'chrome';
const browser=await (target==='firefox'?firefox:target==='webkit'?webkit:chromium).launch({...(target==='firefox'||target==='webkit'?{}:{channel:target}),headless:true});
const page=await browser.newPage({viewport:{width:1280,height:900},acceptDownloads:true});
page.setDefaultNavigationTimeout(60000);
const errors=[];page.on('pageerror',e=>errors.push(e.message));
const base=process.env.RI_BASE_URL||'http://127.0.0.1:18880';
await page.goto(base+'/wp-login.php');
await page.locator('#user_login').fill('ri_admin');await page.locator('#user_pass').fill('ri-local-test-password');
await Promise.all([page.waitForURL('**/wp-admin/**',{waitUntil:'domcontentloaded'}),page.locator('#wp-submit').click()]);
await page.goto(base+'/wp-admin/admin.php?page=request-inspector');
await page.locator('#request-inspector-app h1').waitFor();
await page.evaluate(()=>{window.riNavigationMarker=true;});
await page.locator('#adminmenu a[href="admin.php?page=request-inspector-database"]').click();
await expect(page.getByRole('heading',{name:'Database Inspector',exact:true})).toBeVisible();
if(!await page.evaluate(()=>window.riNavigationMarker))throw new Error('Plugin sidebar reloaded the document');
await page.locator('.ri-nav').getByRole('button',{name:'Request Explorer',exact:true}).click();
async function api(path,data,method=data?'POST':'GET'){return page.evaluate(async({path,data,method})=>window.wp.apiFetch({path:'/request-inspector/v1/'+path,data,method}),{path,data,method});}
const original=(await api('settings')).settings;
fs.mkdirSync('test-results',{recursive:true});
try {
 await api('settings',{...original,enabled:true,mode:'all',hooks:true,database:true,request_body:true,response_body:true});
 await page.evaluate(()=>window.wp.apiFetch({path:'/request-inspector-test/v1/ping'}));
 const records=await api('requests');if(!records.total)throw new Error('Real REST capture missing');
 const id=records.items[0].id;
 await page.goto(base+`/wp-admin/admin.php?page=request-inspector&ri_id=${id}`);
 await page.getByRole('tab',{name:'Database',exact:true}).click();
 await expect(page.getByText('Database queries',{exact:true})).toBeVisible();
 await page.getByRole('tab',{name:'Hooks',exact:true}).click();
 await expect(page.getByRole('heading',{name:'Hooks Inspector'})).toBeVisible();
 await page.screenshot({path:'test-results/hooks.png',fullPage:true});
 await page.getByRole('tab',{name:'Timeline',exact:true}).click();
 await expect(page.getByRole('heading',{name:'Request timeline'})).toBeVisible();
 await page.screenshot({path:'test-results/timeline.png',fullPage:true});
 await page.getByRole('tab',{name:'Response',exact:true}).click();
 await expect(page.getByRole('button',{name:'Copy',exact:true})).toBeVisible();
 const download=page.waitForEvent('download');await page.getByRole('button',{name:'Export',exact:true}).click();
 const artifact=await download;await artifact.saveAs('test-results/browser-export.json');JSON.parse(fs.readFileSync('test-results/browser-export.json','utf8'));
 await page.goto(base+'/wp-admin/admin.php?page=request-inspector&ri_view=advanced');
 await expect(page.getByRole('heading',{name:'Advanced controls'})).toBeVisible();
 await page.getByRole('button',{name:'Run selected action'}).click();
 await expect(page.locator('.ri-content pre')).toContainText('[REDACTED]');
 await page.screenshot({path:'test-results/advanced.png',fullPage:true});
 await page.goto(base+'/wp-admin/admin.php?page=request-inspector&ri_view=workflows');
 await page.getByLabel('New scope name').fill('Browser smoke scope');
 await page.getByRole('button',{name:'Start saved scope'}).click();
 await expect(page.getByRole('heading',{name:'Browser smoke scope'})).toBeVisible();
 await page.screenshot({path:'test-results/workflows.png',fullPage:true});
 await page.setViewportSize({width:390,height:844});
 await page.screenshot({path:'test-results/mobile.png',fullPage:true});
 if(errors.length)throw new Error(errors.join('\n'));
 console.log('PASS: live capture, database/hooks/timeline/body tabs, JSON download, advanced redaction preview, saved scope and narrow layout.');
}finally{
 try {const latest=(await api('settings')).settings;await api('settings',{...original,revision:latest.revision});}finally{await browser.close();}
}
