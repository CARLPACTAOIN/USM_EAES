<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>Attendance Export - {{ $event->title }}</title>
    <style>
        body {
            color: #102018;
            font-family: DejaVu Sans, sans-serif;
            font-size: 10px;
            line-height: 1.35;
        }

        h1, h2, p {
            margin: 0;
        }

        .header {
            border-bottom: 3px solid #0e5d36;
            margin-bottom: 14px;
            padding-bottom: 10px;
        }

        .kicker {
            color: #0e5d36;
            font-size: 9px;
            font-weight: bold;
            text-transform: uppercase;
        }

        .title {
            font-size: 18px;
            margin-top: 3px;
        }

        .meta {
            color: #526258;
            margin-top: 4px;
        }

        .summary {
            margin-bottom: 14px;
            width: 100%;
        }

        .summary td {
            border-bottom: 1px solid #d7e2db;
            padding: 5px 7px;
            vertical-align: top;
        }

        .summary td:first-child {
            color: #526258;
            font-weight: bold;
            width: 24%;
        }

        table.records {
            border-collapse: collapse;
            width: 100%;
        }

        .records th {
            background: #e6f4ea;
            color: #102018;
            font-weight: bold;
            padding: 6px 5px;
            text-align: left;
        }

        .records td {
            border-bottom: 1px solid #e6ece8;
            padding: 5px;
            vertical-align: top;
        }

        .records tr:nth-child(even) td {
            background: #f8fbf8;
        }

        .footer {
            color: #526258;
            font-size: 9px;
            margin-top: 12px;
        }
    </style>
</head>
<body>
    <div class="header">
        <p class="kicker">USM EAES verified attendance export</p>
        <h1 class="title">{{ $event->title }}</h1>
        <p class="meta">
            Generated {{ $generated_at->format('M d, Y h:i A') }} by {{ $generated_by->name }}
        </p>
    </div>

    <table class="summary">
        @foreach($summary_rows as $index => $row)
            @continue($index === 0)
            <tr>
                <td>{{ $row[0] }}</td>
                <td>{{ $row[1] }}</td>
            </tr>
        @endforeach
    </table>

    <h2 style="font-size: 13px; margin-bottom: 7px;">Attendance Records</h2>
    <table class="records">
        <thead>
            <tr>
                @foreach($attendance_rows[0] as $heading)
                    <th>{{ $heading }}</th>
                @endforeach
            </tr>
        </thead>
        <tbody>
            @foreach(array_slice($attendance_rows, 1) as $row)
                <tr>
                    @foreach($row as $cell)
                        <td>{{ $cell }}</td>
                    @endforeach
                </tr>
            @endforeach
        </tbody>
    </table>

    <p class="footer">This export is generated from EAES attendance and evaluation gate records.</p>
</body>
</html>
