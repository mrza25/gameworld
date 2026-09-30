<x-layout>

    <x-slot:title>
        Checkout
    </x-slot:title>

    <div class="py-8">
        @if (session('error'))
            <div class="alert alert-error mb-6">
                <span>
                    {{ session('error') }}
                </span>
            </div>
        @endif

        @if (session('success'))
            <div class="alert alert-success mb-6">
                <span>
                    {{ session('success') }}
                </span>
            </div>
        @endif
        {{-- Header --}}
        <div class="mb-8">

            <h1 class="text-3xl font-bold">
                Checkout
            </h1>

            <p class="text-base-content/60 mt-1">
                Review your order and choose your payment method.
            </p>

        </div>


        <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">

            {{-- Order Details --}}
            <div class="lg:col-span-2">

                <div class="card bg-base-100 shadow-sm">

                    <div class="card-body">

                        <h2 class="card-title mb-4">
                            Order Details
                        </h2>


                        {{-- Console --}}
                        <div class="mb-6">

                            <p class="text-sm text-base-content/60">
                                Console
                            </p>

                            <p class="text-lg font-semibold">
                                {{ $checkout['console_type'] }}
                            </p>

                        </div>


                        {{-- Games --}}
                        <div>

                            <p class="text-sm text-base-content/60 mb-3">
                                Games
                            </p>

                            <div class="space-y-3">

                                @foreach ($games as $game)
                                    <div
                                        class="flex items-center justify-between
                                               border border-base-300
                                               rounded-lg
                                               p-4">

                                        <div>

                                            <p class="font-semibold">
                                                {{ $game->title }}
                                            </p>

                                            <p class="text-sm text-base-content/60">
                                                {{ $game->platform }}
                                            </p>

                                        </div>


                                        <div class="font-semibold">

                                            {{ number_format($game->price, 2) }}

                                        </div>

                                    </div>
                                @endforeach

                            </div>

                        </div>

                    </div>

                </div>

            </div>


            {{-- Payment Summary --}}
            <div>

                <div class="card bg-base-100 shadow-sm">

                    <div class="card-body">

                        <h2 class="card-title">
                            Payment
                        </h2>


                        {{-- Total --}}
                        <div
                            class="flex justify-between
                                   items-center
                                   py-4
                                   border-b
                                   border-base-300">

                            <span class="text-base-content/60">
                                Total
                            </span>

                            <span class="text-2xl font-bold">
                                {{ number_format($total, 2) }}
                            </span>

                        </div>


                        {{-- Payment Methods --}}
                        <div class="mt-6">

                            <p class="font-semibold mb-3">
                                Payment Method
                            </p>


                            <div class="space-y-3">

                                {{-- Wallet --}}
                                <form action="{{ route('checkout.wallet') }}" method="POST">
                                    @csrf

                                    <button type="submit" class="btn btn-primary w-full">
                                        Pay with Wallet
                                    </button>
                                </form>


                                {{-- Gateway --}}
                                <form action="{{ route('checkout.gateway') }}" method="POST">
                                    @csrf

                                    <button type="submit" class="btn btn-outline w-full">
                                        Pay with Gateway
                                    </button>
                                </form>

                            </div>

                        </div>

                    </div>

                </div>

            </div>

        </div>

    </div>

</x-layout>
