import { chromium, expect as baseExpect } from '@playwright/test';
import fs from 'node:fs';
const expect = baseExpect.configure({timeout:20000});
const browser = await chromium.launch({channel:'chrome',headless:true});
const context = await browser.newContext();
const page = await context.newPage();
page.setDefaultTimeout(20000);page.setDefaultNavigationTimeout(60000);
const base='http://127.0.0.1:18880';
const errors=[];page.on('pageerror',e=>errors.push(e.message));
await page.goto(base+'/wp-login.php',{waitUntil:'domcontentloaded'});
await page.locator('#user_login').fill('ri_admin');await page.locator('#user_pass').fill('ri-local-test-password');
await Promise.all([page.waitForURL('**/wp-admin/**',{waitUntil:'domcontentloaded'}),page.locator('#wp-submit').click()]);
await page.goto(base+'/wp-admin/admin.php?page=request-inspector',{waitUntil:'domcontentloaded'});
async function api(path,data){return page.evaluate(async({path,data})=>{
 if(window.wp?.apiFetch)return window.wp.apiFetch({path:'/request-inspector/v1/'+path,...(data?{method:'POST',data}:{})});
 const config=JSON.parse(document.getElementById('ri-live-inspector-root').dataset.config);
 const url=config.summaryUrl.slice(0,config.summaryUrl.indexOf('/live/'))+'/'+path;
 const response=await fetch(url,{method:data?'POST':'GET',headers:{'X-WP-Nonce':config.nonce,'Content-Type':'application/json'},...(data?{body:JSON.stringify(data)}:{})});
 if(!response.ok)throw new Error('API failed '+response.status);return response.json();
 },{path,data});}
const original=(await api('settings')).settings, pref=await api('live/preferences');
try{
 await api('settings',{...original,enabled:false,live_enabled:true,live_events:true,live_caps:true,database:true,hooks:true});
 await api('live/preferences',{enabled:true});
 const before=(await api('requests')).total;
 await page.goto(base+'/wp-admin/tools.php?ri_live_fixture=1',{waitUntil:'domcontentloaded'});
 try { await page.locator('#ri-live-inspector-root[data-ready="true"]').waitFor({state:'attached'}); }
 catch(e){console.log(await page.evaluate(()=>({root:!!document.getElementById('ri-live-inspector-root'),menu:!!document.getElementById('wp-admin-bar-request-inspector-live'),scripts:[...document.scripts].filter(s=>s.src.includes('/live.')).map(s=>s.src)})));console.log(errors);throw e;}
 const config=JSON.parse(await page.locator('#ri-live-inspector-root').getAttribute('data-config'));
 await page.evaluate(()=>window.riDocumentMarker='same');
 await page.locator('#wp-admin-bar-request-inspector-live > a').click();
 await expect(page.locator('#ri-live-panel').getByRole('heading',{name:'Overview',exact:true})).toBeVisible();
 await page.locator('#ri-live-tab-logs').click();
 await expect(page.locator('#ri-live-panel')).toContainText('Synthetic live message');
 const logs=await page.evaluate(async c=>(await fetch(c.panelUrl+'logs',{headers:{'X-WP-Nonce':c.nonce,'X-RI-Live-Ticket':c.ticket}})).json(),config);
 if(JSON.stringify(logs).includes('LIVE_SENTINEL'))throw new Error('Live redaction failed');
 for(const panel of ['request','admin','database','timings','scripts','styles','hooks','languages','http','transients','caps','environment','conditionals','php','timeline']){
  await page.locator('#ri-live-tab-'+panel).click();await expect(page.locator('#ri-live-panel')).toBeVisible();
 }
 if(await page.evaluate(()=>window.riDocumentMarker)!=='same')throw new Error('Dock navigation reloaded page');
 const span=page.locator('.rid-span button').filter({hasText:'admin_init'}).first();
 if(await span.count()) { await span.click(); await expect(page.locator('#ri-live-tab-hooks')).toHaveAttribute('aria-selected','true'); await expect(page.locator('.rid-events details[open]').first()).toBeVisible(); }
 if(process.argv.includes('--query-monitor')) {
  await expect(page.locator('#wp-admin-bar-query-monitor')).toHaveCount(1);
  await expect(page.locator('#wp-admin-bar-request-inspector-live')).toHaveCount(1);
  await page.getByRole('button',{name:'Close live inspector'}).click();
  await page.locator('#wp-admin-bar-query-monitor > a').click();
  await expect(page.locator('#query-monitor-main')).toBeVisible();
  await page.locator('#wp-admin-bar-request-inspector-live > a').click();
  await expect(page.locator('#ri-live-panel')).toBeVisible();
 }
 const other=await context.newPage();await other.goto(base+'/wp-admin/tools.php?ri_live_fixture=1',{waitUntil:'domcontentloaded'});
 const otherConfig=JSON.parse(await other.locator('#ri-live-inspector-root').getAttribute('data-config'));
 if(config.uuid===otherConfig.uuid)throw new Error('Two documents share an identity');
 const forbidden=await context.request.get(otherConfig.summaryUrl,{headers:{'X-WP-Nonce':config.nonce,'X-RI-Live-Ticket':config.ticket}});
 if(forbidden.status()!==403)throw new Error('Mismatched request ticket accepted');
 const outsider=await browser.newContext();const anonymous=await outsider.request.get(config.summaryUrl,{headers:{'X-WP-Nonce':config.nonce,'X-RI-Live-Ticket':config.ticket}});
 if(![401,403].includes(anonymous.status()))throw new Error('Anonymous snapshot access accepted');await outsider.close();await other.close();
 fs.mkdirSync('test-results',{recursive:true});await page.screenshot({path:'test-results/live-dock.png',fullPage:true});
 await page.setViewportSize({width:390,height:844});await expect(page.getByRole('dialog',{name:'Request Inspector',exact:true})).toBeVisible();await page.screenshot({path:'test-results/live-mobile.png'});
 await page.getByRole('button',{name:'Close live inspector'}).click();
 if((await api('requests')).total!==before)throw new Error('Live-only mode created historical requests');
 if(errors.length)throw new Error(errors.join('\n'));
 console.log('PASS: integrated toolbar/dock, panels, unchanged document, two-tab identity, denied mismatched/anonymous tickets, redaction, mobile dialog and no history writes.');
}finally{
 try{const current=(await api('settings')).settings;await api('settings',{...original,revision:current.revision});await api('live/preferences',{enabled:pref.enabled});}finally{await browser.close();}
}
