<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <meta name="robots" content="noindex, nofollow">
  <title>Sign in · JavMeta Operations</title>
  <style>
    :root { color-scheme: dark; font-family: Inter, ui-sans-serif, system-ui, sans-serif; background: #0d141b; color: #eef4f3; }
    * { box-sizing: border-box; }
    body { margin: 0; min-height: 100vh; display: grid; place-items: center; padding: 24px; background: radial-gradient(circle at 50% 0%, #1c3940 0, #0d141b 52%); }
    main { width: min(100%, 410px); background: #17242b; border: 1px solid #315059; border-radius: 18px; padding: 34px; box-shadow: 0 24px 80px #02080c88; }
    .eyebrow { color: #67cfba; text-transform: uppercase; letter-spacing: .15em; font-size: 11px; font-weight: 800; }
    h1 { margin: 12px 0 7px; font-size: 28px; letter-spacing: -.04em; }
    p { margin: 0 0 28px; color: #9eb2b7; line-height: 1.5; }
    label { display: block; margin: 17px 0 7px; font-size: 13px; font-weight: 700; }
    input { width: 100%; height: 45px; background: #0e1a20; border: 1px solid #38545d; border-radius: 9px; color: #eef4f3; padding: 0 13px; font: inherit; }
    input:focus { outline: 2px solid #67cfba; outline-offset: 1px; }
    button { width: 100%; height: 46px; margin-top: 25px; border: 0; border-radius: 9px; color: #09241f; background: #67cfba; font: inherit; font-weight: 800; cursor: pointer; }
    button:hover { background: #8ce3d0; }
    .error { padding: 11px 13px; border: 1px solid #a75658; background: #4a242b; border-radius: 8px; color: #ffd8d9; font-size: 13px; }
  </style>
</head>
<body>
<main>
  <div class="eyebrow">JavMeta · Command Center</div>
  <h1>Operations sign in</h1>
  <p>Live crawl health and catalogue progress for the Super Administrator.</p>
  @if ($errors->any())
    <div class="error" role="alert">{{ $errors->first() }}</div>
  @endif
  <form method="post" action="{{ route('login') }}">
    @csrf
    <label for="email">Email</label>
    <input id="email" name="email" type="email" value="{{ old('email', $email) }}" autocomplete="username" required autofocus>
    <label for="password">Password</label>
    <input id="password" name="password" type="password" autocomplete="current-password" required>
    <button type="submit">Sign in</button>
  </form>
</main>
</body>
</html>
