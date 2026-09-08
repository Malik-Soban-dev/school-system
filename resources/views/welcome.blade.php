<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>School System — We're live</title>
    <style>
        * { box-sizing: border-box; }
        body { margin: 0; min-height: 100vh; display: grid; place-items: center; padding: 24px; background: #f0f5f4; color: #16332e; font-family: system-ui, sans-serif; }
        main { width: min(100%, 700px); padding: clamp(28px, 7vw, 64px); background: white; border: 1px solid #dce7e3; border-radius: 24px; box-shadow: 0 20px 60px #16332e0d; }
        .brand { margin: 0 0 48px; font-weight: 750; letter-spacing: -.03em; font-size: 22px; }
        .status { display: inline-flex; align-items: center; gap: 8px; padding: 7px 12px; border-radius: 30px; background: #e5f6ec; color: #17603a; font-size: 14px; font-weight: 600; }
        .dot { width: 8px; height: 8px; border-radius: 50%; background: #23814c; }
        h1 { margin: 22px 0 18px; font-size: clamp(36px, 7vw, 56px); line-height: 1.08; letter-spacing: -.05em; }
        p { color: #526963; font-size: 17px; line-height: 1.7; }
        footer { margin-top: 40px; padding-top: 22px; border-top: 1px solid #e5ece9; font-size: 13px; color: #6a7d77; }
    </style>
</head>
<body>
    <main>
        <div class="brand">School System</div>
        <div class="status"><span class="dot" aria-hidden="true"></span>Website online</div>
        <h1>School System is live.</h1>
        <p>Our new home for school management is taking shape. The website is up and running, and we're building the tools to bring your school together.</p>
        <footer>Launch preview · School-management features are coming next.</footer>
    </main>
</body>
</html>
