/**
 * The d20 on the dice table.
 *
 * The engine already rolled every one of these numbers, inside a transaction,
 * from a seed derived from the turn id. Nothing here decides anything — this
 * is a replay, and the die is told its face before it starts tumbling. That
 * is the whole contract: the animation must always land on the number it was
 * given, however long the tumble runs.
 *
 * One WebGL renderer serves the whole grid. A canvas per card would burn
 * through the browser's context limit at about sixteen dice; instead a single
 * fixed canvas covers the viewport and each die is drawn into the scissor
 * rectangle of its own card, which also means the grid can scroll for free.
 */
import {
    AmbientLight,
    BufferAttribute,
    CanvasTexture,
    Color,
    DirectionalLight,
    IcosahedronGeometry,
    Mesh,
    MeshStandardMaterial,
    PerspectiveCamera,
    Quaternion,
    Scene,
    SRGBColorSpace,
    Vector3,
    WebGLRenderer,
} from 'three';

export type DieTone = 'player' | 'ally' | 'foe';

const TONES: Record<DieTone, { body: string; ink: string; edge: number }> = {
    player: { body: '#f4ead8', ink: '#221a12', edge: 0x8b6f3f },
    ally: { body: '#dbe7e2', ink: '#16241f', edge: 0x4f7f6b },
    foe: { body: '#2a1a1c', ink: '#f2d7cf', edge: 0x8c3b3b },
};

const CRIT_GLOW = { success: 0xffc65c, failure: 0xff4b3e };

/** Face i of an IcosahedronGeometry, as the three corners it is built from. */
function faceCorners(position: BufferAttribute, face: number): Vector3[] {
    return [0, 1, 2].map((corner) =>
        new Vector3().fromBufferAttribute(position, face * 3 + corner),
    );
}

/**
 * Number the faces the way a real d20 is numbered: opposite faces sum to 21.
 * It costs one pass over twenty normals and it is the difference between a
 * die that reads as a die and a die that reads as a prop.
 */
function numberFaces(normals: Vector3[]): number[] {
    const values = new Array<number>(normals.length).fill(0);
    let next = 1;

    for (let face = 0; face < normals.length; face++) {
        if (values[face] !== 0) continue;

        // Its antipode is the face whose normal points most nearly opposite.
        let opposite = -1;
        let worst = Infinity;
        for (let other = 0; other < normals.length; other++) {
            if (other === face || values[other] !== 0) continue;
            const dot = normals[face].dot(normals[other]);
            if (dot < worst) {
                worst = dot;
                opposite = other;
            }
        }

        values[face] = next;
        if (opposite >= 0) values[opposite] = 21 - next;
        next++;
    }

    return values;
}

/** A 5x4 atlas of numerals, one tile per face. */
function numberAtlas(values: number[], body: string, ink: string): CanvasTexture {
    const tile = 128;
    const cols = 5;
    const rows = 4;
    const canvas = document.createElement('canvas');
    canvas.width = cols * tile;
    canvas.height = rows * tile;
    const ctx = canvas.getContext('2d')!;

    ctx.fillStyle = body;
    ctx.fillRect(0, 0, canvas.width, canvas.height);

    ctx.fillStyle = ink;
    ctx.textAlign = 'center';
    ctx.textBaseline = 'middle';

    values.forEach((value, face) => {
        const x = (face % cols) * tile;
        const y = Math.floor(face / cols) * tile;

        ctx.save();
        ctx.translate(x + tile / 2, y + tile / 2 + tile * 0.04);
        ctx.font = `700 ${value >= 10 ? 46 : 54}px "Georgia", serif`;
        ctx.fillText(String(value), 0, 0);

        // 6 and 9 are the same glyph upside down; the underline is how every
        // physical die has always settled the argument.
        if (value === 6 || value === 9) {
            ctx.fillRect(-16, 26, 32, 4);
        }
        ctx.restore();
    });

    const texture = new CanvasTexture(canvas);
    texture.colorSpace = SRGBColorSpace;
    texture.anisotropy = 4;

    return texture;
}

/** Point each face's three corners at its own tile in the atlas. */
function mapAtlasUvs(geometry: IcosahedronGeometry, faces: number): void {
    const cols = 5;
    const rows = 4;
    const uv = new Float32Array(faces * 3 * 2);

    // An equilateral triangle inset inside its tile, in tile-local space.
    const corners = [
        [0.5, 0.9],
        [0.06, 0.14],
        [0.94, 0.14],
    ];

    for (let face = 0; face < faces; face++) {
        const col = face % cols;
        const row = Math.floor(face / cols);

        corners.forEach(([u, v], corner) => {
            const i = (face * 3 + corner) * 2;
            uv[i] = (col + u) / cols;
            // Canvas rows run downward, texture space runs upward.
            uv[i + 1] = 1 - (row + 1 - v) / rows;
        });
    }

    geometry.setAttribute('uv', new BufferAttribute(uv, 2));
}

