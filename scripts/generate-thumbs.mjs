// Generates static SVG pictogram thumbnails for each exercise into public/thumbs/.
// Run: node scripts/generate-thumbs.mjs
import { mkdirSync, writeFileSync, readFileSync } from 'node:fs';
import { dirname, join } from 'node:path';
import { fileURLToPath } from 'node:url';

const root = join(dirname(fileURLToPath(import.meta.url)), '..');
const catalog = JSON.parse(readFileSync(join(root, 'database/seeders/data/hep-exercises.json'), 'utf8'));

const REGION_COLORS = {
    core: { stroke: '#b45309', bg: '#fdf3e3' },
    hip: { stroke: '#6d28d9', bg: '#f1ecfd' },
    knee: { stroke: '#0f766e', bg: '#e6f4f2' },
    'ankle-foot': { stroke: '#be185d', bg: '#fceef4' },
};

// Pose archetypes: head [cx, cy] + limb polylines on a 96x96 tile, ground at y≈80.
const POSES = {
    supine: {
        head: [16, 68],
        lines: [
            [22, 69, 50, 71],
            [50, 71, 60, 56],
            [60, 56, 68, 74],
            [30, 70, 42, 62],
        ],
    },
    bridge: {
        head: [15, 70],
        lines: [
            [21, 68, 46, 50],
            [46, 50, 58, 56],
            [58, 56, 60, 76],
            [24, 72, 34, 76],
        ],
    },
    quadrupedLift: {
        head: [26, 40],
        lines: [
            [31, 45, 56, 47],
            [34, 47, 34, 70],
            [56, 47, 58, 70],
            [56, 46, 78, 38],
        ],
    },
    quadrupedSide: {
        head: [26, 40],
        lines: [
            [31, 45, 56, 47],
            [34, 47, 34, 70],
            [56, 48, 72, 56],
            [72, 56, 76, 70],
        ],
    },
    halfKneel: {
        head: [42, 24],
        lines: [
            [42, 30, 42, 52],
            [42, 52, 58, 52],
            [58, 52, 58, 74],
            [42, 52, 30, 68],
            [30, 68, 18, 68],
        ],
    },
    squat: {
        head: [46, 26],
        lines: [
            [46, 32, 42, 50],
            [42, 50, 58, 54],
            [58, 54, 54, 76],
            [46, 38, 66, 34],
        ],
    },
    singleLegStand: {
        head: [46, 18],
        lines: [
            [46, 24, 46, 50],
            [46, 50, 46, 76],
            [46, 50, 64, 60],
            [46, 32, 60, 40],
        ],
    },
    hinge: {
        head: [26, 32],
        lines: [
            [31, 35, 52, 44],
            [52, 44, 52, 76],
            [52, 44, 74, 34],
            [31, 38, 40, 54],
        ],
    },
    calfRaise: {
        head: [46, 16],
        lines: [
            [46, 22, 46, 50],
            [46, 50, 46, 68],
            [46, 68, 54, 62],
            [46, 32, 58, 32],
        ],
    },
    bandWalk: {
        head: [46, 20],
        lines: [
            [46, 26, 46, 48],
            [46, 48, 34, 74],
            [46, 48, 60, 74],
            [46, 34, 34, 42],
        ],
        extra: '<ellipse cx="47" cy="70" rx="15" ry="4" fill="none" stroke-dasharray="3 3"/>',
    },
    plank: {
        head: [18, 50],
        lines: [
            [24, 53, 64, 58],
            [28, 54, 28, 72],
            [64, 58, 74, 72],
        ],
    },
    sidePlank: {
        head: [22, 38],
        lines: [
            [27, 43, 62, 62],
            [30, 46, 26, 70],
            [62, 62, 74, 68],
            [40, 48, 48, 38],
        ],
    },
    foot: {
        head: null,
        lines: [
            [26, 70, 58, 70],
            [26, 70, 24, 56],
            [24, 56, 38, 58],
            [58, 70, 68, 60],
        ],
    },
};

const POSE_BY_SLUG = {
    'ta-activation': 'supine',
    'exercise-w': 'halfKneel',
    'exercise-v': 'squat',
    'heel-raise-eccentric': 'calfRaise',
    'exercise-y': 'bandWalk',
    'quadruped-leg-lift': 'quadrupedLift',
    'exercise-x': 'singleLegStand',
    'toe-yoga': 'foot',
    'plank-ball': 'plank',
    'standing-hip-abduction': 'singleLegStand',
    'eccentric-single-leg-squat': 'squat',
    'exercise-u': 'singleLegStand',
    'exercise-t': 'supine',
    'bridge-lift': 'bridge',
    'squat-with-ball': 'squat',
    'bridge-walk': 'bridge',
    'exercise-r': 'quadrupedSide',
    'side-planks': 'sidePlank',
    'exercise-z': 'supine',
    'exercise-s': 'halfKneel',
    'exercise-o': 'hinge',
    'exercise-p': 'singleLegStand',
};

function svg(slug, region) {
    const { stroke, bg } = REGION_COLORS[region];
    const pose = POSES[POSE_BY_SLUG[slug]];
    const lines = pose.lines
        .map(([x1, y1, x2, y2]) => `<line x1="${x1}" y1="${y1}" x2="${x2}" y2="${y2}"/>`)
        .join('\n    ');
    const head = pose.head ? `<circle cx="${pose.head[0]}" cy="${pose.head[1]}" r="5.5" fill="${stroke}" stroke="none"/>` : '';
    return `<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 96 96" width="96" height="96">
  <rect width="96" height="96" rx="18" fill="${bg}"/>
  <line x1="12" y1="80" x2="84" y2="80" stroke="${stroke}" stroke-opacity="0.25" stroke-width="3" stroke-linecap="round"/>
  <g stroke="${stroke}" stroke-width="5" stroke-linecap="round" stroke-linejoin="round" fill="none">
    ${lines}
    ${pose.extra ?? ''}
  </g>
  ${head}
</svg>
`;
}

const outDir = join(root, 'public/thumbs');
mkdirSync(outDir, { recursive: true });
for (const entry of catalog) {
    writeFileSync(join(outDir, `${entry.slug}.svg`), svg(entry.slug, entry.region));
    console.log(`thumbs/${entry.slug}.svg`);
}
