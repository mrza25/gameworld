<x-layout>

    <div class="py-8 max-w-3xl mx-auto">

        @if (session('success'))
            <div class="alert alert-success mb-6">
                {{ session('success') }}
            </div>
        @endif


        <div class="flex items-center justify-between mb-8">

            <div>

                <h1 class="text-3xl font-bold">
                    Order #{{ $order->id }}
                </h1>

                <p class="text-base-content/60">
                    {{ $order->created_at->format('Y-m-d H:i') }}
                </p>

            </div>

            <div class="badge badge-warning">
                {{ $order->status->value }}
            </div>

        </div>


        {{-- Console --}}

        <div class="card bg-base-100 shadow-xl mb-6">

            <div class="card-body">

                <h2 class="card-title">
                    Console
                </h2>

                <p>
                    {{ $order->console->type }}
                </p>

            </div>

        </div>


        {{-- Games --}}

        <div class="card bg-base-100 shadow-xl">

            <div class="card-body">

                <h2 class="card-title mb-4">
                    Games
                </h2>


                <div class="space-y-3">

                    @foreach ($order->items as $item)
                        <div class="flex justify-between border-b pb-3">

                            <div>

                                <div class="font-semibold">
                                    {{ $item->game->title }}
                                </div>

                                <div class="text-sm opacity-60">
                                    {{ $item->game->platform }}
                                </div>

                            </div>

                            <div>
                                {{ $item->quantity }} ×
                                {{ $item->price }}
                            </div>

                        </div>
                    @endforeach

                </div>


                <div class="divider"></div>


                <div class="flex justify-between text-lg font-bold">

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
