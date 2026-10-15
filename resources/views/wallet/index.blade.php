<x-layout>

    <x-slot:title>
        My Wallet
    </x-slot:title>

    <div class="py-8">

        <div class="mb-8">
            <h1 class="text-3xl font-bold">
                My Wallet
            </h1>

            <p class="text-base-content/60 mt-1">
                Manage your wallet balance and transactions.
            </p>
        </div>

        @if (session('success'))
            <div class="alert alert-success mb-6">
                {{ session('success') }}
            </div>
        @endif

        @if (session('error'))
            <div class="alert alert-error mb-6">
                {{ session('error') }}
            </div>
        @endif

        @if ($errors->any())
            <div class="alert alert-error mb-6">
                <ul class="list-disc list-inside">
                    @foreach ($errors->all() as $error)
                        <li>{{ $error }}</li>
                    @endforeach
                </ul>
            </div>
        @endif

        {{-- Balance & Charge Form --}}
        <div class="grid grid-cols-1 lg:grid-cols-3 gap-6 mb-8">
            <div class="card bg-base-100 shadow-sm">
                <div class="card-body">
                    <p class="text-sm text-base-content/60">
                        Current Balance
                    </p>
                    <p class="text-4xl font-bold">
                        {{ number_format($wallet->balance, 2) }}
                    </p>
                    <p class="text-xs text-base-content/50 mt-1">
                        Minimum top-up: 50,000
                    </p>
                </div>
            </div>

            <div class="card bg-base-100 shadow-sm lg:col-span-2">
                <div class="card-body">
                    <h2 class="card-title text-base font-bold mb-2">
                        Top-up Wallet Balance
                    </h2>
                    <form action="{{ route('wallet.charge') }}" method="POST" class="space-y-4">
                        @csrf
                        <div>
                            <label for="amount" class="label">
                                <span class="label-text">Top-up Amount</span>
                            </label>
                            <input
                                type="number"
                                name="amount"
                                id="amount"
                                min="50000"
                                max="50000000"
                                step="1000"
                                placeholder="Enter amount (e.g. 100000)"
                                value="{{ old('amount') }}"
                                class="input input-bordered w-full"
                                required
                            />
                        </div>

                        <div class="flex flex-wrap gap-2">
                            @foreach ([100000, 250000, 500000, 1000000, 2000000, 5000000] as $preset)
                                <button
                                    type="button"
                                    onclick="document.getElementById('amount').value = '{{ $preset }}'"
                                    class="btn btn-sm btn-outline"
                                >
                                    {{ number_format($preset) }}
                                </button>
                            @endforeach
                        </div>

                        <button type="submit" class="btn btn-primary">
                            Proceed to Payment
                        </button>
                    </form>
                </div>
            </div>
        </div>


        {{-- Transactions --}}
        <div class="card bg-base-100 shadow-sm">

            <div class="card-body">

                <h2 class="card-title mb-4">
                    Transaction History
                </h2>

                <div class="overflow-x-auto">

                    <table class="table">

                        <thead>
                            <tr>
                                <th>Date</th>
                                <th>Type</th>
                                <th>Amount</th>
                                <th>Balance After</th>
                                <th>Description</th>
                            </tr>
                        </thead>

                        <tbody>

                            @forelse ($transactions as $transaction)
                                <tr>

                                    <td>
                                        {{ $transaction->created_at->format('Y-m-d H:i') }}
                                    </td>

                                    <td>

                                        @if ($transaction->type === 'credit')
                                            <span class="badge badge-success">
                                                Credit
                                            </span>
                                        @else
                                            <span class="badge badge-error">
                                                Debit
                                            </span>
                                        @endif

                                    </td>

                                    <td>
                                        {{ number_format($transaction->amount, 2) }}
                                    </td>

                                    <td>
                                        {{ number_format($transaction->balance_after, 2) }}
                                    </td>

                                    <td>
                                        {{ $transaction->description ?? '-' }}
                                    </td>

                                </tr>

                            @empty

                                <tr>

                                    <td colspan="5" class="text-center py-10 text-base-content/60">
                                        No transactions yet.
                                    </td>

                                </tr>
                            @endforelse

                        </tbody>

                    </table>

                </div>

            </div>

        </div>

    </div>

</x-layout>
