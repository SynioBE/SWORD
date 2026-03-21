<script setup lang="ts">
import { onMounted, onUnmounted, ref } from 'vue';

const emit = defineEmits<{
    done: [];
}>();

const canvasRef = ref<HTMLCanvasElement | null>(null);
const visible = ref(true);
const clashing = ref(false);
const sparkVisible = ref(false);

// ── Confetti ──────────────────────────────────────────────────
interface Particle {
    x: number;
    y: number;
    vx: number;
    vy: number;
    color: string;
    size: number;
    rotation: number;
    rotationSpeed: number;
    shape: 'rect' | 'circle';
    gravity: number;
    opacity: number;
}

const COLORS = [
    '#f59e0b',
    '#ef4444',
    '#3b82f6',
    '#10b981',
    '#8b5cf6',
    '#ec4899',
    '#f97316',
    '#06b6d4',
    '#84cc16',
    '#a855f7',
];

let animFrame: number;
let particles: Particle[] = [];
let startTime: number;
const DURATION = 5000;

function createParticles(canvas: HTMLCanvasElement) {
    const centerX = canvas.width / 2;
    const centerY = canvas.height / 2;

    for (let i = 0; i < 220; i++) {
        const angle = Math.random() * Math.PI * 2;
        const speed = 4 + Math.random() * 10;
        particles.push({
            x: centerX + (Math.random() - 0.5) * 80,
            y: centerY + (Math.random() - 0.5) * 40,
            vx: Math.cos(angle) * speed,
            vy: Math.sin(angle) * speed - Math.random() * 4,
            color: COLORS[Math.floor(Math.random() * COLORS.length)],
            size: 6 + Math.random() * 8,
            rotation: Math.random() * Math.PI * 2,
            rotationSpeed: (Math.random() - 0.5) * 0.2,
            shape: Math.random() > 0.5 ? 'rect' : 'circle',
            gravity: 0.18 + Math.random() * 0.12,
            opacity: 1,
        });
    }
}

function drawParticle(ctx: CanvasRenderingContext2D, p: Particle) {
    ctx.save();
    ctx.globalAlpha = p.opacity;
    ctx.fillStyle = p.color;
    ctx.translate(p.x, p.y);
    ctx.rotate(p.rotation);

    if (p.shape === 'rect') {
        ctx.fillRect(-p.size / 2, -p.size / 4, p.size, p.size / 2);
    } else {
        ctx.beginPath();
        ctx.arc(0, 0, p.size / 2.5, 0, Math.PI * 2);
        ctx.fill();
    }

    ctx.restore();
}

function animate(timestamp: number) {
    const canvas = canvasRef.value;
    if (!canvas) {
        return;
    }

    const ctx = canvas.getContext('2d');
    if (!ctx) {
        return;
    }

    if (!startTime) {
        startTime = timestamp;
    }

    const elapsed = timestamp - startTime;
    const progress = Math.min(elapsed / DURATION, 1);

    ctx.clearRect(0, 0, canvas.width, canvas.height);

    for (const p of particles) {
        p.x += p.vx;
        p.y += p.vy;
        p.vy += p.gravity;
        p.vx *= 0.99;
        p.rotation += p.rotationSpeed;

        // Fade out in the last 1.5s
        if (progress > 0.6) {
            p.opacity = Math.max(0, 1 - (progress - 0.6) / 0.4);
        }

        if (p.opacity > 0) {
            drawParticle(ctx, p);
        }
    }

    if (progress < 1) {
        animFrame = requestAnimationFrame(animate);
    } else {
        visible.value = false;
        emit('done');
    }
}

function resizeCanvas() {
    const canvas = canvasRef.value;
    if (!canvas) {
        return;
    }

    canvas.width = window.innerWidth;
    canvas.height = window.innerHeight;
}

onMounted(() => {
    resizeCanvas();
    window.addEventListener('resize', resizeCanvas);

    const canvas = canvasRef.value;
    if (canvas) {
        // Start clash animation first, then fire confetti after 400ms
        setTimeout(() => {
            clashing.value = true;
        }, 50);

        setTimeout(() => {
            sparkVisible.value = true;
        }, 380);

        setTimeout(() => {
            createParticles(canvas);
            animFrame = requestAnimationFrame(animate);
        }, 500);
    }
});

