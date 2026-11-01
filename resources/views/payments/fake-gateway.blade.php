<!DOCTYPE html>
<html lang="fa" dir="rtl">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex">
    <title>درگاه پرداخت آزمایشی</title>
    <style>
        :root {
            --bg: #f1f5f9;
            --card: #ffffff;
            --text: #0f172a;
            --muted: #64748b;
            --border: #e2e8f0;
            --primary: #16a34a;
            --primary-hover: #15803d;
            --badge-bg: #fef3c7;
            --badge-text: #92400e;
        }

        @media (prefers-color-scheme: dark) {
            :root {
                --bg: #0b1120;
                --card: #111827;
                --text: #e5e7eb;
                --muted: #9ca3af;
                --border: #1f2937;
                --primary: #16a34a;
                --primary-hover: #15803d;
                --badge-bg: #422006;
                --badge-text: #fcd34d;
            }
        }

        * { box-sizing: border-box; }

        body {
            margin: 0;
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 16px;
            background: var(--bg);
            color: var(--text);
            font-family: Vazirmatn, Tahoma, "Segoe UI", sans-serif;
        }

        .card {
            width: 100%;
            max-width: 420px;
            background: var(--card);
            border: 1px solid var(--border);
            border-radius: 16px;
            padding: 24px;
        }

        .badge {
            display: inline-block;
            padding: 4px 10px;
            border-radius: 999px;
            background: var(--badge-bg);
            color: var(--badge-text);
            font-size: 12px;
        }

        h1 { font-size: 18px; margin: 12px 0 4px; }

        .muted { color: var(--muted); font-size: 13px; margin: 0; }

        .amount { font-size: 28px; font-weight: 700; margin: 24px 0 4px; }

        dl {
            display: grid;
            grid-template-columns: auto 1fr;
            gap: 10px 12px;
            margin: 20px 0 0;
            padding-top: 16px;
            border-top: 1px solid var(--border);
            font-size: 14px;
        }

        dt { color: var(--muted); }

        dd { margin: 0; text-align: left; overflow-wrap: anywhere; }

        dd ul { margin: 0; padding: 0; list-style: none; }

        .ltr { direction: ltr; font-family: Consolas, monospace; font-size: 12px; }

        .notice {
            margin: 24px 0 0;
            padding: 12px;
            border-radius: 10px;
            background: var(--bg);
            font-size: 14px;
        }

        .actions { display: flex; gap: 12px; margin-top: 24px; }

        button {
            flex: 1;
            padding: 12px;
            border-radius: 10px;
            border: 1px solid var(--border);
            background: transparent;
            color: var(--text);
            font: inherit;
            font-size: 15px;
            cursor: pointer;
        }

        button.pay {
            background: var(--primary);
            border-color: var(--primary);
            color: #ffffff;
            font-weight: 700;
        }

        button.pay:hover { background: var(--primary-hover); }
    </style>
</head>
<body>
    <main class="card">
        <span class="badge">محیط آزمایشی؛ هیچ پول واقعی جابه‌جا نمی‌شود</span>
        <h1>درگاه پرداخت آزمایشی</h1>
        <p class="muted">پذیرنده: GameWorld</p>

        <div class="amount">{{ number_format((float) $payment->amount) }} <span class="muted">تومان</span></div>
        <p class="muted">{{ $isWalletCharge ? 'شارژ کیف پول' : 'خرید '.$gameTitles->count().' بازی' }}</p>

        <dl>
            <dt>شماره پرداخت</dt>
            <dd>{{ $payment->id }}</dd>

            <dt>شناسه تراکنش</dt>
            <dd class="ltr">{{ $payment->transaction_id }}</dd>

            @if ($gameTitles->isNotEmpty())
                <dt>بازی‌ها</dt>
                <dd>
                    <ul>
                        @foreach ($gameTitles as $title)
                            <li>{{ $title }}</li>
                        @endforeach
                    </ul>
                </dd>
            @endif
        </dl>

        <form method="POST" action="{{ route('payments.fake-gateway.submit', $payment) }}">
            @csrf
            <input type="hidden" name="transaction_id" value="{{ $payment->transaction_id }}">

            @if ($isPending)
                <div class="actions">
                    <button type="submit" name="result" value="pay" class="pay">پرداخت</button>
                    <button type="submit" name="result" value="cancel">انصراف</button>
                </div>
            @else
                <p class="notice">{{ $isPaid ? 'این پرداخت قبلاً انجام شده است.' : 'این پرداخت لغو شده یا منقضی شده است.' }}</p>
                <div class="actions">
                    <button type="submit" name="result" value="{{ $isPaid ? 'pay' : 'cancel' }}" class="pay">بازگشت به سایت</button>
                </div>
            @endif
        </form>
    </main>
</body>
</html>
