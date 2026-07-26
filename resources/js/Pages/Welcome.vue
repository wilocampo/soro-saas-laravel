<script setup>
import { Head, Link } from '@inertiajs/vue3';
import { onBeforeUnmount, onMounted, ref } from 'vue';

const props = defineProps({
    canLogin: { type: Boolean },
    canRegister: { type: Boolean },
    laravelVersion: { type: String, required: true },
    phpVersion: { type: String, required: true },
});

const pageEl = ref(null);
const heroEl = ref(null);
const scrolled = ref(false);
const year = new Date().getFullYear();

let raf = 0;
const cleanups = [];
const reduceMotion =
    typeof window !== 'undefined' &&
    window.matchMedia &&
    window.matchMedia('(prefers-reduced-motion: reduce)').matches;

function onPointerMove(e) {
    if (!heroEl.value) return;
    const r = heroEl.value.getBoundingClientRect();
    const mx = (e.clientX - r.left) / r.width - 0.5;
    const my = (e.clientY - r.top) / r.height - 0.5;
    if (raf) return;
    raf = requestAnimationFrame(() => {
        heroEl.value?.style.setProperty('--mx', mx.toFixed(4));
        heroEl.value?.style.setProperty('--my', my.toFixed(4));
        raf = 0;
    });
}

function onPointerLeave() {
    heroEl.value?.style.setProperty('--mx', '0');
    heroEl.value?.style.setProperty('--my', '0');
}

let sraf = 0;
function onScroll() {
    if (sraf) return;
    sraf = requestAnimationFrame(() => {
        const y = window.scrollY;
        scrolled.value = y > 12;
        heroEl.value?.style.setProperty('--scroll', String(y));
        sraf = 0;
    });
}

// Card tilt for the feature/compliance cards.
function bindTilt(el) {
    const move = (e) => {
        const r = el.getBoundingClientRect();
        const px = (e.clientX - r.left) / r.width - 0.5;
        const py = (e.clientY - r.top) / r.height - 0.5;
        el.style.setProperty('--tx', (py * -6).toFixed(2) + 'deg');
        el.style.setProperty('--ty', (px * 6).toFixed(2) + 'deg');
        el.style.setProperty('--gx', (px * 100 + 50).toFixed(1) + '%');
        el.style.setProperty('--gy', (py * 100 + 50).toFixed(1) + '%');
    };
    const leave = () => {
        el.style.setProperty('--tx', '0deg');
        el.style.setProperty('--ty', '0deg');
    };
    el.addEventListener('pointermove', move);
    el.addEventListener('pointerleave', leave);
    cleanups.push(() => {
        el.removeEventListener('pointermove', move);
        el.removeEventListener('pointerleave', leave);
    });
}

onMounted(() => {
    // Scroll-reveal for anything marked .reveal.
    const io = new IntersectionObserver(
        (entries) => {
            for (const en of entries) {
                if (en.isIntersecting) {
                    en.target.classList.add('is-visible');
                    io.unobserve(en.target);
                }
            }
        },
        { threshold: 0.16, rootMargin: '0px 0px -8% 0px' },
    );
    pageEl.value
        ?.querySelectorAll('.reveal')
        .forEach((el) => io.observe(el));
    cleanups.push(() => io.disconnect());

    window.addEventListener('scroll', onScroll, { passive: true });
    cleanups.push(() => window.removeEventListener('scroll', onScroll));
    onScroll();

    if (!reduceMotion) {
        const hero = heroEl.value;
        hero?.addEventListener('pointermove', onPointerMove);
        hero?.addEventListener('pointerleave', onPointerLeave);
        cleanups.push(() => {
            hero?.removeEventListener('pointermove', onPointerMove);
            hero?.removeEventListener('pointerleave', onPointerLeave);
        });
        pageEl.value?.querySelectorAll('.js-tilt').forEach(bindTilt);
    }
});

onBeforeUnmount(() => {
    cleanups.forEach((fn) => fn());
    if (raf) cancelAnimationFrame(raf);
    if (sraf) cancelAnimationFrame(sraf);
});

const features = [
    {
        icon: 'pi pi-book',
        title: "A ledger that can't lie",
        body: 'One journal, one source of truth. Posting is the only way in — and posted entries are immutable. Corrections are reversing entries, never quiet edits.',
    },
    {
        icon: 'pi pi-verified',
        title: 'BIR, to the letter',
        body: 'The Invoice as the single principal VAT document, quarterly 2550Q, EWT accrued at booking, mandatory report headers. Built to the citation — not to a guess.',
    },
    {
        icon: 'pi pi-box',
        title: 'Inventory that ties out',
        body: 'Perpetual COGS at moving weighted average. Stock movements are append-only and reconcile to the general ledger — down to the centavo, every night.',
    },
    {
        icon: 'pi pi-lock',
        title: 'Audit-proof by design',
        body: 'An append-only, hash-chained audit log. Gapless document numbers allocated under lock. Nothing hidden, nothing reset — by law and by design.',
    },
];

const compliance = [
    'The Invoice is the single principal VAT document — goods and services alike.',
    'VAT filed quarterly on BIR Form 2550Q; EWT accrues at the booking date.',
    'Serial numbers are gapless and sequential; voids keep their number, never a gap.',
    'Every report carries the mandatory header and footer: software name + version, TIN + branch, user, timestamp.',
    'Books of accounts export and BIR-faithful PDFs, generated to spec.',
    'Documents are voided, never edited or deleted — the audit trail stays whole.',
];

