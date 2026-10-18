<x-layout>

    <div class="py-8 max-w-4xl mx-auto">

        {{-- Back --}}
        <div class="mb-8">
            <a href="{{ route('orders.index') }}" class="text-sm text-base-content/60 hover:text-primary transition">
                ← Back to Orders
            </a>
        </div>


        {{-- Success Message --}}
        @if (session('success'))
            <div class="alert alert-success mb-6">
                {{ session('success') }}
            </div>
        @endif


        {{-- Header --}}
        <div class="flex flex-col sm:flex-row sm:items-start sm:justify-between gap-4 mb-8">

            <div>
                <h1 class="text-3xl font-bold">
                    Order #{{ $order->id }}
                </h1>

                <p class="text-base-content/60 mt-1">
                    Created {{ $order->created_at->format('Y-m-d H:i') }}
                </p>
            </div>


            {{-- Order Status --}}
            @php
                $statusClass = match ($order->status->value) {
                    'pending' => 'badge-warning',
                    'processing' => 'badge-info',
                    'completed' => 'badge-success',
                    default => 'badge-neutral',
                };
            @endphp

            <span class="badge {{ $statusClass }} badge-lg">
                {{ ucfirst($order->status->value) }}
            </span>

        </div>


        {{-- Order Information --}}
        <div class="card bg-base-100 shadow-xl mb-6">

            <div class="card-body">

                <h2 class="card-title mb-5">
                    Order Information
                </h2>


                <div class="grid sm:grid-cols-2 gap-6">

                    {{-- Console --}}
                    <div>
                        <p class="text-sm text-base-content/60">
                            Console
                        </p>

                        <p class="font-semibold mt-1">
                            {{ $order->console_type }}
                        </p>
                    </div>


                    {{-- Payment --}}
                    <div>
                        <p class="text-sm text-base-content/60">
                            Payment Status
                        </p>

                        @php
                            $paymentClass = match ($order->payment_status->value) {
                                'paid' => 'badge-success',
                                'partial' => 'badge-warning',
                                'unpaid' => 'badge-error',
                                default => 'badge-neutral',
                            };
                        @endphp

                        <span class="badge {{ $paymentClass }} mt-1">
                            {{ ucfirst($order->payment_status->value) }}
                        </span>
                    </div>


                    {{-- Order Status --}}
                    <div>
                        <p class="text-sm text-base-content/60">
                            Order Status
                        </p>

                        <span class="badge {{ $statusClass }} mt-1">
                            {{ ucfirst($order->status->value) }}
                        </span>
                    </div>


                    {{-- Created At --}}
                    <div>
                        <p class="text-sm text-base-content/60">
                            Order Date
                        </p>

                        <p class="font-semibold mt-1">
                            {{ $order->created_at->format('Y-m-d H:i') }}
                        </p>
                    </div>

                </div>

            </div>

        </div>


        {{-- Games --}}
        <div class="card bg-base-100 shadow-xl">

            <div class="card-body">

                <div class="flex items-center justify-between mb-5">

                    <div>
                        <h2 class="card-title">
                            Games
                        </h2>

                        <p class="text-sm text-base-content/60 mt-1">
                            Installation status of your games.
                        </p>
                    </div>

                </div>


                <div class="space-y-5">

                    @foreach ($order->items as $item)
                        @php
                            $installationStatus = $item->installation_status;

                            $installationClass = match ($installationStatus) {
                                'pending' => 'badge-warning',
                                'installing' => 'badge-info',
                                'installed' => 'badge-success',
                                default => 'badge-neutral',
                            };
                        @endphp


                        <div class="border border-base-300 rounded-xl p-5">

                            <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4">


                                {{-- Game Info --}}
                                <div>

                                    <a href="{{ route('games.show', $item->game) }}"
                                        class="font-semibold text-lg hover:text-primary transition">
                                        {{ $item->game->title }}
                                    </a>

                                    <p class="text-sm text-base-content/60 mt-1">
                                        {{ $item->game->platform }}

                                        @if ($item->game->genre)
                                            · {{ $item->game->genre }}
                                        @endif
                                    </p>

                                    <p class="text-sm text-base-content/60 mt-2">
                                        Quantity: {{ $item->quantity }}
                                    </p>

                                </div>


                                {{-- Installation Status --}}
                                <div class="text-left sm:text-right">

                                    <span class="badge {{ $installationClass }}">
                                        {{ ucfirst($installationStatus) }}
                                    </span>


                                    @if ($installationStatus === 'installed' && $item->installed_at)
                                        <p class="text-sm text-success mt-2">
                                            Installed
                                            {{ $item->installed_at->format('Y-m-d H:i') }}
                                        </p>
                                    @elseif ($installationStatus === 'installing')
                                        <p class="text-sm text-info mt-2">
                                            Your game is currently being installed.
                                        </p>
                                    @elseif ($installationStatus === 'pending')
                                        <p class="text-sm text-base-content/60 mt-2">
                                            Waiting for installation.
                                        </p>
                                    @endif

                                </div>

                            </div>


                            {{-- Price --}}
                            <div class="flex justify-between items-center mt-4 pt-4 border-t border-base-300">

                                <span class="text-sm text-base-content/60">
                                    Price
                                </span>

                                <span class="font-semibold">
                                    {{ $item->quantity }} × {{ $item->price }}
                                </span>

                            </div>

                        </div>
                    @endforeach

                </div>


                <div class="divider"></div>


                {{-- Total --}}
                <div class="flex items-center justify-between text-xl font-bold">

                    <span>
                        Total
                    </span>

                    <span>
                        {{ $order->total }}
                    </span>

                </div>

            </div>

        </div>

    </div>

</x-layout>