/**
 * Where the die must be turned for face `f` to be read.
 *
 * Pointing the face at the camera is only half of it: the minimal rotation
 * that does so leaves the numeral spun by whatever angle that face's corners
 * happen to sit at, which is different for all twenty. The second term spins
 * the die about the view axis until the corner carrying the top of the glyph
 * is genuinely at the top of the screen.
 */
function uprightPoses(
    position: BufferAttribute,
    faces: number,
): { normals: Vector3[]; poses: Quaternion[] } {
    const normals: Vector3[] = [];
    const poses: Quaternion[] = [];
    const view = new Vector3(0, 0, 1);

    for (let face = 0; face < faces; face++) {
        const [a, b, c] = faceCorners(position, face);
        const centroid = a.clone().add(b).add(c).divideScalar(3);
        const normal = centroid.clone().normalize();

        const facing = new Quaternion().setFromUnitVectors(normal, view);
        // Corner `a` is the one the atlas maps to the top of the numeral.
        const apex = a
            .clone()
            .sub(centroid)
            .applyQuaternion(facing);
        const spin = new Quaternion().setFromAxisAngle(
            view,
            Math.PI / 2 - Math.atan2(apex.y, apex.x),
        );

        normals.push(normal);
        poses.push(spin.multiply(facing));
    }

    return { normals, poses };
}

interface Die {
    mesh: Mesh;
    material: MeshStandardMaterial;
    poses: Quaternion[];
    values: number[];
    /** Where the die must come to rest, once it has a number to show. */
    resting: Quaternion | null;
    tumbling: boolean;
    /** Milliseconds since this die was set rolling. */
    elapsed: number;
    spin: Vector3;
    settled: boolean;
    crit: 'success' | 'failure' | null;
    onSettled: (() => void) | null;
    el: HTMLElement;
}

const TUMBLE_MS = 900;
const SETTLE_MS = 620;

function easeOutBack(t: number): number {
    const c = 1.9;

    return 1 + (c + 1) * Math.pow(t - 1, 3) + c * Math.pow(t - 1, 2);
}

export class DiceStage {
    private renderer: WebGLRenderer;

    private scene = new Scene();

    private camera = new PerspectiveCamera(38, 1, 0.1, 100);

    private dice = new Map<string, Die>();

    private frame: number | null = null;

    private last = 0;

    private running = true;

    constructor(private canvas: HTMLCanvasElement) {
        this.renderer = new WebGLRenderer({
            canvas,
            alpha: true,
            antialias: true,
            powerPreference: 'low-power',
        });
        this.renderer.setPixelRatio(Math.min(window.devicePixelRatio, 2));
        this.renderer.setScissorTest(true);

        this.camera.position.set(0, 0, 4.2);

        this.scene.add(new AmbientLight(0xffffff, 1.35));
        const key = new DirectionalLight(0xffffff, 2.1);
        key.position.set(2, 3, 4);
        this.scene.add(key);
        const rim = new DirectionalLight(0x9fb6ff, 0.9);
        rim.position.set(-3, -1, 2);
        this.scene.add(rim);

        this.resize();
        window.addEventListener('resize', this.resize);
        this.loop(0);
    }

    /** Add one die, parked on a face, waiting to be told what it rolled. */
    add(id: string, el: HTMLElement, tone: DieTone, seed: number): void {
        const geometry = new IcosahedronGeometry(1.28, 0);
        const position = geometry.getAttribute('position') as BufferAttribute;
        const faces = position.count / 3;

        const { normals, poses } = uprightPoses(position, faces);
        const values = numberFaces(normals);
        mapAtlasUvs(geometry, faces);

        const palette = TONES[tone];
        const material = new MeshStandardMaterial({
            map: numberAtlas(values, palette.body, palette.ink),
            roughness: 0.45,
            metalness: 0.12,
            emissive: new Color(palette.edge),
            emissiveIntensity: 0.06,
            flatShading: true,
        });

        const mesh = new Mesh(geometry, material);
        mesh.visible = false;
        // A deterministic resting pose, so a die that is never rolled still
        // looks placed rather than dropped.
        mesh.quaternion.setFromAxisAngle(
            new Vector3(0.4, 1, 0.2).normalize(),
            (seed % 360) * (Math.PI / 180),
        );
        this.scene.add(mesh);

        this.dice.set(id, {
            mesh,
            material,
            poses,
            values,
            resting: null,
            tumbling: false,
            elapsed: 0,
            spin: new Vector3(
                2.4 + (seed % 7) * 0.31,
                3.1 + (seed % 5) * 0.44,
                1.7 + (seed % 3) * 0.5,
            ),
            settled: false,
            crit: null,
            onSettled: null,
            el,
        });
    }

