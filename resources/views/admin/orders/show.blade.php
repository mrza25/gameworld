@extends('admin.layout.layout')

@section('content')

    <div class="min-h-screen bg-zinc-950 text-white px-6 py-10">

        <div class="max-w-7xl mx-auto">

            {{-- Header --}}
            <div class="flex items-center justify-between mb-8">

                <div>
                    <h1 class="text-3xl font-bold text-white">
                        Order #{{ $order->id }}
                    </h1>

                    <p class="text-zinc-400 mt-2">
                        Manage order details, payment and game installation.
                    </p>
                </div>

                <a href="{{ route('admin.orders.index') }}"
                    class="px-4 py-2 rounded-lg bg-zinc-800 text-zinc-200 hover:bg-zinc-700 transition">
                    ← Back to Orders
                </a>

            </div>


            {{-- Order Information --}}
            <div class="grid grid-cols-1 lg:grid-cols-3 gap-6 mb-8">

                {{-- Customer --}}
                <div class="bg-zinc-900 border border-zinc-800 rounded-xl p-6">

                    <h2 class="text-lg font-semibold text-white mb-5">
                        Customer
                    </h2>

                    <div class="space-y-4">

                        <div>
                            <p class="text-sm text-zinc-500">
                                Name
                            </p>

                            <p class="text-white mt-1">
                                {{ $order->user->name }}
                            </p>
                        </div>

                        <div>
                            <p class="text-sm text-zinc-500">
                                Phone
                            </p>

                            <p class="text-white mt-1">
                                {{ $order->user->phonenumber }}
                            </p>
                        </div>

                    </div>

                </div>


                {{-- Order --}}
                <div class="bg-zinc-900 border border-zinc-800 rounded-xl p-6">

                    <h2 class="text-lg font-semibold text-white mb-5">
                        Order Information
                    </h2>

                    <div class="space-y-4">

                        <div>
                            <p class="text-sm text-zinc-500">
                                Console
                            </p>

                            <p class="text-white mt-1">
                                {{ $order->console_type }}
                            </p>
                        </div>

                        <div>
                            <p class="text-sm text-zinc-500">
                                Total
                            </p>

                            <p class="text-green-400 font-semibold mt-1">
                                {{ number_format($order->total) }}
                            </p>
                        </div>

                        <div>
                            <p class="text-sm text-zinc-500">
                                Payment
                            </p>

                            <p class="text-white mt-1">
                                {{ ucfirst($order->payment_status->value) }}
                            </p>
                        </div>

                    </div>

                </div>


                {{-- Status --}}
                <div class="bg-zinc-900 border border-zinc-800 rounded-xl p-6">

                    <h2 class="text-lg font-semibold text-white mb-5">
                        Order Status
                    </h2>

                    <form method="POST" action="{{ route('admin.orders.status.update', $order) }}" class="space-y-4">

                        @csrf
                        @method('PATCH')

                        <select name="status"
                            class="w-full bg-zinc-950 border border-zinc-700 text-white rounded-lg px-4 py-3 focus:outline-none focus:ring-2 focus:ring-red-600">

                            @foreach (['pending', 'confirmed', 'processing', 'ready', 'completed', 'cancelled'] as $status)
                                <option value="{{ $status }}" @selected($order->status->value === $status)>
                                    {{ ucfirst($status) }}
                                </option>
                            @endforeach

                        </select>

                        <button type="submit"
                            class="w-full bg-red-600 hover:bg-red-500 text-white font-semibold py-3 rounded-lg transition">
                            Update Order Status
                        </button>

                    </form>

                </div>

            </div>


            {{-- Payment Status --}}
            <div class="bg-zinc-900 border border-zinc-800 rounded-xl p-6 mb-8">

                <div class="flex items-center justify-between mb-5">

                    <div>
                        <h2 class="text-lg font-semibold text-white">
                            Payment Status
                        </h2>

                        <p class="text-sm text-zinc-500 mt-1">
                            Update the payment state of this order.
                        </p>
                    </div>

                </div>

                <form method="POST" action="{{ route('admin.orders.payment-status.update', $order) }}"
                    class="flex flex-col sm:flex-row gap-3">

                    @csrf
                    @method('PATCH')

                    <select name="payment_status"
                        class="flex-1 bg-zinc-950 border border-zinc-700 text-white rounded-lg px-4 py-3 focus:outline-none focus:ring-2 focus:ring-red-600">

                        @foreach (['unpaid', 'partial', 'paid'] as $paymentStatus)
                            <option value="{{ $paymentStatus }}" @selected($order->payment_status->value === $paymentStatus)>
                                {{ ucfirst($paymentStatus) }}
                            </option>
                        @endforeach

                    </select>

                    <button type="submit"
                        class="bg-red-600 hover:bg-red-500 text-white font-semibold px-6 py-3 rounded-lg transition">
                        Update Payment
                    </button>

                </form>

            </div>


            {{-- Games --}}
            <div class="bg-zinc-900 border border-zinc-800 rounded-xl overflow-hidden">

                <div class="p-6 border-b border-zinc-800">

                    <h2 class="text-xl font-semibold text-white">
                        Games
                    </h2>

                    <p class="text-sm text-zinc-500 mt-1">
                        Manage installation status for each game.
                    </p>

                </div>


                @forelse($order->items as $item)
                    <div class="p-6 border-b border-zinc-800 last:border-b-0">

                        <div class="flex flex-col lg:flex-row lg:items-center lg:justify-between gap-6">

                            {{-- Game Info --}}
                            <div>

                                <h3 class="text-lg font-semibold text-white">
                                    {{ $item->game->title }}
                                </h3>

                                <div class="flex flex-wrap gap-2 mt-3">

                                    <span class="px-3 py-1 rounded-full bg-zinc-800 text-zinc-300 text-sm">
                                        {{ $item->game->genre }}
                                    </span>

                                    <span class="px-3 py-1 rounded-full bg-zinc-800 text-zinc-300 text-sm">
                                        {{ $item->game->platform }}
                                    </span>

                                    <span class="px-3 py-1 rounded-full bg-zinc-800 text-zinc-300 text-sm">
                                        Quantity: {{ $item->quantity }}
                                    </span>

                                </div>

                            </div>


                            {{-- Installation --}}
                            <div class="w-full lg:w-96">

                                <form method="POST"
                                    action="{{ route('admin.orders.items.installation-status.update', $order) }}"
                                    class="flex flex-col sm:flex-row gap-3">

                                    @csrf
                                    @method('PATCH')

                                    <input type="hidden" name="item_id" value="{{ $item->id }}">

                                    <select name="installation_status"
                                        class="flex-1 bg-zinc-950 border border-zinc-700 text-white rounded-lg px-4 py-3">

                                        @foreach (['pending', 'installing', 'installed'] as $installationStatus)
                                            <option value="{{ $installationStatus }}" @selected($item->installation_status instanceof \BackedEnum ? $item->installation_status->value === $installationStatus : $item->installation_status === $installationStatus)>
                                                {{ ucfirst($installationStatus) }}
                                            </option>
                                        @endforeach

                                    </select>

                                    <button type="submit"
                                        class="bg-red-600 hover:bg-red-500 text-white font-semibold px-5 py-3 rounded-lg transition">
                                        Update
                                    </button>

                                </form>


                                @if ($item->installed_at)
                                    <p class="text-sm text-green-400 mt-3">
                                        Installed:
                                        {{ $item->installed_at->format('Y-m-d H:i') }}
                                    </p>
                                @endif

                            </div>

                        </div>

                    </div>

                @empty

                    <div class="p-10 text-center">

                        <p class="text-zinc-400">
                            No games found in this order.
                        </p>

                    </div>
                @endforelse

            </div>

        </div>

    </div>

@endsection
