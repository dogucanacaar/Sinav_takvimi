@props(['title' => 'Çıktı'])

<!DOCTYPE html>
<html lang="tr">
<head>
    <meta charset="utf-8">
    <title>{{ $title }}</title>
    <style>
        /* Yazdırma için ayrı ve bağımsız bir stil: uygulama arayüzünün
           renkleri, gölgeleri ve kenarlıkları kâğıtta okunurluğu düşürür. */
        * { box-sizing: border-box; }

        body {
            font-family: "DejaVu Sans", "Segoe UI", Arial, sans-serif;
            font-size: 11pt;
            color: #000;
            margin: 0;
            padding: 16px;
        }

        h1 { font-size: 15pt; margin: 0 0 2px; }
        h2 { font-size: 12pt; margin: 0 0 6px; }

        .meta { font-size: 9pt; color: #444; margin-bottom: 14px; }

        table { width: 100%; border-collapse: collapse; margin-bottom: 10px; }
        th, td { border: 1px solid #999; padding: 4px 6px; text-align: left; vertical-align: top; }
        th { background: #eee; font-weight: 600; }

        .section { page-break-after: always; }
        .section:last-child { page-break-after: auto; }

        .numbers { column-count: 4; column-gap: 16px; font-size: 10pt; }
        .numbers div { break-inside: avoid; padding: 1px 0; }

        .sign { margin-top: 18px; font-size: 10pt; }
        .sign td { border: none; padding-top: 24px; }

        @media print {
            body { padding: 0; }
            .no-print { display: none; }
        }
    </style>
</head>
<body>
    <div class="no-print" style="margin-bottom:12px">
        <button onclick="window.print()" style="padding:6px 12px">Yazdır</button>
        <span style="font-size:9pt;color:#666">Yazdırma penceresinde "PDF olarak kaydet" seçilebilir.</span>
    </div>

    {{ $slot }}
</body>
</html>
