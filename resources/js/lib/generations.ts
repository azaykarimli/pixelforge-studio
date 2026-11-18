import type {
    CostLineKey,
    Generation,
    GenerationStatus,
    ImageQuality,
    ImageSize,
} from '@/types';

export const sizeLabels: Record<ImageSize, string> = {
    '1024x1024': 'Square',
    '1536x1024': 'Landscape',
    '1024x1536': 'Portrait',
};

export const qualityLabels: Record<ImageQuality, string> = {
    low: 'Low',
    medium: 'Medium',
    high: 'High',
};

// "pending" is ours alone: saved here, but not yet accepted by Pixelforge.
// To the person waiting for an image, that is simply queued.
export const statusLabels: Record<GenerationStatus, string> = {
    pending: 'Queued',
    queued: 'Queued',
    processing: 'Processing',
    succeeded: 'Succeeded',
    failed: 'Failed',
};

// The parts of a generation's cost, in the order they are listed.
export const costLineLabels: Record<CostLineKey, string> = {
    image: 'Image',
    reasoning: 'Reasoning',
    prompt: 'Prompt',
    reference_images: 'Reference images',
};

/** Succeeded or failed: nothing more will happen to it. */
export function isFinished(generation: Generation): boolean {
    return generation.status === 'succeeded' || generation.status === 'failed';
}

const failureMessages: Record<string, string> = {
    content_policy_violation:
        "Pixelforge's content policy didn't allow this prompt. Try rewording it.",
    generation_failed:
        "The model couldn't produce an image for this prompt. Try rewording it.",
    internal_error:
        'Something went wrong at Pixelforge while generating. Trying again usually works.',
    overloaded:
        'Pixelforge was too busy to take this request. Please try again in a moment.',
    daily_limit_exceeded:
        "Today's generation limit has been reached. It resets at midnight UTC.",
    timed_out:
        'This request waited too long for a free slot and was cancelled. Please try again.',
};

/** Why a generation failed, in words meant for the person who asked for it. */
export function failureMessage(generation: Generation): string {
    if (generation.failure_code === 'validation_failed') {
        return `Pixelforge couldn't accept this request: ${generation.failure_message}`;
    }

    return (
        failureMessages[generation.failure_code ?? ''] ??
        'Image generation is unavailable right now. Please try again later.'
    );
}
