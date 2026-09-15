<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<title>{{ $merchant->name }} — Dashboard Preview (local)</title>
<style>
    :root {
        --ink: #171a21; --ink-muted: #5b6472; --ink-faint: #8a919e;
        --bg: #eef1f5; --surface: #fff; --border: #e1e4e9;
        --blue: #2f6fed; --orange: #e08a2e; --green: #2e9e5b;
        --critical: #c0392b; --critical-bg: #fdecea; --critical-border: #f3b9b2;
        --info: #2f6fed; --info-bg: #eaf1fe; --info-border: #b9d3fb;
    }
    * { box-sizing: border-box; }
    body {
        margin: 0; background: var(--bg); color: var(--ink);
        font-family: -apple-system, "Segoe UI", Roboto, sans-serif;
    }
    .topbar {
        background: #151b2b; color: #fff; padding: 18px 28px;
        display: flex; justify-content: space-between; align-items: center;
    }
    .topbar h1 { margin: 0; font-size: 16px; font-weight: 600; }
    .topbar .tag {
        font-size: 10.5px; letter-spacing: .06em; color: #9aa7c7; font-weight: 600;
        border: 1px solid #3a4568; border-radius: 4px; padding: 3px 8px;
    }
    .page { max-width: 1040px; margin: 0 auto; padding: 24px 24px 48px; }
    .sub { color: var(--ink-muted); font-size: 13px; margin-bottom: 20px; }
    .sub code { background: #e4e7ec; padding: 1px 5px; border-radius: 4px; font-size: 12px; }

    .stat-row { display: grid; grid-template-columns: repeat(3, 1fr); gap: 14px; margin-bottom: 20px; }
    .tile {
        background: var(--surface); border: 1px solid var(--border); border-left: 4px solid var(--blue);
        border-radius: 8px; padding: 14px 16px;
    }
    .tile.orange { border-left-color: var(--orange); }
    .tile.green { border-left-color: var(--green); }
    .tile .label { font-size: 12px; color: var(--ink-muted); }
    .tile .value { font-family: ui-monospace, monospace; font-size: 22px; font-weight: 600; margin-top: 4px; }
    .tile .footnote { font-size: 11px; color: var(--ink-faint); margin-top: 4px; }

    .grid { display: grid; grid-template-columns: 1.3fr 0.9fr; gap: 16px; align-items: start; }
    .panel { background: var(--surface); border: 1px solid var(--border); border-radius: 8px; overflow: hidden; }
    .panel h2 { font-size: 13.5px; margin: 0; padding: 13px 18px; border-bottom: 1px solid var(--border); font-weight: 600; }

    table { width: 100%; border-collapse: collapse; font-size: 13.5px; }
    th {
        text-align: left; font-size: 11px; text-transform: uppercase; letter-spacing: .04em;
        color: var(--ink-faint); padding: 9px 18px; border-bottom: 1px solid var(--border); background: #fafbfc;
    }
    th.num, td.num { text-align: right; }
    td { padding: 10px 18px; border-bottom: 1px solid var(--border); }
    tr:last-child td { border-bottom: none; }
    .empty { padding: 16px 18px; color: var(--ink-faint); font-size: 13px; }

    .side-stack { display: flex; flex-direction: column; gap: 14px; }
    .box { border-radius: 8px; padding: 14px 16px; border: 1px solid; }
    .box.risk { background: var(--critical-bg); border-color: var(--critical-border); }
    .box.info { background: var(--info-bg); border-color: var(--info-border); }
    .box h3 {
        margin: 0 0 10px; font-size: 12.5px; font-weight: 700;
        display: flex; align-items: center; gap: 6px;
    }
    .box.risk h3 { color: var(--critical); }
    .box.info h3 { color: var(--info); }
    .risk-row { font-size: 12.5px; margin-bottom: 8px; padding-bottom: 8px; border-bottom: 1px solid var(--critical-border); }
    .risk-row:last-child { border-bottom: none; margin-bottom: 0; padding-bottom: 0; }
    .risk-row .name { font-weight: 600; color: var(--ink); }
    .risk-row .drop { color: var(--critical); font-weight: 700; }
    .info-row { font-size: 12px; color: #33507a; margin-bottom: 8px; line-height: 1.5; }
    .info-row:last-child { margin-bottom: 0; }
    .info-row code { background: #d9e6fc; padding: 0 4px; border-radius: 3px; }

    #loading, #error { padding: 60px; text-align: center; color: var(--ink-muted); }
    #error { color: var(--critical); display: none; }
</style>
</head>
<body>
<div class="topbar">
    <h1>Merchant Dashboard &mdash; {{ $merchant->name }}</h1>
    <span class="tag">LOCAL PREVIEW &middot; NOT THE GRADED API SURFACE</span>
</div>

<div class="page">
    <div class="sub">Live from <code>GET /api/merchants/{{ $merchant->id }}/dashboard</code>, fetched client-side on load with a throwaway token this page minted for itself. Refresh to re-fetch.</div>

    <div id="loading">Loading…</div>
    <div id="error"></div>
    <div id="content" style="display:none">
        <div class="stat-row">
            <div class="tile">
                <div class="label">Current Cycle Usage</div>
                <div class="value" id="cycleUsage">—</div>
            </div>
            <div class="tile orange">
                <div class="label">Projected Overage Revenue</div>
                <div class="value" id="overage">—</div>
                <div class="footnote">extrapolated from pace-to-date, not an invoice figure</div>
            </div>
            <div class="tile green">
                <div class="label">Active Subscriptions</div>
                <div class="value" id="subCount">—</div>
                <div class="footnote">count, not a single "active plan" — see README</div>
            </div>
        </div>

        <div class="grid">
            <div class="panel">
                <h2>Top 5 Customers by Usage (this cycle)</h2>
                <table>
                    <thead><tr><th>Customer</th><th class="num">Usage</th><th class="num">% of Allowance</th></tr></thead>
                    <tbody id="topCustomersBody"></tbody>
                </table>
            </div>

            <div class="side-stack">
                <div class="box risk">
                    <h3>&#9888; Churn Risk (usage &darr; &gt;50% MoM)</h3>
                    <div id="churnBody"></div>
                </div>
                <div class="box info">
                    <h3>System status (informational)</h3>
                    <div class="info-row">Plan pricing cache: <code>Redis</code>, TTL 24h, invalidated instantly on plan save/delete/restore</div>
                    <div class="info-row">Dashboard cache: <code>Redis</code>, plain 1h TTL — no invalidation hook, by design</div>
                    <div class="info-row">Usage rollup job: queued, chunked 200 subs/batch, daily 01:00</div>
                    <div class="info-row">Invoice generation job: queued, daily 02:00</div>
                    <div class="info-row">Usage endpoint: rate-limited, <code>120 req/min</code> per API token</div>
                </div>
            </div>
        </div>
    </div>
</div>

<script>
const token = @json($token);

fetch(@json(url("/api/merchants/{$merchant->id}/dashboard")), {
    headers: { 'Authorization': 'Bearer ' + token, 'Accept': 'application/json' },
})
    .then(async (res) => {
        const body = await res.json();
        if (!res.ok) throw new Error(body.message || ('HTTP ' + res.status));
        return body.data;
    })
    .then((data) => {
        document.getElementById('loading').style.display = 'none';
        document.getElementById('content').style.display = 'block';

        document.getElementById('cycleUsage').textContent =
            Number(data.current_cycle_usage).toLocaleString() + ' / ' + Number(data.current_cycle_allowance).toLocaleString() + ' units';
        document.getElementById('overage').textContent = '$' + Number(data.projected_overage_revenue_this_cycle).toFixed(2);
        document.getElementById('subCount').textContent = data.top_customers_by_usage.length;

        const topBody = document.getElementById('topCustomersBody');
        if (data.top_customers_by_usage.length === 0) {
            topBody.innerHTML = '<tr><td colspan="3" class="empty">No usage recorded yet this cycle.</td></tr>';
        } else {
            topBody.innerHTML = data.top_customers_by_usage.map((c) => `
                <tr>
                    <td><strong>${c.name}</strong><br><span style="color:var(--ink-faint);font-size:11.5px">${c.email}</span></td>
                    <td class="num">${Number(c.usage_quantity).toLocaleString()}</td>
                    <td class="num">${Number(c.percent_of_allowance).toFixed(0)}%</td>
                </tr>
            `).join('');
        }

        const churnBody = document.getElementById('churnBody');
        if (data.churn_risk_customers.length === 0) {
            churnBody.innerHTML = '<div style="font-size:12.5px;color:#7a5450">No customers currently flagged.</div>';
        } else {
            churnBody.innerHTML = data.churn_risk_customers.map((c) => `
                <div class="risk-row">
                    <span class="name">${c.name}</span> &mdash;
                    <span class="drop">${(100 - Number(c.change_ratio) * 100).toFixed(0)}% drop</span>
                    <div style="color:#8a5450;margin-top:2px;">${Number(c.projected_usage_this_cycle).toFixed(0)} projected vs ${Number(c.previous_cycle_usage).toFixed(0)} last cycle</div>
                </div>
            `).join('');
        }
    })
    .catch((err) => {
        document.getElementById('loading').style.display = 'none';
        const el = document.getElementById('error');
        el.style.display = 'block';
        el.textContent = 'Failed to load dashboard: ' + err.message;
    });
</script>
</body>
</html>
