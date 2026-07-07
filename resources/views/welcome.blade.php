<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Momento</title>
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link href="https://fonts.googleapis.com/css2?family=Playfair+Display:wght@900&family=DM+Sans:wght@300;400;500&display=swap" rel="stylesheet">
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        html, body {
            height: 100%;
            font-family: 'DM Sans', sans-serif;
            background: #1A1209;
            background-image:
                radial-gradient(ellipse at 30% 60%, rgba(201,168,76,0.08) 0%, transparent 50%),
                radial-gradient(ellipse at 80% 20%, rgba(201,168,76,0.05) 0%, transparent 40%);
            color: #F5EFE0;
            display: flex;
            align-items: center;
            justify-content: center;
            text-align: center;
            overflow: hidden;
        }
        .wrap {
            padding: 2rem;
            max-width: 480px;
        }
        .logo {
            font-family: 'Playfair Display', serif;
            font-weight: 900;
            font-size: clamp(2.5rem, 6vw, 3.5rem);
            color: #C9A84C;
            letter-spacing: 0.02em;
            margin-bottom: 1.25rem;
        }
        .logo span { color: rgba(201,168,76,0.4); }
        .divider {
            width: 48px;
            height: 2px;
            background: linear-gradient(90deg, #C9A84C, transparent);
            margin: 0 auto 1.75rem;
        }
        .message {
            font-size: 1rem;
            font-weight: 300;
            color: rgba(245,239,224,0.55);
            line-height: 1.7;
        }
        .footnote {
            margin-top: 3rem;
            font-size: 0.7rem;
            letter-spacing: 0.08em;
            text-transform: uppercase;
            color: rgba(245,239,224,0.2);
        }
    </style>
</head>
<body>
    <div class="wrap">
        <div class="logo">Momento<span>.</span></div>
        <div class="divider"></div>
        <p class="message">There's nothing for you to see here.<br>This space is reserved.</p>
    </div>
</body>
</html>