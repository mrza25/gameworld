<x-layout>

    <x-form.form title="Login" action="/login" method="POST">

        @csrf

        <x-form.field name="phonenumber" label="Phone Number" type="tel" placeholder="09123456789" required />

        <x-form.field name="password" label="Password" type="password" placeholder="••••••••" required />

        <button type="submit" class="btn btn-primary w-full">
            Login
        </button>

    </x-form.form>

</x-layout>
