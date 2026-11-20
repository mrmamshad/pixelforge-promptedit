<script setup>
import { ref, computed, watch, onMounted, onUnmounted } from 'vue';
import { useForm, router, Link } from '@inertiajs/vue3';
import axios from 'axios';

const props = defineProps({
    generations: { type: Array, default: () => [] },
    pricing: { type: Object, default: () => ({}) },
    user: { type: Object, default: () => ({}) },
    markupPercent: { type: Number, default: 25 },
});

const form = useForm({
    prompt: 'A cyberpunk street food vendor at night, neon reflections in puddles [succeed]',
    size: '1024x1024',
    quality: 'medium',
    reference_images: [],
});

const newRefUrl = ref('');
const showTopupModal = ref(false);
const topupAmount = ref(10);
const isEstimating = ref(false);
const estimation = ref(null);
const localGenerations = ref([...props.generations]);

// Watch props for updates from Inertia
watch(() => props.generations, (newVal) => {
    localGenerations.value = [...newVal];
});

// Reference images helper
function addRefImage() {
    if (!newRefUrl.value || form.reference_images.length >= 4) return;
    try {
        new URL(newRefUrl.value);
        form.reference_images.push(newRefUrl.value.trim());
        newRefUrl.value = '';
    } catch {
        alert('Please enter a valid HTTP/HTTPS image URL.');
    }
}

function removeRefImage(index) {
    form.reference_images.splice(index, 1);
}

function appendTag(tag) {
    if (!form.prompt.includes(tag)) {
        form.prompt = `${form.prompt.trim()} ${tag}`.trim();
    }
}

// Debounced live price estimation
let estimateTimeout = null;
function fetchEstimate() {
    if (!form.prompt || form.prompt.trim().length === 0) {
        estimation.value = null;
        return;
    }
    isEstimating.value = true;
    clearTimeout(estimateTimeout);
    estimateTimeout = setTimeout(async () => {
        try {
            const res = await axios.post('/generations/estimate', {
                prompt: form.prompt,
                size: form.size,
                quality: form.quality,
                reference_images: form.reference_images,
            });
            estimation.value = res.data;
        } catch (e) {
            console.error('Estimation error:', e);
        } finally {
            isEstimating.value = false;
        }
    }, 250);
}

watch([() => form.prompt, () => form.size, () => form.quality, () => form.reference_images.length], () => {
    fetchEstimate();
}, { immediate: true });

// Form submission
const isSubmitting = ref(false);
const submissionError = ref('');

function submitGeneration() {
    if (isSubmitting.value) return;
    submissionError.value = '';
    isSubmitting.value = true;

    form.post('/generations', {
        preserveScroll: true,
        onSuccess: () => {
            isSubmitting.value = false;
        },
        onError: (err) => {
            isSubmitting.value = false;
            submissionError.value = err.error || Object.values(err)[0] || 'Generation failed to queue.';
        },
    });
}

// Simulated Credit Topup
function submitTopup() {
    router.post('/credits/topup', { amount: topupAmount.value }, {
        preserveScroll: true,
        onSuccess: () => {
            showTopupModal.value = false;
        },
    });
}

// Polling for queued/processing generations fallback
let pollInterval = null;
async function pollPendingGenerations() {
    const pending = localGenerations.value.filter(g => g.status === 'queued' || g.status === 'processing');
    if (pending.length === 0) return;

    for (const item of pending) {
        try {
            const res = await axios.get(`/generations/${item.id}/status`);
            if (res.data?.generation) {
                const updated = res.data.generation;
                const index = localGenerations.value.findIndex(g => g.id === updated.id);
                if (index !== -1) {
                    localGenerations.value[index] = updated;
                }
                if (res.data.user_balance && props.user) {
                    props.user.balance_usd = res.data.user_balance;
                }
            }
        } catch (err) {
            // ignore network glitch
        }
    }
}

onMounted(() => {
    pollInterval = setInterval(pollPendingGenerations, 3000);
});

onUnmounted(() => {
    clearInterval(pollInterval);
});

function formatUsd(val) {
    if (val === null || val === undefined) return '$0.00';
    const num = Number(val);
    if (isNaN(num)) return '$0.00';
    return `$${num.toFixed(6)}`;
}
</script>

