import fs from 'node:fs';
import path from 'node:path';
function visit(dir){for(const entry of fs.readdirSync(dir,{withFileTypes:true})){const file=path.join(dir,entry.name);if(entry.isDirectory()){visit(file);continue;}if(!file.endsWith('.php'))continue;let text=fs.readFileSync(file,'utf8');text=text.replace(/(^[\t ]*)\/\*\* ([^\n]*?) \*\//gm,(_,indent,body)=>{const parts=body.split(/\s+(?=@(?:param|return|var)\b)/);if(!body.includes('@'))return `${indent}/**\n${indent} * ${body}\n${indent} */`;const result=parts.map(p=>`${indent} * ${p}`);return `${indent}/**\n${result.join('\n')}\n${indent} */`;});fs.writeFileSync(file,text);}}
visit('request-inspector');
