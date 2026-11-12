<x-filament-panels::page>
    <style>
        .hub-docs {
            --docs-ink: #18201d;
            --docs-muted: #5f6b65;
            --docs-line: rgba(24, 32, 29, .13);
            --docs-paper: #fbfaf6;
            --docs-panel: #ffffff;
            --docs-amber: #d97706;
            --docs-green: #18794e;
            --docs-red: #b42318;
            color: var(--docs-ink);
        }
        .dark .hub-docs {
            --docs-ink: #eef3ef;
            --docs-muted: #9eaaa3;
            --docs-line: rgba(238, 243, 239, .14);
            --docs-paper: #111614;
            --docs-panel: #171d1a;
        }
        .docs-hero {
            position: relative;
            overflow: hidden;
            padding: clamp(1.4rem, 4vw, 3.4rem);
            border: 1px solid var(--docs-line);
            border-radius: 1.25rem;
            background-color: var(--docs-paper);
            background-image:
                linear-gradient(var(--docs-line) 1px, transparent 1px),
                linear-gradient(90deg, var(--docs-line) 1px, transparent 1px);
            background-size: 28px 28px;
            box-shadow: 0 18px 50px rgba(24, 32, 29, .08);
        }
        .docs-hero::after {
            content: "INTERNAL / RUNBOOK 01";
            position: absolute;
            right: -2.4rem;
            top: 2rem;
            padding: .5rem 3rem;
            background: var(--docs-ink);
            color: var(--docs-paper);
            font: 700 .65rem/1 ui-monospace, SFMono-Regular, Menlo, monospace;
            letter-spacing: .16em;
            transform: rotate(35deg);
        }
        .docs-kicker,
        .docs-eyebrow {
            color: var(--docs-amber);
            font: 700 .72rem/1.2 ui-monospace, SFMono-Regular, Menlo, monospace;
            letter-spacing: .14em;
            text-transform: uppercase;
        }
        .docs-hero h2 {
            max-width: 760px;
            margin-top: .85rem;
            font-family: Georgia, Cambria, "Times New Roman", serif;
            font-size: clamp(2rem, 5vw, 4.25rem);
            font-weight: 600;
            line-height: .98;
            letter-spacing: -.045em;
        }
        .docs-hero p {
            max-width: 720px;
            margin-top: 1.1rem;
            color: var(--docs-muted);
            font-size: 1rem;
            line-height: 1.75;
        }
        .docs-signal-row {
            display: flex;
            flex-wrap: wrap;
            gap: .55rem;
            margin-top: 1.35rem;
        }
        .docs-signal {
            display: inline-flex;
            align-items: center;
            gap: .45rem;
            padding: .45rem .7rem;
            border: 1px solid var(--docs-line);
            border-radius: 999px;
            background: color-mix(in srgb, var(--docs-panel) 88%, transparent);
            font-size: .78rem;
            font-weight: 700;
        }
        .docs-signal::before {
            width: .5rem;
            height: .5rem;
            border-radius: 999px;
            background: var(--docs-green);
            content: "";
            box-shadow: 0 0 0 3px color-mix(in srgb, var(--docs-green) 15%, transparent);
        }
        .docs-layout {
            display: grid;
            grid-template-columns: minmax(0, 210px) minmax(0, 1fr);
            gap: 1.5rem;
            margin-top: 1.5rem;
            align-items: start;
        }
        .docs-nav {
            position: sticky;
            top: 1.5rem;
            padding: .8rem;
            border: 1px solid var(--docs-line);
            border-radius: 1rem;
            background: var(--docs-panel);
        }
        .docs-nav a {
            display: flex;
            align-items: center;
            gap: .65rem;
            padding: .65rem .7rem;
            border-radius: .65rem;
            color: var(--docs-muted);
            font-size: .82rem;
            font-weight: 650;
            text-decoration: none;
            transition: .18s ease;
        }
        .docs-nav a:hover {
            background: color-mix(in srgb, var(--docs-amber) 10%, transparent);
            color: var(--docs-ink);
            transform: translateX(3px);
        }
        .docs-nav span {
            color: var(--docs-amber);
            font: 700 .67rem/1 ui-monospace, SFMono-Regular, Menlo, monospace;
        }
        .docs-content {
            display: grid;
            gap: 1.25rem;
            min-width: 0;
        }
        .docs-section {
            scroll-margin-top: 1.5rem;
            padding: clamp(1.1rem, 3vw, 2rem);
            border: 1px solid var(--docs-line);
            border-radius: 1rem;
            background: var(--docs-panel);
            box-shadow: 0 8px 26px rgba(24, 32, 29, .04);
        }
        .docs-section h3 {
            margin-top: .45rem;
            font-family: Georgia, Cambria, "Times New Roman", serif;
            font-size: clamp(1.45rem, 3vw, 2.2rem);
            font-weight: 600;
            letter-spacing: -.025em;
        }
        .docs-section > p {
            margin-top: .65rem;
            color: var(--docs-muted);
            line-height: 1.7;
        }
        .docs-grid {
            display: grid;
            grid-template-columns: repeat(2, minmax(0, 1fr));
            gap: .8rem;
            margin-top: 1rem;
        }
        .docs-card {
            padding: 1rem;
            border: 1px solid var(--docs-line);
            border-radius: .85rem;
            background: var(--docs-paper);
        }
        .docs-card h4 {
            font-weight: 800;
            font-size: .94rem;
        }
        .docs-card p,
        .docs-card li {
            margin-top: .4rem;
            color: var(--docs-muted);
            font-size: .84rem;
            line-height: 1.6;
        }
        .docs-card ul {
            margin-top: .35rem;
            padding-left: 1.05rem;
            list-style: square;
        }
        .docs-code {
            display: inline-block;
            padding: .15rem .38rem;
            border: 1px solid var(--docs-line);
            border-radius: .35rem;
            background: var(--docs-panel);
            font: 650 .76rem/1.4 ui-monospace, SFMono-Regular, Menlo, monospace;
        }
        .docs-flow {
            display: grid;
            grid-template-columns: repeat(7, minmax(112px, 1fr));
            gap: .55rem;
            margin-top: 1rem;
            overflow-x: auto;
            padding: .2rem .15rem .8rem;
        }
        .docs-flow-node {
            position: relative;
            min-height: 112px;
            padding: .85rem;
            border: 1px solid var(--docs-line);
            border-top: 4px solid var(--docs-amber);
            border-radius: .75rem;
            background: var(--docs-paper);
        }
        .docs-flow-node:not(:last-child)::after {
            content: "→";
            position: absolute;
            right: -.52rem;
            top: 42%;
            z-index: 2;
            color: var(--docs-amber);
            font-weight: 900;
        }
        .docs-flow-node strong {
            display: block;
            font-size: .82rem;
        }
        .docs-flow-node small {
            display: block;
            margin-top: .35rem;
            color: var(--docs-muted);
            font-size: .72rem;
            line-height: 1.45;
        }
        .docs-flow-node b {
            display: inline-grid;
            width: 1.45rem;
            height: 1.45rem;
            margin-bottom: .55rem;
            place-items: center;
            border-radius: 999px;
            background: var(--docs-ink);
            color: var(--docs-paper);
            font: 700 .68rem/1 ui-monospace, SFMono-Regular, Menlo, monospace;
        }
        .docs-lanes {
            display: grid;
            gap: .65rem;
            margin-top: 1rem;
        }
        .docs-lane {
            display: grid;
            grid-template-columns: 145px minmax(0, 1fr);
            border: 1px solid var(--docs-line);
            border-radius: .8rem;
            overflow: hidden;
        }
        .docs-lane-label {
            padding: .9rem;
            background: var(--docs-ink);
            color: var(--docs-paper);
            font: 700 .72rem/1.35 ui-monospace, SFMono-Regular, Menlo, monospace;
            letter-spacing: .05em;
            text-transform: uppercase;
        }
        .docs-lane-body {
            display: flex;
            flex-wrap: wrap;
            align-items: center;
            gap: .45rem;
            padding: .75rem;
            background: var(--docs-paper);
        }
        .docs-lane-step {
            padding: .42rem .58rem;
            border: 1px solid var(--docs-line);
            border-radius: .45rem;
            background: var(--docs-panel);
            font-size: .75rem;
            font-weight: 700;
        }
        .docs-lane-arrow { color: var(--docs-amber); font-weight: 900; }
        .docs-table-wrap {
            margin-top: 1rem;
            overflow-x: auto;
            border: 1px solid var(--docs-line);
            border-radius: .8rem;
        }
        .docs-table {
            width: 100%;
            min-width: 680px;
            border-collapse: collapse;
            font-size: .82rem;
        }
        .docs-table th,
        .docs-table td {
            padding: .75rem .85rem;
            border-bottom: 1px solid var(--docs-line);
            text-align: left;
            vertical-align: top;
        }
        .docs-table th {
            background: var(--docs-paper);
            font: 700 .7rem/1.2 ui-monospace, SFMono-Regular, Menlo, monospace;
            letter-spacing: .07em;
            text-transform: uppercase;
        }
        .docs-table td { color: var(--docs-muted); line-height: 1.55; }
        .docs-table tr:last-child td { border-bottom: 0; }
        .docs-steps {
            display: grid;
            gap: .55rem;
            margin-top: 1rem;
            counter-reset: docs-step;
        }
        .docs-step {
            display: grid;
            grid-template-columns: 2.2rem minmax(0, 1fr);
            gap: .75rem;
            align-items: start;
            padding: .8rem;
            border: 1px solid var(--docs-line);
            border-radius: .75rem;
            counter-increment: docs-step;
        }
        .docs-step::before {
            content: counter(docs-step, decimal-leading-zero);
            display: grid;
            width: 2rem;
            height: 2rem;
            place-items: center;
            border-radius: .5rem;
            background: color-mix(in srgb, var(--docs-amber) 14%, transparent);
            color: var(--docs-amber);
            font: 800 .72rem/1 ui-monospace, SFMono-Regular, Menlo, monospace;
        }
        .docs-step strong { display: block; font-size: .88rem; }
        .docs-step p { margin-top: .22rem; color: var(--docs-muted); font-size: .81rem; line-height: 1.55; }
        .docs-callout {
            margin-top: 1rem;
            padding: .9rem 1rem;
            border-left: 4px solid var(--docs-amber);
            background: color-mix(in srgb, var(--docs-amber) 8%, transparent);
            color: var(--docs-muted);
            font-size: .84rem;
            line-height: 1.6;
        }
        .docs-callout strong { color: var(--docs-ink); }
        .docs-decision {
            display: grid;
            grid-template-columns: repeat(4, minmax(0, 1fr));
            gap: .6rem;
            margin-top: 1rem;
        }
        .docs-decision .docs-card { border-top: 3px solid var(--docs-green); }
        @media (max-width: 900px) {
            .docs-layout { grid-template-columns: 1fr; }
            .docs-nav { position: static; display: flex; overflow-x: auto; }
            .docs-nav a { flex: 0 0 auto; }
            .docs-grid, .docs-decision { grid-template-columns: 1fr; }
            .docs-lane { grid-template-columns: 1fr; }
        }
    </style>

    <div class="hub-docs">
        <header class="docs-hero">
            <div class="docs-kicker">Opsifin Print Hub / Internal knowledge base</div>
            <h2>From invoice decision to verified print.</h2>
            <p>
                This handbook explains what each module owns, how data stays isolated, and how an invoice
                moves safely through Opsifin, the Hub, the Windows Agent, and the printer.
            </p>
            <div class="docs-signal-row" aria-label="Platform principles">
                <span class="docs-signal">Internal team only</span>
                <span class="docs-signal">Tenant isolated</span>
                <span class="docs-signal">Append-only timeline</span>
                <span class="docs-signal">Fail closed</span>
            </div>
        </header>

        <div class="docs-layout">
            <nav class="docs-nav" aria-label="Documentation sections">
                <a href="#start"><span>01</span> Start here</a>
                <a href="#modules"><span>02</span> Modules</a>
                <a href="#flow"><span>03</span> End-to-end flow</a>
                <a href="#safety"><span>04</span> Safety model</a>
                <a href="#stack"><span>05</span> Technical stack</a>
                <a href="#guide"><span>06</span> User guide</a>
                <a href="#incidents"><span>07</span> Failure handling</a>
            </nav>

            <main class="docs-content">
                <section class="docs-section" id="start">
                    <div class="docs-eyebrow">01 / Start here</div>
                    <h3>One control plane, four operational stages.</h3>
                    <p>
                        The domain modules remain separate for safety, but daily onboarding can be understood as
                        four stages. This is the simplified mental model for operators.
                    </p>
                    <div class="docs-decision">
                        <article class="docs-card">
                            <h4>1. Trust</h4>
                            <p>Match the tenant Integration Identity and verify the Opsifin Connection.</p>
                        </article>
                        <article class="docs-card">
                            <h4>2. Fleet</h4>
                            <p>Create the Site, pair the Agent PC, and activate the detected Windows printer.</p>
                        </article>
                        <article class="docs-card">
                            <h4>3. Route</h4>
                            <p>Bind one Opsifin queue identity to exactly one destination printer.</p>
                        </article>
                        <article class="docs-card">
                            <h4>4. Operate</h4>
                            <p>Observe Print Jobs, retry failures deliberately, and monitor callbacks.</p>
                        </article>
                    </div>
                    <div class="docs-callout">
                        <strong>Why not merge everything into one record?</strong> Identity, credentials, physical
                        devices, routing, and job history have different lifecycles. Keeping them separate prevents
                        a printer edit from silently changing tenant identity or historical print evidence.
                    </div>
                </section>

                <section class="docs-section" id="modules">
                    <div class="docs-eyebrow">02 / Module reference</div>
                    <h3>What each detail module owns.</h3>
                    <div class="docs-grid">
                        <article class="docs-card">
                            <h4>Integration Identity</h4>
                            <p>A permanent Hub-issued UUID that identifies one tenant. Opsifin's <span class="docs-code">/identity</span> must return the same UUID.</p>
                            <ul><li>Not a password</li><li>Cannot be shared between tenants</li><li>Mismatch blocks operations</li></ul>
                        </article>
                        <article class="docs-card">
                            <h4>Connections</h4>
                            <p>Stores the Opsifin base URL, encrypted REST credentials, API version, active hours, and pull interval.</p>
                            <ul><li>Verify before activation</li><li>Health and pull errors are visible</li><li>One tenant can have multiple sources</li></ul>
                        </article>
                        <article class="docs-card">
                            <h4>Sites & Agents</h4>
                            <p>Sites represent physical locations. An Agent represents one paired Windows PC and reports the printers visible from that PC.</p>
                            <ul><li>Pairing code is single-use</li><li>Heartbeat drives online status</li><li>Re-pairing reuses the same machine identity</li></ul>
                        </article>
                        <article class="docs-card">
                            <h4>Printers</h4>
                            <p>The physical Windows printer destination reported by an Agent. Activation, pause state, and print settings live here.</p>
                            <ul><li>Belongs to one Agent</li><li>Can serve multiple bindings</li><li>Pause keeps jobs queued</li></ul>
                        </article>
                        <article class="docs-card">
                            <h4>Bindings</h4>
                            <p>The explicit routing contract between an Opsifin printer identity and a physical Hub printer.</p>
                            <ul><li>Source name + identifier + version</li><li>Synced through <span class="docs-code">/printerName</span></li><li>Activate only after legacy autoprint stops</li></ul>
                        </article>
                        <article class="docs-card">
                            <h4>Print Jobs</h4>
                            <p>The durable state of one document. Dedupe uses tenant + invoice + item type + invoice version.</p>
                            <ul><li>Owns current state</li><li>Timeline is append-only</li><li>Manual retry creates a new print run, not a new job</li></ul>
                        </article>
                        <article class="docs-card">
                            <h4>Callback Outbox</h4>
                            <p>Reliable delivery of Success or Failed back to Opsifin. Network failures retry with backoff.</p>
                            <ul><li>Separate callback per print run</li><li>Pending old run becomes superseded on retry</li><li>Response remains inspectable</li></ul>
                        </article>
                        <article class="docs-card">
                            <h4>Audit Log</h4>
                            <p>Human configuration changes: who changed what, when, and the safe before/after values.</p>
                            <ul><li>Secrets are redacted</li><li>Tenant scoped</li><li>Complements the machine job timeline</li></ul>
                        </article>
                    </div>
                </section>

                <section class="docs-section" id="flow">
                    <div class="docs-eyebrow">03 / Application flow</div>
                    <h3>End-to-end document journey.</h3>
                    <p>The seven boxes are the critical path. Each arrow represents a controlled hand-off with validation or acknowledgement.</p>
                    <div class="docs-flow" role="img" aria-label="Invoice print flow from Opsifin to callback">
                        <div class="docs-flow-node"><b>01</b><strong>Opsifin queue</strong><small>Invoice becomes eligible for its configured printer identity.</small></div>
                        <div class="docs-flow-node"><b>02</b><strong>Hub pull</strong><small>The active binding calls <span class="docs-code">/getData</span>.</small></div>
                        <div class="docs-flow-node"><b>03</b><strong>Validate</strong><small>Dedupe, tenant match, PDF inspection, hash, and snapshot.</small></div>
                        <div class="docs-flow-node"><b>04</b><strong>Queue</strong><small>Job becomes queued for exactly one destination printer.</small></div>
                        <div class="docs-flow-node"><b>05</b><strong>Agent claim</strong><small>Agent receives a lease, signed PDF URL, and print settings.</small></div>
                        <div class="docs-flow-node"><b>06</b><strong>Windows print</strong><small>Agent renders the PDF and follows the spooler JobId.</small></div>
                        <div class="docs-flow-node"><b>07</b><strong>Callback</strong><small>Hub reports Success or Failed to the matching Opsifin source.</small></div>
                    </div>

                    <div class="docs-lanes" aria-label="System responsibility lanes">
                        <div class="docs-lane">
                            <div class="docs-lane-label">Opsifin main</div>
                            <div class="docs-lane-body"><span class="docs-lane-step">Decide document</span><span class="docs-lane-arrow">→</span><span class="docs-lane-step">Render PDF</span><span class="docs-lane-arrow">→</span><span class="docs-lane-step">Receive final result</span></div>
                        </div>
                        <div class="docs-lane">
                            <div class="docs-lane-label">Print Hub</div>
                            <div class="docs-lane-body"><span class="docs-lane-step">Pull</span><span class="docs-lane-arrow">→</span><span class="docs-lane-step">Validate & dedupe</span><span class="docs-lane-arrow">→</span><span class="docs-lane-step">Route & lease</span><span class="docs-lane-arrow">→</span><span class="docs-lane-step">Timeline & callback</span></div>
                        </div>
                        <div class="docs-lane">
                            <div class="docs-lane-label">Print Agent</div>
                            <div class="docs-lane-body"><span class="docs-lane-step">Heartbeat</span><span class="docs-lane-arrow">→</span><span class="docs-lane-step">Claim</span><span class="docs-lane-arrow">→</span><span class="docs-lane-step">Download</span><span class="docs-lane-arrow">→</span><span class="docs-lane-step">Print events</span></div>
                        </div>
                        <div class="docs-lane">
                            <div class="docs-lane-label">Windows spooler</div>
                            <div class="docs-lane-body"><span class="docs-lane-step">Accept document</span><span class="docs-lane-arrow">→</span><span class="docs-lane-step">JobId status</span><span class="docs-lane-arrow">→</span><span class="docs-lane-step">Complete or error</span></div>
                        </div>
                    </div>
                </section>

                <section class="docs-section" id="safety">
                    <div class="docs-eyebrow">04 / Safety model</div>
                    <h3>How tenant and printer mix-ups are prevented.</h3>
                    <div class="docs-grid">
                        <article class="docs-card"><h4>Tenant context</h4><p>Every business model is tenant-scoped. Without an active tenant context, queries return no business data.</p></article>
                        <article class="docs-card"><h4>Identity verification</h4><p>A Connection cannot operate until the remote Integration ID exactly matches its Hub tenant.</p></article>
                        <article class="docs-card"><h4>Explicit binding</h4><p>Routing never guesses. A binding stores both the source identity and destination printer ID.</p></article>
                        <article class="docs-card"><h4>Fail closed</h4><p>Inactive source, identity mismatch, revoked Agent, inactive printer, or invalid lease stops the operation.</p></article>
                        <article class="docs-card"><h4>Dedupe boundary</h4><p>The tenant is part of the duplicate key, so identical invoice numbers in different clients never collide.</p></article>
                        <article class="docs-card"><h4>Controlled retry</h4><p>Only failed jobs can be retried. A mandatory reason, new print run, event, and audit entry preserve accountability.</p></article>
                    </div>
                    <div class="docs-callout"><strong>Critical cutover rule:</strong> stop the legacy autoprint for the exact printer identity before activating its Hub binding.</div>
                </section>

                <section class="docs-section" id="stack">
                    <div class="docs-eyebrow">05 / Technical document</div>
                    <h3>Technology choices and their purpose.</h3>
                    <div class="docs-table-wrap">
                        <table class="docs-table">
                            <thead><tr><th>Layer</th><th>Technology</th><th>Why it is used</th></tr></thead>
                            <tbody>
                                <tr><td>Hub backend</td><td><span class="docs-code">Laravel 13 / PHP 8.5</span></td><td>Modular domain services, transactions, encryption, scheduler, queues, and a stack familiar to the Opsifin team.</td></tr>
                                <tr><td>Admin UI</td><td><span class="docs-code">Filament 5 / Livewire</span></td><td>Secure internal CRUD and monitoring without maintaining a separate SPA or duplicate API surface.</td></tr>
                                <tr><td>Database</td><td><span class="docs-code">MySQL 9.7 LTS</span></td><td>Durable state, unique dedupe constraints, transactional claims, and operational familiarity.</td></tr>
                                <tr><td>Cache, lock & queue runtime</td><td><span class="docs-code">Redis 8 / Horizon</span></td><td>Redis provides cache and scheduler overlap locks. Horizon processes background PDF refetch requests; source pulls and callbacks still execute synchronously from the scheduler.</td></tr>
                                <tr><td>Agent</td><td><span class="docs-code">.NET 10 Worker Service</span></td><td>Native Windows service lifecycle and direct access to Win32 printer and spooler APIs.</td></tr>
                                <tr><td>Print engine</td><td><span class="docs-code">PDFium + GDI</span></td><td>Vector PDF rendering without relying on a user's default desktop PDF application.</td></tr>
                                <tr><td>Agent API</td><td><span class="docs-code">HTTPS JSON / OpenAPI 3.1</span></td><td>Versioned, testable contract; Agent only makes outbound connections and never receives Opsifin credentials.</td></tr>
                                <tr><td>Deployment</td><td><span class="docs-code">Docker Compose</span></td><td>Repeatable local and server runtime for app, scheduler, Horizon, MySQL, and Redis.</td></tr>
                            </tbody>
                        </table>
                    </div>
                </section>

                <section class="docs-section" id="guide">
                    <div class="docs-eyebrow">06 / User guide</div>
                    <h3>Onboard a tenant safely.</h3>
                    <div class="docs-steps">
                        <div class="docs-step"><div><strong>Create the tenant</strong><p>Use Tenants and confirm its name, code, timezone, and Active status.</p></div></div>
                        <div class="docs-step"><div><strong>Copy the Integration ID</strong><p>Place the Hub UUID in the matching opsifin-main instance. Never reuse it for another client.</p></div></div>
                        <div class="docs-step"><div><strong>Create the Connection</strong><p>Enter the tenant's Opsifin URL and REST credentials. Use API version 2 for the current legacy endpoint.</p></div></div>
                        <div class="docs-step"><div><strong>Verify identity, then activate</strong><p>The remote <span class="docs-code">/identity</span> response must match before pulls or callbacks are allowed.</p></div></div>
                        <div class="docs-step"><div><strong>Create a Site and pair the Agent</strong><p>Generate a single-use pairing code and enroll the correct Windows PC at that physical location.</p></div></div>
                        <div class="docs-step"><div><strong>Review detected printers</strong><p>Activate only the intended Windows printer and confirm its Agent and Site before routing.</p></div></div>
                        <div class="docs-step"><div><strong>Create an inactive Binding</strong><p>Enter the exact source printer name, identifier, and version used by the legacy autoprint.</p></div></div>
                        <div class="docs-step"><div><strong>Stop legacy autoprint</strong><p>Do this for the exact binding identity to remove the duplicate-print risk during cutover.</p></div></div>
                        <div class="docs-step"><div><strong>Activate and observe</strong><p>Activate the Binding, then watch Last pull, Print Jobs, the Agent, and Callback Outbox.</p></div></div>
                    </div>
                </section>

                <section class="docs-section" id="incidents">
                    <div class="docs-eyebrow">07 / Failure handling</div>
                    <h3>Choose the smallest safe recovery.</h3>
                    <div class="docs-table-wrap">
                        <table class="docs-table">
                            <thead><tr><th>Condition</th><th>Action</th><th>Why</th></tr></thead>
                            <tbody>
                                <tr><td>Printer/Agent issue fixed; stored PDF is valid</td><td><strong>Retry printing</strong></td><td>Selects an active destination binding, reuses the evidence already validated by the Hub, and creates a new print run.</td></tr>
                                <tr><td>PDF was regenerated or corrected in Opsifin</td><td><strong>Refetch and retry</strong></td><td>Selects an active destination binding, then queues Horizon to download and validate a fresh snapshot without blocking the operator.</td></tr>
                                <tr><td>Duplicate ignored while original job is active or complete</td><td><strong>No action</strong></td><td>The safety mechanism is preventing the same invoice version from being printed twice.</td></tr>
                                <tr><td>Callback is pending</td><td><strong>Wait or diagnose source availability</strong></td><td>The outbox retries independently; do not create a second job.</td></tr>
                                <tr><td>Simulation remains Printing for about one minute</td><td><strong>Wait for verification</strong></td><td>The Agent intentionally applies the same 60-second post-spool safety window used by physical printers before reporting Completed.</td></tr>
                                <tr><td>Agent PC replaced but same machine identity is restored</td><td><strong>Re-pair</strong></td><td>The Hub reuses the Agent record and keeps printer and job history intact.</td></tr>
                            </tbody>
                        </table>
                    </div>
                    <div class="docs-callout"><strong>Do not delete Print Jobs to recover failures.</strong> Use controlled retry so timeline, documents, attempts, callback evidence, and any destination binding change remain available. A retry destination must stay inside the same tenant and Opsifin connection as the original job.</div>
                </section>
            </main>
        </div>
    </div>
</x-filament-panels::page>