const steps = [
    {
        n: '01',
        title: 'Set up your books',
        body: 'Chart of accounts, tax profile, opening balances. Guided onboarding gets you to your first balanced entry fast.',
    },
    {
        n: '02',
        title: 'Record with confidence',
        body: 'Invoices, bills, payments, inventory — each one posts a balanced journal entry behind the scenes, automatically.',
    },
    {
        n: '03',
        title: 'File without the fear',
        body: 'Pull 2550Q, books of accounts, and financial statements that already carry the BIR header and footer.',
    },
];

const invariants = [
    { fig: '= 0', label: 'Every entry nets to zero', note: 'Σ debit = Σ credit, enforced at the database.' },
    { fig: '0', label: 'Gaps in any document series', note: 'Numbers allocated under lock, inside the transaction.' },
    { fig: '×100', label: 'Money kept in centavos', note: 'BIGINT integers — never a float, never a rounding drift.' },
    { fig: '∞', label: 'Append-only audit trail', note: 'Hash-chained and re-verified every night.' },
];
</script>

<template>
    <Head title="Soro — Books you can defend">
        <link rel="preconnect" href="https://fonts.bunny.net" />
        <link
            href="https://fonts.bunny.net/css?family=space-grotesk:500,600,700|ibm-plex-mono:400,500,600&display=swap"
            rel="stylesheet"
        />
    </Head>

    <div ref="pageEl" class="soro">
        <div class="grain" aria-hidden="true"></div>

        <!-- Top navigation -->
        <header class="nav" :class="{ 'nav--solid': scrolled }">
            <div class="shell nav__inner">
                <a href="#top" class="brand" aria-label="Soro home">
                    <span class="brand__mark" aria-hidden="true">
                        <svg viewBox="0 0 40 40" width="34" height="34" role="img" aria-label="Soro fox mark">
                            <defs>
                                <linearGradient id="soroTile" x1="0" y1="0" x2="1" y2="1">
                                    <stop offset="0" stop-color="#34d399" />
                                    <stop offset="1" stop-color="#047857" />
                                </linearGradient>
                            </defs>
                            <rect x="1" y="1" width="38" height="38" rx="11" fill="url(#soroTile)" />
                            <!-- fox head -->
                            <path d="M9 11 L20 16 L31 11 L26 24.5 L20 30 L14 24.5 Z" fill="#F4F1E7" />
                            <!-- origami fold, right side in shadow -->
                            <path d="M20 16 L31 11 L26 24.5 L20 30 Z" fill="#E3DCC6" />
                            <!-- inner-ear accents -->
                            <path d="M9 11 L13.5 12.8 L11.7 16 Z" fill="#D8B25A" />
                            <path d="M31 11 L26.5 12.8 L28.3 16 Z" fill="#B8923A" />
                            <!-- eyes -->
                            <path d="M15.2 18.6 L18 20.1" stroke="#0b3b2e" stroke-width="1.7" stroke-linecap="round" />
                            <path d="M24.8 18.6 L22 20.1" stroke="#0b3b2e" stroke-width="1.7" stroke-linecap="round" />
                            <!-- nose -->
                            <path d="M20 27.8 L18.4 25.6 L21.6 25.6 Z" fill="#0b3b2e" />
                        </svg>
                    </span>
                    <span class="brand__word">Soro</span>
                </a>

                <nav class="nav__links" aria-label="Primary">
                    <a href="#ledger">Ledger</a>
                    <a href="#compliance">BIR</a>
                    <a href="#inventory">Inventory</a>
                    <a href="#start">Pricing</a>
                </nav>

                <div class="nav__cta" v-if="canLogin">
                    <template v-if="$page.props.auth?.user">
                        <Link :href="route('dashboard')" class="btn btn--ghost">Dashboard</Link>
                    </template>
                    <template v-else>
                        <Link :href="route('login')" class="btn btn--ghost">Sign in</Link>
                        <Link
                            :href="canRegister ? route('register') : route('login')"
                            class="btn btn--solid"
                            >Start free</Link
                        >
                    </template>
                </div>
            </div>
        </header>

        <!-- Hero -->
        <section id="top" ref="heroEl" class="hero">
            <div class="hero__bg" aria-hidden="true">
                <div class="glow glow--a"></div>
                <div class="glow glow--b"></div>
                <div class="floor"></div>
            </div>

            <div class="shell hero__grid">
                <div class="hero__copy">
                    <p class="eyebrow">Double-entry · BIR-ready · made for PH SMEs</p>
                    <h1 class="hero__title">
                        Books you can <span class="ink-accent">defend.</span>
                    </h1>
                    <p class="hero__sub">
                        Soro is the double-entry accounting system for Philippine
                        SMEs. Every peso lands in a balanced journal entry. Every
                        invoice and receipt is gapless and audit-proof. Every
                        report is BIR-faithful — down to the header, the footer,
                        and the centavo.
                    </p>
                    <div class="hero__actions">
                        <Link
                            :href="
                                $page.props.auth?.user
                                    ? route('dashboard')
                                    : canRegister
                                      ? route('register')
                                      : route('login')
                            "
                            class="btn btn--solid btn--lg"
                        >
                            Start free
                            <i class="pi pi-arrow-right"></i>
                        </Link>
                        <a href="#ledger" class="btn btn--ghost btn--lg">See the ledger</a>
                    </div>
                    <ul class="hero__chips" aria-label="Highlights">
                        <li><i class="pi pi-shield"></i> Append-only audit trail</li>
                        <li><i class="pi pi-hashtag"></i> Gapless serial numbers</li>
                        <li><i class="pi pi-percentage"></i> 2550Q-ready VAT</li>
                    </ul>
                </div>

                <!-- Signature: the balanced ledger -->
                <div class="stage" aria-hidden="true">
                    <div class="stage__inner">
                        <article class="jcard jcard--debit">
                            <header>
                                <span class="tag tag--dr">DR</span>
                                <span class="jcard__ref">JE-000148</span>
                            </header>
                            <p class="jcard__acct">Cash in Bank</p>
                            <p class="jcard__amt">₱ 25,000.00</p>
                        </article>

                        <article class="jcard jcard--credit">
                            <header>
                                <span class="tag tag--cr">CR</span>
                                <span class="jcard__ref">Sales · Output VAT</span>
                            </header>
                            <p class="jcard__acct">Sales — VATable</p>
                            <p class="jcard__amt">₱ 22,321.43</p>
                            <p class="jcard__acct jcard__acct--sub">Output VAT payable</p>
                            <p class="jcard__amt jcard__amt--sub">₱ 2,678.57</p>
                        </article>

                        <div class="axis"></div>

                        <div class="seal">
                            <span class="seal__ring"></span>
                            <span class="seal__check"><i class="pi pi-check"></i></span>
                            <span class="seal__top">Balanced</span>
                            <span class="seal__bot">Σ DR = Σ CR</span>
                        </div>
                    </div>
                </div>
            </div>

            <div class="marquee" aria-hidden="true">
                <div class="marquee__track">
                    <span>RMC 5-2021</span><span>·</span>
                    <span>Form 2550Q</span><span>·</span>
                    <span>ACCN-gated go-live</span><span>·</span>
                    <span>EWT at booking</span><span>·</span>
                    <span>Books of accounts export</span><span>·</span>
                    <span>NIRC Sec. 264</span><span>·</span>
                    <span>RMC 5-2021</span><span>·</span>
                    <span>Form 2550Q</span><span>·</span>
                    <span>ACCN-gated go-live</span><span>·</span>
                    <span>EWT at booking</span><span>·</span>
                    <span>Books of accounts export</span><span>·</span>
                    <span>NIRC Sec. 264</span><span>·</span>
                </div>
            </div>
        </section>

        <!-- Features -->
        <section id="inventory" class="section">
            <div class="shell">
                <p class="eyebrow reveal">Why Soro</p>
                <h2 class="section__title reveal">
                    Correct at the core, before it's convenient on the surface.
                </h2>
                <div class="feature-grid">
                    <article
                        v-for="(f, i) in features"
                        :key="f.title"
                        class="card js-tilt reveal"
                        :style="{ transitionDelay: i * 70 + 'ms' }"
                    >
                        <div class="card__glow"></div>
                        <span class="card__icon"><i :class="f.icon"></i></span>
                        <h3>{{ f.title }}</h3>
                        <p>{{ f.body }}</p>
                    </article>
                </div>
            </div>
        </section>

        <!-- The ledger, made visible (paper section) -->
        <section id="ledger" class="section section--paper">
            <div class="shell ledger-split">
                <div class="ledger-split__copy reveal">
                    <p class="eyebrow eyebrow--dark">The whole point</p>
                    <h2 class="section__title section__title--dark">
                        Every transaction, twice.
                    </h2>
                    <p class="lede">
                        Debits on the left, credits on the right, and a total
                        that always nets to zero. In Soro that isn't a report you
                        run — it's a rule the database refuses to break. If an
                        entry doesn't balance, it doesn't post.
                    </p>
                    <p class="lede lede--muted">
                        Balances are derived from the journal, never edited in
                        place. So the number you file is the number you can trace,
                        line by line, back to the source.
                    </p>
                </div>

                <div class="paper reveal" role="img" aria-label="A balanced journal entry: total debits equal total credits.">
                    <div class="paper__head">
                        <span>Journal Entry</span>
                        <span class="paper__no">JE-000148</span>
                    </div>
                    <table class="jtable">
                        <thead>
                            <tr>
                                <th>Account</th>
                                <th class="num">Debit</th>
                                <th class="num">Credit</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr>
                                <td>Cash in Bank</td>
                                <td class="num">25,000.00</td>
                                <td class="num">—</td>
                            </tr>
                            <tr>
                                <td>Sales — VATable</td>
                                <td class="num">—</td>
                                <td class="num">22,321.43</td>
                            </tr>
                            <tr>
                                <td>Output VAT Payable</td>
                                <td class="num">—</td>
                                <td class="num">2,678.57</td>
                            </tr>
                        </tbody>
                        <tfoot>
                            <tr>
                                <td>Total</td>
                                <td class="num">25,000.00</td>
                                <td class="num">25,000.00</td>
                            </tr>
                        </tfoot>
                    </table>
                    <div class="paper__foot">
                        <span class="balanced"><i class="pi pi-check-circle"></i> Balanced</span>
                        <span class="paper__meta">Asia/Manila · centavo-exact</span>
                    </div>
                </div>
            </div>
        </section>

        <!-- BIR differentiator -->
        <section id="compliance" class="section">
            <div class="shell compliance">
                <div class="compliance__intro reveal">
                    <p class="eyebrow">The differentiator</p>
                    <h2 class="section__title">Built to the reference. Not to a guess.</h2>
                    <p class="lede lede--onDark">
                        Anything that touches tax, invoicing, books, or the audit
                        trail is built straight from the BIR citations — the same
                        rules an examiner would open the manual to. Here's what
                        that buys you.
                    </p>
                    <p class="disclaimer">
                        Soro implements BIR requirements to spec. Final
                        accreditation — the Acknowledgement Certificate (ACCN) —
                        is issued by the BIR, and Soro gates go-live on it. Soro
                        is accounting software, not tax or legal advice.
                    </p>
                </div>
                <ul class="checklist reveal">
                    <li v-for="item in compliance" :key="item">
                        <span class="tick"><i class="pi pi-check"></i></span>
                        <span>{{ item }}</span>
                    </li>
                </ul>
            </div>
        </section>

        <!-- Process -->
        <section class="section section--tight">
            <div class="shell">
                <p class="eyebrow reveal">From zero to filed</p>
                <h2 class="section__title reveal">Three steps, in order.</h2>
                <div class="steps">
                    <article
                        v-for="(s, i) in steps"
                        :key="s.n"
                        class="step reveal"
                        :style="{ transitionDelay: i * 90 + 'ms' }"
                    >
                        <span class="step__n">{{ s.n }}</span>
                        <h3>{{ s.title }}</h3>
                        <p>{{ s.body }}</p>
                    </article>
                </div>
            </div>
        </section>

        <!-- Invariants -->
        <section class="section section--tight">
            <div class="shell">
                <p class="eyebrow reveal">Guarantees, not features</p>
                <h2 class="section__title reveal">The invariants Soro won't let you break.</h2>
                <div class="invariants">
                    <div
                        v-for="(v, i) in invariants"
                        :key="v.label"
                        class="inv reveal"
                        :style="{ transitionDelay: i * 70 + 'ms' }"
                    >
                        <span class="inv__fig">{{ v.fig }}</span>
                        <span class="inv__label">{{ v.label }}</span>
                        <span class="inv__note">{{ v.note }}</span>
                    </div>
                </div>
            </div>
        </section>

        <!-- Final CTA -->
        <section id="start" class="cta">
            <div class="shell cta__inner reveal">
                <h2>Close your next period with a clear conscience.</h2>
                <p>
                    Start keeping books that balance, trace, and file — the way
                    the BIR expects, without the dread.
                </p>
                <div class="hero__actions hero__actions--center">
                    <Link
                        :href="
                            $page.props.auth?.user
                                ? route('dashboard')
                                : canRegister
                                  ? route('register')
                                  : route('login')
                        "
                        class="btn btn--solid btn--lg"
                    >
                        Start free
                        <i class="pi pi-arrow-right"></i>
                    </Link>
                    <a href="#top" class="btn btn--ghost btn--lg">Back to top</a>
                </div>
            </div>
        </section>

        <footer class="foot">
            <div class="shell foot__inner">
                <div class="foot__brand">
                    <span class="brand__word">Soro</span>
                    <p>Double-entry accounting for Philippine SMEs, built BIR-first.</p>
                </div>
                <div class="foot__meta">
                    <span>Made in the Philippines · Asia/Manila</span>
                    <span>© {{ year }} Soro. Laravel {{ laravelVersion }} · PHP {{ phpVersion }}.</span>
                </div>
            </div>
        </footer>
    </div>
