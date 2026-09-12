import {execFile} from 'node:child_process';
import {promisify} from 'node:util';
import fs from 'node:fs';
const exec=promisify(execFile);
async function worker(action){const r=await exec('rtk',['proxy','php','-c','.runtime/php.ini','tests/worker.php',action],{windowsHide:true});return r.stdout.trim();}
await worker('prepare');
try {
 const claims=(await Promise.all(Array.from({length:8},()=>worker('claim')))).map(Number);
 if(claims.reduce((a,b)=>a+b,0)!==7)throw new Error(`Claim overrun: ${claims}`);
 const writes=(await Promise.all(Array.from({length:8},()=>worker('write')))).map(Number);
 const state=JSON.parse(await worker('state'));
 if(state.rows!==9||Number(state.control.records)!==9||state.roots!==3)throw new Error(JSON.stringify(state));
 fs.mkdirSync('test-results',{recursive:true});
 fs.writeFileSync('test-results/concurrency.json',JSON.stringify({workers:8,claims,writes,state},null,2));
 console.log('PASS: 8 workers / 64 claims reserve exactly 7 slots; concurrent three-row traces retain exactly 9 rows under a 10-row cap.');
}finally{await worker('reset');}
