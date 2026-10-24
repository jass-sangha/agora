<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Chat</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Figtree:wght@400;500;600;700&display=swap" rel="stylesheet">
    <style>
        body {
            margin: 0;
        }
    </style>
</head>
<body class="chat-page">
    @include('chat::partials.assets', ['chatConfig' => $chatConfig, 'themeOverrideVersion' => $themeOverrideVersion, 'iconOverrideVersion' => $iconOverrideVersion, 'wallpaperOverrideVersion' => $wallpaperOverrideVersion])
</body>
</html>
 