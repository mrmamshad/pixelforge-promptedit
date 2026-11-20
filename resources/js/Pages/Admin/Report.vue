<script setup>
import { computed } from 'vue';
import { Link } from '@inertiajs/vue3';

const props = defineProps({
    dailyRecords: { type: Array, default: () => [] },
    userBreakdown: { type: Array, default: () => [] },
    officialUsage: { type: Object, default: () => null },
});

const totalSpend = computed(() => {
    return props.dailyRecords.reduce((acc, row) => acc + Number(row.total_user_spend || 0), 0);
});

const totalProviderCost = computed(() => {
    return props.dailyRecords.reduce((acc, row) => acc + Number(row.total_provider_cost || 0), 0);
});

const totalProfit = computed(() => {
    return props.dailyRecords.reduce((acc, row) => acc + Number(row.total_profit || 0), 0);
});

const profitMargin = computed(() => {
    if (totalSpend.value <= 0) return '0.0%';
    return ((totalProfit.value / totalSpend.value) * 100).toFixed(1) + '%';
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
        <!-- Top Nav -->
        <header class="border-b border-slate-800 bg-slate-900/60 backdrop-blur sticky top-0 z-20">
            <div class="max-w-7xl mx-auto px-4 sm:px-6 h-16 flex items-center justify-between">
                <div class="flex items-center gap-3">
                    <div class="w-9 h-9 rounded-lg bg-gradient-to-tr from-indigo-500 to-purple-600 flex items-center justify-center font-bold text-white shadow-lg">
                        P
                    </div>
                    <div>
                        <span class="font-semibold text-lg tracking-tight">Operator Financial Report</span>
                        <span class="ml-2 text-xs font-mono px-2 py-0.5 rounded bg-emerald-500/10 text-emerald-400 border border-emerald-500/20">Reconciled</span>
                    </div>
                </div>

                <Link href="/" class="text-xs text-slate-300 hover:text-white bg-slate-800 border border-slate-700 px-3 py-1.5 rounded-lg transition font-medium">
                    &larr; Back to Studio
                </Link>
            </div>
        </header>

        <main class="flex-1 max-w-7xl w-full mx-auto px-4 sm:px-6 py-8 space-y-8">
            <!-- KPI Summary Cards -->
            <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4">
                <div class="p-5 rounded-2xl bg-slate-900/70 border border-slate-800 shadow-lg backdrop-blur">
                    <div class="text-xs text-slate-400 font-medium">Total User Spend (Revenue)</div>
                    <div class="text-2xl font-bold font-mono text-white mt-1">{{ formatUsd(totalSpend) }}</div>
                    <div class="text-[11px] text-slate-500 mt-1">Charged to users with +25% markup</div>
                </div>

                <div class="p-5 rounded-2xl bg-slate-900/70 border border-slate-800 shadow-lg backdrop-blur">
                    <div class="text-xs text-slate-400 font-medium">Pixelforge Provider Cost</div>
                    <div class="text-2xl font-bold font-mono text-amber-400 mt-1">{{ formatUsd(totalProviderCost) }}</div>
                    <div class="text-[11px] text-slate-500 mt-1">Billed by Pixelforge token rates</div>
                </div>

                <div class="p-5 rounded-2xl bg-slate-900/70 border border-slate-800 shadow-lg backdrop-blur">
                    <div class="text-xs text-slate-400 font-medium">Net Profit</div>
                    <div class="text-2xl font-bold font-mono text-emerald-400 mt-1">{{ formatUsd(totalProfit) }}</div>
                    <div class="text-[11px] text-slate-500 mt-1">User Spend &minus; Provider Cost</div>
                </div>

                <div class="p-5 rounded-2xl bg-slate-900/70 border border-slate-800 shadow-lg backdrop-blur">
                    <div class="text-xs text-slate-400 font-medium">Operating Margin</div>
                    <div class="text-2xl font-bold font-mono text-indigo-400 mt-1">{{ profitMargin }}</div>
                    <div class="text-[11px] text-slate-500 mt-1">Exact markup target: 20% margin (25% on cost)</div>
                </div>
            </div>

            <!-- Daily Aggregate Table -->
            <div class="bg-slate-900/70 border border-slate-800 rounded-2xl p-6 shadow-xl backdrop-blur space-y-4">
                <div class="flex items-center justify-between">
                    <div>
                        <h2 class="text-base font-semibold text-white">Daily Credit Spend &amp; Provider Reconciliation</h2>
                        <p class="text-xs text-slate-400">Aggregated by UTC day across all successful generations.</p>
                    </div>
                </div>

                <div class="overflow-x-auto">
                    <table class="w-full text-left text-xs">
                        <thead>
                            <tr class="border-b border-slate-800 text-slate-400 uppercase text-[10px] tracking-wider font-semibold">
                                <th class="pb-3">Date (UTC)</th>
                                <th class="pb-3 text-right">Generations</th>
                                <th class="pb-3 text-right">User Charged</th>
                                <th class="pb-3 text-right">Provider Cost</th>
                                <th class="pb-3 text-right">Profit</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-slate-800/60 font-mono">
                            <tr v-if="dailyRecords.length === 0">
                                <td colspan="5" class="py-6 text-center text-slate-500 font-sans">
                                    No completed generations recorded yet.
                                </td>
                            </tr>
                            <tr v-for="row in dailyRecords" :key="row.report_date" class="hover:bg-slate-800/30 transition">
                                <td class="py-3 text-slate-200 font-sans font-medium">{{ row.report_date }}</td>
                                <td class="py-3 text-right text-slate-300">{{ row.total_generations }}</td>
                                <td class="py-3 text-right text-emerald-400 font-semibold">{{ formatUsd(row.total_user_spend) }}</td>
                                <td class="py-3 text-right text-amber-400">{{ formatUsd(row.total_provider_cost) }}</td>
                                <td class="py-3 text-right text-indigo-400 font-semibold">{{ formatUsd(row.total_profit) }}</td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>

            <!-- User Breakdown Table -->
            <div class="bg-slate-900/70 border border-slate-800 rounded-2xl p-6 shadow-xl backdrop-blur space-y-4">
                <div>
                    <h2 class="text-base font-semibold text-white">User Accounts &amp; Spend Breakdown</h2>
                    <p class="text-xs text-slate-400">Individual user spend and operating profit contribution.</p>
                </div>

                <div class="overflow-x-auto">
                    <table class="w-full text-left text-xs">
                        <thead>
                            <tr class="border-b border-slate-800 text-slate-400 uppercase text-[10px] tracking-wider font-semibold">
                                <th class="pb-3">User</th>
                                <th class="pb-3">Email</th>
                                <th class="pb-3 text-right">Balance</th>
                                <th class="pb-3 text-right">Generations</th>
                                <th class="pb-3 text-right">Total Spent</th>
                                <th class="pb-3 text-right">Provider Cost</th>
                                <th class="pb-3 text-right">Net Profit</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-slate-800/60 font-mono">
                            <tr v-for="u in userBreakdown" :key="u.id" class="hover:bg-slate-800/30 transition">
                                <td class="py-3 text-slate-200 font-sans font-medium">{{ u.name }}</td>
                                <td class="py-3 text-slate-400">{{ u.email }}</td>
                                <td class="py-3 text-right text-emerald-300">{{ formatUsd(u.balance_usd) }}</td>
                                <td class="py-3 text-right text-slate-300">{{ u.successful_generations }}</td>
                                <td class="py-3 text-right text-emerald-400 font-semibold">{{ formatUsd(u.total_spent) }}</td>
                                <td class="py-3 text-right text-amber-400">{{ formatUsd(u.total_provider_cost) }}</td>
                                <td class="py-3 text-right text-indigo-400 font-semibold">{{ formatUsd(u.total_profit) }}</td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>

            <!-- Live Pixelforge GET /v1/usage Cross-Check -->
            <div v-if="officialUsage" class="bg-slate-900/70 border border-slate-800 rounded-2xl p-6 shadow-xl backdrop-blur space-y-4">
                <div class="flex items-center justify-between">
                    <div>
                        <h2 class="text-base font-semibold text-white">Pixelforge Official Usage API Reconciliation (GET /v1/usage)</h2>
                        <p class="text-xs text-slate-400">Live data returned by Pixelforge's official usage endpoint for our API key.</p>
                    </div>
                    <span class="text-xs font-mono text-emerald-400 bg-emerald-500/10 border border-emerald-500/20 px-2.5 py-1 rounded-lg">
                        API Total: {{ formatUsd(officialUsage?.total?.cost_usd) }}
                    </span>
                </div>

                <div class="overflow-x-auto max-h-72">
                    <table class="w-full text-left text-xs">
                        <thead class="sticky top-0 bg-slate-900 border-b border-slate-800">
                            <tr class="text-slate-400 uppercase text-[10px] tracking-wider font-semibold">
                                <th class="pb-3">Date</th>
                                <th class="pb-3 text-right">Generations</th>
                                <th class="pb-3 text-right">Text Tokens</th>
                                <th class="pb-3 text-right">Output Tokens</th>
                                <th class="pb-3 text-right">Reasoning</th>
                                <th class="pb-3 text-right">Official Cost (USD)</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-slate-800/60 font-mono">
                            <tr
                                v-for="d in officialUsage?.data?.filter(item => item.generations > 0) || []"
                                :key="d.date"
                                class="hover:bg-slate-800/30 transition"
                            >
                                <td class="py-2.5 text-slate-200 font-sans font-medium">{{ d.date }}</td>
                                <td class="py-2.5 text-right text-slate-300">{{ d.generations }}</td>
                                <td class="py-2.5 text-right text-slate-400">{{ d.input_text_tokens }}</td>
                                <td class="py-2.5 text-right text-slate-400">{{ d.output_tokens }}</td>
                                <td class="py-2.5 text-right text-slate-400">{{ d.reasoning_tokens }}</td>
                                <td class="py-2.5 text-right text-amber-400 font-semibold">{{ formatUsd(d.cost_usd) }}</td>
                            </tr>
                            <tr v-if="!officialUsage?.data?.some(item => item.generations > 0)">
                                <td colspan="6" class="py-6 text-center text-slate-500 font-sans">
                                    No usage recorded on this Pixelforge API key yet. Once generations are triggered, they will reconcile here automatically.
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>
        </main>
    </div>
</template>
