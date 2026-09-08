<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="color-scheme" content="light">
    <title>@yield('title', 'School System')</title>
    <style>
        :root{--brown:#725752;--sage:#878e88;--mint:#96c0b7;--pale:#d4dfc7;--cream:#fef6c9;--ink:#302b29;--muted:#605c56;--paper:#fffdf6}
        *{box-sizing:border-box}body{margin:0;background:var(--paper);color:var(--ink);font-family:system-ui,-apple-system,BlinkMacSystemFont,"Segoe UI",sans-serif}
        a{color:var(--brown)}button,input{font:inherit}a,button,input{touch-action:manipulation}
        :focus-visible{outline:3px solid var(--brown);outline-offset:4px}
        .shell{max-width:1200px;margin:auto;padding:0 32px}.topbar{display:flex;justify-content:space-between;align-items:center;gap:20px;padding:25px 0;border-bottom:1px solid #72575225}
        .brand{text-decoration:none;font-size:20px;font-weight:750;letter-spacing:-.7px;display:flex;align-items:center;gap:12px}.brand-mark{display:grid;place-items:center;width:38px;height:38px;border-radius:12px;background:var(--brown);color:var(--cream);font-family:Georgia,serif;font-size:25px}
        nav{display:flex;align-items:center;gap:20px;flex-wrap:wrap}nav a{text-decoration:none;font-size:14px}nav form{margin:0}
        .button{display:inline-flex;align-items:center;justify-content:center;border:1px solid var(--brown);border-radius:10px;padding:13px 22px;background:var(--brown);color:white;font-weight:600;text-decoration:none;cursor:pointer;min-height:46px}
        .button:hover{background:#5c4541}.button.secondary{background:transparent;color:var(--brown)}.button.secondary:hover{background:var(--cream)}.button.small{padding:8px 14px;min-height:40px;font-size:14px}
        .eyebrow{font-size:12px;letter-spacing:2px;text-transform:uppercase;font-weight:700;color:var(--brown)}
        h1,h2,h3,p{margin-top:0}h1{font-family:Georgia,serif;font-weight:400;font-size:clamp(38px,5vw,62px);line-height:1.07;letter-spacing:-2px}h2{font-size:22px;letter-spacing:-.6px}p{line-height:1.7;color:var(--muted)}
        .split{display:grid;grid-template-columns:1.1fr 1fr;min-height:660px;margin:42px 0;overflow:hidden;border-radius:24px;border:1px solid #72575225}
        .story{padding:clamp(30px,5vw,66px);background:var(--pale);display:flex;flex-direction:column;justify-content:center;position:relative}.story p{max-width:370px}.story .eyebrow{margin-bottom:24px}
        .art{position:relative;margin-top:25px;height:150px;width:230px}.art span{position:absolute;border-radius:10px}.art .one{left:0;bottom:0;width:80px;height:95px;background:var(--brown)}.art .two{left:90px;bottom:0;width:60px;height:135px;background:var(--mint)}.art .three{left:160px;bottom:0;width:60px;height:65px;background:var(--sage)}.art .sun{width:38px;height:38px;border-radius:50%;background:var(--cream);left:18px;top:0}
        .form-panel{padding:clamp(28px,5vw,64px);display:flex;flex-direction:column;justify-content:center;background:white}.form-panel h2{font-size:30px}.helper{font-size:13px;color:var(--muted);line-height:1.6}
        label{display:block;font-size:14px;font-weight:650;margin-bottom:8px}input{display:block;width:100%;min-height:48px;padding:12px 14px;border:1px solid #878e8890;border-radius:9px;background:#fffef9;color:var(--ink)}
        .field{margin-bottom:22px}.full{width:100%}.notice{padding:14px 18px;border-radius:10px;background:var(--cream);margin:20px 0;font-size:14px;line-height:1.6}.errors{background:#f9e7e3;color:#733c34}.errors ul{margin:0;padding-left:20px}
        .page-heading{padding:48px 0 25px;display:flex;justify-content:space-between;align-items:start;gap:20px}.page-heading h1{font-size:44px;margin-bottom:14px}.badge{display:inline-block;border-radius:30px;background:var(--pale);padding:7px 12px;font-size:12px;font-weight:650}
        .grid{display:grid;grid-template-columns:repeat(3,1fr);gap:20px}.card{padding:28px;border:1px solid #72575225;border-radius:16px;background:white}.card p{font-size:14px;margin:0}.card .number{font-family:Georgia,serif;font-size:36px;color:var(--brown);margin:20px 0}
        .banner{padding:30px;background:var(--cream);border-radius:16px;margin:24px 0;display:flex;justify-content:space-between;align-items:center;gap:24px}.banner p{margin:0}.account-form{max-width:560px}
        footer{padding:30px 0;margin-top:30px;font-size:12px;color:var(--muted);border-top:1px solid #72575225}
        @media(max-width:760px){.shell{padding:0 20px}.split{grid-template-columns:1fr;margin:24px 0;min-height:auto}.story{padding:32px}.art{display:none}.story h1{font-size:38px}.story p{margin-bottom:0}.grid{grid-template-columns:1fr}.topbar{align-items:flex-start}nav{gap:12px}.page-heading,.banner{flex-direction:column}.form-panel{padding:30px}.page-heading h1{font-size:36px}}
    </style>
</head>
<body>
<div class="shell">
    <header class="topbar">
        <a href="{{ url('/') }}" class="brand"><span class="brand-mark" aria-hidden="true">s</span>School System</a>
        <nav aria-label="Main navigation">
            @auth
                <a href="{{ route('dashboard') }}">Overview</a>
                <a href="{{ route('account') }}">My account</a>
                <form method="POST" action="{{ route('logout') }}">@csrf<button class="button secondary small" type="submit">Sign out</button></form>
            @else
                <a href="{{ url('/') }}">Home</a>
                @unless(request()->routeIs('login'))<a class="button small" href="{{ route('login') }}">Sign in</a>@endunless
            @endauth
        </nav>
    </header>
    <main>
        @if(session('status'))<div class="notice" role="status">{{ session('status') }}</div>@endif
        @if($errors->any())<div class="notice errors" role="alert"><ul>@foreach($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul></div>@endif
        @yield('content')
    </main>
    <footer>School System &middot; A little more connected, every school day.</footer>
</div>
</body>
</html>