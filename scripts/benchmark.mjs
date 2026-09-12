import {execFile} from 'node:child_process';
import {promisify} from 'node:util';
import fs from 'node:fs';
const exec=promisify(execFile);
const rows=[];
for(const mode of ['off','http','bodies','database','errors','hooks']){
 for(let n=0;n<22;n++){
  const r=await exec('rtk',['proxy','php','-c','.runtime/php.ini','tests/benchmark-worker.php',mode],{windowsHide:true});
  if(n>=2)rows.push(JSON.parse(r.stdout));
 }
 console.log(`Measured ${mode}: 20 samples after 2 warmups.`);
}
fs.mkdirSync('test-results',{recursive:true});fs.mkdirSync('docs',{recursive:true});
fs.writeFileSync('test-results/benchmark.json',JSON.stringify(rows,null,2));
const quantile=(items,key,p)=>[...items].map(x=>x[key]).sort((a,b)=>a-b)[Math.ceil(items.length*p)-1];
let text='# Synthetic benchmark\n\nWordPress 7.1 / PHP 8.2.27 / MariaDB 10.6.23 on this Windows workstation. Each fresh process loads WordPress before measurement, then performs 30 filter calls, ten real SELECT queries and one short-circuited HTTP fixture. Collection and storage finalization are included; WordPress bootstrap is excluded. SAVEQUERIES is externally enabled for every mode. Twenty measured samples per mode follow two warmups. Empirical p99 with twenty samples is the maximum, not a stable production estimate.\n\n| Mode | p50 ms | p95 ms | p99 ms | p95 extra memory KiB | Estimated KiB / 1,000 workloads |\n|---|---:|---:|---:|---:|---:|\n';
for(const mode of ['off','http','bodies','database','errors','hooks']){const items=rows.filter(r=>r.mode===mode);text+=`| ${mode} | ${quantile(items,'elapsed_ms',.5).toFixed(3)} | ${quantile(items,'elapsed_ms',.95).toFixed(3)} | ${quantile(items,'elapsed_ms',.99).toFixed(3)} | ${(quantile(items,'peak_extra_bytes',.95)/1024).toFixed(1)} | ${(items.reduce((n,r)=>n+r.stored_bytes,0)/items.length*1000/1024).toFixed(1)} |\n`;}
text+='\nThese are reproducible local fixture results, not a guarantee of website overhead. HTTP transport latency, real workloads, database engines, persistent caches and profiler combinations require their own measurements. Raw samples include additional query counts and stored byte estimates.\n';
fs.writeFileSync('docs/BENCHMARK.md',text);
