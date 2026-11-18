export type GenerationStatus =
    | 'pending'
    | 'queued'
    | 'processing'
    | 'succeeded'
    | 'failed';

export type ImageSize = '1024x1024' | '1536x1024' | '1024x1536';

export type ImageQuality = 'low' | 'medium' | 'high';

// Money always arrives from the server as an exact decimal string in US
// dollars, e.g. "0.072312500". See lib/money.ts for how it is displayed.
export type Money = string;

// The four things a generation is charged for.
export type CostLineKey = 'image' | 'reasoning' | 'prompt' | 'reference_images';

export type Generation = {
    id: string;
    status: GenerationStatus;
    prompt: string;
    size: ImageSize;
    quality: ImageQuality;
    reference_images: string[];
    estimated_charge: Money;
    max_charge: Money;
    charge: Money | null;
    breakdown: Record<CostLineKey, { tokens: number; charge: Money }> | null;
    image_url: string | null;
    image_url_expires_at: string | null;
    failure_code: string | null;
    failure_message: string | null;
    created_at: string;
    completed_at: string | null;
};

// A figure that isn't known exactly until the generation finishes.
export type Estimate<T> = {
    min: T;
    expected: T;
    max: T;
};

export type Quote = {
    estimated: Money;
    min: Money;
    max: Money;
    // Whether the user's available credit covers the most this could cost.
    available: Money;
    affordable: boolean;
    lines: Record<
        CostLineKey,
        { tokens: Estimate<number>; charge: Estimate<Money> }
    >;
};

export type Paginated<T> = {
    data: T[];
    links: {
        prev: string | null;
        next: string | null;
    };
    meta: {
        current_page: number;
        last_page: number;
        total: number;
    };
};
