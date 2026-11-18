import type { Money } from './generation';

// The signed-in user's credit, shared with every page.
export type Credits = {
    // Everything bought, minus everything charged.
    balance: Money;
    // Set aside for generations still in progress: the most each could cost.
    reserved: Money;
    // What can be spent right now: balance minus reserved.
    available: Money;
    // The amounts of credit on offer, in whole dollars.
    packs: number[];
};

export type LedgerEntry = {
    id: number;
    type: 'purchase' | 'generation_charge';
    // Signed: negative for a charge, positive for a purchase.
    amount: Money;
    // The prompt of the generation a charge was for.
    prompt: string | null;
    created_at: string;
};