onUnmounted(() => {
    window.removeEventListener('resize', resizeCanvas);
    cancelAnimationFrame(animFrame);
});
</script>

<template>
    <Transition name="celebration-fade">
        <div
            v-if="visible"
            class="fixed inset-0 z-50 flex items-center justify-center"
            aria-live="assertive"
        >
            <!-- Backdrop -->
            <div class="absolute inset-0 bg-black/70 backdrop-blur-sm" />

            <!-- Confetti canvas -->
            <canvas
                ref="canvasRef"
                class="pointer-events-none absolute inset-0"
            />

            <!-- Central celebration card -->
            <div
                class="relative z-10 flex flex-col items-center gap-6 rounded-2xl border border-white/10 bg-white/10 px-12 py-10 text-center shadow-2xl backdrop-blur-md dark:bg-black/30"
            >
                <!-- Blade clash scene -->
                <div class="clash-scene" :class="{ clashing }">
                    <!-- Left blade -->
                    <div class="blade blade-left">
                        <div class="blade-guard blade-guard-left" />
                        <div class="blade-handle blade-handle-left" />
                        <div class="blade-edge blade-edge-left" />
                    </div>

                    <!-- Spark / impact flash -->
                    <Transition name="spark-pop">
                        <div v-if="sparkVisible" class="spark-burst">
                            <div
                                v-for="i in 8"
                                :key="i"
                                class="spark-ray"
                                :style="`--i: ${i}`"
                            />
                        </div>
                    </Transition>

                    <!-- Right blade -->
                    <div class="blade blade-right">
                        <div class="blade-guard blade-guard-right" />
                        <div class="blade-handle blade-handle-right" />
                        <div class="blade-edge blade-edge-right" />
                    </div>
                </div>

                <!-- Text -->
                <div class="flex flex-col items-center gap-1">
                    <p
                        class="text-3xl font-bold tracking-tight text-white drop-shadow-lg"
                    >
                        Site Deployed!
                    </p>
                    <p class="text-sm text-white/70">
                        Your site is live and ready.
                    </p>
                </div>
            </div>
        </div>
    </Transition>
</template>

<style scoped>
/* ── Fade transition ───────────────────────────────────────── */
.celebration-fade-enter-active {
    transition: opacity 0.3s ease;
}
.celebration-fade-leave-active {
    transition: opacity 0.6s ease;
}
.celebration-fade-enter-from,
.celebration-fade-leave-to {
    opacity: 0;
}

/* ── Clash scene container ────────────────────────────────── */
.clash-scene {
    position: relative;
    width: 340px;
    height: 120px;
    display: flex;
    align-items: center;
    justify-content: center;
}

/* ── Individual blade ─────────────────────────────────────── */
.blade {
    position: absolute;
    top: 50%;
    margin-top: 20px;
    width: 120px;
    height: 20px;
    display: flex;
    align-items: center;
}

/*
 * Sword layout (left blade, reading left→right):
 *   [handle] [guard] [——— blade tip →] pointing RIGHT toward center
 *
 * Sword layout (right blade, reading left→right):
 *   [← blade tip ———] [guard] [handle] pointing LEFT toward center
 *
 * transform-origin is at the handle end so the whole sword pivots there.
 */

/* ── Left blade: handle on left, tip points right toward center ── */
.blade-left {
    right: 50%;
    transform-origin: left center;
    transform: translateX(-100px) rotate(-35deg);
    transition: transform 0.35s cubic-bezier(0.22, 1, 0.36, 1);
}
.clashing .blade-left {
    transform: translateX(50px) rotate(-35deg);
}

