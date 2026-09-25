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


                    <a href="{{ route('games.index') }}" class="hover:text-primary transition">
                        Games
                    </a>


                    @auth

                        <a href="{{ route('orders.index') }}" class="hover:text-primary transition">
                            My Orders
                        </a>


                        @if (auth()->user()->is_admin)
                            <div class="divider divider-horizontal mx-0"></div>

                            <a href="{{ route('admin.games.create') }}" class="btn btn-primary btn-sm">
                                Add Game
                            </a>

                            <a href="{{ route('admin.orders.index') }}" class="hover:text-primary transition">
                                Manage Orders
                            </a>
                        @endif


                        {{-- Logout --}}

                        <form action="{{ route('logout') }}" method="POST">

                            @csrf

                            <button type="submit" class="hover:text-error transition">
                                Logout
                            </button>

                        </form>
                    @else
                        <a href="{{ route('login') }}" class="hover:text-primary transition">
                            Login
                        </a>

                        <a href="{{ route('register') }}" class="btn btn-primary btn-sm">
                            Register
                        </a>

                    @endauth

                </div>

            </div>

        </div>

    </div>

</nav>
