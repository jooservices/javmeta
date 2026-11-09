<!doctype html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <meta name="robots" content="noindex, nofollow">
  <title>{{ __('auth.title') }}</title>
  <style>
    :root { color-scheme: dark; font-family: system-ui, -apple-system, 'Segoe UI', 'Noto Sans CJK JP', 'Noto Sans CJK', Meiryo, 'Yu Gothic', sans-serif; background: #0d141b; color: #eef4f3; }
    * { box-sizing: border-box; }
    body { margin: 0; min-height: 100vh; display: grid; place-items: center; padding: 24px; background: radial-gradient(circle at 50% 0%, #1c3940 0, #0d141b 52%); }
    main { width: min(100%, 410px); background: #17242b; border: 1px solid #315059; border-radius: 18px; padding: 34px; box-shadow: 0 24px 80px #02080c88; }
    .eyebrow { color: #67cfba; text-transform: uppercase; letter-spacing: .15em; font-size: 11px; font-weight: 800; }
    h1 { margin: 12px 0 7px; font-size: 28px; letter-spacing: -.04em; }
    p { margin: 0 0 28px; color: #9eb2b7; line-height: 1.5; }
    label { display: block; margin: 17px 0 7px; font-size: 13px; font-weight: 700; }
    input { width: 100%; height: 45px; background: #0e1a20; border: 1px solid #38545d; border-radius: 9px; color: #eef4f3; padding: 0 13px; font: inherit; }
    input:focus { outline: 2px solid #67cfba; outline-offset: 1px; }
    .pw { position: relative; }
    .pw input { padding-right: 72px; }
    .pw button { position: absolute; right: 8px; top: 8px; width: auto; height: 29px; margin: 0; padding: 0 10px; font-size: 12px; font-weight: 700; }
    button { width: 100%; height: 46px; margin-top: 25px; border: 0; border-radius: 9px; color: #09241f; background: #67cfba; font: inherit; font-weight: 800; cursor: pointer; }
    button:hover { background: #8ce3d0; }
    .error { padding: 11px 13px; border: 1px solid #a75658; background: #4a242b; border-radius: 8px; color: #ffd8d9; font-size: 13px; }
  </style>
</head>
<body>
<main>
  <div class="eyebrow">XCrawler</div>
  <h1>{{ __('auth.heading') }}</h1>
  <p>{{ __('auth.intro') }}</p>
  @if ($errors->any())
    <div class="error" role="alert">{{ $errors->first() }}</div>
  @endif
  <form method="post" action="{{ route('login', array_filter(['lang' => request()->query('lang')])) }}">
    @csrf
    <label for="email">{{ __('auth.email') }}</label>
    <input id="email" name="email" type="email" value="{{ old('email') }}" autocomplete="username" required autofocus>
    <label for="password">{{ __('auth.password') }}</label>
    <div class="pw">
      <input id="password" name="password" type="password" autocomplete="current-password" required>
      <button type="button" id="pw-toggle" aria-pressed="false" aria-controls="password"
        data-show="{{ __('auth.show') }}" data-hide="{{ __('auth.hide') }}">{{ __('auth.show') }}</button>
    </div>
    <button type="submit">{{ __('auth.submit') }}</button>
  </form>
</main>
<script>
  document.getElementById('pw-toggle').addEventListener('click', function () {
    const input = document.getElementById('password');
    const show = input.type === 'password';
    input.type = show ? 'text' : 'password';
    this.setAttribute('aria-pressed', show ? 'true' : 'false');
    this.textContent = show ? this.dataset.hide : this.dataset.show;
  });
</script>
</body>
</html>
