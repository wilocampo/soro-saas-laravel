{{--
  Print-faithful rendering of any ReportSheet (docs/specs/03 §2).

  The header and footer blocks are NOT decoration: RMC 5-2021 Annex B item 4
  requires registered name, address, TIN with branch code, software name and
  version, the generating user and the timestamp on every generated book,
  financial statement and report.
--}}
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>{{ $sheet->title }}</title>
    <style>
        @page { size: A4 landscape; margin: 12mm; }
        body { font-family: "DejaVu Sans", Arial, sans-serif; font-size: 9pt; color: #111; margin: 0; }
        header { border-bottom: 2px solid #111; padding-bottom: 6px; margin-bottom: 10px; }
        header .name { font-weight: 700; font-size: 11pt; }
        header .title { font-weight: 700; font-size: 12pt; margin-top: 6px; }
        header .sub { color: #555; }
        table { width: 100%; border-collapse: collapse; }
        th, td { border: 1px solid #bbb; padding: 3px 5px; }
        th { background: #f1f1f1; text-align: left; font-size: 8pt; text-transform: uppercase; }
        td.num, th.num { text-align: right; white-space: nowrap; font-variant-numeric: tabular-nums; }
        tr.total td { font-weight: 700; border-top: 2px solid #111; }
        footer { margin-top: 12px; border-top: 1px solid #999; padding-top: 4px; font-size: 7.5pt; color: #555; }
    </style>
</head>
<body>

<header>
    <div class="name">{{ $sheet->header['registered_name'] }}</div>
    <div class="sub">{{ $sheet->header['registered_address'] }}</div>
    <div class="sub">{{ $sheet->header['tin'] }}</div>
    <div class="title">{{ $sheet->title }}</div>
    @if ($sheet->subtitle)
        <div class="sub">{{ $sheet->subtitle }}</div>
    @endif
</header>

<table>
    <thead>
        <tr>
            @foreach ($sheet->columns as $column)
                <th @class(['num' => $column->isNumeric()])>{{ $column->label }}</th>
            @endforeach
        </tr>
    </thead>
    <tbody>
        @foreach ($sheet->rows as $row)
            <tr>
                @foreach ($sheet->columns as $column)
                    <td @class(['num' => $column->isNumeric()])>{{ $column->displayValue($row[$column->key] ?? null) }}</td>
                @endforeach
            </tr>
        @endforeach
    </tbody>
    @if (count($sheet->totals))
        <tfoot>
            @foreach ($sheet->totals as $total)
                <tr class="total">
                    @foreach ($sheet->columns as $column)
                        <td @class(['num' => $column->isNumeric()])>{{ $column->displayValue($total[$column->key] ?? null) }}</td>
                    @endforeach
                </tr>
            @endforeach
        </tfoot>
    @endif
</table>

<footer>
    <div>{{ $sheet->header['registered_name'] }} — {{ $sheet->header['tin'] }}</div>
    @foreach ($sheet->footerLines() as $line)
        <div>{{ $line }}</div>
    @endforeach
</footer>

</body>
</html>
