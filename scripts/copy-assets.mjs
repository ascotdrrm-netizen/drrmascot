// Copies the third-party files the app serves from its own host, so no page
// loads anything from a CDN (works offline, on localhost, and in the PWA).
// Runs before `npm run dev` and `npm run build` (see package.json).
//
// 1. public/vendor/  Chart.js and Leaflet, loaded by plain <script> tags so the
//    inline page scripts that call `new Chart(...)` and `L.map(...)` keep
//    running exactly as they did with the CDN copies.
// 2. resources/icons/material/  Only the Google Material Symbols (Rounded) the
//    views actually use: every <x-icon name="..."> in resources/views, plus the
//    names listed in resources/icons/extra.json for icons chosen in PHP.
//    <x-icon> inlines these SVGs at render time (resources/views/components/icon.blade.php).
import { cpSync, existsSync, mkdirSync, readFileSync, readdirSync, rmSync, statSync, writeFileSync } from 'node:fs';
import { dirname, join, relative } from 'node:path';
import { fileURLToPath } from 'node:url';

const root = join(dirname(fileURLToPath(import.meta.url)), '..');
const modules = join(root, 'node_modules');

// ---- 1. Vendor scripts and styles -------------------------------------------
const vendor = [
    ['chart.js/dist/chart.umd.js', 'public/vendor/chart.js/chart.umd.js'],
    ['leaflet/dist/leaflet.js', 'public/vendor/leaflet/leaflet.js'],
    ['leaflet/dist/leaflet.css', 'public/vendor/leaflet/leaflet.css'],
    ['leaflet/dist/images', 'public/vendor/leaflet/images'],
];

for (const [from, to] of vendor) {
    const src = join(modules, from);
    if (!existsSync(src)) throw new Error(`Missing ${from}: run npm install first.`);
    mkdirSync(dirname(join(root, to)), { recursive: true });
    cpSync(src, join(root, to), { recursive: true });
}

// ---- 2. Material Symbols subset ---------------------------------------------
const iconSource = join(modules, '@material-symbols/svg-400/rounded');
const iconTarget = join(root, 'resources/icons/material');

function bladeFiles(dir) {
    return readdirSync(dir).flatMap((name) => {
        const path = join(dir, name);
        return statSync(path).isDirectory() ? bladeFiles(path) : path.endsWith('.blade.php') ? [path] : [];
    });
}

const names = new Set();
for (const file of bladeFiles(join(root, 'resources/views'))) {
    for (const match of readFileSync(file, 'utf8').matchAll(/<x-icon\b[^>]*?\bname="([a-z0-9_]+)"/g)) {
        names.add(match[1]);
    }
}
const extra = join(root, 'resources/icons/extra.json');
if (existsSync(extra)) JSON.parse(readFileSync(extra, 'utf8')).forEach((name) => names.add(name));

rmSync(iconTarget, { recursive: true, force: true });
mkdirSync(iconTarget, { recursive: true });

const missing = [];
for (const name of [...names].sort()) {
    for (const variant of [name, `${name}-fill`]) {
        const src = join(iconSource, `${variant}.svg`);
        if (existsSync(src)) {
            // Drop the fixed 48px size; <x-icon> sets it with classes.
            const svg = readFileSync(src, 'utf8').replace(/ width="48" height="48"/, '');
            writeFileSync(join(iconTarget, `${variant}.svg`), svg);
        } else if (variant === name) {
            missing.push(name);
        }
    }
}

if (missing.length) {
    throw new Error(`Unknown Material Symbols: ${missing.join(', ')} (see fonts.google.com/icons)`);
}
console.log(`copy-assets: vendor files + ${names.size} icons -> ${relative(root, iconTarget)}`);
