<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>M-Pesa Webhooks Dashboard</title>
    <style>
        *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }
        body {
            font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif;
            background: #f8f9fa; color: #212529; font-size: 14px; line-height: 1.5;
        }
        .header {
            background: #1a1a2e; color: #fff; padding: 16px 24px;
            display: flex; align-items: center; gap: 12px;
        }
        .header h1 { font-size: 18px; font-weight: 600; }
        .header .badge {
            background: #00a651; color: #fff; font-size: 11px;
            padding: 2px 8px; border-radius: 9999px; font-weight: 600;
        }
        .container { max-width: 1400px; margin: 0 auto; padding: 24px; }
        .stats {
            display: grid; grid-template-columns: repeat(auto-fit, minmax(160px, 1fr));
            gap: 16px; margin-bottom: 24px;
        }
        .stat-card {
            background: #fff; border: 1px solid #e9ecef;
            border-radius: 8px; padding: 16px 20px;
        }
        .stat-card .label {
            font-size: 12px; text-transform: uppercase;
            letter-spacing: .06em; color: #6c757d; font-weight: 600;
        }
        .stat-card .value { font-size: 28px; font-weight: 700; margin-top: 4px; }
        .stat-card.processed .value { color: #198754; }
        .stat-card.failed    .value { color: #dc3545; }
        .stat-card.pending   .value { color: #fd7e14; }
        .filters {
            background: #fff; border: 1px solid #e9ecef; border-radius: 8px;
            padding: 16px 20px; margin-bottom: 16px;
            display: flex; gap: 12px; flex-wrap: wrap; align-items: flex-end;
        }
        .filter-group { display: flex; flex-direction: column; gap: 4px; }
        .filter-group label { font-size: 12px; font-weight: 600; color: #495057; }
        .filter-group select, .filter-group input {
            border: 1px solid #ced4da; border-radius: 6px; padding: 6px 10px;
            font-size: 13px; background: #fff; color: #212529; outline: none;
        }
        .filter-group select:focus, .filter-group input:focus {
            border-color: #00a651; box-shadow: 0 0 0 2px rgba(0,166,81,.15);
        }
        .btn {
            display: inline-flex; align-items: center; gap: 6px;
            padding: 7px 14px; border-radius: 6px; font-size: 13px;
            font-weight: 500; border: 1px solid transparent; cursor: pointer; text-decoration: none;
        }
        .btn-primary { background: #00a651; color: #fff; border-color: #00a651; }
        .btn-secondary { background: #fff; color: #495057; border-color: #ced4da; }
        .table-wrapper {
            background: #fff; border: 1px solid #e9ecef;
            border-radius: 8px; overflow: hidden;
        }
        table { width: 100%; border-collapse: collapse; }
        thead tr { background: #f8f9fa; }
        th {
            padding: 10px 16px; text-align: left; font-size: 12px; font-weight: 600;
            color: #6c757d; text-transform: uppercase; letter-spacing: .05em;
            border-bottom: 1px solid #e9ecef; white-space: nowrap;
        }
        td { padding: 10px 16px; border-bottom: 1px solid #f1f3f5; vertical-align: middle; }
        tr:last-child td { border-bottom: none; }
        tr:hover td { background: #f8f9fa; }
        .status {
            display: inline-block; padding: 2px 8px; border-radius: 9999px;
            font-size: 11px; font-weight: 600; text-transform: uppercase; letter-spacing: .04em;
        }
        .status-processed { background: #d1fae5; color: #065f46; }
        .status-failed    { background: #fee2e2; color: #991b1b; }
        .status-pending   { background: #fff3cd; color: #856404; }
        .status-duplicate { background: #e0e7ff; color: #3730a3; }
        .status-rejected  { background: #fce7f3; color: #9d174d; }
        .type-badge {
            font-family: "SF Mono", "Fira Code", monospace; font-size: 11px;
            background: #f1f3f5; border: 1px solid #dee2e6;
            padding: 2px 6px; border-radius: 4px; color: #495057; white-space: nowrap;
        }
        .key-cell {
            font-family: "SF Mono", "Fira Code", monospace; font-size: 11px;
            color: #495057; max-width: 200px; overflow: hidden;
            text-overflow: ellipsis; white-space: nowrap;
        }
        .error-cell {
            max-width: 260px; overflow: hidden; text-overflow: ellipsis;
            white-space: nowrap; color: #dc3545; font-size: 12px;
        }
        .pagination {
            display: flex; justify-content: flex-end;
            padding: 16px 20px; gap: 4px; border-top: 1px solid #e9ecef;
        }
        .empty-state { text-align: center; padding: 60px 20px; color: #6c757d; }
        .empty-state p { font-size: 15px; }
    </style>
</head>
<body>
<div class="header">
    <h1>M-Pesa Webhooks</h1>
    <span class="badge">Dashboard</span>
</div>
<div class="container">
    <div class="stats">
        <div class="stat-card">
            <div class="label">Total</div>
            <div class="value">{{ number_format($stats['total']) }}</div>
        </div>
        <div class="stat-card processed">
            <div class="label">Processed</div>
            <div class="value">{{ number_format($stats['processed']) }}</div>
        </div>
        <div class="stat-card failed">
            <div class="label">Failed</div>
            <div class="value">{{ number_format($stats['failed']) }}</div>
        </div>
        <div class="stat-card pending">
            <div class="label">Pending</div>
            <div class="value">{{ number_format($stats['pending']) }}</div>
        </div>
    </div>
    <form method="GET" action="" class="filters">
        <div class="filter-group">
            <label>Type</label>
            <select name="type">
                <option value="">All types</option>
                <option value="stk_callback" @selected(request('type') === 'stk_callback')>STK Callback</option>
                <option value="c2b_confirmation" @selected(request('type') === 'c2b_confirmation')>C2B Confirmation</option>
                <option value="b2c_result" @selected(request('type') === 'b2c_result')>B2C Result</option>
                <option value="unknown" @selected(request('type') === 'unknown')>Unknown</option>
            </select>
        </div>
        <div class="filter-group">
            <label>Status</label>
            <select name="status">
                <option value="">All statuses</option>
                <option value="processed" @selected(request('status') === 'processed')>Processed</option>
                <option value="failed" @selected(request('status') === 'failed')>Failed</option>
                <option value="pending" @selected(request('status') === 'pending')>Pending</option>
                <option value="duplicate" @selected(request('status') === 'duplicate')>Duplicate</option>
                <option value="rejected" @selected(request('status') === 'rejected')>Rejected</option>
            </select>
        </div>
        <div class="filter-group">
            <label>Search (key / IP)</label>
            <input type="text" name="search" value="{{ request('search') }}" placeholder="ws_CO_... or 196.201.x.x">
        </div>
        <button type="submit" class="btn btn-primary">Filter</button>
        <a href="{{ request()->url() }}" class="btn btn-secondary">Reset</a>
    </form>
    <div class="table-wrapper">
        <table>
            <thead>
                <tr>
                    <th>#</th><th>Type</th><th>Status</th><th>Idempotency Key</th>
                    <th>IP</th><th>Attempts</th><th>Error</th><th>Processed At</th><th>Received At</th>
                </tr>
            </thead>
            <tbody>
                @forelse ($logs as $log)
                <tr>
                    <td>{{ $log->id }}</td>
                    <td><span class="type-badge">{{ $log->type }}</span></td>
                    <td><span class="status status-{{ $log->status }}">{{ $log->status }}</span></td>
                    <td class="key-cell" title="{{ $log->idempotency_key }}">{{ $log->idempotency_key ?? '—' }}</td>
                    <td>{{ $log->ip_address ?? '—' }}</td>
                    <td>{{ $log->attempts }}</td>
                    <td class="error-cell" title="{{ $log->error }}">
                        {{ $log->error ? \Illuminate\Support\Str::limit($log->error, 60) : '—' }}
                    </td>
                    <td>
                        @if ($log->processed_at)
                            <span title="{{ $log->processed_at->toIso8601String() }}">{{ $log->processed_at->diffForHumans() }}</span>
                        @else —
                        @endif
                    </td>
                    <td><span title="{{ $log->created_at->toIso8601String() }}">{{ $log->created_at->diffForHumans() }}</span></td>
                </tr>
                @empty
                <tr><td colspan="9">
                    <div class="empty-state"><p>No webhooks found matching your filters.</p></div>
                </td></tr>
                @endforelse
            </tbody>
        </table>
        @if ($logs->hasPages())
        <div class="pagination">{{ $logs->links() }}</div>
        @endif
    </div>
</div>
</body>
</html>
