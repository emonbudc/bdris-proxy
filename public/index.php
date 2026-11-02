<?php
declare(strict_types=1);
?><!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>BDRIS &middot; Birth Registration</title>
<meta name="description" content="Apply for birth registration, check status, request correction or reprint through a clean proxied frontend.">
<link rel="stylesheet" href="/assets/app.css">
<link rel="prefetch" href="/site/br/application" as="document">
<link rel="prefetch" href="/site/br/application/status" as="document">
</head>
<body>
<div class="glow" aria-hidden="true"></div>

<header class="topbar">
    <a class="brand" href="/">
        <span class="mark" aria-hidden="true">
            <svg viewBox="0 0 40 40" width="32" height="32" fill="none">
                <rect x="1.5" y="1.5" width="37" height="37" rx="11" stroke="currentColor" stroke-width="2"/>
                <path d="M12 13.5h16M12 20h16M12 26.5h9" stroke="currentColor" stroke-width="2.4" stroke-linecap="round"/>
                <circle cx="28.5" cy="27" r="4" stroke="currentColor" stroke-width="2"/>
            </svg>
        </span>
        <span class="brand-text"><strong>BDRIS</strong><em>Birth</em></span>
    </a>
    <nav class="menu">
        <a href="/site/br/application">Apply</a>
        <a href="/site/br/application/status">Status</a>
        <a href="/site/login">Sign in</a>
        <a class="cta" href="/site/br/application">Start application</a>
    </nav>
</header>

<main>
    <section class="hero">
        <p class="eyebrow">Birth registration &middot; Bangladesh</p>
        <h1>One form.<br>One birth certificate.</h1>
        <p class="sub">Apply for birth registration, check where your application stands, fix a mistake, or pull a fresh copy. Everything runs live against bdris.gov.bd, wrapped in a cleaner frontend.</p>
        <div class="hero-actions">
            <a class="btn" href="/site/br/application">Start birth registration</a>
            <a class="btn ghost" href="/site/br/application/status">Check status</a>
        </div>
        <ul class="trust">
            <li><i></i>Live form, live submission</li>
            <li><i></i>Session cookies stay in your browser</li>
        </ul>
    </section>

    <section class="services">
        <div class="head">
            <h2>Birth registration services</h2>
            <p>Each card opens the official page through this proxy.</p>
        </div>
        <div class="grid">
            <a class="card" href="/site/br/application">
                <span class="icon">
                    <svg viewBox="0 0 24 24" width="22" height="22" fill="none"><path d="M6 3.5h9l4 4V20.5H6V3.5z" stroke="currentColor" stroke-width="1.7" stroke-linejoin="round"/><path d="M14.5 3.5v4.5H19M9 12h6M9 15.5h6M9 8.5h2" stroke="currentColor" stroke-width="1.7" stroke-linecap="round"/></svg>
                </span>
                <h3>New application</h3>
                <p>Register a birth and submit the online application form.</p>
                <span class="arrow">Open form &rarr;</span>
            </a>
            <a class="card" href="/site/br/application/status">
                <span class="icon">
                    <svg viewBox="0 0 24 24" width="22" height="22" fill="none"><circle cx="10.5" cy="10.5" r="6" stroke="currentColor" stroke-width="1.8"/><path d="M15 15l4.5 4.5M8 10.5h5M10.5 8v5" stroke="currentColor" stroke-width="1.8" stroke-linecap="round"/></svg>
                </span>
                <h3>Application status</h3>
                <p>Track an existing birth registration application.</p>
                <span class="arrow">Track &rarr;</span>
            </a>
            <a class="card" href="/site/br/correction">
                <span class="icon">
                    <svg viewBox="0 0 24 24" width="22" height="22" fill="none"><path d="M4 20l3.5-.8L19 7.7a2 2 0 00-2.8-2.8L4.7 16.4 4 20z" stroke="currentColor" stroke-width="1.8" stroke-linejoin="round"/><path d="M14.5 6.5l3 3" stroke="currentColor" stroke-width="1.8"/></svg>
                </span>
                <h3>Correction</h3>
                <p>Fix a name, date or other detail on a certificate.</p>
                <span class="arrow">Correct &rarr;</span>
            </a>
            <a class="card" href="/site/br/reprint/add">
                <span class="icon">
                    <svg viewBox="0 0 24 24" width="22" height="22" fill="none"><path d="M7 9V4h10v5M7 17H5a2 2 0 01-2-2v-4a2 2 0 012-2h14a2 2 0 012 2v4a2 2 0 01-2 2h-2" stroke="currentColor" stroke-width="1.7" stroke-linejoin="round"/><rect x="7" y="14" width="10" height="6.5" stroke="currentColor" stroke-width="1.7"/></svg>
                </span>
                <h3>Reprint</h3>
                <p>Request a duplicate copy of a birth certificate.</p>
                <span class="arrow">Reprint &rarr;</span>
            </a>
            <a class="card" href="/site/login">
                <span class="icon">
                    <svg viewBox="0 0 24 24" width="22" height="22" fill="none"><path d="M14 4h5v16h-5M10 8l4 4-4 4M14 12H3" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"/></svg>
                </span>
                <h3>Sign in</h3>
                <p>Open your dashboard, saved applications and downloads.</p>
                <span class="arrow">Sign in &rarr;</span>
            </a>
            <a class="card" href="/site/user/create">
                <span class="icon">
                    <svg viewBox="0 0 24 24" width="22" height="22" fill="none"><circle cx="9" cy="8" r="3.2" stroke="currentColor" stroke-width="1.8"/><path d="M3.5 19c.6-3 2.8-4.6 5.5-4.6s4.9 1.6 5.5 4.6M18 8v6M15 11h6" stroke="currentColor" stroke-width="1.8" stroke-linecap="round"/></svg>
                </span>
                <h3>Create account</h3>
                <p>First time here? Set up an account before applying.</p>
                <span class="arrow">Register &rarr;</span>
            </a>
        </div>
    </section>

    <section class="strip">
        <div>
            <strong>01</strong>
            <span>Fill the live application form</span>
        </div>
        <div>
            <strong>02</strong>
            <span>Attach documents and submit</span>
        </div>
        <div>
            <strong>03</strong>
            <span>Track it until the certificate lands</span>
        </div>
    </section>

    <section class="warm">
        <div class="head">
            <h2>Cache warmer</h2>
            <p>Preload pages and static assets so proxied forms open faster. Run it after upstream updates.</p>
        </div>
        <div class="warm-actions">
            <button type="button" class="btn" id="warm-btn">Warm cache now</button>
            <span class="warm-state" id="warm-state">idle</span>
        </div>
        <pre class="warm-log" id="warm-log">ready. press warm to start.</pre>
    </section>

    <section class="other">
        <a href="/site/dr/application">Death registration application <span>&rarr;</span></a>
        <a href="/site/">Full official site <span>&rarr;</span></a>
    </section>
