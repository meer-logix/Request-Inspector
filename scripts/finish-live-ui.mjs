import fs from 'node:fs';
const file = 'request-inspector/admin/src/live/dock.tsx';
let source = fs.readFileSync(file, 'utf8');
source = source.replace('className="rid-waterfall"', 'className="rid-waterfall" style={{width: zoom * 100 + "%"}}');
source = source.replace("|| 'overview')} title={label(row)}", "|| 'overview', row.live_id)} title={label(row)}");
source = source.replace('<details key={`${panel.id}-${i}`}>', '<details id={row.live_id ? "ri-live-row-" + row.live_id : undefined} key={`${panel.id}-${i}`}>');
fs.writeFileSync(file, source);