    /**
     * Send a die tumbling toward the face the engine already rolled. The
     * value is an input, never an output — this method cannot fail to land
     * on it, and there is nothing here that could produce a different one.
     */
    roll(
        id: string,
        value: number,
        crit: 'success' | 'failure' | null,
        onSettled?: () => void,
    ): void {
        const die = this.dice.get(id);
        if (!die || die.tumbling || die.settled) return;

        const face = die.values.indexOf(value);
        const upright = die.poses[face >= 0 ? face : 0];

        // A shallow tip off dead-on keeps the silhouette a solid rather than
        // a flat triangle. It is about the screen's own horizontal, so the
        // numeral stays upright and centred while the die stops looking flat.
        const tip = new Quaternion().setFromAxisAngle(
            new Vector3(1, 0, 0),
            -0.13,
        );

        die.resting = tip.multiply(upright);
        die.crit = crit;
        die.tumbling = true;
        die.elapsed = 0;
        die.onSettled = onSettled ?? null;
    }

    hasSettled(id: string): boolean {
        return this.dice.get(id)?.settled ?? false;
    }

    dispose(): void {
        this.running = false;
        if (this.frame !== null) cancelAnimationFrame(this.frame);
        window.removeEventListener('resize', this.resize);
        this.dice.forEach((die) => {
            die.mesh.geometry.dispose();
            die.material.map?.dispose();
            die.material.dispose();
        });
        this.dice.clear();
        this.renderer.dispose();
    }

    private resize = (): void => {
        this.renderer.setSize(window.innerWidth, window.innerHeight, false);
    };

    private loop = (now: number): void => {
        if (!this.running) return;
        this.frame = requestAnimationFrame(this.loop);

        const delta = this.last === 0 ? 16 : Math.min(now - this.last, 48);
        this.last = now;

        // Wipe the whole canvas once, unscissored: dice move with the page as
        // it scrolls, and a per-rectangle clear leaves the trail behind them.
        this.renderer.setScissorTest(false);
        this.renderer.setViewport(0, 0, window.innerWidth, window.innerHeight);
        this.renderer.setClearAlpha(0);
        this.renderer.clear();
        this.renderer.setScissorTest(true);
        this.renderer.autoClear = false;

        this.dice.forEach((die) => {
            this.advance(die, delta);
            this.draw(die);
        });
    };

    private advance(die: Die, delta: number): void {
        if (!die.tumbling) return;

        die.elapsed += delta;

        if (die.elapsed < TUMBLE_MS) {
            // Free tumble: fast, loose, and going nowhere in particular.
            const step = delta / 1000;
            die.mesh.rotateX(die.spin.x * step);
            die.mesh.rotateY(die.spin.y * step);
            die.mesh.rotateZ(die.spin.z * step);
            die.mesh.position.y = Math.abs(Math.sin(die.elapsed / 110)) * 0.22;

            return;
        }

        const t = Math.min((die.elapsed - TUMBLE_MS) / SETTLE_MS, 1);
        const eased = easeOutBack(t);

        if (die.resting) {
            // Slerp toward the face it was always going to land on. Snapping
            // exactly at t = 1 guarantees the read matches the record even if
            // floating point drifts on the way in.
            die.mesh.quaternion.slerp(die.resting, Math.min(eased, 1) * 0.35);
            if (t >= 1) die.mesh.quaternion.copy(die.resting);
        }

        die.mesh.position.y = (1 - t) * 0.18 * Math.sin(t * Math.PI * 3);

        if (t >= 1) {
            die.tumbling = false;
            die.settled = true;
            if (die.crit) {
                die.material.emissive.setHex(CRIT_GLOW[die.crit]);
                die.material.emissiveIntensity = 0.85;
            }
            die.onSettled?.();
            die.onSettled = null;
        }
    }

    private draw(die: Die): void {
        const rect = die.el.getBoundingClientRect();
        const bottom = window.innerHeight - rect.bottom;

        // Cards scrolled out of the window cost nothing.
        if (
            rect.bottom < 0 ||
            rect.top > window.innerHeight ||
            rect.width === 0
        ) {
            return;
        }

        this.renderer.setViewport(rect.left, bottom, rect.width, rect.height);
        this.renderer.setScissor(rect.left, bottom, rect.width, rect.height);
        this.camera.aspect = rect.width / rect.height;
        this.camera.updateProjectionMatrix();

        die.mesh.visible = true;
        this.renderer.render(this.scene, this.camera);
        die.mesh.visible = false;
    }
}

/** Whether this browser can draw the table at all. */
export function supportsWebGL(): boolean {
    try {
        const probe = document.createElement('canvas');

        return !!(
            probe.getContext('webgl2') ||
            probe.getContext('webgl') ||
            probe.getContext('experimental-webgl')
        );
    } catch {
        return false;
    }
}
