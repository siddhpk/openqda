<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <title>Enter access code – {{ config('app.name') }}</title>
    @include('desktop.style')
</head>
<body>
<main class="card">
    <h1>{{ config('app.name') }}</h1>
    <p>This OpenQDA is shared from someone's computer. Enter the access code they gave you.</p>
    <form method="POST" action="{{ url('/share/enter') }}" autocomplete="off">
        @csrf
        <label for="code">Access code</label>
        <input id="code" name="code" type="text" inputmode="text" autocapitalize="characters"
               placeholder="ABC-DEF-GHJ" maxlength="32" required autofocus>
        @if ($error)
            <p class="error" role="alert">{{ $error }}</p>
        @endif
        <button type="submit">Continue</button>
    </form>
    <p class="hint">After this you sign in with your own OpenQDA account, or create one.</p>
</main>
</body>
</html>
