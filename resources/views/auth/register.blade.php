<x-layout>

    <x-form.form title="Register" action="/register" method="POST">

        @csrf

        <x-form.field name="name" label="Name" type="text" placeholder="Your name" required />

        <x-form.field name="phonenumber" label="Phone Number" type="tel" placeholder="09123456789" required />

        <x-form.field name="password" label="Password" type="password" placeholder="••••••••" required />

        <x-form.field name="password_confirmation" label="Confirm Password" type="password" placeholder="••••••••"
            required />

        <button type="submit" class="btn btn-primary w-full">
            Register
        </button>

    </x-form.form>

</x-layout>