<template>
    <div class="min-h-screen bg-slate-950 text-slate-100 flex flex-col selection:bg-indigo-500 selection:text-white">
        <!-- Header -->
        <header class="border-b border-slate-800 bg-slate-900/60 backdrop-blur sticky top-0 z-20">
            <div class="max-w-7xl mx-auto px-4 sm:px-6 h-16 flex items-center justify-between">
                <div class="flex items-center gap-3">
                    <div class="w-9 h-9 rounded-lg bg-gradient-to-tr from-indigo-500 to-purple-600 flex items-center justify-center font-bold text-white shadow-lg shadow-indigo-500/20">
                        P
                    </div>
                    <div>
                        <span class="font-semibold text-lg tracking-tight bg-gradient-to-r from-white to-slate-400 bg-clip-text text-transparent">Pixelforge Studio</span>
                        <span class="ml-2 text-xs font-mono px-2 py-0.5 rounded bg-indigo-500/10 text-indigo-400 border border-indigo-500/20">+{{ markupPercent }}% Pricing</span>
                    </div>
                </div>

                <div class="flex items-center gap-4">
                    <!-- Balance Badge -->
                    <div class="flex items-center gap-2 bg-slate-800/80 border border-slate-700/60 rounded-lg px-3 py-1.5">
                        <span class="text-xs text-slate-400 font-medium">Balance:</span>
                        <span class="text-sm font-mono font-semibold text-emerald-400">{{ formatUsd(user?.balance_usd) }}</span>
                        <button @click="showTopupModal = true" class="text-xs bg-emerald-500/20 hover:bg-emerald-500/30 text-emerald-300 font-medium px-2 py-0.5 rounded transition">
                            + Top Up
                        </button>
                    </div>

                    <!-- Admin Report Link -->
                    <Link href="/admin/report" class="text-xs text-slate-300 hover:text-white bg-slate-800/60 hover:bg-slate-800 border border-slate-700 px-3 py-1.5 rounded-lg transition font-medium">
                        Admin Report &rarr;
                    </Link>
                </div>
            </div>
        </header>

        <!-- Main Layout -->
        <main class="flex-1 max-w-7xl w-full mx-auto px-4 sm:px-6 py-8 grid grid-cols-1 lg:grid-cols-12 gap-8">
            <!-- Left: Creation & Estimation Form -->
            <div class="lg:col-span-5 space-y-6">
                <div class="bg-slate-900/70 border border-slate-800 rounded-2xl p-6 shadow-xl backdrop-blur">
                    <h2 class="text-lg font-semibold tracking-tight text-white mb-1">Create Generation</h2>
                    <p class="text-xs text-slate-400 mb-5">Generate AI imagery with real-time transparent token-rate pricing.</p>

                    <!-- Error Alert -->
                    <div v-if="submissionError" class="mb-4 p-3 bg-red-950/60 border border-red-800/80 rounded-xl text-xs text-red-200">
                        {{ submissionError }}
                    </div>

                    <form @submit.prevent="submitGeneration" class="space-y-5">
                        <!-- Prompt Input -->
                        <div>
                            <div class="flex items-center justify-between mb-1.5">
                                <label class="text-xs font-medium text-slate-300">Prompt</label>
                                <span class="text-[11px] text-slate-500 font-mono">{{ form.prompt.length }}/4000 chars</span>
                            </div>
                            <textarea
                                v-model="form.prompt"
                                rows="4"
                                maxlength="4000"
                                required
                                placeholder="Describe what you want to generate..."
                                class="w-full bg-slate-950/80 border border-slate-800 rounded-xl p-3 text-sm text-slate-100 placeholder-slate-500 focus:outline-none focus:ring-2 focus:ring-indigo-500/50 focus:border-indigo-500 transition resize-none"
                            ></textarea>

                            <!-- Test Behavior Pills -->
                            <div class="mt-2 flex flex-wrap items-center gap-1.5">
                                <span class="text-[10px] text-slate-500 font-medium">Test keywords:</span>
                                <button type="button" @click="appendTag('[succeed]')" class="text-[10px] font-mono px-2 py-0.5 rounded bg-emerald-500/10 text-emerald-400 hover:bg-emerald-500/20 border border-emerald-500/20 transition">[succeed]</button>
                                <button type="button" @click="appendTag('[fail]')" class="text-[10px] font-mono px-2 py-0.5 rounded bg-red-500/10 text-red-400 hover:bg-red-500/20 border border-red-500/20 transition">[fail]</button>
                                <button type="button" @click="appendTag('[duplicate]')" class="text-[10px] font-mono px-2 py-0.5 rounded bg-amber-500/10 text-amber-400 hover:bg-amber-500/20 border border-amber-500/20 transition">[duplicate]</button>
                                <button type="button" @click="appendTag('[slow]')" class="text-[10px] font-mono px-2 py-0.5 rounded bg-blue-500/10 text-blue-400 hover:bg-blue-500/20 border border-blue-500/20 transition">[slow]</button>
                                <button type="button" @click="appendTag('[overloaded]')" class="text-[10px] font-mono px-2 py-0.5 rounded bg-purple-500/10 text-purple-400 hover:bg-purple-500/20 border border-purple-500/20 transition">[overloaded]</button>
                            </div>
                        </div>

                        <!-- Size Selector -->
                        <div>
                            <label class="text-xs font-medium text-slate-300 block mb-1.5">Dimensions</label>
                            <div class="grid grid-cols-3 gap-2">
                                <button
                                    type="button"
                                    @click="form.size = '1024x1024'"
                                    :class="form.size === '1024x1024' ? 'bg-indigo-600/30 border-indigo-500 text-white font-medium' : 'bg-slate-950/60 border-slate-800 text-slate-400 hover:border-slate-700'"
                                    class="border rounded-xl py-2 px-2 text-center text-xs transition"
                                >
                                    <div class="font-mono text-xs">1024x1024</div>
                                    <div class="text-[10px] text-slate-500 mt-0.5">Square</div>
                                </button>
                                <button
                                    type="button"
                                    @click="form.size = '1536x1024'"
                                    :class="form.size === '1536x1024' ? 'bg-indigo-600/30 border-indigo-500 text-white font-medium' : 'bg-slate-950/60 border-slate-800 text-slate-400 hover:border-slate-700'"
                                    class="border rounded-xl py-2 px-2 text-center text-xs transition"
                                >
                                    <div class="font-mono text-xs">1536x1024</div>
                                    <div class="text-[10px] text-slate-500 mt-0.5">Landscape</div>
                                </button>
                                <button
                                    type="button"
                                    @click="form.size = '1024x1536'"
                                    :class="form.size === '1024x1536' ? 'bg-indigo-600/30 border-indigo-500 text-white font-medium' : 'bg-slate-950/60 border-slate-800 text-slate-400 hover:border-slate-700'"
                                    class="border rounded-xl py-2 px-2 text-center text-xs transition"
                                >
                                    <div class="font-mono text-xs">1024x1536</div>
                                    <div class="text-[10px] text-slate-500 mt-0.5">Portrait</div>
                                </button>
                            </div>
                        </div>

                        <!-- Quality Selector -->
                        <div>
                            <label class="text-xs font-medium text-slate-300 block mb-1.5">Quality Level</label>
                            <div class="grid grid-cols-3 gap-2">
                                <button
                                    type="button"
                                    @click="form.quality = 'low'"
                                    :class="form.quality === 'low' ? 'bg-indigo-600/30 border-indigo-500 text-white font-medium' : 'bg-slate-950/60 border-slate-800 text-slate-400 hover:border-slate-700'"
                                    class="border rounded-xl py-2 px-2 text-center text-xs transition"
                                >
                                    <div class="capitalize text-xs font-semibold">Low</div>
                                    <div class="text-[10px] text-slate-500 mt-0.5">Fastest &bull; Lowest Cost</div>
                                </button>
                                <button
                                    type="button"
                                    @click="form.quality = 'medium'"
                                    :class="form.quality === 'medium' ? 'bg-indigo-600/30 border-indigo-500 text-white font-medium' : 'bg-slate-950/60 border-slate-800 text-slate-400 hover:border-slate-700'"
                                    class="border rounded-xl py-2 px-2 text-center text-xs transition"
                                >
                                    <div class="capitalize text-xs font-semibold">Medium</div>
                                    <div class="text-[10px] text-slate-500 mt-0.5">Balanced (Default)</div>
                                </button>
                                <button
                                    type="button"
                                    @click="form.quality = 'high'"
                                    :class="form.quality === 'high' ? 'bg-indigo-600/30 border-indigo-500 text-white font-medium' : 'bg-slate-950/60 border-slate-800 text-slate-400 hover:border-slate-700'"
                                    class="border rounded-xl py-2 px-2 text-center text-xs transition"
                                >
                                    <div class="capitalize text-xs font-semibold">High</div>
                                    <div class="text-[10px] text-slate-500 mt-0.5">Deep Reasoning</div>
                                </button>
                            </div>
                        </div>

                        <!-- Reference Images Input -->
                        <div>
                            <label class="text-xs font-medium text-slate-300 block mb-1.5">
                                Reference Image URLs (Optional, max 4 &bull; +765 tokens each)
                            </label>
                            <div class="flex gap-2">
                                <input
                                    v-model="newRefUrl"
                                    type="url"
                                    placeholder="https://example.com/style.png"
                                    :disabled="form.reference_images.length >= 4"
                                    class="flex-1 bg-slate-950/80 border border-slate-800 rounded-xl px-3 py-1.5 text-xs text-slate-100 placeholder-slate-500 focus:outline-none focus:ring-1 focus:ring-indigo-500"
                                />
                                <button
                                    type="button"
                                    @click="addRefImage"
                                    :disabled="!newRefUrl || form.reference_images.length >= 4"
                                    class="px-3 py-1.5 bg-slate-800 hover:bg-slate-700 disabled:opacity-40 text-xs font-medium rounded-xl text-slate-200 transition"
                                >
                                    Add
                                </button>
                            </div>
                            <ul v-if="form.reference_images.length" class="mt-2 space-y-1">
                                <li v-for="(img, idx) in form.reference_images" :key="idx" class="flex items-center justify-between bg-slate-950/50 border border-slate-800/80 px-2.5 py-1 rounded-lg text-[11px] text-slate-300">
                                    <span class="truncate max-w-[240px]">{{ img }}</span>
                                    <button type="button" @click="removeRefImage(idx)" class="text-red-400 hover:text-red-300 ml-2">&times;</button>
                                </li>
                            </ul>
                        </div>

                        <!-- Live Transparent Cost Breakdown Card -->
                        <div class="p-4 rounded-xl bg-slate-950/90 border border-slate-800/90 space-y-3">
                            <div class="flex items-center justify-between border-b border-slate-800/80 pb-2">
                                <span class="text-xs font-semibold text-slate-200 flex items-center gap-1.5">
                                    <span>Cost Breakdown</span>
                                    <span v-if="isEstimating" class="w-1.5 h-1.5 rounded-full bg-indigo-500 animate-ping"></span>
                                </span>
                                <span class="text-[11px] text-slate-400">Tokens &times; Rates + 25%</span>
                            </div>

                            <div v-if="estimation" class="space-y-2 text-xs">
                                <div class="grid grid-cols-2 gap-2 text-[11px] text-slate-400">
                                    <div>Text Tokens: <span class="text-slate-200 font-mono">{{ estimation.tokens_breakdown.text_estimated }}</span> (&plusmn;15%)</div>
                                    <div>Output Tokens: <span class="text-slate-200 font-mono">{{ estimation.tokens_breakdown.output_tokens }}</span></div>
                                    <div>Ref Image Tokens: <span class="text-slate-200 font-mono">{{ estimation.tokens_breakdown.image_tokens }}</span></div>
                                    <div>Reasoning Range: <span class="text-slate-200 font-mono">{{ estimation.tokens_breakdown.reasoning_range[0] }}–{{ estimation.tokens_breakdown.reasoning_range[1] }}</span></div>
                                </div>

                                <div class="pt-2 border-t border-slate-800/60 flex items-baseline justify-between">
                                    <div>
                                        <div class="text-[11px] text-slate-400">Estimated Cost Range:</div>
                                        <div class="text-xs text-slate-400 font-mono">
                                            {{ formatUsd(estimation.user_price.min) }} &ndash; {{ formatUsd(estimation.user_price.max) }}
                                        </div>
                                    </div>
                                    <div class="text-right">
                                        <div class="text-[10px] uppercase tracking-wider text-slate-400 font-medium">Expected Charge</div>
                                        <div class="text-base font-bold font-mono text-emerald-400">
                                            {{ formatUsd(estimation.user_price.expected) }}
                                        </div>
                                    </div>
                                </div>

                                <p class="text-[10px] text-slate-500 italic leading-snug">
                                    *Part of provider cost is known after generation completes. Failed generations cost $0.00.
                                </p>
                            </div>
                            <div v-else class="text-xs text-slate-500 py-1">
                                Enter a prompt to calculate live price estimate.
                            </div>
                        </div>

                        <!-- Submit Button -->
                        <button
                            type="submit"
                            :disabled="isSubmitting || !form.prompt"
                            class="w-full py-3 px-4 rounded-xl font-medium text-sm text-white bg-gradient-to-r from-indigo-500 to-purple-600 hover:from-indigo-600 hover:to-purple-700 shadow-lg shadow-indigo-500/20 disabled:opacity-50 transition flex items-center justify-center gap-2 cursor-pointer"
                        >
                            <span v-if="isSubmitting" class="w-4 h-4 border-2 border-white/30 border-t-white rounded-full animate-spin"></span>
                            <span>{{ isSubmitting ? 'Submitting to Queue...' : 'Generate Image' }}</span>
                        </button>
                    </form>
                </div>
            </div>

            <!-- Right: Generation History & Live Status -->
            <div class="lg:col-span-7 space-y-6">
                <div class="bg-slate-900/70 border border-slate-800 rounded-2xl p-6 shadow-xl backdrop-blur">
                    <div class="flex items-center justify-between mb-5">
                        <div>
                            <h2 class="text-lg font-semibold tracking-tight text-white">Generation History</h2>
                            <p class="text-xs text-slate-400">Live feed of asynchronous jobs, webhook events, and final billed costs.</p>
                        </div>
                        <span class="text-xs font-mono text-slate-400 bg-slate-800/60 px-2.5 py-1 rounded-lg border border-slate-700/60">
                            {{ localGenerations.length }} records
                        </span>
                    </div>

                    <div v-if="localGenerations.length === 0" class="text-center py-16 border border-dashed border-slate-800 rounded-xl text-slate-500 text-sm">
                        No generations yet. Try submitting your first prompt on the left!
                    </div>

                    <div v-else class="space-y-4">
                        <div
                            v-for="gen in localGenerations"
                            :key="gen.id"
                            class="p-4 rounded-xl border bg-slate-950/60 transition"
                            :class="{
                                'border-amber-500/30 bg-amber-950/10': gen.status === 'queued',
                                'border-blue-500/30 bg-blue-950/10': gen.status === 'processing',
                                'border-emerald-500/20': gen.status === 'succeeded',
                                'border-red-500/20 bg-red-950/10': gen.status === 'failed',
                            }"
                        >
                            <div class="flex items-start justify-between gap-4 mb-2">
                                <div class="space-y-1 flex-1">
                                    <div class="flex items-center gap-2">
                                        <!-- Status Pill -->
                                        <span
                                            class="text-[11px] font-mono px-2 py-0.5 rounded font-medium inline-flex items-center gap-1.5"
                                            :class="{
                                                'bg-amber-500/20 text-amber-300 border border-amber-500/30': gen.status === 'queued',
                                                'bg-blue-500/20 text-blue-300 border border-blue-500/30': gen.status === 'processing',
                                                'bg-emerald-500/20 text-emerald-300 border border-emerald-500/30': gen.status === 'succeeded',
                                                'bg-red-500/20 text-red-300 border border-red-500/30': gen.status === 'failed',
                                            }"
                                        >
                                            <span v-if="gen.status === 'queued' || gen.status === 'processing'" class="w-1.5 h-1.5 rounded-full bg-current animate-pulse"></span>
                                            {{ gen.status.toUpperCase() }}
                                        </span>
                                        <span class="text-xs font-mono text-slate-400">{{ gen.size }} &bull; {{ gen.quality }}</span>
                                        <span class="text-[11px] text-slate-500 font-mono">{{ gen.pixelforge_id || ('#local-' + gen.id) }}</span>
                                    </div>
                                    <p class="text-sm text-slate-200 line-clamp-2">{{ gen.prompt }}</p>
                                </div>

                                <!-- Charge Summary -->
                                <div class="text-right shrink-0">
                                    <div v-if="gen.status === 'succeeded'">
                                        <div class="text-xs text-slate-400">Actual Billed</div>
                                        <div class="text-base font-bold font-mono text-emerald-400">
                                            {{ formatUsd(gen.user_charged_usd) }}
                                        </div>
                                    </div>
                                    <div v-else-if="gen.status === 'failed'">
                                        <div class="text-xs text-slate-400">Charge</div>
                                        <div class="text-base font-bold font-mono text-slate-400">$0.00</div>
                                    </div>
                                    <div v-else>
                                        <div class="text-xs text-slate-400">Est. Charge</div>
                                        <div class="text-sm font-mono text-amber-400">
                                            {{ formatUsd(gen.estimated_cost_usd) }}
                                        </div>
                                    </div>
                                </div>
                            </div>

                            <!-- Image Preview if Succeeded -->
                            <div v-if="gen.status === 'succeeded' && gen.image_url" class="mt-3">
                                <div class="rounded-lg overflow-hidden border border-slate-800 bg-slate-900 max-h-64 flex items-center justify-center">
                                    <img :src="gen.image_url" alt="Generated Image" class="max-h-64 w-full object-contain" loading="lazy" />
                                </div>
                            </div>

                            <!-- Failure Details -->
                            <div v-if="gen.status === 'failed'" class="mt-2 p-2.5 bg-red-950/40 border border-red-900/50 rounded-lg text-xs text-red-300">
                                <div class="font-medium text-red-200">Error: {{ gen.error_code || 'Generation Failed' }}</div>
                                <div class="text-[11px] text-red-300/80 mt-0.5">{{ gen.error_message }}</div>
                            </div>

                            <!-- Detailed Token & Rate Footnote -->
                            <div v-if="gen.status === 'succeeded'" class="mt-3 pt-2 border-t border-slate-800/60 grid grid-cols-2 sm:grid-cols-4 gap-2 text-[10px] text-slate-400 font-mono">
                                <div>Text: {{ gen.input_text_tokens }} tok</div>
                                <div>Image: {{ gen.input_image_tokens }} tok</div>
                                <div>Output: {{ gen.output_tokens }} tok</div>
                                <div>Reasoning: {{ gen.reasoning_tokens }} tok</div>
                                <div>Provider Cost: {{ formatUsd(gen.provider_cost_usd) }}</div>
                                <div>Markup Profit: {{ formatUsd(gen.profit_usd) }}</div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </main>

        <!-- Simulated Topup Modal -->
        <div v-if="showTopupModal" class="fixed inset-0 z-50 flex items-center justify-center bg-black/70 backdrop-blur-sm p-4">
            <div class="bg-slate-900 border border-slate-800 rounded-2xl p-6 max-w-sm w-full space-y-4 shadow-2xl">
                <div class="flex items-center justify-between">
                    <h3 class="font-semibold text-white">Buy Credits (Simulated)</h3>
                    <button @click="showTopupModal = false" class="text-slate-400 hover:text-white">&times;</button>
                </div>
                <p class="text-xs text-slate-400">
                    This is a simulated balance purchase to satisfy the stretch goal. No real money is charged.
                </p>
                <div class="grid grid-cols-4 gap-2">
                    <button
                        v-for="amt in [5, 10, 25, 50]"
                        :key="amt"
                        type="button"
                        @click="topupAmount = amt"
                        :class="topupAmount === amt ? 'bg-indigo-600 border-indigo-500 text-white' : 'bg-slate-950 border-slate-800 text-slate-300'"
                        class="border rounded-xl py-2 text-center text-xs font-mono font-medium"
                    >
                        ${{ amt }}
                    </button>
                </div>
                <div class="flex gap-2 pt-2">
                    <button
                        @click="showTopupModal = false"
                        class="flex-1 py-2 rounded-xl text-xs bg-slate-800 hover:bg-slate-700 text-slate-300 font-medium"
                    >
                        Cancel
                    </button>
                    <button
                        @click="submitTopup"
                        class="flex-1 py-2 rounded-xl text-xs bg-emerald-600 hover:bg-emerald-500 text-white font-medium"
                    >
                        Add ${{ topupAmount }}
                    </button>
                </div>
            </div>
        </div>
    </div>
</template>
