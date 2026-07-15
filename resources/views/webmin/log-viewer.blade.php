<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <title>Log Viewer · webmin</title>
    <style>
        :root {
            --bg: #0f172a; --panel: #1e293b; --panel2: #172033; --border: #334155;
            --text: #e2e8f0; --muted: #94a3b8; --accent: #38bdf8;
        }
        * { box-sizing: border-box; }
        body { margin: 0; background: var(--bg); color: var(--text);
            font-family: ui-sans-serif, system-ui, -apple-system, Segoe UI, Roboto, sans-serif; font-size: 14px; }
        header { position: sticky; top: 0; z-index: 10; background: var(--panel);
            border-bottom: 1px solid var(--border); padding: 12px 16px; }
        h1 { font-size: 16px; margin: 0 0 10px; display: flex; align-items: center; gap: 8px; }
        h1 .dot { width: 8px; height: 8px; border-radius: 50%; background: var(--accent); }
        form.toolbar { display: flex; flex-wrap: wrap; gap: 8px; align-items: center; }
        select, input[type=text], button, a.btn {
            background: var(--panel2); color: var(--text); border: 1px solid var(--border);
            border-radius: 8px; padding: 7px 10px; font-size: 13px; text-decoration: none; }
        input[type=text] { min-width: 220px; }
        button, a.btn { cursor: pointer; }
        button:hover, a.btn:hover { border-color: var(--accent); }
        .meta { color: var(--muted); font-size: 12px; margin-left: auto; }
        main { padding: 12px 16px; }
        .entry { border: 1px solid var(--border); border-radius: 10px; margin-bottom: 8px;
            background: var(--panel); overflow: hidden; }
        .entry summary { list-style: none; cursor: pointer; padding: 10px 12px; display: flex;
            gap: 10px; align-items: flex-start; }
        .entry summary::-webkit-details-marker { display: none; }
        .entry.no-stack summary { cursor: default; }
        .ts { color: var(--muted); font-variant-numeric: tabular-nums; white-space: nowrap; font-size: 12px; padding-top: 2px; }
        .msg { flex: 1; word-break: break-word; white-space: pre-wrap; font-family: ui-monospace, SFMono-Regular, Menlo, monospace; font-size: 12.5px; }
        .badge { text-transform: uppercase; font-size: 10px; font-weight: 700; letter-spacing: .04em;
            padding: 3px 7px; border-radius: 999px; white-space: nowrap; }
        .lvl-error, .lvl-critical, .lvl-alert, .lvl-emergency { background: #7f1d1d; color: #fecaca; }
        .lvl-warning { background: #78350f; color: #fde68a; }
        .lvl-info, .lvl-notice { background: #0c4a6e; color: #bae6fd; }
        .lvl-debug { background: #334155; color: #cbd5e1; }
        .stack { margin: 0; padding: 12px; background: var(--panel2); border-top: 1px solid var(--border);
            overflow-x: auto; font-family: ui-monospace, SFMono-Regular, Menlo, monospace;
            font-size: 12px; color: var(--muted); white-space: pre; }
        .empty { text-align: center; color: var(--muted); padding: 48px 0; }
        .chan { color: var(--muted); font-size: 11px; }
    </style>
</head>
<body>
    <header>
        <h1><span class="dot"></span> Laravel Log Viewer</h1>
        <form class="toolbar" method="get" action="{{ route('webmin.log-viewer') }}">
            <select name="file" onchange="this.form.submit()">
                @forelse ($files as $f)
                    <option value="{{ $f }}" @selected($f === $file)>{{ $f }}</option>
                @empty
                    <option value="">(no log files)</option>
                @endforelse
            </select>

            <select name="level" onchange="this.form.submit()">
                <option value="">All levels</option>
                @foreach ($levels as $lvl)
                    <option value="{{ $lvl }}" @selected($lvl === $level)>{{ ucfirst($lvl) }}</option>
                @endforeach
            </select>

            <input type="text" name="q" value="{{ $search }}" placeholder="Search…" autocomplete="off">
            <button type="submit">Filter</button>
            <a class="btn" href="{{ route('webmin.log-viewer') }}">Reset</a>
            @if ($file)
                <a class="btn" href="{{ route('webmin.log-viewer.download', ['file' => $file]) }}">Download</a>
            @endif

            <span class="meta">
                {{ number_format($shown) }} / {{ number_format($total) }} entries
                @if ($total > $shown) (newest {{ number_format($shown) }} shown) @endif
            </span>
        </form>
    </header>

    <main>
        @forelse ($entries as $e)
            <details class="entry {{ $e['stack'] === '' ? 'no-stack' : '' }}">
                <summary>
                    <span class="ts">{{ $e['timestamp'] }}</span>
                    <span class="badge lvl-{{ $e['level'] }}">{{ $e['level'] }}</span>
                    <span class="msg">{{ $e['message'] }}<br><span class="chan">{{ $e['channel'] }}</span></span>
                </summary>
                @if ($e['stack'] !== '')
                    <pre class="stack">{{ $e['stack'] }}</pre>
                @endif
            </details>
        @empty
            <div class="empty">
                @if (empty($files))
                    No log files found in storage/logs.
                @else
                    No entries match the current filter.
                @endif
            </div>
        @endforelse
    </main>
</body>
</html>
