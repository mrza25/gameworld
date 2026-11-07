<?php

namespace App\Http\Requests;

use App\CouponType;
use App\Models\Coupon;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * Creating a coupon needs its code, type and value. Updating can change any field.
 */
class CouponRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        if ($this->filled('code') && is_string($this->input('code'))) {
            $this->merge(['code' => Coupon::normalizeCode($this->input('code'))]);
        }
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        $required = $this->isMethod('post') ? 'required' : 'sometimes';

        return [
            'code' => [$required, 'string', 'max:32', 'regex:/^[A-Z0-9_-]+$/', Rule::unique('coupons', 'code')->ignore($this->route('coupon'))],
            'type' => [$required, Rule::enum(CouponType::class)],
            'value' => [$required, 'numeric', 'min:1', 'max:9999999999'],
            'max_discount' => ['nullable', 'numeric', 'min:1', 'max:9999999999'],
            'min_order_amount' => ['nullable', 'numeric', 'min:0', 'max:9999999999'],
            'usage_limit' => ['nullable', 'integer', 'min:1'],
            'per_user_limit' => ['nullable', 'integer', 'min:1'],
            'starts_at' => ['nullable', 'date'],
            'expires_at' => ['nullable', 'date', 'after_or_equal:starts_at'],
            'is_active' => ['sometimes', 'boolean'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'code.required' => 'کد تخفیف را وارد کنید.',
            'code.regex' => 'کد تخفیف فقط می‌تواند حرف انگلیسی، عدد، خط تیره و زیرخط باشد.',
            'code.unique' => 'این کد تخفیف قبلاً ساخته شده است.',
            'type.required' => 'نوع تخفیف را انتخاب کنید.',
            'type.enum' => 'نوع تخفیف باید درصدی یا مبلغ ثابت باشد.',
            'value.required' => 'مقدار تخفیف را وارد کنید.',
            'value.min' => 'مقدار تخفیف باید بیشتر از صفر باشد.',
            'expires_at.after_or_equal' => 'تاریخ پایان نمی‌تواند قبل از تاریخ شروع باشد.',
        ];
    }

    /**
     * @return array<int, callable(Validator): void>
     */
    public function after(): array
    {
        return [function (Validator $validator) {
            $type = $this->input('type', $this->route('coupon')?->type?->value);
            $value = $this->input('value', $this->route('coupon')?->value);

            // A 100% coupon would leave nothing to pay, and every order has something to pay.
            if ($type === CouponType::Percent->value && is_numeric($value) && (float) $value >= 100) {
                $validator->errors()->add('value', 'تخفیف درصدی باید کمتر از ۱۰۰ باشد.');
            }
        }];
    }
}
