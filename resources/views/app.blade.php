<!DOCTYPE html>
{{--
    The contestant shell.

    lang="ar" dir="rtl" is set here, once, on the root element. Every Madad
    component below it composes with logical properties (inline-start,
    padding-inline, margin-block) rather than re-flipping a left-to-right
    layout, so the direction is a real document property and not a CSS trick.
--}}
<html lang="ar" dir="rtl">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <meta name="theme-color" content="#14544a">
    <title>مداد الغدير — المسابقة الطلابيّة</title>

    {{-- Arabic faces: a naskh-flavoured display face and a clean UI sans,
         reconstructed from the source screenshots. Both degrade to the
         platform Arabic stack if the CDN is unavailable. --}}
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link
        href="https://fonts.googleapis.com/css2?family=IBM+Plex+Sans+Arabic:wght@400;500;600;700&family=Noto+Naskh+Arabic:wght@500;600;700&display=swap"
        rel="stylesheet"
    >

    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body>
    <a class="madad-skip-link" href="#madad-main">تخطّي إلى المحتوى</a>
    <div id="app"></div>
</body>
</html>
