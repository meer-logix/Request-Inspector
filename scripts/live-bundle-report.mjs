import fs from 'node:fs';
import { gzipSync } from 'node:zlib';
const directory = 'request-inspector/admin/build';
const files = fs.readdirSync(directory).filter(file => /^(live\.(js|css)|ri-live-dock.*\.(js|css))$/.test(file));
const report = Object.fromEntries(files.map(file => {
  const data = fs.readFileSync(`${directory}/${file}`);
  return [file, { bytes: data.length, gzip_bytes: gzipSync(data).length }];
}));
fs.writeFileSync('.runtime/live-bundles.json', JSON.stringify(report, null, 2) + '\n');
console.log(JSON.stringify(report, null, 2));
