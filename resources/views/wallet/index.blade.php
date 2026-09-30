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


        {{-- Balance --}}
        <div class="card bg-base-100 shadow-sm mb-8">

            <div class="card-body">

                <p class="text-sm text-base-content/60">
                    Current Balance
                </p>

                <p class="text-4xl font-bold">
                    {{ number_format($wallet->balance, 2) }}
                </p>

                <div class="mt-4">

                    <button type="button" class="btn btn-primary">
                        Charge Wallet
                    </button>

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