</main>

<script>
(function(){
var btn=document.getElementById('warm-btn');
var state=document.getElementById('warm-state');
var log=document.getElementById('warm-log');
if(!btn||!log){return;}
btn.addEventListener('click',function(){
if(btn.disabled){return;}
btn.disabled=true;
state.textContent='running';
log.textContent='';
var started=Date.now();
fetch('/warm',{method:'POST',cache:'no-store'}).then(function(res){
if(!res.ok||!res.body){throw new Error('http '+res.status);}
var reader=res.body.getReader();
var decoder=new TextDecoder();
function pump(){
reader.read().then(function(r){
if(r.done){finish();return;}
log.textContent+=decoder.decode(r.value,{stream:true});
log.scrollTop=log.scrollHeight;
pump();
}).catch(fail);
}
pump();
}).catch(fail);
function finish(){
var s=((Date.now()-started)/1000).toFixed(1);
state.textContent='done in '+s+'s';
btn.disabled=false;
}
function fail(err){
state.textContent='failed';
log.textContent+='\n'+err.message;
btn.disabled=false;
}
});
})();
</script>

<footer>
    <p>Custom frontend over a reverse proxy to <code>bdris.gov.bd</code>. Not affiliated with the official authority. Personal data you enter goes straight to the upstream service.</p>
    <nav>
        <a href="/site/br/application">Apply</a>
        <a href="/site/br/application/status">Status</a>
        <a href="/site/login">Sign in</a>
    </nav>
</footer>
</body>
</html>
