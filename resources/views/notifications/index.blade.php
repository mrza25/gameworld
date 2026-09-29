<x-layout>

    <div class="py-8 max-w-4xl mx-auto">

        <div class="mb-8">

            <h1 class="text-3xl font-bold">
                Notifications
            </h1>

            <p class="text-base-content/60 mt-1">
                Stay updated about your orders.
            </p>

        </div>


        <div class="card bg-base-100 shadow-xl">

            <div class="card-body">

                @forelse ($notifications as $notification)
                    @php
                        $data = $notification->data;

                        $isRead = !is_null($notification->read_at);
                    @endphp


                    <div
                        class="flex items-start justify-between gap-4 p-4 rounded-xl border
                        {{ $isRead ? 'border-base-300' : 'border-primary bg-primary/5' }}">

                        <div class="flex gap-4">

                            {{-- Icon --}}
                            <div class="text-2xl">
                                🔔
                            </div>


                            {{-- Content --}}
                            <div>

                                <p class="font-semibold">
                                    {{ $data['message'] ?? 'You have a new notification.' }}
                                </p>

                                @if (isset($data['order_id']))
                                    <a href="{{ route('orders.show', $data['order_id']) }}"
                                        class="text-sm text-primary hover:underline mt-2 inline-block">
                                        View Order #{{ $data['order_id'] }}
                                    </a>
                                @endif

                                <p class="text-xs text-base-content/50 mt-2">
                                    {{ $notification->created_at->diffForHumans() }}
                                </p>

                            </div>

                        </div>


                        {{-- Mark as read --}}
                        @if (!$isRead)
                            <form method="POST" action="{{ route('notifications.read', $notification->id) }}">

                                @csrf
                                @method('PATCH')

                                <button type="submit" class="btn btn-sm btn-ghost">
                                    Mark as read
                                </button>

                            </form>
                        @endif

                    </div>

                @empty

                    <div class="text-center py-12">

                        <div class="text-4xl mb-4">
                            🔔
                        </div>

                        <p class="font-semibold">
                            No notifications
                        </p>

                        <p class="text-sm text-base-content/60 mt-1">
                            You're all caught up.
                        </p>

                    </div>
                @endforelse

            </div>

        </div>

    </div>

</x-layout>
