<x-layout>
    <div class="py-8 max-w-3xl mx-auto">

        <div class="mb-8">
            <a href="{{ route('orders.index') }}" class="text-sm text-base-content/60 hover:text-primary">
                ← Back to Orders
            </a>

            <h1 class="text-3xl font-bold mt-4">
                Create New Order
            </h1>

            <p class="text-base-content/60 mt-2">
                Choose your console and the game versions you want installed.
            </p>
        </div>

        {{-- Validation Errors --}}
        @if ($errors->any())
            <div class="alert alert-error mb-6">
                <ul class="list-disc list-inside">
                    @foreach ($errors->all() as $error)
                        <li>{{ $error }}</li>
                    @endforeach
                </ul>
            </div>
        @endif

        <div class="card bg-base-100 shadow-xl">
            <div class="card-body">

                <form action="{{ route('orders.store') }}" method="POST" class="space-y-6">

                    @csrf

                    {{-- Console Type --}}
                    <div>
                        <label for="console_type" class="label">
                            <span class="label-text font-semibold">
                                Console
                            </span>
                        </label>

                        <select name="console_type" id="console_type" class="select select-bordered w-full" required>

                            <option value="">Select your console</option>

                            @foreach ($platforms as $platform)
                                <option value="{{ $platform->name }}" @selected(old('console_type') === $platform->name)>
                                    {{ $platform->name }}
                                </option>
                            @endforeach
                        </select>

                        @error('console_type')
                            <p class="text-error text-sm mt-1">
                                {{ $message }}
                            </p>
                        @enderror
                    </div>

                    {{-- Game Variants --}}
                    <div>
                        <div class="mb-3">
                            <h2 class="font-semibold">
                                Select Game Versions
                            </h2>

                            <p class="text-sm text-base-content/60 mt-1">
                                Select at least one available game version for your console.
                            </p>
                        </div>

                        @if ($variants->isEmpty())
                            <div class="alert alert-warning">
                                No game versions are currently available.
                            </div>
                        @else
                            <div class="space-y-3">
                                @foreach ($variants as $variant)
                                    <label
                                        class="flex items-center justify-between gap-4 p-4 border border-base-300 rounded-lg cursor-pointer hover:bg-base-200">

                                        <div class="flex items-center gap-3">
                                            <input type="checkbox" name="variants[]" value="{{ $variant->id }}"
                                                class="checkbox checkbox-primary" @checked(in_array($variant->id, old('variants', [])))>

                                            <div>
                                                <div class="font-semibold">
                                                    {{ $variant->game->title }}
                                                </div>

                                                <div class="text-sm text-base-content/60">
                                                    {{ $variant->platform->name }}

                                                    @if ($variant->game->genre)
                                                        · {{ $variant->game->genre }}
                                                    @endif

                                                    @if ($variant->size !== null)
                                                        · {{ $variant->size }} GB
                                                    @endif
                                                </div>
                                            </div>
                                        </div>

                                        <div class="font-semibold whitespace-nowrap">
                                            {{ number_format((float) $variant->price, 2) }}
                                        </div>
                                    </label>
                                @endforeach
                            </div>
                        @endif

                        @error('variants')
                            <p class="text-error text-sm mt-2">
                                {{ $message }}
                            </p>
                        @enderror

                        @error('variants.*')
                            <p class="text-error text-sm mt-2">
                                {{ $message }}
                            </p>
                        @enderror
                    </div>

                    {{-- Actions --}}
                    <div class="flex justify-end gap-3 pt-4">
                        <a href="{{ route('orders.index') }}" class="btn btn-ghost">
                            Cancel
                        </a>

                        <button type="submit" class="btn btn-primary" @disabled($variants->isEmpty())>
                            Continue to Checkout
                        </button>
                    </div>

                </form>
            </div>
        </div>
    </div>
</x-layout>
