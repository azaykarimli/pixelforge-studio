<?php

namespace App\Http\Requests;

use App\Enums\ImageQuality;
use App\Enums\ImageSize;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class QuoteGenerationRequest extends FormRequest
{
    /**
     * A quote is asked for while the form is still being filled in, so the
     * prompt may be empty and reference image links half typed. Only how
     * many reference images there are affects the price.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'prompt' => ['nullable', 'string', 'max:4000'],
            'size' => ['required', Rule::enum(ImageSize::class)],
            'quality' => ['required', Rule::enum(ImageQuality::class)],
            'reference_images' => ['array', 'max:4'],
            'reference_images.*' => ['nullable', 'string'],
        ];
    }
}
