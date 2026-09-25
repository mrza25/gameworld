<x-layout>

    <div class="min-h-[80vh] flex items-center justify-center py-12">

        <div class="w-full max-w-md">

            <div class="card bg-base-100 shadow-xl">

                <div class="card-body">

                    <div class="text-center mb-6">

                        <h1 class="text-3xl font-bold">
                            Login
                        </h1>

                        <p class="text-base-content/60 mt-2">
                            Login to your Game World account.
                        </p>

                    </div>


                    @if ($errors->any())

                        <div class="alert alert-error mb-5">

                            <ul class="list-disc list-inside">

                                @foreach ($errors->all() as $error)
                                    <li>{{ $error }}</li>
                                @endforeach

                            </ul>

                        </div>

                    @endif


                    <form action="{{ route('login.store') }}" method="POST" class="space-y-5">

                        @csrf


                        <div>

                            <label for="phonenumber" class="label">
                                <span class="label-text">
                                    Phone Number
                                </span>
                            </label>

                            <input type="text" id="phonenumber" name="phonenumber" value="{{ old('phonenumber') }}"
                                class="input input-bordered w-full" required autofocus>

                            @error('phonenumber')
                                <p class="text-error text-sm mt-1">
                                    {{ $message }}
                                </p>
                            @enderror

                        </div>


                        <div>

                            <label for="password" class="label">
                                <span class="label-text">
                                    Password
                                </span>
                            </label>

                            <input type="password" id="password" name="password" class="input input-bordered w-full"
                                required>

                            @error('password')
                                <p class="text-error text-sm mt-1">
                                    {{ $message }}
                                </p>
                            @enderror

                        </div>


                        <button type="submit" class="btn btn-primary w-full">
                            Login
                        </button>

                    </form>


                    <div class="divider">
                        OR
                    </div>


                    <p class="text-center text-sm">

                        Don't have an account?

                        <a href="{{ route('register') }}" class="text-primary font-semibold hover:underline">
                            Register
                        </a>

                    </p>

                </div>

            </div>

        </div>

    </div>

</x-layout>
