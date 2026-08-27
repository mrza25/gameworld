@props(['name', 'label', 'type' => 'text', 'placeholder' => '', 'value' => null])

<div>

    <label for="{{ $name }}" class="label">
        <span class="label-text">
            {{ $label }}
        </span>
    </label>

    <input type="{{ $type }}" name="{{ $name }}" id="{{ $name }}" value="{{ old($name, $value) }}"
        placeholder="{{ $placeholder }}" class="input input-bordered w-full" {{ $attributes }}>

    @error($name)
        <p class="text-error text-sm mt-1">
            {{ $message }}
        </p>
    @enderror

</div>
