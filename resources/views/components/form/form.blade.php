@props(['title', 'action', 'method' => 'POST'])

<div class="min-h-screen flex items-center justify-center bg-base-200 px-4">
    <div class="w-full max-w-md">
        <div class="card bg-base-100 shadow-xl">
            <div class="card-body">

                <h2 class="text-2xl font-bold text-center mb-6">
                    {{ $title }}
                </h2>

                <form action="{{ $action }}" method="{{ $method }}" class="space-y-4">
                    {{ $slot }}
                </form>

            </div>
        </div>
    </div>
</div>
