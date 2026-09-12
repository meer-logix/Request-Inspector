import fs from 'node:fs';
const root='request-inspector/admin/src/';
let profile=fs.readFileSync(root+'profiling.tsx','utf8');
const legacy=profile.indexOf('export function Timeline(');
if(legacy!==-1)profile=profile.slice(0,legacy);
const helper=`export function revealEvent() {
 const id=new URLSearchParams(window.location.search).get('ri_event');
 if(!id||! /^[0-9]+$/.test(id))return;
 const element=document.getElementById('ri-event-'+id);
 if(element instanceof HTMLDetailsElement){element.open=true;element.scrollIntoView({block:'nearest'});}
}
`;
if(!profile.includes('export function revealEvent'))profile+='\n'+helper;
profile=profile.replace('key={event.id}', 'id={"ri-event-"+event.id} key={event.id}');
let start=profile.indexOf('export function HookInspector(');
let section=profile.slice(start);
section=section.replace('  return (\n    <section>', '  useEffect(()=>{requestAnimationFrame(revealEvent);},[data]);\n  return (\n    <section>');
profile=profile.slice(0,start)+section;
fs.writeFileSync(root+'profiling.tsx',profile);
let index=fs.readFileSync(root+'index.tsx','utf8');
index=index.replace('import { HookInspector, HookPage, ExportControls }', 'import { HookInspector, HookPage, ExportControls, revealEvent }');
start=index.indexOf('function Events(');const end=index.indexOf('function Detail(',start);
section=index.slice(start,end).replace('key={event.id}', 'id={"ri-event-"+event.id} key={event.id}');
section=section.replace('  return (\n    <section>', '  useEffect(()=>{requestAnimationFrame(revealEvent);},[data]);\n  return (\n    <section>');
index=index.slice(0,start)+section+index.slice(end);
fs.writeFileSync(root+'index.tsx',index);