</template>

<style scoped>
/* ---- Tokens (page-scoped, emerald-true to the Sakai theme) ---- */
.soro {
    --ink: #04140e;
    --ink-2: #071f16;
    --panel: rgba(12, 34, 26, 0.72);
    --panel-line: rgba(120, 200, 170, 0.16);
    --em-300: #6ee7b7;
    --em-400: #34d399;
    --em-500: #10b981;
    --em-600: #059669;
    --brass: #d8b25a;
    --brass-deep: #b8923a;
    --paper: #f3f1e7;
    --text: #e9f3ee;
    --muted: #93b0a5;
    --radius: 18px;

    position: relative;
    min-height: 100vh;
    background:
        radial-gradient(120% 90% at 80% -10%, #0a3226 0%, transparent 55%),
        radial-gradient(90% 70% at 0% 0%, #072a20 0%, transparent 50%),
        var(--ink);
    color: var(--text);
    font-family:
        Figtree,
        ui-sans-serif,
        system-ui,
        -apple-system,
        sans-serif;
    -webkit-font-smoothing: antialiased;
    overflow-x: clip;
}

.grain {
    position: fixed;
    inset: 0;
    z-index: 0;
    pointer-events: none;
    opacity: 0.04;
    background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='140' height='140'%3E%3Cfilter id='n'%3E%3CfeTurbulence type='fractalNoise' baseFrequency='0.9' numOctaves='2'/%3E%3C/filter%3E%3Crect width='100%25' height='100%25' filter='url(%23n)'/%3E%3C/svg%3E");
}

.shell {
    width: min(1160px, 92vw);
    margin-inline: auto;
    position: relative;
    z-index: 1;
}

/* ---- Buttons ---- */
.btn {
    display: inline-flex;
    align-items: center;
    gap: 0.5rem;
    font-weight: 600;
    font-size: 0.95rem;
    letter-spacing: 0.01em;
    border-radius: 12px;
    padding: 0.68rem 1.15rem;
    cursor: pointer;
    text-decoration: none;
    border: 1px solid transparent;
    transition:
        transform 0.18s ease,
        box-shadow 0.25s ease,
        background 0.25s ease,
        border-color 0.25s ease;
    white-space: nowrap;
}
.btn--lg {
    padding: 0.9rem 1.5rem;
    font-size: 1rem;
    border-radius: 14px;
}
.btn--solid {
    color: #04140e;
    background: linear-gradient(180deg, var(--em-300), var(--em-500));
    box-shadow:
        0 1px 0 rgba(255, 255, 255, 0.4) inset,
        0 10px 30px -10px rgba(16, 185, 129, 0.6);
}
.btn--solid:hover {
    transform: translateY(-2px);
    box-shadow:
        0 1px 0 rgba(255, 255, 255, 0.5) inset,
        0 16px 40px -12px rgba(16, 185, 129, 0.75);
}
.btn--ghost {
    color: var(--text);
    background: rgba(255, 255, 255, 0.04);
    border-color: var(--panel-line);
    backdrop-filter: blur(6px);
}
.btn--ghost:hover {
    transform: translateY(-2px);
    border-color: rgba(110, 231, 183, 0.5);
    background: rgba(255, 255, 255, 0.07);
}
.btn i {
    font-size: 0.8rem;
}

/* ---- Nav ---- */
.nav {
    position: fixed;
    inset: 0 0 auto 0;
    z-index: 40;
    transition:
        background 0.3s ease,
        border-color 0.3s ease,
        backdrop-filter 0.3s ease;
    border-bottom: 1px solid transparent;
}
.nav--solid {
    background: rgba(4, 20, 14, 0.72);
    backdrop-filter: blur(14px) saturate(1.2);
    border-bottom-color: var(--panel-line);
}
.nav__inner {
    display: flex;
    align-items: center;
    justify-content: space-between;
    height: 70px;
}
.brand {
    display: inline-flex;
    align-items: center;
    gap: 0.6rem;
    text-decoration: none;
    color: var(--text);
}
.brand__mark {
    display: grid;
    place-items: center;
    filter: drop-shadow(0 6px 14px rgba(16, 185, 129, 0.35));
}
.brand__word {
    font-family: 'Space Grotesk', Figtree, sans-serif;
    font-weight: 700;
    font-size: 1.35rem;
    letter-spacing: -0.02em;
}
.nav__links {
    display: none;
    gap: 1.9rem;
}
.nav__links a {
    color: var(--muted);
    text-decoration: none;
    font-size: 0.92rem;
    font-weight: 500;
    transition: color 0.2s ease;
}
.nav__links a:hover {
    color: var(--text);
}
.nav__cta {
    display: flex;
    align-items: center;
    gap: 0.6rem;
}
@media (min-width: 900px) {
    .nav__links {
        display: flex;
    }
}

/* ---- Eyebrow / titles ---- */
.eyebrow {
    font-family: 'IBM Plex Mono', ui-monospace, monospace;
    text-transform: uppercase;
    letter-spacing: 0.22em;
    font-size: 0.72rem;
    font-weight: 500;
    color: var(--em-300);
    margin: 0 0 1rem;
}
.eyebrow--dark {
    color: var(--em-600);
}
.section__title {
    font-family: 'Space Grotesk', Figtree, sans-serif;
    font-weight: 600;
    font-size: clamp(1.7rem, 3.4vw, 2.7rem);
    line-height: 1.08;
    letter-spacing: -0.025em;
    margin: 0 0 2.4rem;
    max-width: 22ch;
    color: #eef7f2;
}
.section__title--dark {
    color: #14261f;
}

/* ---- Hero ---- */
.hero {
    position: relative;
    padding: 130px 0 70px;
    perspective: 1400px;
    overflow: hidden;
}
.hero__bg {
    position: absolute;
    inset: 0;
    z-index: 0;
    pointer-events: none;
}
.glow {
    position: absolute;
    border-radius: 50%;
    filter: blur(80px);
    opacity: 0.6;
    transform: translate3d(
        calc(var(--mx, 0) * 30px),
        calc(var(--my, 0) * 30px + var(--scroll, 0) * 0.06px),
        0
    );
}
.glow--a {
    width: 560px;
    height: 560px;
    top: -120px;
    right: -80px;
    background: radial-gradient(circle, rgba(16, 185, 129, 0.55), transparent 70%);
}
.glow--b {
    width: 420px;
    height: 420px;
    bottom: -120px;
    left: -60px;
    background: radial-gradient(circle, rgba(216, 178, 90, 0.28), transparent 70%);
}
.floor {
    position: absolute;
    inset: auto 0 0 0;
    height: 46%;
    background-image:
        linear-gradient(rgba(110, 231, 183, 0.09) 1px, transparent 1px),
        linear-gradient(90deg, rgba(110, 231, 183, 0.09) 1px, transparent 1px);
    background-size: 46px 46px;
    transform: perspective(500px) rotateX(66deg) translateY(calc(var(--scroll, 0) * 0.04px));
    transform-origin: bottom center;
    -webkit-mask-image: linear-gradient(transparent, #000 60%);
    mask-image: linear-gradient(transparent, #000 60%);
    opacity: 0.7;
}
.hero__grid {
    display: grid;
    grid-template-columns: 1fr;
    gap: 3rem;
    align-items: center;
}
@media (min-width: 980px) {
    .hero__grid {
        grid-template-columns: 1.05fr 0.95fr;
        gap: 2rem;
    }
}
.hero__title {
    font-family: 'Space Grotesk', Figtree, sans-serif;
    font-weight: 700;
    font-size: clamp(2.7rem, 6.4vw, 4.6rem);
    line-height: 0.98;
    letter-spacing: -0.035em;
    margin: 0 0 1.5rem;
    color: #f1f8f4;
}
.ink-accent {
    color: transparent;
    background: linear-gradient(120deg, var(--em-300), var(--brass));
    -webkit-background-clip: text;
    background-clip: text;
}
.hero__sub {
    color: var(--muted);
    font-size: 1.1rem;
    line-height: 1.65;
    max-width: 42ch;
    margin: 0 0 2rem;
}
.hero__actions {
    display: flex;
    flex-wrap: wrap;
    gap: 0.85rem;
    margin-bottom: 2rem;
}
.hero__actions--center {
    justify-content: center;
}
.hero__chips {
    list-style: none;
    margin: 0;
    padding: 0;
    display: flex;
    flex-wrap: wrap;
    gap: 0.6rem 1.4rem;
}
.hero__chips li {
    display: inline-flex;
    align-items: center;
    gap: 0.45rem;
    font-size: 0.85rem;
    color: var(--muted);
    font-family: 'IBM Plex Mono', monospace;
}
.hero__chips i {
    color: var(--em-400);
    font-size: 0.85rem;
}

/* ---- Signature: balanced ledger stage ---- */
.stage {
    position: relative;
    min-height: 400px;
    transform-style: preserve-3d;
}
.stage__inner {
    position: relative;
    height: 100%;
    min-height: 400px;
    transform-style: preserve-3d;
    transform: rotateY(calc(var(--mx, 0) * 14deg)) rotateX(calc(var(--my, 0) * -10deg));
    transition: transform 0.25s ease-out;
}
.jcard {
    position: absolute;
    width: 260px;
    padding: 1.15rem 1.3rem;
    border-radius: var(--radius);
    background: var(--panel);
    border: 1px solid var(--panel-line);
    backdrop-filter: blur(14px);
    box-shadow: 0 30px 60px -24px rgba(0, 0, 0, 0.7);
    transform-style: preserve-3d;
    animation: float 7s ease-in-out infinite;
}
.jcard header {
    display: flex;
    align-items: center;
    justify-content: space-between;
    margin-bottom: 0.7rem;
}
.jcard__ref {
    font-family: 'IBM Plex Mono', monospace;
    font-size: 0.72rem;
    color: var(--muted);
}
.jcard__acct {
    font-size: 0.9rem;
    color: var(--text);
    margin: 0 0 0.15rem;
}
.jcard__acct--sub {
    margin-top: 0.6rem;
    color: var(--muted);
    font-size: 0.82rem;
}
.jcard__amt {
    font-family: 'IBM Plex Mono', monospace;
    font-weight: 600;
    font-size: 1.25rem;
    margin: 0;
    letter-spacing: -0.01em;
}
.jcard__amt--sub {
    font-size: 0.95rem;
    color: var(--em-300);
}
.jcard--debit {
    top: 26px;
    left: 0;
    transform: translateZ(70px);
    z-index: 3;
}
.jcard--credit {
    bottom: 20px;
    right: 0;
    transform: translateZ(30px);
    animation-delay: -3.2s;
    z-index: 2;
}
.tag {
    font-family: 'IBM Plex Mono', monospace;
    font-weight: 600;
    font-size: 0.72rem;
    padding: 0.15rem 0.5rem;
    border-radius: 6px;
}
.tag--dr {
    color: #04140e;
    background: var(--em-300);
}
.tag--cr {
    color: var(--brass);
    background: rgba(216, 178, 90, 0.14);
    border: 1px solid rgba(216, 178, 90, 0.4);
}
.axis {
    position: absolute;
    top: 50%;
    left: 8%;
    right: 8%;
    height: 1px;
    background: linear-gradient(90deg, transparent, rgba(216, 178, 90, 0.6), transparent);
    transform: translateZ(10px);
}
.seal {
    position: absolute;
    top: 50%;
    left: 50%;
    width: 122px;
    height: 122px;
    margin: -61px 0 0 -61px;
    border-radius: 50%;
    display: grid;
    place-items: center;
    text-align: center;
    background: radial-gradient(circle at 40% 35%, #f0d492, var(--brass) 55%, var(--brass-deep));
    box-shadow:
        0 0 0 6px rgba(216, 178, 90, 0.14),
        0 20px 44px -14px rgba(216, 178, 90, 0.6);
    color: #3c2f11;
    transform: translateZ(120px);
    z-index: 5;
    animation: float 7s ease-in-out infinite;
    animation-delay: -1.6s;
}
.seal__ring {
    position: absolute;
    inset: 9px;
    border-radius: 50%;
    border: 1.5px dashed rgba(60, 47, 17, 0.4);
}
.seal__check {
    font-size: 1.15rem;
    line-height: 1;
    margin-bottom: 0.15rem;
}
.seal__top {
    font-family: 'Space Grotesk', sans-serif;
    font-weight: 700;
    font-size: 0.82rem;
    letter-spacing: 0.06em;
    text-transform: uppercase;
}
.seal__bot {
    font-family: 'IBM Plex Mono', monospace;
    font-size: 0.66rem;
    margin-top: 0.1rem;
    opacity: 0.85;
}
@keyframes float {
    0%,
    100% {
        translate: 0 0;
    }
    50% {
        translate: 0 -12px;
    }
}

/* ---- Marquee ---- */
.marquee {
    margin-top: 4rem;
    border-block: 1px solid var(--panel-line);
    padding: 0.9rem 0;
    overflow: hidden;
    -webkit-mask-image: linear-gradient(90deg, transparent, #000 12%, #000 88%, transparent);
    mask-image: linear-gradient(90deg, transparent, #000 12%, #000 88%, transparent);
}
.marquee__track {
    display: inline-flex;
    align-items: center;
    gap: 1.6rem;
    white-space: nowrap;
    font-family: 'IBM Plex Mono', monospace;
    font-size: 0.82rem;
    letter-spacing: 0.05em;
    color: var(--muted);
    animation: slide 26s linear infinite;
}
.marquee__track span:nth-child(even) {
    color: rgba(216, 178, 90, 0.7);
}
@keyframes slide {
    from {
        transform: translateX(0);
    }
    to {
        transform: translateX(-50%);
    }
}

/* ---- Sections ---- */
.section {
    padding: 96px 0;
    position: relative;
}
.section--tight {
    padding: 76px 0;
}
.section--paper {
    background: var(--paper);
    color: #2a352f;
}
.section--paper .section__title {
    color: #14261f;
}

/* ---- Feature grid ---- */
.feature-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(240px, 1fr));
    gap: 1.1rem;
}
.card {
    position: relative;
    padding: 1.7rem 1.6rem;
    border-radius: var(--radius);
    background: linear-gradient(180deg, rgba(255, 255, 255, 0.05), rgba(255, 255, 255, 0.02));
    border: 1px solid var(--panel-line);
    transform-style: preserve-3d;
    transform: perspective(800px) rotateX(var(--tx, 0deg)) rotateY(var(--ty, 0deg));
    transition:
        transform 0.2s ease-out,
        border-color 0.3s ease;
    overflow: hidden;
}
.card:hover {
    border-color: rgba(110, 231, 183, 0.4);
}
.card__glow {
    position: absolute;
    inset: 0;
    opacity: 0;
    transition: opacity 0.3s ease;
    background: radial-gradient(
        260px circle at var(--gx, 50%) var(--gy, 50%),
        rgba(16, 185, 129, 0.16),
        transparent 60%
    );
    pointer-events: none;
}
.card:hover .card__glow {
    opacity: 1;
}
.card__icon {
    display: grid;
    place-items: center;
    width: 46px;
    height: 46px;
    border-radius: 12px;
    background: rgba(16, 185, 129, 0.12);
    border: 1px solid rgba(110, 231, 183, 0.28);
    color: var(--em-300);
    font-size: 1.15rem;
    margin-bottom: 1.1rem;
}
.card h3 {
    font-family: 'Space Grotesk', sans-serif;
    font-weight: 600;
    font-size: 1.15rem;
    letter-spacing: -0.01em;
    margin: 0 0 0.55rem;
    color: #eef7f2;
}
.card p {
    color: var(--muted);
    font-size: 0.94rem;
    line-height: 1.6;
    margin: 0;
}

/* ---- Ledger paper split ---- */
.ledger-split {
    display: grid;
    grid-template-columns: 1fr;
    gap: 3rem;
    align-items: center;
}
@media (min-width: 900px) {
    .ledger-split {
        grid-template-columns: 1fr 1fr;
        gap: 4rem;
    }
}
.lede {
    font-size: 1.08rem;
    line-height: 1.7;
    color: #3b4a43;
    max-width: 46ch;
    margin: 0 0 1.1rem;
}
.lede--muted {
    color: #6a776f;
}
.lede--onDark {
    color: var(--muted);
}
.paper {
    background: #fffdf7;
    border-radius: 14px;
    padding: 1.5rem 1.6rem 1.3rem;
    box-shadow:
        0 1px 0 rgba(0, 0, 0, 0.04),
        0 30px 60px -30px rgba(20, 38, 31, 0.35);
    border: 1px solid #e7e2d2;
    color: #24312b;
}
.paper__head {
    display: flex;
    justify-content: space-between;
    align-items: baseline;
    font-family: 'Space Grotesk', sans-serif;
    font-weight: 600;
    margin-bottom: 1rem;
    padding-bottom: 0.7rem;
    border-bottom: 2px solid #e7e2d2;
}
.paper__no {
    font-family: 'IBM Plex Mono', monospace;
    font-size: 0.8rem;
    color: var(--brass-deep);
}
.jtable {
    width: 100%;
    border-collapse: collapse;
    font-size: 0.92rem;
}
.jtable th,
.jtable td {
    text-align: left;
    padding: 0.5rem 0;
    border-bottom: 1px solid #efeadd;
}
.jtable th {
    font-size: 0.72rem;
    text-transform: uppercase;
    letter-spacing: 0.1em;
    color: #8a9089;
    font-weight: 600;
}
.jtable .num {
    text-align: right;
    font-family: 'IBM Plex Mono', monospace;
    font-variant-numeric: tabular-nums;
}
.jtable tfoot td {
    border-bottom: none;
    border-top: 2px solid #d9d2bd;
    font-weight: 700;
    padding-top: 0.65rem;
}
.paper__foot {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-top: 1rem;
}
.balanced {
    display: inline-flex;
    align-items: center;
    gap: 0.4rem;
    font-weight: 600;
    font-size: 0.9rem;
    color: var(--em-600);
}
.paper__meta {
    font-family: 'IBM Plex Mono', monospace;
    font-size: 0.72rem;
    color: #9aa199;
}

/* ---- Compliance ---- */
.compliance {
    display: grid;
    grid-template-columns: 1fr;
    gap: 3rem;
    align-items: start;
}
@media (min-width: 920px) {
    .compliance {
        grid-template-columns: 0.9fr 1.1fr;
        gap: 4rem;
    }
}
.disclaimer {
    font-size: 0.82rem;
    line-height: 1.55;
    color: #78938a;
    border-left: 2px solid rgba(216, 178, 90, 0.5);
    padding-left: 1rem;
    max-width: 44ch;
}
.checklist {
    list-style: none;
    margin: 0;
    padding: 0;
    display: grid;
    gap: 0.9rem;
}
.checklist li {
    display: flex;
    gap: 0.9rem;
    align-items: flex-start;
    padding: 1rem 1.1rem;
    border-radius: 14px;
    background: rgba(255, 255, 255, 0.03);
    border: 1px solid var(--panel-line);
    font-size: 0.96rem;
    line-height: 1.5;
    color: #cfe0d8;
    transition:
        transform 0.2s ease,
        border-color 0.25s ease;
}
.checklist li:hover {
    transform: translateX(4px);
    border-color: rgba(216, 178, 90, 0.4);
}
.tick {
    flex: none;
    display: grid;
    place-items: center;
    width: 24px;
    height: 24px;
    border-radius: 50%;
    background: rgba(216, 178, 90, 0.16);
    color: var(--brass);
    font-size: 0.72rem;
    margin-top: 0.1rem;
}

/* ---- Steps ---- */
.steps {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(240px, 1fr));
    gap: 1.4rem;
}
.step {
    position: relative;
    padding: 1.6rem 1.5rem;
    border-radius: var(--radius);
    border: 1px solid var(--panel-line);
    background: rgba(255, 255, 255, 0.02);
}
.step__n {
    font-family: 'IBM Plex Mono', monospace;
    font-size: 1.6rem;
    font-weight: 600;
    color: transparent;
    background: linear-gradient(120deg, var(--em-300), var(--brass));
    -webkit-background-clip: text;
    background-clip: text;
    display: block;
    margin-bottom: 0.8rem;
}
.step h3 {
    font-family: 'Space Grotesk', sans-serif;
    font-weight: 600;
    font-size: 1.12rem;
    margin: 0 0 0.5rem;
    color: #eef7f2;
}
.step p {
    color: var(--muted);
    font-size: 0.94rem;
    line-height: 1.6;
    margin: 0;
}

/* ---- Invariants ---- */
.invariants {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(220px, 1fr));
    gap: 1.1rem;
}
.inv {
    padding: 1.6rem 1.5rem;
    border-radius: var(--radius);
    border: 1px solid var(--panel-line);
    background: linear-gradient(180deg, rgba(16, 185, 129, 0.06), transparent);
    display: flex;
    flex-direction: column;
    gap: 0.4rem;
}
.inv__fig {
    font-family: 'IBM Plex Mono', monospace;
    font-weight: 600;
    font-size: 2.4rem;
    line-height: 1;
    color: var(--em-300);
    letter-spacing: -0.02em;
}
.inv__label {
    font-weight: 600;
    font-size: 1rem;
}
.inv__note {
    color: var(--muted);
    font-size: 0.86rem;
    line-height: 1.5;
}

/* ---- CTA ---- */
.cta {
    padding: 110px 0;
    position: relative;
    text-align: center;
    background:
        radial-gradient(70% 120% at 50% 0%, rgba(16, 185, 129, 0.16), transparent 60%),
        var(--ink-2);
    border-top: 1px solid var(--panel-line);
}
.cta__inner h2 {
    font-family: 'Space Grotesk', sans-serif;
    font-weight: 700;
    font-size: clamp(1.9rem, 4vw, 3rem);
    letter-spacing: -0.03em;
    line-height: 1.05;
    margin: 0 auto 1rem;
    max-width: 18ch;
    color: #f1f8f4;
}
.cta__inner p {
    color: var(--muted);
    font-size: 1.1rem;
    line-height: 1.6;
    max-width: 46ch;
    margin: 0 auto 2rem;
}

/* ---- Footer ---- */
.foot {
    padding: 46px 0;
    border-top: 1px solid var(--panel-line);
}
.foot__inner {
    display: flex;
    flex-wrap: wrap;
    justify-content: space-between;
    gap: 1.5rem;
    align-items: flex-end;
}
.foot__brand p {
    color: var(--muted);
    font-size: 0.9rem;
    margin: 0.4rem 0 0;
    max-width: 38ch;
}
.foot__meta {
    display: flex;
    flex-direction: column;
    gap: 0.3rem;
    text-align: right;
    font-family: 'IBM Plex Mono', monospace;
    font-size: 0.78rem;
    color: var(--muted);
}

/* ---- Reveal animation ---- */
.reveal {
    opacity: 0;
    transform: translateY(22px);
    transition:
        opacity 0.7s cubic-bezier(0.2, 0.7, 0.2, 1),
        transform 0.7s cubic-bezier(0.2, 0.7, 0.2, 1);
}
.reveal.is-visible {
    opacity: 1;
    transform: none;
}

@media (max-width: 600px) {
    .foot__meta {
        text-align: left;
    }
    .stage {
        min-height: 340px;
    }
    .jcard {
        width: 220px;
    }
}

@media (prefers-reduced-motion: reduce) {
    .jcard,
    .seal,
    .marquee__track {
        animation: none !important;
    }
    .stage__inner {
        transform: none !important;
    }
    .reveal {
        opacity: 1 !important;
        transform: none !important;
        transition: none !important;
    }
    .btn:hover {
        transform: none !important;
    }
}
</style>
