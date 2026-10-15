<nav class="border-b border-base-300 bg-base-100">

    <div class="max-w-7xl mx-auto px-4">

        <div class="navbar min-h-16">

            {{-- Logo --}}
            <div class="flex-1">

                <a href="{{ route('home') }}" class="text-xl font-bold">
                    Game World
                </a>

            </div>


            {{-- Navigation --}}
            <div class="flex-none">

                <div class="flex items-center gap-5">

                    {{-- Games --}}
                    <a href="{{ route('games.index') }}" class="hover:text-primary transition">
                        Games
                    </a>


                    @auth

                        {{-- My Orders --}}
                        <a href="{{ route('orders.index') }}" class="hover:text-primary transition">
                            My Orders
                        </a>


                        {{-- Wallet --}}
                        <a href="{{ route('wallet.index') }}" class="hover:text-primary transition">
                            Wallet
                        </a>


                        {{-- Admin Navigation --}}
                        @if (auth()->user()?->is_admin)
                            <div class="divider divider-horizontal mx-0"></div>

                            {{-- Add Game --}}
                            <a href="{{ route('admin.games.create') }}" class="btn btn-primary btn-sm">
                                Add Game
                            </a>

                            {{-- Manage Orders --}}
                            <a href="{{ route('admin.orders.index') }}" class="hover:text-primary transition">
                                Manage Orders
                            </a>
                        @endif


                        {{-- Notifications --}}
                        <a href="{{ route('notifications.index') }}" class="relative hover:text-primary transition"
                            title="Notifications">

                            <span class="text-xl">
                                🔔
                            </span>

                            @if (auth()->user()->unreadNotifications->count() > 0)
                                <span class="absolute -top-2 -right-3 badge badge-error badge-xs">
                                    {{ auth()->user()->unreadNotifications->count() }}
                                </span>
                            @endif

                        </a>


                        {{-- Logout --}}
                        <form action="{{ route('logout') }}" method="POST">

                            @csrf

                            <button type="submit" class="hover:text-error transition">
                                Logout
                            </button>

                        </form>
                    @else
                        {{-- Login --}}
                        <a href="{{ route('login') }}" class="hover:text-primary transition">
                            Login
                        </a>


                        {{-- Register --}}
                        <a href="{{ route('register') }}" class="btn btn-primary btn-sm">
                            Register
                        </a>

                    @endauth

                </div>

            </div>

        </div>

    </div>

</nav>
