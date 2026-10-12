<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Master Admin</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <style>
        .master-shell { height: 100vh; height: 100dvh; display: flex; flex-direction: column; }
        .master-header { display: grid; grid-template-columns: minmax(0, 1fr) auto; gap: .75rem; padding: .75rem; background: #f8f9fa; border-bottom: 1px solid #dee2e6; }
        .master-title { display: flex; align-items: center; gap: .25rem; min-width: 0; }
        .master-title h1 { font-size: 1.25rem; margin: 0; }
        .master-pills { grid-column: 1 / -1; display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: .5rem; }
        .master-pills[hidden] { display: none; }
        .master-pills .nav-link { text-align: center; min-height: 44px; display: flex; align-items: center; justify-content: center; gap: .5rem; }
        .master-logout { grid-column: 2; grid-row: 1; margin: 0; }
        .master-logout button, .master-login button, .master-login input { min-height: 44px; }
        .master-shell :focus-visible { outline: 3px solid #084298; outline-offset: 3px; }
        .master-content { flex: 1; min-height: 0; min-width: 0; display: flex; position: relative; }
        .master-content iframe { width: 100%; flex: 1; border: 0; min-width: 0; }
        .master-loading { position: absolute; inset: 0; display: flex; align-items: center; justify-content: center; gap: .75rem; background: #fff; color: #084298; }
        .master-loading[hidden], .master-pills .spinner-border[hidden] { display: none; }
        .master-login { width: min(100%, 28rem); margin: auto; padding: 1.25rem; }
        .master-login-region { overflow-y: auto; }
        .admin-help-tooltip { display: inline-flex; align-items: center; justify-content: center; min-width: 44px; min-height: 44px; color: #084298; cursor: help; font-weight: bold; }
        @media (min-width: 576px) { .master-pills { grid-template-columns: repeat(4, minmax(0, 1fr)); } }
        @media (min-width: 992px) {
            .master-header { grid-template-columns: auto minmax(0, 1fr) auto; align-items: center; }
            .master-pills { grid-column: 2; grid-row: 1; width: min(100%, 36rem); justify-self: center; }
            .master-logout { grid-column: 3; }
        }
    </style>
</head>
<body>
<main class="master-shell">
    <header class="master-header">
        <div class="master-title">
            <h1>Master Admin</h1>
            @include('partials.admin_help_tooltip', ['text' => 'Choose Drivers, Kitchen, Tech or RPI to work here. The loading icon stays visible until the selected page has loaded. Only the selected page runs. Logout ends Master Admin access. Existing staff links keep their usual access.'])
        </div>
        @if($authenticated)
            <nav class="nav nav-pills master-pills" aria-label="Admin sections">
                @foreach($pages as $key => $path)
                    <a class="nav-link {{ $section === $key ? 'active' : '' }}" href="{{ route('master_admin.index', ['section' => $key]) }}" data-section="{{ $key }}" @if($section === $key) aria-current="page" @endif>
                        <span>{{ $key === 'rpi' ? 'RPI' : ucfirst($key) }}</span>
                        <span class="spinner-border spinner-border-sm" aria-hidden="true" hidden></span>
                    </a>
                @endforeach
            </nav>
            <form class="master-logout" method="POST" action="{{ route('master_admin.logout') }}">
                @csrf
                <input type="hidden" name="section" value="{{ $section }}">
                <button class="btn btn-outline-secondary" type="submit">Logout</button>
            </form>
        @endif
    </header>
    <div class="master-content {{ $authenticated ? '' : 'master-login-region' }}">
        @if($authenticated)
            {{-- Start blank: the session endpoint must approve the first page too. --}}
            <iframe id="master-panel" title="{{ $section === 'rpi' ? 'RPI' : ucfirst($section) }} admin" allow="camera 'self'"></iframe>
            <div id="master-loading" class="master-loading" role="status" aria-live="polite" hidden>
                <span class="spinner-border" aria-hidden="true"></span>
                <span id="master-loading-label"></span>
            </div>
        @else
            <form class="master-login" method="POST" action="{{ route('master_admin.login') }}">
                @csrf
                <h2 class="h4">Sign in</h2>
                <p>Enter the Master Admin password.</p>
                <input type="hidden" name="section" value="{{ $section }}">
                <label for="master-password" class="form-label">Password</label>
                <input id="master-password" name="password" type="password" class="form-control" autocomplete="current-password" required @if($errors->has('password')) aria-invalid="true" aria-describedby="login-error" @endif>
                @error('password')<p id="login-error" class="text-danger mt-2" role="alert">{{ $message }}</p>@enderror
                <button class="btn btn-primary w-100 mt-3" type="submit">Sign in</button>
            </form>
        @endif
    </div>
</main>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script>
    document.querySelectorAll('[data-bs-toggle="tooltip"]').forEach(element => new bootstrap.Tooltip(element));
</script>
@if($authenticated)
<script>
(() => {
    const pages = @json($pages);
    const panel = document.getElementById('master-panel');
    const logout = document.querySelector('.master-logout');
    const loading = document.getElementById('master-loading');
    const loadingLabel = document.getElementById('master-loading-label');
    let selected;
    let loadingSection = null;
    let validating = false;
    let generation = 0;
    let expiryTimer;
    let ended = false;

    function sectionFromUrl() {
        const value = new URL(location.href).searchParams.get('section');
        return Object.prototype.hasOwnProperty.call(pages, value) ? value : 'drivers';
    }

    function setLoading(section = null) {
        loadingSection = section;
        loading.hidden = section === null;
        loadingLabel.textContent = section ? `Loading ${section === 'rpi' ? 'RPI' : section[0].toUpperCase() + section.slice(1)}…` : '';
        panel.setAttribute('aria-busy', String(section !== null));
        // Hide the previous page while switching so it cannot receive clicks
        // or keyboard input underneath the loading message.
        panel.style.visibility = section ? 'hidden' : '';
        document.querySelectorAll('[data-section]').forEach(link => {
            const busy = link.dataset.section === section;
            link.setAttribute('aria-busy', String(busy));
            link.querySelector('.spinner-border').hidden = !busy;
        });
    }

    function finishLoading() {
        if (ended || !loadingSection || loadingSection !== selected) return;
        // src changes before the iframe finishes navigating. Check its actual
        // document so a late load from the previous page (or about:blank) cannot
        // hide the spinner for the newly requested page.
        try {
            if (panel.contentWindow.location.href === panel.src && panel.contentDocument.readyState === 'complete') {
                setLoading();
            }
        } catch (error) {
            // A browser network-error document may be inaccessible. Let the
            // browser show that error instead of covering it with a spinner.
            setLoading();
        }
    }

    function unload() {
        setLoading();
        panel.src = 'about:blank';
        selected = null;
    }

    function endAccess() {
        ended = true;
        ++generation;
        clearTimeout(expiryTimer);
        unload();
        // Leave a safe, empty shell on network failure instead of repeatedly
        // reloading an authenticated page while the session endpoint is down.
        document.querySelector('.master-pills').hidden = true;
        const message = document.createElement('div');
        message.className = 'master-login';
        message.setAttribute('role', 'alert');
        const text = document.createElement('p');
        text.textContent = 'Master access has ended or could not be verified.';
        const link = document.createElement('a');
        link.className = 'btn btn-primary';
        link.href = @json(route('master_admin.index')).concat('?section=', sectionFromUrl());
        link.textContent = 'Return to sign in';
        message.append(text, link);
        panel.replaceWith(message);
    }

    async function selectSection(section, push = false) {
        if (ended) return;
        const current = ++generation;
        validating = true;
        if (push || selected !== section) setLoading(section);
        // A sequence number prevents slow responses from selecting an older pill.
        // Network failures fail closed, including when returning from another tab.
        const controller = new AbortController();
        const timeout = setTimeout(() => controller.abort(), 10000);
        try {
            const response = await fetch(@json(route('master_admin.session')), {
                credentials: 'same-origin', cache: 'no-store', signal: controller.signal,
                headers: { Accept: 'application/json' }
            });
            if (!response.ok) throw new Error('Session ended');
            const session = await response.json();
            if (current !== generation || ended) return;
            const remaining = session.expires_at * 1000 - Date.now();
            if (!Number.isFinite(remaining) || remaining <= 0) throw new Error('Session expired');
            clearTimeout(expiryTimer);
            expiryTimer = setTimeout(endAccess, Math.min(remaining, 2147483647));
            const url = new URL(location.href);
            url.searchParams.set('section', section);
            if (push && sectionFromUrl() !== section) history.pushState(null, '', url);
            else history.replaceState(null, '', url);
            document.querySelectorAll('[data-section]').forEach(link => {
                const active = link.dataset.section === section;
                link.classList.toggle('active', active);
                if (active) link.setAttribute('aria-current', 'page');
                else link.removeAttribute('aria-current');
            });
            logout.elements.section.value = section;
            if (selected !== section) {
                selected = section;
                panel.title = `${section === 'rpi' ? 'RPI' : section[0].toUpperCase() + section.slice(1)} admin`;
                panel.src = `${pages[section]}?master_frame=1`;
            } else {
                // Re-selecting an already loaded pill finishes immediately;
                // re-selecting one still loading waits for its real load event.
                finishLoading();
            }
        } catch (error) {
            if (current === generation && !ended) endAccess();
        } finally {
            clearTimeout(timeout);
            if (current === generation) validating = false;
        }
    }

    function validateCurrentSection() {
        // Focus and periodic checks must not replace a pill click that is
        // still validating and has not yet updated the URL.
        if (!validating) selectSection(sectionFromUrl());
    }

    panel.addEventListener('load', finishLoading);
    document.querySelectorAll('[data-section]').forEach(link => link.addEventListener('click', event => {
        event.preventDefault();
        selectSection(link.dataset.section, true);
    }));
    logout.addEventListener('submit', () => { ended = true; ++generation; unload(); });
    window.addEventListener('popstate', () => selectSection(sectionFromUrl()));
    window.addEventListener('focus', validateCurrentSection);
    document.addEventListener('visibilitychange', () => {
        if (!document.hidden) validateCurrentSection();
    });
    window.addEventListener('pagehide', () => { ++generation; unload(); });
    window.addEventListener('pageshow', event => {
        // Rebuild from the server after bfcache restoration, including a shell
        // that had already removed its iframe following a validation failure.
        if (event.persisted) location.reload();
    });
    setInterval(validateCurrentSection, 30000);
    selectSection(sectionFromUrl());
})();
</script>
@endif
</body>
</html>