/* handle — leftmost (far from center) */
.blade-handle-left {
    position: absolute;
    left: 0;
    width: 28px;
    height: 13px;
    background: linear-gradient(to bottom, #92400e, #d97706, #92400e);
    border-radius: 4px;
    box-shadow: 0 0 4px rgba(0, 0, 0, 0.5);
}

/* guard — just right of handle */
.blade-guard-left {
    position: absolute;
    left: 26px;
    width: 10px;
    height: 36px;
    background: linear-gradient(to bottom, #78716c, #d6d3d1, #78716c);
    border-radius: 4px;
    box-shadow: 0 0 6px rgba(0, 0, 0, 0.4);
}

/* blade edge — tip points right, toward center */
.blade-edge-left {
    position: absolute;
    right: 0;
    width: 88px;
    height: 8px;
    background: linear-gradient(to right, #64748b, #e2e8f0, #f8fafc);
    border-radius: 2px 24px 24px 2px;
    box-shadow:
        0 0 10px rgba(255, 255, 255, 0.6),
        0 0 24px rgba(148, 163, 184, 0.4);
}

/* ── Right blade: handle on right, tip points left toward center ── */
.blade-right {
    left: 50%;
    transform-origin: right center;
    transform: translateX(100px) rotate(35deg);
    transition: transform 0.35s cubic-bezier(0.22, 1, 0.36, 1);
}
.clashing .blade-right {
    transform: translateX(-50px) rotate(35deg);
}

/* blade edge — tip points left, toward center */
.blade-edge-right {
    position: absolute;
    left: 0;
    width: 88px;
    height: 8px;
    background: linear-gradient(to left, #64748b, #e2e8f0, #f8fafc);
    border-radius: 24px 2px 2px 24px;
    box-shadow:
        0 0 10px rgba(255, 255, 255, 0.6),
        0 0 24px rgba(148, 163, 184, 0.4);
}

/* guard — just left of handle */
.blade-guard-right {
    position: absolute;
    right: 26px;
    width: 10px;
    height: 36px;
    background: linear-gradient(to bottom, #78716c, #d6d3d1, #78716c);
    border-radius: 4px;
    box-shadow: 0 0 6px rgba(0, 0, 0, 0.4);
}

/* handle — rightmost (far from center) */
.blade-handle-right {
    position: absolute;
    right: 0;
    width: 28px;
    height: 13px;
    background: linear-gradient(to bottom, #92400e, #d97706, #92400e);
    border-radius: 4px;
    box-shadow: 0 0 4px rgba(0, 0, 0, 0.5);
}

/* ── Spark burst at impact ────────────────────────────────── */
.spark-burst {
    position: absolute;
    top: 50%;
    left: 50%;
    width: 0;
    height: 0;
    pointer-events: none;
    margin-top: -30px;
}

.spark-ray {
    position: absolute;
    top: 0;
    left: 0;
    width: 4px;
    height: 36px;
    border-radius: 2px;
    background: linear-gradient(to top, transparent, #fbbf24, #fff);
    /* rotate around the center point, then push outward along the ray axis */
    transform-origin: center bottom;
    transform: rotate(calc(var(--i) * 45deg)) translateY(-18px);
    animation: spark-fly 0.5s ease-out forwards;
    box-shadow: 0 0 8px #fbbf24;
    /* offset so the ray's bottom (origin) sits at the burst center */
    margin-left: -2px;
    margin-top: -36px;
}

@keyframes spark-fly {
    0% {
        opacity: 1;
        height: 36px;
        transform: rotate(calc(var(--i) * 45deg)) translateY(-18px);
    }
    60% {
        opacity: 0.9;
        height: 48px;
        transform: rotate(calc(var(--i) * 45deg)) translateY(-32px);
    }
    100% {
        opacity: 0;
        height: 16px;
        transform: rotate(calc(var(--i) * 45deg)) translateY(-48px);
    }
}

/* ── After clash: blades vibrate ─────────────────────────── */
.clashing .blade-left {
    animation: vibrate-left 0.15s ease-in-out 0.35s 3;
}
.clashing .blade-right {
    animation: vibrate-right 0.15s ease-in-out 0.35s 3;
}

@keyframes vibrate-left {
    0%,
    100% {
        transform: translateX(50px) rotate(-35deg);
    }
    50% {
        transform: translateX(48px) rotate(-37deg);
    }
}

@keyframes vibrate-right {
    0%,
    100% {
        transform: translateX(-50px) rotate(35deg);
    }
    50% {
        transform: translateX(-48px) rotate(37deg);
    }
}

/* ── Spark pop transition ─────────────────────────────────── */
.spark-pop-enter-active {
    transition:
        transform 0.15s ease-out,
        opacity 0.15s ease-out;
}
.spark-pop-leave-active {
    transition: opacity 0.3s ease-in;
}
.spark-pop-enter-from {
    transform: scale(0);
    opacity: 0;
}
.spark-pop-leave-to {
    opacity: 0;
}
</style>
