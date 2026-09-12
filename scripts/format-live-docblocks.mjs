import fs from 'node:fs';
for (const name of ['live-collectors','live-inspector','live-store','recorder','plugin']) {
  const file = `request-inspector/includes/class-${name}.php`;
  let source = fs.readFileSync(file, 'utf8');
  source = source.replace(/^(\s*)\/\*\* (@(?:var|param|return)[^\r\n]*?) \*\//gm, (_, indent, body) => {
    const tags = body.split(/\s+(?=@(?:return|param|var)\b)/);
    const description = tags[0].replace(/^@(?:var|return)\s+\S+\s*|^@param\s+\S+\s+\$\w+\s*/, '').trim() || 'Internal feature contract.';
    return `${indent}/**\n${indent} * ${description}\n${indent} *\n${tags.map(tag => `${indent} * ${tag}`).join('\n')}\n${indent} */`;
  });
  fs.writeFileSync(file, source);
}
