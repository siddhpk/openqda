<style>
    :root { color-scheme: light dark; }
    * { box-sizing: border-box; }
    body { margin: 0; min-height: 100vh; display: grid; place-items: center; padding: 1.5rem;
           font: 16px/1.5 system-ui, -apple-system, "Segoe UI", Roboto, sans-serif;
           background: #f4f6f8; color: #1f2933; }
    .card { width: 100%; max-width: 26rem; background: #fff; border-radius: 12px; padding: 2rem;
            box-shadow: 0 1px 3px rgba(0,0,0,.08), 0 8px 24px rgba(0,0,0,.06); }
    h1 { margin: 0 0 .75rem; font-size: 1.35rem; }
    p { margin: 0 0 1rem; }
    label { display: block; font-weight: 600; margin-bottom: .35rem; }
    input { width: 100%; font: inherit; font-size: 1.25rem; letter-spacing: .12em; text-transform: uppercase;
            padding: .6rem .75rem; border: 1px solid #cbd2d9; border-radius: 8px; margin-bottom: 1rem; }
    input:focus { outline: 2px solid #2f6fdf; outline-offset: 1px; }
    button { width: 100%; font: inherit; font-weight: 600; color: #fff; background: #2f6fdf;
             border: 0; border-radius: 8px; padding: .7rem; cursor: pointer; }
    button:hover { background: #2559b8; }
    .error { color: #b42318; }
    .hint { color: #616e7c; font-size: .9rem; margin: 1rem 0 0; }
    @media (prefers-color-scheme: dark) {
        body { background: #12161b; color: #e4e7eb; }
        .card { background: #1c2229; box-shadow: none; }
        input { background: #12161b; color: inherit; border-color: #3e4c59; }
        .hint { color: #9aa5b1; }
        .error { color: #f97066; }
    }
</style>
