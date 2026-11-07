<?php

namespace App\Http\Requests;

use App\Helpers\PersianText;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

/**
 * A card-to-card receipt: the photo, and what the bank statement shows for the
 * transfer. Those details are optional, but they let an admin find the deposit
 * and catch a transfer claimed twice even if the photo was cropped.
 */
class StoreReceiptRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        foreach (['tracking_code', 'card_last4'] as $field) {
            if ($this->filled($field) && is_string($this->input($field))) {
                $this->merge([
                    $field => (string) preg_replace('/[\s-]+/', '', PersianText::latinDigits($this->input($field))),
                ]);
            }
        }
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'receipt' => ['required', 'image', 'mimes:jpg,jpeg,png,webp', 'max:5120'],
            'tracking_code' => ['nullable', 'string', 'alpha_num:ascii', 'min:4', 'max:40'],
            'card_last4' => ['nullable', 'digits:4'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'receipt.required' => 'عکس رسید را انتخاب کنید.',
            'receipt.image' => 'رسید باید عکس باشد.',
            'receipt.mimes' => 'رسید باید با فرمت jpg، png یا webp باشد.',
            'receipt.max' => 'حجم عکس رسید حداکثر ۵ مگابایت است.',
            'tracking_code.alpha_num' => 'شماره پیگیری فقط می‌تواند عدد و حرف انگلیسی باشد.',
            'tracking_code.min' => 'شماره پیگیری کوتاه‌تر از حد مجاز است.',
            'tracking_code.max' => 'شماره پیگیری طولانی‌تر از حد مجاز است.',
            'card_last4.digits' => 'چهار رقم آخر کارت را درست وارد کنید.',
        ];
    }

    /**
     * @return array{tracking_code: ?string, card_last4: ?string}
     */
    public function paymentDetails(): array
    {
        return [
            'tracking_code' => $this->validated('tracking_code'),
            'card_last4' => $this->validated('card_last4'),
        ];
    }
}
