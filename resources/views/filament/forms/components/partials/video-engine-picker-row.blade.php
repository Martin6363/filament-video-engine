@props([
    'label',
])

<div {{ $attributes->class(['fi-fo-field', 'fi-fo-field-has-inline-label']) }}>
    <div class="fi-fo-field-label-col">
        <div class="fi-fo-field-label-ctn">
            <div class="fi-fo-field-label">
                <span class="fi-fo-field-label-content">{{ $label }}</span>
            </div>
        </div>
    </div>

    <div class="fi-fo-field-content-col">
        <div class="fi-fo-field-content-ctn">
            <div class="fi-fo-field-content">
                {{ $slot }}
            </div>
        </div>
    </div>
</div>
